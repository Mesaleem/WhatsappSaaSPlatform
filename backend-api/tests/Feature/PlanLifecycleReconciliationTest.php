<?php

namespace Tests\Feature;

use App\Jobs\ReconcilePlanAccountsJob;
use App\Models\Account;
use App\Models\AccountEntitlement;
use App\Models\Capability;
use App\Models\Invoice;
use App\Models\Plan;
use App\Models\User;
use App\Services\Access\PlanEntitlementReconciliationService;
use App\Services\Billing\InvoiceCreditService;
use App\Support\PlanCatalog;
use Database\Seeders\Phase1FoundationSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Phase 5 Task 10 — plan upgrade/downgrade + entitlement reconciliation.
 *
 * The rules under test, all of them in one service
 * (PlanEntitlementReconciliationService):
 *
 *   source='plan'        reconciled in both directions
 *   source='manual_grant'    never touched
 *   source='agent_delegated' never touched, never converted
 *   revoked_reason='manual'  never restored
 *   revoked_reason='plan_downgrade'  restored when the plan returns it
 *   provider compatibility   an independent gate a plan cannot bypass
 *
 * Expected capability sets are DERIVED from the database (the plan's own
 * bundle intersected with provider support), never hardcoded from the
 * task description — so these tests stay correct if the confirmed matrix
 * or a provider rule changes, and fail loudly if reconciliation stops
 * honouring either.
 */
class PlanLifecycleReconciliationTest extends TestCase
{
    use RefreshDatabase;

    private const PLANS = '/api/admin/plans-management';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        $this->seed(Phase1FoundationSeeder::class);
    }

    // =================================================================
    // Fixtures
    // =================================================================

    private function reconciler(): PlanEntitlementReconciliationService
    {
        return app(PlanEntitlementReconciliationService::class);
    }

    /** Buys $planKey for real, through the payment path. */
    private function buy(Account $account, string $planKey): void
    {
        $plan = PlanCatalog::find($planKey);

        $invoice = Invoice::create([
            'account_id' => $account->id,
            'invoice_number' => 'INV-'.uniqid(),
            'plan_key' => $planKey,
            'plan_label' => $plan['label'],
            'amount' => $plan['price'],
            'tax_amount' => 0,
            'total_amount' => $plan['price'],
            'currency' => 'INR',
            'payment_gateway' => 'razorpay',
            'gateway_order_id' => 'order_'.uniqid(),
            'gateway_payment_id' => null,
            'status' => 'pending',
            'paid_at' => null,
            'gateway_raw_response' => null,
        ]);

        app(InvoiceCreditService::class)->markPaidAndCreditQuota($invoice->id, 'pay_'.uniqid());
    }

    /** @return array{account: Account, user: User} */
    private function tenant(string $planKey = 'business'): array
    {
        $account = Account::factory()->create();
        $this->buy($account, $planKey);

        $user = User::factory()->create(['account_id' => $account->id, 'is_active' => true]);
        $user->assignRole('admin');

        return ['account' => $account->fresh(), 'user' => $user];
    }

    private function superAdmin(): User
    {
        $user = User::factory()->create(['account_id' => null, 'is_active' => true]);
        $user->assignRole('super_admin');

        return $user;
    }

    private function capabilityId(string $slug): int
    {
        return Capability::where('slug', $slug)->firstOrFail()->id;
    }

    /** @return array<int, string> */
    private function heldBy(Account $account): array
    {
        return AccountEntitlement::where('account_id', $account->id)
            ->active()
            ->with('capability')
            ->get()
            ->pluck('capability.slug')
            ->sort()
            ->values()
            ->all();
    }

    /**
     * What a plan SHOULD yield for a given provider — derived, not
     * hardcoded: the plan's own bundle minus anything the provider
     * cannot support.
     *
     * @return array<int, string>
     */
    private function expectedFor(string $planSlug, string $provider): array
    {
        $providerCapabilities = app(\App\Services\Access\ProviderCapabilityService::class);

        return Plan::where('slug', $planSlug)->firstOrFail()
            ->capabilities
            ->pluck('slug')
            ->filter(fn (string $slug) => $providerCapabilities->supports($provider, $slug))
            ->sort()
            ->values()
            ->all();
    }

    private function row(Account $account, string $slug): ?AccountEntitlement
    {
        return AccountEntitlement::where('account_id', $account->id)
            ->where('capability_id', $this->capabilityId($slug))
            ->first();
    }

    // =================================================================
    // 1. Initial grant
    // =================================================================

    public function test_a_new_purchase_yields_exactly_the_plan_bundle_the_provider_supports(): void
    {
        ['account' => $account] = $this->tenant('business');

        $this->assertSame($this->expectedFor('business', 'meta'), $this->heldBy($account));
    }

    public function test_reconciliation_is_idempotent(): void
    {
        ['account' => $account] = $this->tenant('business');

        $before = $this->heldBy($account);
        $rows = AccountEntitlement::where('account_id', $account->id)->count();

        $this->reconciler()->reconcile($account);
        $this->reconciler()->reconcile($account);

        $this->assertSame($before, $this->heldBy($account));
        $this->assertSame($rows, AccountEntitlement::where('account_id', $account->id)->count());
    }

    // =================================================================
    // 2. Downgrade
    // =================================================================

    public function test_a_downgrade_revokes_plan_capabilities_the_new_plan_drops(): void
    {
        ['account' => $account] = $this->tenant('business');

        $dropped = array_values(array_diff(
            $this->expectedFor('business', 'meta'),
            Plan::where('slug', 'growth')->firstOrFail()->capabilities->pluck('slug')->all(),
        ));

        $this->assertNotEmpty($dropped, 'Fixture assumption: Business must bundle something Growth does not.');

        $this->buy($account, 'growth');
        $account->refresh();

        foreach ($dropped as $slug) {
            $row = $this->row($account, $slug);

            $this->assertNotNull($row, "'{$slug}' must be kept as a record, not hard-deleted.");
            $this->assertNotNull($row->revoked_at, "'{$slug}' should have been revoked by the downgrade.");
            $this->assertSame(AccountEntitlement::REVOKED_PLAN_DOWNGRADE, $row->revoked_reason);
            $this->assertSame(AccountEntitlement::SOURCE_PLAN, $row->source);
        }

        // Growth is a QR plan, so the survivors are Growth's bundle
        // filtered by what QR supports — derived, not assumed.
        $this->assertSame($this->expectedFor('growth', 'qr'), $this->heldBy($account));
    }

    public function test_a_downgrade_never_hard_deletes(): void
    {
        ['account' => $account] = $this->tenant('business');
        $before = AccountEntitlement::where('account_id', $account->id)->count();

        $this->buy($account, 'growth');

        $this->assertGreaterThanOrEqual(
            $before,
            AccountEntitlement::where('account_id', $account->id)->count(),
            'Rows must be marked, never removed.'
        );
    }

    // =================================================================
    // 3. Upgrade, and the round trip
    // =================================================================

    public function test_an_upgrade_grants_the_new_plans_capabilities(): void
    {
        ['account' => $account] = $this->tenant('starter');

        $this->buy($account, 'business');
        $account->refresh();

        $this->assertSame($this->expectedFor('business', 'meta'), $this->heldBy($account));
    }

    public function test_a_downgrade_then_upgrade_restores_plan_revoked_capabilities(): void
    {
        /*
         * The round trip the revoked_reason column exists for. Without
         * it, these capabilities would either stay revoked forever or be
         * restored by a rule that would equally have undone an
         * administrator's revocation.
         */
        ['account' => $account] = $this->tenant('business');
        $original = $this->heldBy($account);

        $this->buy($account, 'growth');
        $account->refresh();
        $this->assertNotSame($original, $this->heldBy($account));

        $this->buy($account, 'business');
        $account->refresh();

        $this->assertSame($original, $this->heldBy($account), 'A re-upgrade must restore what the downgrade took.');

        // And the restored rows carry no stale revocation metadata.
        foreach ($original as $slug) {
            $row = $this->row($account, $slug);
            $this->assertNull($row->revoked_at, $slug);
            $this->assertNull($row->revoked_reason, $slug);
        }
    }

    public function test_the_full_lifecycle_settles_deterministically(): void
    {
        // business -> growth -> business -> growth, asserting after each.
        ['account' => $account] = $this->tenant('business');
        $this->assertSame($this->expectedFor('business', 'meta'), $this->heldBy($account));

        foreach (['growth' => 'qr', 'business' => 'meta', 'growth' => 'qr'] as $plan => $provider) {
            $this->buy($account, $plan);
            $account->refresh();
            $this->assertSame($this->expectedFor($plan, $provider), $this->heldBy($account), $plan);
        }
    }

    // =================================================================
    // 4. Source protection
    // =================================================================

    public function test_a_manual_grant_survives_a_downgrade_with_its_source_intact(): void
    {
        ['account' => $account] = $this->tenant('business');

        // 'ads' is in Business, not in Growth. Make it a MANUAL grant.
        $this->row($account, 'ads')?->forceFill(['source' => AccountEntitlement::SOURCE_MANUAL_GRANT])->save();

        $this->buy($account, 'growth');
        $account->refresh();

        $row = $this->row($account, 'ads');

        $this->assertNull($row->revoked_at, 'A manual grant must survive a plan downgrade.');
        $this->assertSame(AccountEntitlement::SOURCE_MANUAL_GRANT, $row->source);
        $this->assertContains('ads', $this->heldBy($account));
    }

    public function test_an_agent_delegated_grant_survives_a_downgrade_untouched(): void
    {
        ['account' => $account] = $this->tenant('business');
        $agent = Account::factory()->create(['account_type' => 'agent']);

        $this->row($account, 'commerce')?->forceFill([
            'source' => AccountEntitlement::SOURCE_AGENT_DELEGATED,
            'granted_by_account_id' => $agent->id,
        ])->save();

        $this->buy($account, 'growth');
        $account->refresh();

        $row = $this->row($account, 'commerce');

        $this->assertNull($row->revoked_at);
        $this->assertSame(AccountEntitlement::SOURCE_AGENT_DELEGATED, $row->source);
        $this->assertSame($agent->id, $row->granted_by_account_id, 'Delegation metadata must be preserved.');
    }

    public function test_reconciliation_never_rewrites_a_manual_source_to_plan(): void
    {
        ['account' => $account] = $this->tenant('business');

        // A capability the plan DOES bundle, held manually.
        $this->row($account, 'crm')?->forceFill(['source' => AccountEntitlement::SOURCE_MANUAL_GRANT])->save();

        $this->reconciler()->reconcile($account);

        $this->assertSame(AccountEntitlement::SOURCE_MANUAL_GRANT, $this->row($account, 'crm')->source);
    }

    // =================================================================
    // 5. Revocation protection
    // =================================================================

    public function test_a_manually_revoked_capability_is_never_restored_by_reconciliation(): void
    {
        ['account' => $account] = $this->tenant('business');

        $this->actingAs($this->superAdmin())
            ->deleteJson("/api/admin/accounts/{$account->id}/entitlements/custom_code")
            ->assertStatus(200);

        $this->assertSame(AccountEntitlement::REVOKED_MANUAL, $this->row($account, 'custom_code')->revoked_reason);

        // The plan still includes it; reconciling repeatedly must not
        // bring it back.
        $this->reconciler()->reconcile($account->fresh());
        $this->reconciler()->reconcile($account->fresh());

        $this->assertNotContains('custom_code', $this->heldBy($account));
        $this->assertNotNull($this->row($account, 'custom_code')->revoked_at);
    }

    public function test_a_manual_revocation_survives_a_downgrade_and_re_upgrade(): void
    {
        ['account' => $account] = $this->tenant('business');

        $this->actingAs($this->superAdmin())
            ->deleteJson("/api/admin/accounts/{$account->id}/entitlements/custom_code");

        $this->buy($account, 'growth');
        $this->buy($account, 'business');
        $account->refresh();

        $this->assertNotContains('custom_code', $this->heldBy($account), 'A re-upgrade must not undo an administrator.');
        $this->assertSame(AccountEntitlement::REVOKED_MANUAL, $this->row($account, 'custom_code')->revoked_reason);
    }

    public function test_an_explicit_re_grant_does_clear_a_manual_revocation(): void
    {
        ['account' => $account, 'user' => $user] = $this->tenant('business');
        $superAdmin = $this->superAdmin();

        $this->actingAs($superAdmin)->deleteJson("/api/admin/accounts/{$account->id}/entitlements/custom_code");
        $this->actingAs($superAdmin)->postJson("/api/admin/accounts/{$account->id}/entitlements", ['capability' => 'custom_code'])
            ->assertStatus(200);

        $this->assertContains('custom_code', $this->heldBy($account));
        $this->assertNull($this->row($account, 'custom_code')->revoked_reason);
        $this->actingAs($user)->getJson('/api/auth/me')->assertJsonPath('user.capabilities.custom_code', true);
    }

    // =================================================================
    // 6. Provider compatibility stays independent
    // =================================================================

    public function test_a_qr_account_does_not_receive_a_bundled_capability_its_provider_cannot_support(): void
    {
        // Phase 7 Task 1.5: journey_automation became QR-supported (owner
        // decision) and is now received; the provider dimension is still
        // independent, shown with ads (a QR refusal) bundled in-test.
        Plan::where('slug', 'growth')->firstOrFail()->capabilities()->syncWithoutDetaching([
            $this->capabilityId('ads') => ['usage_limit' => null],
        ]);
        ['account' => $account] = $this->tenant('growth');

        $this->assertContains('ads', Plan::where('slug', 'growth')->firstOrFail()->capabilities->pluck('slug')->all());
        $this->assertNotContains('ads', $this->heldBy($account));
        $this->assertContains('journey_automation', $this->heldBy($account));
    }

    public function test_a_qr_account_does_receive_crm_after_the_task_9_follow_up(): void
    {
        ['account' => $account] = $this->tenant('growth');

        $this->assertContains('crm', $this->heldBy($account));
    }

    public function test_qr_messaging_is_unaffected_by_any_reconciliation(): void
    {
        ['account' => $account] = $this->tenant('starter');

        $this->reconciler()->reconcile($account);

        $this->assertContains('whatsapp_send', $this->heldBy($account));
        $this->assertContains('whatsapp_groups', $this->heldBy($account));
    }

    public function test_meta_only_capabilities_are_unaffected_for_meta_accounts(): void
    {
        ['account' => $account] = $this->tenant('business');

        $this->assertContains('commerce', $this->heldBy($account));
        // And whatsapp_groups is still refused on Meta — technical.
        $this->assertNotContains('whatsapp_groups', $this->heldBy($account));
    }

    // =================================================================
    // 7. Plan creation
    // =================================================================

    public function test_a_super_admin_can_create_a_plan_with_a_capability_bundle(): void
    {
        $this->actingAs($this->superAdmin())->postJson(self::PLANS, [
            'slug' => 'enterprise',
            'label' => 'Enterprise',
            'price' => 19999,
            'duration_days' => 30,
            // Phase 5 Task 11 — a plan is not creatable without the
            // dimensions that make it purchasable.
            'engine_type' => 'meta',
            'billing_model' => 'flat_quota',
            'total_allocated_messages' => 50000,
            'capabilities' => ['whatsapp_send', 'crm', 'ai'],
        ])->assertStatus(201);

        $plan = Plan::where('slug', 'enterprise')->firstOrFail();

        $this->assertSame(['ai', 'crm', 'whatsapp_send'], $plan->capabilities->pluck('slug')->sort()->values()->all());
        $this->assertSame(19999.00, (float) $plan->price);
    }

    public function test_creating_a_duplicate_plan_slug_is_rejected(): void
    {
        $this->actingAs($this->superAdmin())->postJson(self::PLANS, [
            'slug' => 'business',
            'label' => 'Another Business',
            'price' => 1,
            'duration_days' => 30,
            'engine_type' => 'qr',
            'billing_model' => 'flat_quota',
        ])->assertStatus(422);
    }

    public function test_a_duplicated_capability_in_the_payload_is_rejected(): void
    {
        $this->actingAs($this->superAdmin())->postJson(self::PLANS, [
            'slug' => 'dupes',
            'label' => 'Dupes',
            'price' => 1,
            'duration_days' => 30,
            'engine_type' => 'qr',
            'billing_model' => 'flat_quota',
            'capabilities' => ['crm', 'crm'],
        ])->assertStatus(422);
    }

    public function test_an_unknown_capability_is_rejected(): void
    {
        $this->actingAs($this->superAdmin())->postJson(self::PLANS, [
            'slug' => 'bogus',
            'label' => 'Bogus',
            'price' => 1,
            'duration_days' => 30,
            'engine_type' => 'qr',
            'billing_model' => 'flat_quota',
            'capabilities' => ['teleportation'],
        ])->assertStatus(422);

        $this->assertDatabaseMissing('plans', ['slug' => 'bogus']);
    }

    // =================================================================
    // 8. Plan modification
    // =================================================================

    public function test_removing_a_capability_from_a_plan_reconciles_its_accounts(): void
    {
        ['account' => $account] = $this->tenant('business');
        $this->assertContains('ads', $this->heldBy($account));

        $keep = array_values(array_diff(
            Plan::where('slug', 'business')->firstOrFail()->capabilities->pluck('slug')->all(),
            ['ads'],
        ));

        $this->actingAs($this->superAdmin())
            ->putJson(self::PLANS.'/business', ['capabilities' => $keep])
            ->assertStatus(200)
            ->assertJsonPath('data.bundle_changed', true)
            ->assertJsonPath('data.removed', ['ads']);

        // The job runs synchronously under the test queue driver.
        $this->assertNotContains('ads', $this->heldBy($account->fresh()));
        $this->assertSame(AccountEntitlement::REVOKED_PLAN_DOWNGRADE, $this->row($account, 'ads')->revoked_reason);
    }

    public function test_adding_a_capability_to_a_plan_reaches_its_accounts(): void
    {
        ['account' => $account] = $this->tenant('starter');
        $this->assertNotContains('ai', $this->heldBy($account));

        $bundle = Plan::where('slug', 'starter')->firstOrFail()->capabilities->pluck('slug')->all();

        $this->actingAs($this->superAdmin())
            ->putJson(self::PLANS.'/starter', ['capabilities' => [...$bundle, 'ai']])
            ->assertStatus(200)
            ->assertJsonPath('data.added', ['ai']);

        $this->assertContains('ai', $this->heldBy($account->fresh()));
    }

    public function test_a_pricing_only_change_does_not_touch_the_bundle(): void
    {
        $before = Plan::where('slug', 'business')->firstOrFail()->capabilities->pluck('slug')->sort()->values()->all();

        Queue::fake();

        $this->actingAs($this->superAdmin())
            ->putJson(self::PLANS.'/business', ['price' => 8999])
            ->assertStatus(200)
            ->assertJsonPath('data.bundle_changed', false);

        $plan = Plan::where('slug', 'business')->firstOrFail();

        $this->assertSame(8999.00, (float) $plan->price);
        $this->assertSame($before, $plan->capabilities->pluck('slug')->sort()->values()->all());
        // No bundle change means no fleet reconciliation.
        Queue::assertNotPushed(ReconcilePlanAccountsJob::class);
    }

    public function test_a_capability_only_change_does_not_touch_pricing(): void
    {
        $plan = Plan::where('slug', 'starter')->firstOrFail();
        $price = (float) $plan->price;
        $duration = $plan->duration_days;

        $this->actingAs($this->superAdmin())
            ->putJson(self::PLANS.'/starter', ['capabilities' => ['whatsapp_send']])
            ->assertStatus(200);

        $plan->refresh();

        $this->assertSame($price, (float) $plan->price);
        $this->assertSame($duration, $plan->duration_days);
    }

    public function test_a_plan_edit_leaves_manual_and_agent_grants_alone(): void
    {
        ['account' => $account] = $this->tenant('business');
        $agent = Account::factory()->create(['account_type' => 'agent']);

        $this->row($account, 'ads')?->forceFill(['source' => AccountEntitlement::SOURCE_MANUAL_GRANT])->save();
        $this->row($account, 'commerce')?->forceFill([
            'source' => AccountEntitlement::SOURCE_AGENT_DELEGATED,
            'granted_by_account_id' => $agent->id,
        ])->save();

        // Strip the plan down to messaging only.
        $this->actingAs($this->superAdmin())
            ->putJson(self::PLANS.'/business', ['capabilities' => ['whatsapp_send']])
            ->assertStatus(200);

        $held = $this->heldBy($account->fresh());

        $this->assertContains('ads', $held, 'A manual grant must survive a plan bundle change.');
        $this->assertContains('commerce', $held, 'An agent delegation must survive a plan bundle change.');
        $this->assertSame($agent->id, $this->row($account, 'commerce')->granted_by_account_id);
    }

    // =================================================================
    // 9. API authorization
    // =================================================================

    public function test_a_tenant_admin_cannot_read_or_modify_plans(): void
    {
        ['user' => $tenantAdmin] = $this->tenant('business');

        $this->actingAs($tenantAdmin)->getJson(self::PLANS)->assertStatus(403);
        $this->actingAs($tenantAdmin)->postJson(self::PLANS, [
            'slug' => 'sneaky', 'label' => 'Sneaky', 'price' => 0, 'duration_days' => 30,
            'engine_type' => 'qr', 'billing_model' => 'flat_quota',
            'capabilities' => ['custom_code'],
        ])->assertStatus(403);
        $this->actingAs($tenantAdmin)->putJson(self::PLANS.'/starter', ['capabilities' => ['custom_code']])
            ->assertStatus(403);

        $this->assertDatabaseMissing('plans', ['slug' => 'sneaky']);
    }

    public function test_an_agent_cannot_modify_a_global_plan(): void
    {
        $agentAccount = Account::factory()->create(['account_type' => 'agent']);
        $agentUser = User::factory()->create(['account_id' => $agentAccount->id, 'is_active' => true]);
        $agentUser->assignRole('admin');
        $agentUser->givePermissionTo('manage-accounts');

        $this->actingAs($agentUser)->putJson(self::PLANS.'/starter', ['capabilities' => ['custom_code']])
            ->assertStatus(403);

        // An Agent may resell to its own sub-clients — that is a
        // different dimension and is untouched here.
        $this->assertSame(
            ['external_api', 'social', 'whatsapp_groups', 'whatsapp_send'],
            Plan::where('slug', 'starter')->firstOrFail()->capabilities->pluck('slug')->sort()->values()->all(),
        );
    }

    public function test_an_unauthenticated_caller_cannot_reach_plan_management(): void
    {
        $this->getJson(self::PLANS)->assertStatus(401);
        $this->postJson(self::PLANS, ['slug' => 'x', 'label' => 'x', 'price' => 0, 'duration_days' => 30])
            ->assertStatus(401);
    }

    public function test_a_tenant_cannot_self_grant_by_spoofing_a_plan_key(): void
    {
        ['account' => $account, 'user' => $user] = $this->tenant('starter');

        // Neither a body field nor a query parameter changes which plan
        // the server resolves — it reads the account's own paid invoice.
        $this->actingAs($user)->getJson('/api/auth/me?plan=business&plan_key=business&capability=custom_code')
            ->assertJsonPath('user.capabilities.custom_code', false);

        $this->assertSame($this->expectedFor('starter', 'qr'), $this->heldBy($account));
    }

    public function test_reconciliation_reads_the_plan_from_the_accounts_own_invoice_only(): void
    {
        ['account' => $mine] = $this->tenant('starter');
        ['account' => $theirs] = $this->tenant('business');

        // Reconciling one account can never pull the other's plan in.
        $this->reconciler()->reconcile($mine);

        $this->assertSame($this->expectedFor('starter', 'qr'), $this->heldBy($mine));
        $this->assertSame($this->expectedFor('business', 'meta'), $this->heldBy($theirs));
    }

    // =================================================================
    // 10. Command
    // =================================================================

    public function test_the_reconcile_command_fixes_a_stale_account(): void
    {
        ['account' => $account] = $this->tenant('business');

        // Simulate drift: a capability the plan bundles goes missing.
        $this->row($account, 'ads')?->delete();
        $this->assertNotContains('ads', $this->heldBy($account));

        $this->artisan('entitlements:reconcile-plan')->assertExitCode(0);

        $this->assertContains('ads', $this->heldBy($account->fresh()));
    }

    public function test_the_reconcile_command_dry_run_writes_nothing(): void
    {
        ['account' => $account] = $this->tenant('business');
        $this->row($account, 'ads')?->delete();

        $this->artisan('entitlements:reconcile-plan', ['--dry-run' => true])->assertExitCode(0);

        $this->assertNotContains('ads', $this->heldBy($account->fresh()));
    }

    public function test_the_reconcile_command_can_be_scoped_to_one_account(): void
    {
        ['account' => $mine] = $this->tenant('business');
        ['account' => $theirs] = $this->tenant('business');

        $this->row($mine, 'ads')?->delete();
        $this->row($theirs, 'ads')?->delete();

        $this->artisan('entitlements:reconcile-plan', ['--account' => $mine->id])->assertExitCode(0);

        $this->assertContains('ads', $this->heldBy($mine->fresh()));
        $this->assertNotContains('ads', $this->heldBy($theirs->fresh()), 'Another tenant was touched.');
    }

    public function test_the_reconcile_command_skips_accounts_without_a_paid_invoice(): void
    {
        $account = Account::factory()->create();

        $this->artisan('entitlements:reconcile-plan')->assertExitCode(0);

        $this->assertSame([], $this->heldBy($account));
    }

    public function test_the_reconcile_command_skips_a_suspended_account(): void
    {
        ['account' => $account] = $this->tenant('business');
        $this->row($account, 'ads')?->delete();
        $account->forceFill(['status' => 'suspended'])->save();

        $this->artisan('entitlements:reconcile-plan')->assertExitCode(0);

        $this->assertNotContains('ads', $this->heldBy($account->fresh()));
    }

    public function test_the_command_output_exposes_no_tenant_content(): void
    {
        ['account' => $account] = $this->tenant('business');
        $account->forceFill(['company_name' => 'Highly Confidential Ltd'])->save();

        $this->artisan('entitlements:reconcile-plan')
            ->doesntExpectOutputToContain('Highly Confidential Ltd')
            ->assertExitCode(0);
    }

    // =================================================================
    // 11. Audit logging
    // =================================================================

    /*
     * WHY THESE ASSERT ON EVENTS AND NOT ON activity_logs ROWS.
     *
     * LogsActivity::recordActivity() returns early when
     * app()->runningInConsole() — a deliberate pre-existing guard — and
     * PHPUnit always runs in console, so NO activity_logs row is ever
     * written during the test suite, whatever the code does. Asserting
     * on the table would therefore be a test that can only ever fail,
     * or (worse) one that passes for the wrong reason.
     *
     * What is genuinely testable, and what actually regressed in Task 9,
     * is whether these writes go through Eloquent at all: LogsActivity
     * hooks the model's updated event, so a mass query-builder update
     * silently skips auditing in production too. These tests assert the
     * event fires, which is the precondition for the audit row in a real
     * HTTP request.
     */
    private function countEloquentEvents(string $event, callable $action): int
    {
        $fired = 0;
        \Illuminate\Support\Facades\Event::listen($event, function () use (&$fired) {
            $fired++;
        });

        $action();

        return $fired;
    }

    public function test_a_plan_downgrade_revocation_goes_through_eloquent_so_it_is_auditable(): void
    {
        ['account' => $account] = $this->tenant('business');

        $fired = $this->countEloquentEvents(
            'eloquent.updated: '.AccountEntitlement::class,
            fn () => $this->buy($account, 'growth'),
        );

        $this->assertGreaterThan(0, $fired, 'A downgrade changes authorization and must be auditable.');
    }

    public function test_a_manual_revocation_goes_through_eloquent_so_it_is_auditable(): void
    {
        ['account' => $account] = $this->tenant('business');
        $superAdmin = $this->superAdmin();

        // Task 9 used a mass query-builder update here, which bypasses
        // Eloquent events and therefore LogsActivity entirely. Task 10
        // saves through the model.
        $fired = $this->countEloquentEvents(
            'eloquent.updated: '.AccountEntitlement::class,
            fn () => $this->actingAs($superAdmin)
                ->deleteJson("/api/admin/accounts/{$account->id}/entitlements/custom_code")
                ->assertStatus(200),
        );

        $this->assertGreaterThan(0, $fired);
    }

    public function test_a_plan_modification_goes_through_eloquent_so_it_is_auditable(): void
    {
        $superAdmin = $this->superAdmin();

        $fired = $this->countEloquentEvents(
            'eloquent.updated: '.Plan::class,
            fn () => $this->actingAs($superAdmin)
                ->putJson(self::PLANS.'/business', ['price' => 8888])
                ->assertStatus(200),
        );

        $this->assertGreaterThan(0, $fired);
    }

    // =================================================================
    // 12. Visibility (Task 8 panel)
    // =================================================================

    public function test_the_admin_panel_distinguishes_a_plan_revocation_from_a_manual_one(): void
    {
        ['account' => $account] = $this->tenant('business');

        $this->actingAs($this->superAdmin())
            ->deleteJson("/api/admin/accounts/{$account->id}/entitlements/custom_code");

        $this->buy($account, 'growth');

        $rows = collect(
            $this->actingAs($this->superAdmin())
                ->getJson("/api/admin/accounts/{$account->id}/entitlements")
                ->json('data')
        )->keyBy('slug');

        $this->assertSame('manual', $rows['custom_code']['revoked_reason']);
        $this->assertSame('plan_downgrade', $rows['ads']['revoked_reason']);
        $this->assertTrue($rows['ads']['revoked']);
    }
}
