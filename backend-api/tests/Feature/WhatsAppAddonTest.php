<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Invoice;
use App\Models\Subscription;
use App\Models\User;
use App\Models\WhatsAppNumber;
use Database\Seeders\Phase1FoundationSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Paid extra WhatsApp numbers (agreed 2026-10-05): one invoice per purchase with a
 * GST-inclusive total, manual payment by Super Admin or Agent, a one-month term per
 * number from payment, and pausing (never removal) when a term or plan ends.
 */
class WhatsAppAddonTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        $this->seed(Phase1FoundationSeeder::class);
        config(['services.qr_engine.internal_secret' => 'test-secret']);
    }

    /** @return array{0: Account, 1: User} tenant admin on an active plan with one included number */
    private function tenant(string $phone = '919876543210'): array
    {
        $account = Account::factory()->create();
        Subscription::factory()->for($account)->create(['engine_type' => 'qr']);
        WhatsAppNumber::create([
            'account_id' => $account->id,
            'phone_number' => $phone,
            'is_included' => true,
            'is_default' => true,
            'status' => WhatsAppNumber::STATUS_UNLINKED,
        ]);

        $user = User::factory()->create(['account_id' => $account->id]);
        $user->assignRole('admin');

        return [$account, $user];
    }

    private function superAdmin(): User
    {
        $user = User::factory()->create(['account_id' => null]);
        $user->assignRole('super_admin');

        return $user;
    }

    private function buy(User $user, array $phones, ?Account $account = null): \Illuminate\Testing\TestResponse
    {
        return $this->actingAs($user)->postJson('/api/whatsapp/numbers/purchase', ['phone_numbers' => $phones]);
    }

    public function test_the_price_is_the_gst_inclusive_addon_price(): void
    {
        [, $user] = $this->tenant();

        $this->actingAs($user)->getJson('/api/whatsapp/numbers/addon-price')
            ->assertOk()
            ->assertJsonPath('price', 99)
            ->assertJsonPath('includes_gst', true)
            ->assertJsonPath('term_months', 1);
    }

    public function test_a_purchase_creates_one_invoice_with_a_gst_inclusive_total_and_one_line_per_number(): void
    {
        [$account, $user] = $this->tenant();

        $response = $this->buy($user, ['917236062374', '918888888888'])->assertCreated();

        $invoice = Invoice::query()->findOrFail($response->json('invoice.id'));
        $this->assertSame($account->id, $invoice->account_id);
        $this->assertSame(198.0, (float) $invoice->total_amount, 'two numbers at 99 each, GST included');
        $this->assertSame(0.0, (float) $invoice->tax_amount, 'no GST line is shown');
        $this->assertSame('pending', $invoice->status);
        $this->assertCount(2, $invoice->lineItems);
        $this->assertSame(['pending_payment', 'pending_payment'], WhatsAppNumber::where('addon_invoice_id', $invoice->id)->pluck('status')->all());
    }

    public function test_the_response_shows_only_the_gst_inclusive_total(): void
    {
        [, $user] = $this->tenant();

        $response = $this->buy($user, ['917236062374'])->assertCreated();

        $this->assertArrayNotHasKey('tax_amount', $response->json('invoice'));
        $this->assertSame(99.0, (float) $response->json('invoice.total_amount'));
    }

    public function test_a_purchase_never_changes_the_included_number_or_the_default(): void
    {
        [$account, $user] = $this->tenant();

        $this->buy($user, ['917236062374'])->assertCreated();

        $included = WhatsAppNumber::where('account_id', $account->id)->where('is_included', true)->firstOrFail();
        $this->assertTrue($included->is_default);
        $this->assertSame('919876543210', $included->phone_number);
        $this->assertSame(1, WhatsAppNumber::where('account_id', $account->id)->where('is_default', true)->count());
    }

    public function test_a_number_already_used_anywhere_is_refused(): void
    {
        [, $user] = $this->tenant();
        [, $other] = $this->tenant('917000000001');

        $this->buy($user, ['917000000001'])
            ->assertStatus(409)
            ->assertJsonPath('error_code', 'number_already_used');

        $this->buy($other, ['919876543210'])
            ->assertStatus(409)
            ->assertJsonPath('error_code', 'number_already_used');
    }

    public function test_a_purchase_needs_an_included_number_and_valid_numbers(): void
    {
        $account = Account::factory()->create();
        Subscription::factory()->for($account)->create(['engine_type' => 'qr']);
        $user = User::factory()->create(['account_id' => $account->id]);
        $user->assignRole('admin');

        $this->buy($user, ['917236062374'])
            ->assertUnprocessable()
            ->assertJsonPath('error_code', 'no_included_number');

        [, $tenant] = $this->tenant();
        $this->buy($tenant, ['7236062374'])->assertUnprocessable()->assertJsonPath('error_code', 'missing_country_code');
        $this->buy($tenant, [])->assertUnprocessable();
        $this->buy($tenant, array_map(fn ($i) => '9170000000'.str_pad((string) $i, 2, '0', STR_PAD_LEFT), range(1, 11)))
            ->assertUnprocessable();
    }

    public function test_cancelling_an_unpaid_purchase_releases_its_numbers(): void
    {
        [$account, $user] = $this->tenant();
        $invoiceId = $this->buy($user, ['917236062374'])->assertCreated()->json('invoice.id');

        $this->actingAs($user)->deleteJson("/api/whatsapp/addon-invoices/{$invoiceId}")->assertOk();

        $this->assertSame(0, WhatsAppNumber::where('phone_number', '917236062374')->count());
        $this->assertSame('failed', Invoice::find($invoiceId)->status);
    }

    public function test_a_super_admin_records_a_manual_payment_and_the_numbers_get_a_one_month_term(): void
    {
        [$account, $user] = $this->tenant();
        $invoiceId = $this->buy($user, ['917236062374'])->assertCreated()->json('invoice.id');

        $this->actingAs($this->superAdmin())
            ->postJson("/api/admin/whatsapp/addon-invoices/{$invoiceId}/record-payment?account_id={$account->id}", $this->paymentBody())
            ->assertOk()
            ->assertJsonPath('invoice.status', 'paid');

        $slot = WhatsAppNumber::where('phone_number', '917236062374')->firstOrFail();
        $this->assertSame(WhatsAppNumber::STATUS_UNLINKED, $slot->status, 'paid numbers can now be connected');
        $this->assertNotNull($slot->locked_at);
        // The term starts on the date paid and runs one month.
        $this->assertSame(now()->addMonth()->toDateString(), $slot->term_ends_at->toDateString());
    }

    public function test_recording_a_payment_twice_is_refused(): void
    {
        [$account, $user] = $this->tenant();
        $invoiceId = $this->buy($user, ['917236062374'])->assertCreated()->json('invoice.id');
        $admin = $this->superAdmin();
        $url = "/api/admin/whatsapp/addon-invoices/{$invoiceId}/record-payment?account_id={$account->id}";

        $this->actingAs($admin)->postJson($url, $this->paymentBody())->assertOk();
        $this->actingAs($admin)->postJson($url, $this->paymentBody(['transaction_id' => 'TXN-SECOND']))
            ->assertStatus(409)
            ->assertJsonPath('error_code', 'invoice_not_pending');
    }

    public function test_a_tenant_admin_cannot_record_a_payment(): void
    {
        [$account, $user] = $this->tenant();
        $invoiceId = $this->buy($user, ['917236062374'])->assertCreated()->json('invoice.id');

        $this->actingAs($user)
            ->postJson("/api/admin/whatsapp/addon-invoices/{$invoiceId}/record-payment?account_id={$account->id}", $this->paymentBody())
            ->assertForbidden();

        $this->assertSame('pending', Invoice::find($invoiceId)->status);
    }

    public function test_a_paid_purchase_cannot_be_cancelled(): void
    {
        [$account, $user] = $this->tenant();
        $invoiceId = $this->buy($user, ['917236062374'])->assertCreated()->json('invoice.id');
        $this->actingAs($this->superAdmin())
            ->postJson("/api/admin/whatsapp/addon-invoices/{$invoiceId}/record-payment?account_id={$account->id}", $this->paymentBody())
            ->assertOk();

        $this->actingAs($user)->deleteJson("/api/whatsapp/addon-invoices/{$invoiceId}")
            ->assertStatus(409)
            ->assertJsonPath('error_code', 'invoice_not_pending');
        $this->assertSame(1, WhatsAppNumber::where('phone_number', '917236062374')->count());
    }

    /** A valid manual payment for a 99 invoice; override any field per test. */
    private function paymentBody(array $overrides = []): array
    {
        return array_merge([
            'amount' => 99,
            'method' => 'bank_transfer',
            'transaction_id' => 'TXN-'.uniqid(),
            'paid_on' => now()->toDateString(),
        ], $overrides);
    }

    /** @return array{0: Account, 1: User} an agent account and its agent user */
    private function agent(): array
    {
        $agentAccount = Account::factory()->create(['account_type' => 'agent']);
        $agent = User::factory()->create(['account_id' => $agentAccount->id]);
        $agent->assignRole('agent');

        return [$agentAccount, $agent];
    }

    /** @return array{0: Account, 1: User} a client created by an agent (agent_id set) */
    private function clientOf(Account $agentAccount): array
    {
        $account = Account::factory()->create(['agent_id' => $agentAccount->id]);
        Subscription::factory()->for($account)->create(['engine_type' => 'qr']);
        WhatsAppNumber::create([
            'account_id' => $account->id,
            'phone_number' => '919000000'.random_int(100, 999),
            'is_included' => true,
            'is_default' => true,
            'status' => WhatsAppNumber::STATUS_UNLINKED,
        ]);
        $user = User::factory()->create(['account_id' => $account->id]);
        $user->assignRole('admin');

        return [$account, $user];
    }

    public function test_an_agent_records_the_payment_of_its_own_client_and_the_numbers_activate(): void
    {
        [$agentAccount, $agent] = $this->agent();
        [$client, $clientUser] = $this->clientOf($agentAccount);
        $invoiceId = $this->buy($clientUser, ['917236062374'])->assertCreated()->json('invoice.id');

        $this->actingAs($agent)
            ->postJson("/api/admin/whatsapp/addon-invoices/{$invoiceId}/record-payment?account_id={$client->id}", $this->paymentBody())
            ->assertOk()
            ->assertJsonPath('invoice.status', 'paid');

        $this->assertDatabaseHas('manual_payments', ['invoice_id' => $invoiceId, 'recorded_by_role' => 'agent']);
        $this->assertSame(WhatsAppNumber::STATUS_UNLINKED, WhatsAppNumber::where('phone_number', '917236062374')->value('status'));
    }

    public function test_an_agent_cannot_record_a_payment_for_a_client_another_agent_created(): void
    {
        [$agentAccount] = $this->agent();
        [, $otherAgent] = $this->agent();
        [$client, $clientUser] = $this->clientOf($agentAccount);
        $invoiceId = $this->buy($clientUser, ['917236062374'])->assertCreated()->json('invoice.id');

        $status = $this->actingAs($otherAgent)
            ->postJson("/api/admin/whatsapp/addon-invoices/{$invoiceId}/record-payment?account_id={$client->id}", $this->paymentBody())
            ->status();

        $this->assertContains($status, [403, 404], 'an agent must not reach another agent\'s client');
        $this->assertSame('pending', Invoice::find($invoiceId)->status);
    }

    public function test_an_agent_cannot_approve_a_self_signup_account(): void
    {
        [, $agent] = $this->agent();
        [$direct, $directUser] = $this->tenant();
        $invoiceId = $this->buy($directUser, ['917236062374'])->assertCreated()->json('invoice.id');

        $status = $this->actingAs($agent)
            ->postJson("/api/admin/whatsapp/addon-invoices/{$invoiceId}/record-payment?account_id={$direct->id}", $this->paymentBody())
            ->status();

        $this->assertContains($status, [403, 404]);
        $this->assertSame('pending', Invoice::find($invoiceId)->status);
    }

    public function test_a_super_admin_can_record_for_a_self_signup_account_and_for_an_agents_client_when_the_agent_does_not_respond(): void
    {
        [$agentAccount] = $this->agent();
        [$client, $clientUser] = $this->clientOf($agentAccount);
        $clientInvoice = $this->buy($clientUser, ['917236062374'])->assertCreated()->json('invoice.id');
        [$direct, $directUser] = $this->tenant();
        $directInvoice = $this->buy($directUser, ['917236062375'])->assertCreated()->json('invoice.id');
        $admin = $this->superAdmin();

        $this->actingAs($admin)
            ->postJson("/api/admin/whatsapp/addon-invoices/{$clientInvoice}/record-payment?account_id={$client->id}", $this->paymentBody())
            ->assertOk();
        $this->actingAs($admin)
            ->postJson("/api/admin/whatsapp/addon-invoices/{$directInvoice}/record-payment?account_id={$direct->id}", $this->paymentBody(['transaction_id' => 'TXN-DIRECT']))
            ->assertOk();

        $this->assertDatabaseHas('manual_payments', ['invoice_id' => $clientInvoice, 'recorded_by_role' => 'super_admin']);
    }

    public function test_the_amount_must_equal_the_invoice_total(): void
    {
        [$account, $user] = $this->tenant();
        $invoiceId = $this->buy($user, ['917236062374'])->assertCreated()->json('invoice.id');

        $this->actingAs($this->superAdmin())
            ->postJson("/api/admin/whatsapp/addon-invoices/{$invoiceId}/record-payment?account_id={$account->id}", $this->paymentBody(['amount' => 98]))
            ->assertUnprocessable()
            ->assertJsonPath('error_code', 'amount_mismatch');

        $this->assertSame('pending', Invoice::find($invoiceId)->status);
    }

    public function test_a_transaction_id_cannot_be_recorded_twice_across_invoices(): void
    {
        [$account, $user] = $this->tenant();
        $first = $this->buy($user, ['917236062374'])->assertCreated()->json('invoice.id');
        $second = $this->buy($user, ['917236062375'])->assertCreated()->json('invoice.id');
        $admin = $this->superAdmin();

        $this->actingAs($admin)
            ->postJson("/api/admin/whatsapp/addon-invoices/{$first}/record-payment?account_id={$account->id}", $this->paymentBody(['transaction_id' => 'UTR123']))
            ->assertOk();
        $this->actingAs($admin)
            ->postJson("/api/admin/whatsapp/addon-invoices/{$second}/record-payment?account_id={$account->id}", $this->paymentBody(['transaction_id' => 'UTR123']))
            ->assertStatus(409)
            ->assertJsonPath('error_code', 'transaction_already_recorded');
    }

    public function test_a_payment_dated_in_the_future_or_missing_its_details_is_refused(): void
    {
        [$account, $user] = $this->tenant();
        $invoiceId = $this->buy($user, ['917236062374'])->assertCreated()->json('invoice.id');
        $url = "/api/admin/whatsapp/addon-invoices/{$invoiceId}/record-payment?account_id={$account->id}";
        $admin = $this->superAdmin();

        $this->actingAs($admin)->postJson($url, $this->paymentBody(['paid_on' => now()->addDay()->toDateString()]))
            ->assertUnprocessable();
        $this->actingAs($admin)->postJson($url, $this->paymentBody(['transaction_id' => '']))
            ->assertUnprocessable();
        $this->actingAs($admin)->postJson($url, $this->paymentBody(['method' => 'bitcoin']))
            ->assertUnprocessable();
        $this->actingAs($admin)->postJson($url, $this->paymentBody(['amount' => 0]))
            ->assertUnprocessable();

        $this->assertSame('pending', Invoice::find($invoiceId)->status);
    }

    public function test_the_term_can_start_on_a_chosen_date_and_runs_one_month_from_it(): void
    {
        [$account, $user] = $this->tenant();
        $invoiceId = $this->buy($user, ['917236062374'])->assertCreated()->json('invoice.id');

        $this->actingAs($this->superAdmin())
            ->postJson("/api/admin/whatsapp/addon-invoices/{$invoiceId}/record-payment?account_id={$account->id}", $this->paymentBody([
                'paid_on' => now()->toDateString(),
                'term_starts_on' => now()->addDays(5)->toDateString(),
            ]))
            ->assertOk();

        $slot = WhatsAppNumber::where('phone_number', '917236062374')->firstOrFail();
        $this->assertSame(now()->addDays(5)->addMonth()->toDateString(), $slot->term_ends_at->toDateString());
        $payment = \App\Models\ManualPayment::where('invoice_id', $invoiceId)->firstOrFail();
        $this->assertSame(now()->addDays(5)->toDateString(), $payment->term_starts_on->toDateString());
    }

    public function test_the_term_start_cannot_move_more_than_thirty_days(): void
    {
        [$account, $user] = $this->tenant();
        $invoiceId = $this->buy($user, ['917236062374'])->assertCreated()->json('invoice.id');

        $this->actingAs($this->superAdmin())
            ->postJson("/api/admin/whatsapp/addon-invoices/{$invoiceId}/record-payment?account_id={$account->id}", $this->paymentBody([
                'term_starts_on' => now()->addDays(45)->toDateString(),
            ]))
            ->assertUnprocessable()
            ->assertJsonPath('error_code', 'term_start_out_of_range');
    }

    public function test_pending_list_shows_a_super_admin_everything_and_an_agent_only_its_own_clients(): void
    {
        [$agentAccount, $agent] = $this->agent();
        [$ownClient, $ownClientUser] = $this->clientOf($agentAccount);
        [$otherAgentAccount] = $this->agent();
        [$otherClient, $otherClientUser] = $this->clientOf($otherAgentAccount);
        [$direct, $directUser] = $this->tenant();

        $own = $this->buy($ownClientUser, ['917236062374'])->assertCreated()->json('invoice.id');
        $other = $this->buy($otherClientUser, ['917236062375'])->assertCreated()->json('invoice.id');
        $directInvoice = $this->buy($directUser, ['917236062376'])->assertCreated()->json('invoice.id');

        $agentIds = collect($this->actingAs($agent)->getJson('/api/admin/whatsapp/addon-invoices/pending')->assertOk()->json('data'))->pluck('id')->all();
        $this->assertSame([$own], $agentIds, 'an agent sees its own clients only');

        $adminIds = collect($this->actingAs($this->superAdmin())->getJson('/api/admin/whatsapp/addon-invoices/pending')->assertOk()->json('data'))->pluck('id')->all();
        $this->assertEqualsCanonicalizing([$own, $other, $directInvoice], $adminIds, 'a Super Admin sees every account');
    }

    public function test_paid_invoices_leave_the_pending_list_and_a_tenant_admin_cannot_open_it(): void
    {
        [$account, $user] = $this->tenant();
        $invoiceId = $this->buy($user, ['917236062374'])->assertCreated()->json('invoice.id');

        $this->actingAs($user)->getJson('/api/admin/whatsapp/addon-invoices/pending')->assertForbidden();

        $this->actingAs($this->superAdmin())
            ->postJson("/api/admin/whatsapp/addon-invoices/{$invoiceId}/record-payment?account_id={$account->id}", $this->paymentBody())
            ->assertOk();

        $this->actingAs($this->superAdmin())->getJson('/api/admin/whatsapp/addon-invoices/pending')
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }

    public function test_online_payment_is_refused_while_no_gateway_is_configured(): void
    {
        [$account, $user] = $this->tenant();
        $invoiceId = $this->buy($user, ['917236062374'])->assertCreated()->json('invoice.id');

        $this->actingAs($user)->postJson("/api/whatsapp/addon-invoices/{$invoiceId}/pay", ['gateway' => 'razorpay'])
            ->assertUnprocessable()
            ->assertJsonPath('error_code', 'gateway_not_configured');

        $this->assertSame('pending', Invoice::find($invoiceId)->status);
    }

    public function test_a_paid_invoice_cannot_be_paid_online_again(): void
    {
        [$account, $user] = $this->tenant();
        $invoiceId = $this->buy($user, ['917236062374'])->assertCreated()->json('invoice.id');
        $this->actingAs($this->superAdmin())
            ->postJson("/api/admin/whatsapp/addon-invoices/{$invoiceId}/record-payment?account_id={$account->id}", $this->paymentBody())
            ->assertOk();

        $this->actingAs($user)->postJson("/api/whatsapp/addon-invoices/{$invoiceId}/pay", ['gateway' => 'razorpay'])
            ->assertStatus(409)
            ->assertJsonPath('error_code', 'invoice_not_pending');
    }

    public function test_an_unknown_gateway_is_refused_by_validation(): void
    {
        [$account, $user] = $this->tenant();
        $invoiceId = $this->buy($user, ['917236062374'])->assertCreated()->json('invoice.id');

        $this->actingAs($user)->postJson("/api/whatsapp/addon-invoices/{$invoiceId}/pay", ['gateway' => 'bitcoin'])
            ->assertUnprocessable();
    }

    public function test_a_gateway_confirmed_payment_activates_the_numbers_once(): void
    {
        [$account, $user] = $this->tenant();
        $invoiceId = $this->buy($user, ['917236062374'])->assertCreated()->json('invoice.id');
        $credit = app(\App\Services\Billing\InvoiceCreditService::class);

        // The same call the verify route and the webhook make after the gateway confirms.
        $this->assertTrue($credit->markPaidAndCreditQuota($invoiceId, 'pay_ONLINE_1'));
        $this->assertFalse($credit->markPaidAndCreditQuota($invoiceId, 'pay_ONLINE_1'), 'a repeated confirmation changes nothing');

        $invoice = Invoice::find($invoiceId);
        $this->assertSame('paid', $invoice->status);
        $this->assertSame('pay_ONLINE_1', $invoice->gateway_payment_id);

        $slot = WhatsAppNumber::where('phone_number', '917236062374')->firstOrFail();
        $this->assertSame(WhatsAppNumber::STATUS_UNLINKED, $slot->status);
        $this->assertNotNull($slot->term_ends_at);
        $this->assertSame(0, \App\Models\ManualPayment::where('invoice_id', $invoiceId)->count(), 'no manual record for an online payment');
    }

    public function test_an_expired_addon_term_is_paused_and_its_engine_session_logged_out_but_not_removed(): void
    {
        Http::fake(['*/api/qr/logout' => Http::response(['message' => 'Session ended.'], 200)]);
        [$account] = $this->tenant();
        $slot = WhatsAppNumber::create([
            'account_id' => $account->id,
            'phone_number' => '917236062374',
            'is_included' => false,
            'is_default' => false,
            'status' => WhatsAppNumber::STATUS_LINKED,
            'term_ends_at' => now()->subDay(),
        ]);

        $this->artisan('whatsapp:enforce-terms')->assertSuccessful();

        $this->assertSame(WhatsAppNumber::STATUS_PAUSED, $slot->fresh()->status);
        $this->assertDatabaseHas('whatsapp_numbers', ['id' => $slot->id]);
        Http::assertSent(fn ($request) => str_contains($request->url(), '/api/qr/logout') && $request['session_id'] === $slot->id);
    }

    public function test_an_addon_within_its_term_is_left_running(): void
    {
        Http::fake();
        [$account] = $this->tenant();
        $slot = WhatsAppNumber::create([
            'account_id' => $account->id,
            'phone_number' => '917236062374',
            'is_included' => false,
            'is_default' => false,
            'status' => WhatsAppNumber::STATUS_LINKED,
            'term_ends_at' => now()->addDays(10),
        ]);

        $this->artisan('whatsapp:enforce-terms')->assertSuccessful();

        $this->assertSame(WhatsAppNumber::STATUS_LINKED, $slot->fresh()->status);
        Http::assertNothingSent();
    }

    public function test_an_addon_outlives_the_plan_but_the_included_number_pauses_with_it(): void
    {
        Http::fake();
        [$account] = $this->tenant();
        // The plan ends: no active subscription any more.
        Subscription::where('account_id', $account->id)->update(['expires_at' => now()->subDay()]);
        $addon = WhatsAppNumber::create([
            'account_id' => $account->id,
            'phone_number' => '917236062374',
            'is_included' => false,
            'is_default' => false,
            'status' => WhatsAppNumber::STATUS_LINKED,
            'term_ends_at' => now()->addWeeks(2),
        ]);

        $this->artisan('whatsapp:enforce-terms')->assertSuccessful();

        $this->assertSame(WhatsAppNumber::STATUS_PAUSED, WhatsAppNumber::where('account_id', $account->id)->where('is_included', true)->value('status'));
        $this->assertSame(WhatsAppNumber::STATUS_LINKED, $addon->fresh()->status, 'the extra number keeps running to its own end date');
    }
}
