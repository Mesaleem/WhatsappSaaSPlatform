<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Invoice;
use App\Models\ManualPayment;
use App\Models\Subscription;
use App\Models\User;
use Carbon\Carbon;
use Database\Seeders\Phase1FoundationSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Plan payments by role: a client asks for a plan, and the plan is paid online (only once a
 * gateway is configured) or by hand. A Super Admin records any payment; an agent only its
 * own clients'; a direct client's payment is the Super Admin's alone.
 */
class PlanPaymentMatrixTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        $this->seed(Phase1FoundationSeeder::class);
    }

    private function client(?Account $agent = null): array
    {
        $account = Account::factory()->create([
            'agent_id' => $agent?->id,
            'allowed_modules' => ['dashboard', 'billing'],
        ]);
        Subscription::factory()->for($account)->create(['engine_type' => 'qr']);
        $user = User::factory()->create(['account_id' => $account->id]);
        $user->assignRole('admin');

        return [$account, $user];
    }

    private function agent(): array
    {
        $account = Account::factory()->create(['account_type' => 'agent']);
        $user = User::factory()->create(['account_id' => $account->id]);
        $user->assignRole('admin');
        $user->assignRole('agent');

        return [$account, $user];
    }

    private function superAdmin(): User
    {
        $user = User::factory()->create(['account_id' => null]);
        $user->assignRole('super_admin');

        return $user;
    }

    /** A pending plan invoice, as the client's manual checkout creates it. */
    private function manualInvoice(Account $account, User $user): Invoice
    {
        $response = $this->actingAs($user)->postJson('/api/billing/manual-checkout', ['plan_key' => 'starter'])
            ->assertCreated();

        return Invoice::query()->findOrFail($response->json('invoice.id'));
    }

    private function recordBody(Invoice $invoice, array $overrides = []): array
    {
        return array_merge([
            'amount' => (float) $invoice->total_amount,
            'method' => 'bank_transfer',
            'transaction_id' => 'TX-'.$invoice->id.'-'.uniqid(),
            'paid_on' => now()->subDays(2)->toDateString(),
            'term_starts_on' => now()->subDays(2)->toDateString(),
        ], $overrides);
    }

    public function test_a_client_without_a_gateway_requests_a_plan_and_gets_a_manual_invoice_with_gst(): void
    {
        [$account, $user] = $this->client();

        $invoice = $this->manualInvoice($account, $user);

        $this->assertSame('pending', $invoice->status);
        $this->assertSame('manual', $invoice->payment_gateway);
        $plan = app(\App\Services\Billing\PlanRepository::class)->findPurchasable('starter');
        $this->assertEqualsWithDelta((float) $plan->price * 1.18, (float) $invoice->total_amount, 0.01, 'GST is added to the plan price');
    }

    public function test_a_second_manual_request_for_the_same_plan_waits_for_the_first(): void
    {
        [$account, $user] = $this->client();
        $this->manualInvoice($account, $user);

        $this->actingAs($user)->postJson('/api/billing/manual-checkout', ['plan_key' => 'starter'])
            ->assertStatus(409)
            ->assertJsonPath('error_code', 'request_pending');
    }

    public function test_online_payment_is_refused_while_no_gateway_is_configured(): void
    {
        [$account, $user] = $this->client();
        $invoice = $this->manualInvoice($account, $user);

        $this->actingAs($user)->postJson("/api/billing/invoices/{$invoice->id}/pay", ['gateway' => 'razorpay'])
            ->assertStatus(422)
            ->assertJsonPath('error_code', 'gateway_not_configured');
    }

    public function test_a_super_admin_records_a_payment_and_the_plan_starts_on_the_chosen_date(): void
    {
        [$account, $user] = $this->client();
        // No plan is running yet (the case the chosen start date is for): the client's earlier
        // subscription has lapsed. A still-running plan is extended from its expiry instead.
        Subscription::query()->where('account_id', $account->id)->update(['starts_at' => now()->subDays(40), 'expires_at' => now()->subDay()]);
        $invoice = $this->manualInvoice($account, $user);
        $startOn = now()->subDays(2)->startOfDay();

        $this->actingAs($this->superAdmin())->postJson("/api/admin/billing/invoices/{$invoice->id}/record-payment", $this->recordBody($invoice))
            ->assertOk();

        $invoice->refresh();
        $this->assertSame('paid', $invoice->status);
        $this->assertSame($startOn->toDateString(), Carbon::parse($account->fresh()->currentSubscription->starts_at)->toDateString(), 'the plan starts on the chosen date, not today');

        $plan = app(\App\Services\Billing\PlanRepository::class)->findPurchasable('starter');
        $this->assertSame(
            $startOn->copy()->addDays((int) $plan->duration_days)->toDateString(),
            Carbon::parse($account->fresh()->currentSubscription->expires_at)->toDateString(),
            'the term runs from the chosen start date',
        );

        $payment = ManualPayment::query()->where('invoice_id', $invoice->id)->firstOrFail();
        $this->assertSame('super_admin', $payment->recorded_by_role);
    }

    public function test_an_agent_records_a_payment_for_its_own_client(): void
    {
        [$agentAccount, $agentUser] = $this->agent();
        [$client, $clientUser] = $this->client($agentAccount);
        $invoice = $this->manualInvoice($client, $clientUser);

        $this->actingAs($agentUser)->postJson("/api/admin/billing/invoices/{$invoice->id}/record-payment", $this->recordBody($invoice))
            ->assertOk();

        $this->assertSame('paid', $invoice->fresh()->status);
        $this->assertSame('agent', ManualPayment::query()->where('invoice_id', $invoice->id)->value('recorded_by_role'));
    }

    public function test_an_agent_cannot_record_for_a_direct_client_or_another_agents_client(): void
    {
        [$agentAccount, $agentUser] = $this->agent();
        [$direct, $directUser] = $this->client();
        [$otherAgent] = $this->agent();
        [$otherClient, $otherClientUser] = $this->client($otherAgent);

        $directInvoice = $this->manualInvoice($direct, $directUser);
        $otherInvoice = $this->manualInvoice($otherClient, $otherClientUser);

        $this->actingAs($agentUser)->postJson("/api/admin/billing/invoices/{$directInvoice->id}/record-payment", $this->recordBody($directInvoice))
            ->assertForbidden()
            ->assertJsonPath('error_code', 'self_signup_needs_super_admin');

        $this->actingAs($agentUser)->postJson("/api/admin/billing/invoices/{$otherInvoice->id}/record-payment", $this->recordBody($otherInvoice))
            ->assertForbidden()
            ->assertJsonPath('error_code', 'not_your_client');

        $this->assertSame('pending', $directInvoice->fresh()->status);
        $this->assertSame('pending', $otherInvoice->fresh()->status);
    }

    public function test_a_client_cannot_record_its_own_payment(): void
    {
        [$account, $user] = $this->client();
        $invoice = $this->manualInvoice($account, $user);

        $this->actingAs($user)->postJson("/api/admin/billing/invoices/{$invoice->id}/record-payment", $this->recordBody($invoice))
            ->assertForbidden();
    }

    public function test_the_pending_list_is_everything_for_a_super_admin_and_only_own_clients_for_an_agent(): void
    {
        [$agentAccount, $agentUser] = $this->agent();
        [$mine, $mineUser] = $this->client($agentAccount);
        [$direct, $directUser] = $this->client();
        $this->manualInvoice($mine, $mineUser);
        $this->manualInvoice($direct, $directUser);

        $this->actingAs($this->superAdmin())->getJson('/api/admin/billing/pending-invoices')
            ->assertOk()
            ->assertJsonCount(2, 'data');

        $this->actingAs($agentUser)->getJson('/api/admin/billing/pending-invoices')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.account_id', $mine->id);
    }

    public function test_the_pending_list_gives_each_invoices_term_and_the_gateways_ready_now(): void
    {
        [$account, $user] = $this->client();
        $invoice = $this->manualInvoice($account, $user);
        $plan = app(\App\Services\Billing\PlanRepository::class)->findPurchasable('starter');

        $this->actingAs($this->superAdmin())->getJson('/api/admin/billing/pending-invoices')
            ->assertOk()
            ->assertJsonPath('data.0.id', $invoice->id)
            ->assertJsonPath('data.0.term.days', (int) $plan->duration_days)
            ->assertJsonPath('gateways', []);
    }

    public function test_the_billing_list_gives_each_invoice_its_term(): void
    {
        [$account, $user] = $this->client();
        $invoice = $this->manualInvoice($account, $user);
        $plan = app(\App\Services\Billing\PlanRepository::class)->findPurchasable('starter');

        $this->actingAs($user)->getJson('/api/billing/invoices')
            ->assertOk()
            ->assertJsonPath('data.0.id', $invoice->id)
            ->assertJsonPath('data.0.term.days', (int) $plan->duration_days);
    }

    public function test_an_agent_reaches_the_online_payment_for_its_own_client_and_is_stopped_by_the_gateway_check(): void
    {
        [$agentAccount, $agentUser] = $this->agent();
        [$client, $clientUser] = $this->client($agentAccount);
        $invoice = $this->manualInvoice($client, $clientUser);

        // Permission passes (not a 403); the missing gateway is the refusal.
        $this->actingAs($agentUser)->postJson("/api/billing/invoices/{$invoice->id}/pay?account_id={$client->id}", ['gateway' => 'razorpay'])
            ->assertStatus(422)
            ->assertJsonPath('error_code', 'gateway_not_configured');
    }
}
