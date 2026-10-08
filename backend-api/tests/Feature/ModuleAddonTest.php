<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Invoice;
use App\Models\ModuleAddonOffer;
use App\Models\ModuleAddonRequest;
use App\Models\Subscription;
use App\Models\User;
use App\Services\Billing\InvoiceCreditService;
use Database\Seeders\Phase1FoundationSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Paid module add-ons (Native WhatsApp Groups -- module key `contact_groups`, see
 * ModuleAddonService::alreadyIncluded()'s own re-scoping docblock for why this file still uses that
 * key): request -> approve (invoice) -> pay (manual or online) -> the module is on for one term ->
 * switched off when it ends. The offer (price, term, units) is stored and editable by a Super Admin.
 */
class ModuleAddonTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        $this->seed(Phase1FoundationSeeder::class);
    }

    /** @return array{0: Account, 1: User} a client admin whose account does NOT include contact groups */
    private function client(?Account $agent = null): array
    {
        $account = $agent
            ? Account::factory()->create(['agent_id' => $agent->id, 'allowed_modules' => ['dashboard']])
            : Account::factory()->create(['allowed_modules' => ['dashboard']]);
        Subscription::factory()->for($account)->create(['engine_type' => 'qr']);

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

    /** @return array{0: Account, 1: User} an agent account and its agent user */
    private function agent(): array
    {
        $agentAccount = Account::factory()->create(['account_type' => 'agent']);
        $agent = User::factory()->create(['account_id' => $agentAccount->id]);
        $agent->assignRole('agent');

        return [$agentAccount, $agent];
    }

    private function requestFor(User $user): int
    {
        return $this->actingAs($user)->postJson('/api/module-addons/request', ['module' => 'contact_groups', 'units' => 3])
            ->assertCreated()
            ->json('data.id');
    }

    private function approve(User $admin, int $requestId): void
    {
        $this->actingAs($admin)->postJson("/api/admin/module-addons/{$requestId}/approve")->assertOk();
    }

    private function paymentBody(array $overrides = []): array
    {
        return array_merge([
            'amount' => (float) ModuleAddonOffer::where('module', 'contact_groups')->value('price'),
            'method' => 'bank_transfer',
            'transaction_id' => 'TXN-'.uniqid(),
            'paid_on' => now()->toDateString(),
        ], $overrides);
    }

    public function test_a_client_can_request_the_module_and_a_second_open_request_is_refused(): void
    {
        [, $user] = $this->client();

        $this->actingAs($user)->postJson('/api/module-addons/request', ['module' => 'contact_groups', 'units' => 3, 'reason' => 'Vendor groups'])
            ->assertCreated()
            ->assertJsonPath('data.status', 'requested');

        $this->actingAs($user)->postJson('/api/module-addons/request', ['module' => 'contact_groups', 'units' => 3])
            ->assertStatus(409)
            ->assertJsonPath('error_code', 'request_pending');
    }

    public function test_an_unknown_module_is_refused(): void
    {
        [, $user] = $this->client();

        $this->actingAs($user)->postJson('/api/module-addons/request', ['module' => 'no_such_module'])
            ->assertUnprocessable();
    }

    /**
     * [Re-scoped 2026-10-07, disclosed]: 'contact_groups' now prices Native WhatsApp Group COUNT
     * only -- internal segment groups are free and always on (Account::effectiveModules() always
     * includes 'contact_groups'), so a native-group allowance is never "already included" by the
     * allowed_modules flag any more; it is always something bought in units (see
     * ModuleAddonService::alreadyIncluded()'s own re-scoping docblock). The scenario this test used
     * to cover ("a module the account already has cannot be requested") has no example left on this
     * one module-addon this whole file is built around -- repointed to its new, real behavior
     * instead of deleted.
     */
    public function test_contact_groups_can_always_be_requested_regardless_of_allowed_modules(): void
    {
        [$account, $user] = $this->client();
        $account->forceFill(['allowed_modules' => ['dashboard', 'contact_groups']])->save();

        $this->actingAs($user)->postJson('/api/module-addons/request', ['module' => 'contact_groups', 'units' => 3])
            ->assertCreated()
            ->assertJsonPath('data.status', 'requested');
    }

    public function test_only_a_super_admin_or_agent_can_see_pending_requests_and_approve(): void
    {
        [, $user] = $this->client();
        $requestId = $this->requestFor($user);

        $this->actingAs($user)->getJson('/api/admin/module-addons/pending')->assertForbidden();
        $this->actingAs($user)->postJson("/api/admin/module-addons/{$requestId}/approve")->assertForbidden();
        $this->assertSame(ModuleAddonRequest::REQUESTED, ModuleAddonRequest::find($requestId)->status);
    }

    public function test_approval_creates_one_invoice_with_the_gst_inclusive_total(): void
    {
        [, $user] = $this->client();
        $requestId = $this->requestFor($user);

        $this->approve($this->superAdmin(), $requestId);

        $request = ModuleAddonRequest::find($requestId);
        $this->assertSame(ModuleAddonRequest::INVOICED, $request->status);
        $invoice = Invoice::findOrFail($request->invoice_id);
        $this->assertSame((float) ModuleAddonOffer::where('module', 'contact_groups')->value('price'), (float) $invoice->total_amount);
        $this->assertSame(0.0, (float) $invoice->tax_amount);
        $this->assertCount(1, $invoice->lineItems);
        $this->actingAs($this->superAdmin())->postJson("/api/admin/module-addons/{$requestId}/approve")
            ->assertStatus(409)
            ->assertJsonPath('error_code', 'not_requested');
    }

    public function test_an_agent_approves_its_own_client_but_not_another_agents_or_a_direct_client(): void
    {
        [$agentAccount, $agent] = $this->agent();
        [, $ownClientUser] = $this->client($agentAccount);
        [, $otherAgentClientUser] = $this->client($this->agent()[0]);
        [, $directUser] = $this->client();

        $own = $this->requestFor($ownClientUser);
        $other = $this->requestFor($otherAgentClientUser);
        $direct = $this->requestFor($directUser);

        $visible = collect($this->actingAs($agent)->getJson('/api/admin/module-addons/pending')->assertOk()->json('data'))->pluck('id')->all();
        $this->assertSame([$own], $visible);

        $this->actingAs($agent)->postJson("/api/admin/module-addons/{$own}/approve")->assertOk();
        $this->actingAs($agent)->postJson("/api/admin/module-addons/{$other}/approve")->assertForbidden();
        $this->actingAs($agent)->postJson("/api/admin/module-addons/{$direct}/approve")->assertForbidden();
    }

    public function test_a_super_admin_can_approve_for_any_client_including_direct_ones(): void
    {
        [$agentAccount] = $this->agent();
        [, $agentClient] = $this->client($agentAccount);
        [, $direct] = $this->client();
        $a = $this->requestFor($agentClient);
        $b = $this->requestFor($direct);

        $this->approve($this->superAdmin(), $a);
        $this->approve($this->superAdmin(), $b);

        $this->assertSame(ModuleAddonRequest::INVOICED, ModuleAddonRequest::find($a)->status);
        $this->assertSame(ModuleAddonRequest::INVOICED, ModuleAddonRequest::find($b)->status);
    }

    public function test_a_rejected_request_creates_no_invoice(): void
    {
        [, $user] = $this->client();
        $requestId = $this->requestFor($user);

        $this->actingAs($this->superAdmin())->postJson("/api/admin/module-addons/{$requestId}/reject", ['note' => 'Not needed'])
            ->assertOk();

        $request = ModuleAddonRequest::find($requestId);
        $this->assertSame(ModuleAddonRequest::REJECTED, $request->status);
        $this->assertNull($request->invoice_id);
    }

    public function test_a_manual_payment_switches_the_module_on_for_one_term(): void
    {
        [$account, $user] = $this->client();
        $requestId = $this->requestFor($user);
        $this->approve($this->superAdmin(), $requestId);

        $this->actingAs($this->superAdmin())
            ->postJson("/api/admin/module-addons/{$requestId}/record-payment", $this->paymentBody())
            ->assertOk();

        $request = ModuleAddonRequest::find($requestId);
        $this->assertSame(ModuleAddonRequest::PAID, $request->status);
        $this->assertSame(now()->addMonth()->toDateString(), $request->term_ends_at->toDateString());
        $this->assertContains('contact_groups', $account->fresh()->allowed_modules);
    }

    public function test_a_wrong_amount_or_a_repeated_transaction_is_refused(): void
    {
        [, $user] = $this->client();
        $first = $this->requestFor($user);
        $this->approve($this->superAdmin(), $first);

        $this->actingAs($this->superAdmin())
            ->postJson("/api/admin/module-addons/{$first}/record-payment", $this->paymentBody(['amount' => 1]))
            ->assertUnprocessable()
            ->assertJsonPath('error_code', 'amount_mismatch');
    }

    public function test_an_online_confirmation_switches_the_module_on(): void
    {
        [$account, $user] = $this->client();
        $requestId = $this->requestFor($user);
        $this->approve($this->superAdmin(), $requestId);
        $invoiceId = ModuleAddonRequest::find($requestId)->invoice_id;

        $this->assertTrue(app(InvoiceCreditService::class)->markPaidAndCreditQuota($invoiceId, 'pay_MODULE_1'));

        $this->assertSame(ModuleAddonRequest::PAID, ModuleAddonRequest::find($requestId)->status);
        $this->assertContains('contact_groups', $account->fresh()->allowed_modules);
        $this->assertFalse(app(InvoiceCreditService::class)->markPaidAndCreditQuota($invoiceId, 'pay_MODULE_1'), 'a repeated confirmation changes nothing');
    }

    public function test_an_ended_term_switches_the_module_off_and_a_running_one_keeps_it(): void
    {
        [$account, $user] = $this->client();
        $account->forceFill(['allowed_modules' => ['dashboard', 'contact_groups']])->save();

        $ended = ModuleAddonRequest::create([
            'account_id' => $account->id, 'module' => 'contact_groups', 'status' => ModuleAddonRequest::PAID,
            'requested_by_user_id' => $user->id, 'term_starts_at' => now()->subMonths(2), 'term_ends_at' => now()->subDay(),
        ]);

        $this->artisan('modules:enforce-addons')->assertSuccessful();

        $this->assertSame(ModuleAddonRequest::EXPIRED, $ended->fresh()->status);
        $this->assertNotContains('contact_groups', $account->fresh()->allowed_modules);
    }

    public function test_the_module_stays_on_while_another_paid_term_still_runs(): void
    {
        [$account, $user] = $this->client();
        $account->forceFill(['allowed_modules' => ['dashboard', 'contact_groups']])->save();

        ModuleAddonRequest::create([
            'account_id' => $account->id, 'module' => 'contact_groups', 'status' => ModuleAddonRequest::PAID,
            'requested_by_user_id' => $user->id, 'term_starts_at' => now()->subMonths(2), 'term_ends_at' => now()->subDay(),
        ]);
        ModuleAddonRequest::create([
            'account_id' => $account->id, 'module' => 'contact_groups', 'status' => ModuleAddonRequest::PAID,
            'requested_by_user_id' => $user->id, 'term_starts_at' => now(), 'term_ends_at' => now()->addWeek(),
        ]);

        $this->artisan('modules:enforce-addons')->assertSuccessful();

        $this->assertContains('contact_groups', $account->fresh()->allowed_modules);
    }

    public function test_an_agent_can_see_the_offers_but_cannot_change_them(): void
    {
        [, $agent] = $this->agent();

        $this->actingAs($agent)->getJson('/api/admin/module-offers')->assertOk()->assertJsonPath('data.0.module', 'contact_groups');
        $this->actingAs($agent)->putJson('/api/admin/module-offers/contact_groups', [
            'label' => 'X', 'price' => 1, 'term_months' => 1, 'units_included' => 0, 'is_active' => true,
        ])->assertForbidden();
        $this->assertSame(99.0, (float) \App\Models\ModuleAddonOffer::where('module', 'contact_groups')->value('price'));
    }

    public function test_the_client_sees_the_live_offer_and_whether_online_payment_is_ready(): void
    {
        [, $user] = $this->client();
        $this->actingAs($this->superAdmin())->putJson('/api/admin/module-offers/contact_groups', [
            'label' => 'Custom Contact Groups', 'price' => 120, 'term_months' => 2, 'units_included' => 5, 'is_active' => true,
        ])->assertOk();

        $this->actingAs($user)->getJson('/api/module-addons')
            ->assertOk()
            ->assertJsonPath('offers.0.price', 120)
            ->assertJsonPath('offers.0.term_months', 2)
            ->assertJsonPath('offers.0.units_included', 5)
            ->assertJsonPath('gateway_ready', false);
    }

    public function test_an_offer_switched_off_cannot_be_requested(): void
    {
        [, $user] = $this->client();
        $this->actingAs($this->superAdmin())->putJson('/api/admin/module-offers/contact_groups', [
            'label' => 'Custom Contact Groups', 'price' => 99, 'term_months' => 1, 'units_included' => 5, 'is_active' => false,
        ])->assertOk();

        $this->actingAs($user)->postJson('/api/module-addons/request', ['module' => 'contact_groups', 'units' => 3])
            ->assertUnprocessable();
    }

    public function test_online_payment_of_a_module_invoice_is_refused_while_no_gateway_is_configured(): void
    {
        [, $user] = $this->client();
        $requestId = $this->requestFor($user);
        $this->approve($this->superAdmin(), $requestId);
        $invoiceId = ModuleAddonRequest::find($requestId)->invoice_id;

        $this->actingAs($user)->postJson("/api/module-addons/invoices/{$invoiceId}/pay", ['gateway' => 'razorpay'])
            ->assertUnprocessable()
            ->assertJsonPath('error_code', 'gateway_not_configured');
        $this->assertSame('pending', Invoice::find($invoiceId)->status);
    }

    public function test_the_client_is_notified_when_approved_paid_and_when_the_term_ends(): void
    {
        [, $user] = $this->client();
        $requestId = $this->requestFor($user);

        $this->approve($this->superAdmin(), $requestId);
        $this->assertDatabaseHas('in_app_notifications', ['user_id' => $user->id, 'category' => 'billing']);
        $this->assertSame(1, \App\Models\InAppNotification::where('user_id', $user->id)->count());

        $this->actingAs($this->superAdmin())
            ->postJson("/api/admin/module-addons/{$requestId}/record-payment", $this->paymentBody())
            ->assertOk();
        $this->assertSame(2, \App\Models\InAppNotification::where('user_id', $user->id)->count());

        ModuleAddonRequest::find($requestId)->forceFill(['term_ends_at' => now()->subDay()])->save();
        $this->artisan('modules:enforce-addons')->assertSuccessful();
        // Term ended: the expiry notice and the renewal invoice notice.
        $this->assertSame(4, \App\Models\InAppNotification::where('user_id', $user->id)->count());
    }

    public function test_the_client_is_notified_when_a_request_is_rejected(): void
    {
        [, $user] = $this->client();
        $requestId = $this->requestFor($user);

        $this->actingAs($this->superAdmin())->postJson("/api/admin/module-addons/{$requestId}/reject", ['note' => 'Not needed'])->assertOk();

        $this->assertDatabaseHas('in_app_notifications', ['user_id' => $user->id, 'title' => 'Request not approved']);
    }

    public function test_a_client_sees_its_own_requests_and_their_status(): void
    {
        [, $user] = $this->client();
        $this->requestFor($user);

        $this->actingAs($user)->getJson('/api/module-addons')
            ->assertOk()
            ->assertJsonPath('data.0.status', 'requested')
            ->assertJsonPath('offers.0.module', 'contact_groups');
    }

    // --- Super Admin "Free (no charge)" switch (2026-10-07): off by default, so every test above
    // this line exercises the paid path exactly as before. These cover the switch itself. ---

    public function test_is_free_defaults_to_false_so_existing_paid_behaviour_is_unchanged(): void
    {
        $this->assertFalse(app(\App\Services\Billing\ModuleAddonService::class)->isFree('contact_groups'));
    }

    public function test_a_super_admin_can_switch_a_module_free_and_it_is_reflected_immediately(): void
    {
        $admin = $this->superAdmin();
        $offer = ModuleAddonOffer::where('module', 'contact_groups')->first();

        $this->actingAs($admin)->putJson('/api/admin/module-offers/contact_groups', [
            'label' => $offer->label,
            'price' => (float) $offer->price,
            'term_months' => $offer->term_months,
            'units_included' => $offer->units_included,
            'is_active' => $offer->is_active,
            'is_free' => true,
        ])->assertOk()->assertJsonPath('data.is_free', true);

        $this->assertTrue(app(\App\Services\Billing\ModuleAddonService::class)->isFree('contact_groups'));
    }

    public function test_requesting_a_module_that_is_switched_free_is_refused_since_there_is_nothing_to_buy(): void
    {
        [, $user] = $this->client();
        ModuleAddonOffer::where('module', 'contact_groups')->update(['is_free' => true]);

        $this->actingAs($user)->postJson('/api/module-addons/request', ['module' => 'contact_groups', 'units' => 3])
            ->assertUnprocessable()
            ->assertJsonPath('error_code', 'module_is_free');
    }

    public function test_a_free_module_has_no_unit_limit_even_with_no_purchase(): void
    {
        [$account] = $this->client();
        ModuleAddonOffer::where('module', 'contact_groups')->update(['is_free' => true]);

        $this->assertNull(app(\App\Services\Billing\ModuleAddonService::class)->activeUnitLimit($account, 'contact_groups'));
    }

    public function test_switching_a_module_back_to_paid_resumes_the_stored_price_and_tiers(): void
    {
        $admin = $this->superAdmin();
        ModuleAddonOffer::where('module', 'contact_groups')->update(['is_free' => true]);
        $offer = ModuleAddonOffer::where('module', 'contact_groups')->first();

        $this->actingAs($admin)->putJson('/api/admin/module-offers/contact_groups', [
            'label' => $offer->label,
            'price' => (float) $offer->price,
            'term_months' => $offer->term_months,
            'units_included' => $offer->units_included,
            'is_active' => $offer->is_active,
            'is_free' => false,
        ])->assertOk()->assertJsonPath('data.is_free', false)->assertJsonPath('data.price', (float) $offer->price);

        $this->assertFalse(app(\App\Services\Billing\ModuleAddonService::class)->isFree('contact_groups'));
        [, $user] = $this->client();
        $this->requestFor($user); // paid path works again, exactly as before the switch.
    }
}
