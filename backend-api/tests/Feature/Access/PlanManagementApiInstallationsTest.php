<?php

namespace Tests\Feature\Access;

use App\Models\Account;
use App\Models\AccountEntitlement;
use App\Models\Capability;
use App\Models\Invoice;
use App\Models\Plan;
use App\Models\User;
use App\Services\Access\AccessControlService;
use App\Services\Access\PlanEntitlementReconciliationService;
use App\Services\ApiAccess\InstallationAllowanceResolver;
use App\Services\Billing\InvoiceCreditService;
use Database\Seeders\Phase1FoundationSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Phase 4 Task 2 — `api_installations` capability foundation.
 *
 * Part A: the plan CRUD validation rules (requirements 6 & 7) and
 * persistence of a concrete usage_limit through create/update, plus
 * confirmation that `external_api` and every other unmetered capability
 * keep their exact prior behavior.
 *
 * Part B (added on correction — see the class docblock further down)
 * proves `InstallationAllowanceResolver::resolveForAccount()` tracks the
 * account's EFFECTIVE plan correctly across every plan-lifecycle
 * transition that can actually happen in this codebase, using the real
 * production path (InvoiceCreditService::markPaidAndCreditQuota() ->
 * PlanEntitlementReconciliationService::reconcile()) that
 * tests/Feature/PlanLifecycleReconciliationTest.php already exhaustively
 * covers for entitlement PRESENCE — this file covers the same
 * transitions for the concrete usage_limit NUMBER instead, since that is
 * the new thing this task adds on top.
 *
 * Does NOT cover binding count enforcement — that is Task 6. Does NOT
 * cover gate()/authentication behavior — that is Task 7.
 */
class PlanManagementApiInstallationsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        $this->seed(Phase1FoundationSeeder::class);
    }

    private function superAdmin(): User
    {
        $user = User::factory()->create();
        $user->assignRole('super_admin');

        return $user;
    }

    // =====================================================================
    // Part A — validation & persistence (requirements 6 & 7)
    // =====================================================================

    public function test_creating_a_plan_with_external_api_but_no_api_installations_is_rejected(): void
    {
        $response = $this->actingAs($this->superAdmin())->postJson('/api/admin/plans-management', [
            'slug' => 'no-installations-plan',
            'label' => 'No Installations',
            'price' => 100,
            'duration_days' => 30,
            'engine_type' => 'qr',
            'billing_model' => 'flat_quota',
            'capabilities' => ['external_api'],
        ]);

        $response->assertStatus(422)->assertJsonValidationErrors(['capabilities']);
        $this->assertDatabaseMissing('plans', ['slug' => 'no-installations-plan']);
    }

    public function test_creating_a_plan_with_external_api_and_api_installations_but_a_null_limit_is_rejected(): void
    {
        $response = $this->actingAs($this->superAdmin())->postJson('/api/admin/plans-management', [
            'slug' => 'null-limit-plan',
            'label' => 'Null Limit',
            'price' => 100,
            'duration_days' => 30,
            'engine_type' => 'qr',
            'billing_model' => 'flat_quota',
            'capabilities' => ['external_api', InstallationAllowanceResolver::CAPABILITY],
            // No capability_limits entry for api_installations at all.
        ]);

        $response->assertStatus(422)->assertJsonValidationErrors(['capability_limits.'.InstallationAllowanceResolver::CAPABILITY]);
        $this->assertDatabaseMissing('plans', ['slug' => 'null-limit-plan']);
    }

    public function test_creating_a_plan_with_external_api_and_an_explicit_zero_api_installations_limit_is_accepted(): void
    {
        // usage_limit = 0 is a deliberate, valid "explicit deny" (requirement 3) — not a missing configuration.
        $response = $this->actingAs($this->superAdmin())->postJson('/api/admin/plans-management', [
            'slug' => 'zero-installations-plan',
            'label' => 'Zero Installations',
            'price' => 100,
            'duration_days' => 30,
            'engine_type' => 'qr',
            'billing_model' => 'flat_quota',
            'capabilities' => ['external_api', InstallationAllowanceResolver::CAPABILITY],
            'capability_limits' => [InstallationAllowanceResolver::CAPABILITY => 0],
        ]);

        $response->assertStatus(201);
        $this->assertSame(0, $response->json('data.capability_limits.'.InstallationAllowanceResolver::CAPABILITY));
    }

    public function test_a_plan_without_external_api_needs_no_api_installations_at_all(): void
    {
        $response = $this->actingAs($this->superAdmin())->postJson('/api/admin/plans-management', [
            'slug' => 'no-api-plan',
            'label' => 'No API',
            'price' => 100,
            'duration_days' => 30,
            'engine_type' => 'qr',
            'billing_model' => 'flat_quota',
            'capabilities' => ['whatsapp_send'],
        ]);

        $response->assertStatus(201);
    }

    public function test_a_negative_api_installations_limit_is_rejected_at_the_http_boundary(): void
    {
        $response = $this->actingAs($this->superAdmin())->postJson('/api/admin/plans-management', [
            'slug' => 'negative-limit-plan',
            'label' => 'Negative Limit',
            'price' => 100,
            'duration_days' => 30,
            'engine_type' => 'qr',
            'billing_model' => 'flat_quota',
            'capabilities' => ['external_api', InstallationAllowanceResolver::CAPABILITY],
            'capability_limits' => [InstallationAllowanceResolver::CAPABILITY => -1],
        ]);

        $response->assertStatus(422)->assertJsonValidationErrors(['capability_limits.'.InstallationAllowanceResolver::CAPABILITY]);
        $this->assertDatabaseMissing('plans', ['slug' => 'negative-limit-plan']);
    }

    public function test_create_persists_the_exact_api_installations_usage_limit(): void
    {
        $response = $this->actingAs($this->superAdmin())->postJson('/api/admin/plans-management', [
            'slug' => 'five-installations-plan',
            'label' => 'Five Installations',
            'price' => 100,
            'duration_days' => 30,
            'engine_type' => 'qr',
            'billing_model' => 'flat_quota',
            'capabilities' => ['external_api', InstallationAllowanceResolver::CAPABILITY],
            'capability_limits' => [InstallationAllowanceResolver::CAPABILITY => 5],
        ]);

        $response->assertStatus(201);

        $plan = Plan::where('slug', 'five-installations-plan')->with('capabilities')->first();
        $capability = $plan->capabilities->firstWhere('slug', InstallationAllowanceResolver::CAPABILITY);

        $this->assertNotNull($capability);
        $this->assertSame(5, $capability->pivot->usage_limit);
    }

    public function test_update_can_change_only_the_api_installations_limit_without_resending_the_bundle(): void
    {
        $this->actingAs($admin = $this->superAdmin())->postJson('/api/admin/plans-management', [
            'slug' => 'limit-update-plan',
            'label' => 'Limit Update',
            'price' => 100,
            'duration_days' => 30,
            'engine_type' => 'qr',
            'billing_model' => 'flat_quota',
            'capabilities' => ['external_api', InstallationAllowanceResolver::CAPABILITY, 'whatsapp_send'],
            'capability_limits' => [InstallationAllowanceResolver::CAPABILITY => 2],
        ])->assertStatus(201);

        $response = $this->actingAs($admin)->putJson('/api/admin/plans-management/limit-update-plan', [
            'capability_limits' => [InstallationAllowanceResolver::CAPABILITY => 7],
        ]);

        $response->assertStatus(200);
        $this->assertSame(7, $response->json('data.capability_limits.'.InstallationAllowanceResolver::CAPABILITY));
        $this->assertEqualsCanonicalizing(
            ['external_api', InstallationAllowanceResolver::CAPABILITY, 'whatsapp_send'],
            $response->json('data.capabilities'),
        );
        $this->assertFalse($response->json('data.bundle_changed'));
    }

    public function test_update_cannot_drop_the_api_installations_limit_to_null_while_external_api_remains_bundled(): void
    {
        $this->actingAs($admin = $this->superAdmin())->postJson('/api/admin/plans-management', [
            'slug' => 'cannot-null-plan',
            'label' => 'Cannot Null',
            'price' => 100,
            'duration_days' => 30,
            'engine_type' => 'qr',
            'billing_model' => 'flat_quota',
            'capabilities' => ['external_api', InstallationAllowanceResolver::CAPABILITY],
            'capability_limits' => [InstallationAllowanceResolver::CAPABILITY => 2],
        ])->assertStatus(201);

        $response = $this->actingAs($admin)->putJson('/api/admin/plans-management/cannot-null-plan', [
            'capabilities' => ['external_api'],
        ]);

        $response->assertStatus(422)->assertJsonValidationErrors(['capabilities']);
    }

    public function test_capability_limits_naming_a_capability_outside_the_bundle_is_rejected(): void
    {
        $response = $this->actingAs($this->superAdmin())->postJson('/api/admin/plans-management', [
            'slug' => 'stray-limit-plan',
            'label' => 'Stray Limit',
            'price' => 100,
            'duration_days' => 30,
            'engine_type' => 'qr',
            'billing_model' => 'flat_quota',
            'capabilities' => ['whatsapp_send'],
            'capability_limits' => [InstallationAllowanceResolver::CAPABILITY => 2],
        ]);

        $response->assertStatus(422)->assertJsonValidationErrors(['capability_limits']);
    }

    public function test_external_api_keeps_its_prior_unconditional_null_usage_limit_when_no_limit_is_supplied_for_it(): void
    {
        $response = $this->actingAs($this->superAdmin())->postJson('/api/admin/plans-management', [
            'slug' => 'external-api-null-plan',
            'label' => 'External API Null',
            'price' => 100,
            'duration_days' => 30,
            'engine_type' => 'qr',
            'billing_model' => 'flat_quota',
            'capabilities' => ['external_api', InstallationAllowanceResolver::CAPABILITY],
            'capability_limits' => [InstallationAllowanceResolver::CAPABILITY => 1],
        ]);

        $response->assertStatus(201);
        $this->assertNull($response->json('data.capability_limits.external_api'));
    }

    /**
     * Task 2A — "create with NULL" from the required test list. Explicit
     * NULL (a key present with value null) must behave identically to an
     * omitted key for a capability that is not constrained by
     * requirement 7 — proven explicitly here rather than inferred from
     * the omitted-key case above.
     */
    public function test_create_persists_an_explicit_null_usage_limit_for_a_capability_that_permits_it(): void
    {
        $response = $this->actingAs($this->superAdmin())->postJson('/api/admin/plans-management', [
            'slug' => 'explicit-null-plan',
            'label' => 'Explicit Null',
            'price' => 100,
            'duration_days' => 30,
            'engine_type' => 'qr',
            'billing_model' => 'flat_quota',
            'capabilities' => ['whatsapp_send'],
            'capability_limits' => ['whatsapp_send' => null],
        ]);

        $response->assertStatus(201);
        $this->assertNull($response->json('data.capability_limits.whatsapp_send'));

        $plan = Plan::where('slug', 'explicit-null-plan')->with('capabilities')->first();
        $capability = $plan->capabilities->firstWhere('slug', 'whatsapp_send');
        $this->assertNull($capability->pivot->usage_limit);
    }

    /**
     * Task 2A — "update concrete -> NULL where permitted", exercising
     * the LIMIT-ONLY branch specifically (modify()'s $capabilities ===
     * null path), not the bundle-rewrite branch already covered above.
     * Permitted here because external_api is NOT in this plan's bundle,
     * so requirement 7 does not apply.
     */
    public function test_update_limit_only_can_null_the_api_installations_limit_when_external_api_is_not_bundled(): void
    {
        $this->actingAs($admin = $this->superAdmin())->postJson('/api/admin/plans-management', [
            'slug' => 'limit-only-null-plan',
            'label' => 'Limit Only Null',
            'price' => 100,
            'duration_days' => 30,
            'engine_type' => 'qr',
            'billing_model' => 'flat_quota',
            'capabilities' => [InstallationAllowanceResolver::CAPABILITY, 'whatsapp_send'],
            'capability_limits' => [InstallationAllowanceResolver::CAPABILITY => 3],
        ])->assertStatus(201);

        $response = $this->actingAs($admin)->putJson('/api/admin/plans-management/limit-only-null-plan', [
            'capability_limits' => [InstallationAllowanceResolver::CAPABILITY => null],
        ]);

        $response->assertStatus(200);
        $this->assertNull($response->json('data.capability_limits.'.InstallationAllowanceResolver::CAPABILITY));
        $this->assertFalse($response->json('data.bundle_changed'));

        $plan = Plan::where('slug', 'limit-only-null-plan')->with('capabilities')->first();
        $capability = $plan->capabilities->firstWhere('slug', InstallationAllowanceResolver::CAPABILITY);
        $this->assertNull($capability->pivot->usage_limit);
    }

    /**
     * Task 2A — the same limit-only branch must still enforce
     * requirement 7 when external_api IS bundled: dropping
     * api_installations to NULL through the limit-only path (not just
     * a full bundle rewrite) must be rejected.
     */
    public function test_update_limit_only_cannot_null_the_api_installations_limit_while_external_api_remains_bundled(): void
    {
        $this->actingAs($admin = $this->superAdmin())->postJson('/api/admin/plans-management', [
            'slug' => 'limit-only-cannot-null-plan',
            'label' => 'Limit Only Cannot Null',
            'price' => 100,
            'duration_days' => 30,
            'engine_type' => 'qr',
            'billing_model' => 'flat_quota',
            'capabilities' => ['external_api', InstallationAllowanceResolver::CAPABILITY],
            'capability_limits' => [InstallationAllowanceResolver::CAPABILITY => 3],
        ])->assertStatus(201);

        $response = $this->actingAs($admin)->putJson('/api/admin/plans-management/limit-only-cannot-null-plan', [
            'capability_limits' => [InstallationAllowanceResolver::CAPABILITY => null],
        ]);

        $response->assertStatus(422)->assertJsonValidationErrors(['capability_limits.'.InstallationAllowanceResolver::CAPABILITY]);

        // Must remain at its prior concrete value — the rejected request must not have partially applied.
        $plan = Plan::where('slug', 'limit-only-cannot-null-plan')->with('capabilities')->first();
        $capability = $plan->capabilities->firstWhere('slug', InstallationAllowanceResolver::CAPABILITY);
        $this->assertSame(3, $capability->pivot->usage_limit);
    }

    public function test_hasapikeyaccess_behavior_for_external_api_is_unchanged(): void
    {
        $account = Account::factory()->create();
        $this->grantCapabilityManually($account, InstallationAllowanceResolver::CAPABILITY);

        $this->assertFalse(app(AccessControlService::class)->hasApiKeyAccess($account));

        $this->grantCapabilityManually($account, 'external_api');

        $this->assertTrue(app(AccessControlService::class)->hasApiKeyAccess($account->fresh()));
    }

    /**
     * Correction (this task was returned twice): the three seeded plans
     * (starter/growth/business) are DELIBERATELY left without a
     * product-owner-confirmed api_installations limit — see
     * Phase1FoundationSeeder's PLAN_CAPABILITIES docblock. An account on
     * one of them must fall into rule 1 (absent -> default 1), never an
     * invented number.
     */
    public function test_an_account_on_a_seeded_plan_resolves_to_the_default_allowance_because_no_business_limit_exists_yet(): void
    {
        $account = Account::factory()->create();
        $this->buy($account, 'starter', price: 599, label: 'Starter');

        $this->assertNotContains(InstallationAllowanceResolver::CAPABILITY, $this->heldBy($account->fresh()), 'starter must not bundle api_installations — no confirmed limit exists.');

        $result = app(InstallationAllowanceResolver::class)->resolveForAccount($account->fresh());

        $this->assertSame(1, $result['allowance']);
        $this->assertSame(InstallationAllowanceResolver::SOURCE_CAPABILITY_ABSENT, $result['source']);
        $this->assertNull($result['warning']);
    }

    public function test_resolve_for_account_defaults_with_a_warning_when_capability_is_manually_granted_without_a_plan(): void
    {
        $account = Account::factory()->create();
        // No paid invoice at all -> currentPlanSlug() is null -> no concrete limit can be read, even though the capability is held.
        $this->grantCapabilityManually($account, InstallationAllowanceResolver::CAPABILITY);

        $result = app(InstallationAllowanceResolver::class)->resolveForAccount($account->fresh());

        $this->assertSame(1, $result['allowance']);
        $this->assertSame(InstallationAllowanceResolver::SOURCE_NULL_LIMIT, $result['source']);
        $this->assertNotNull($result['warning']);
    }

    private function grantCapabilityManually(Account $account, string $slug): void
    {
        $capability = Capability::where('slug', $slug)->firstOrFail();
        AccountEntitlement::create([
            'account_id' => $account->id,
            'capability_id' => $capability->id,
            'source' => AccountEntitlement::SOURCE_MANUAL_GRANT,
        ]);
    }

    // =====================================================================
    // Part B — resolveForAccount() across every real plan-lifecycle
    // transition (Issue 1 of the correction). Two CUSTOM plans are used
    // ('resolver-small' / 'resolver-large'), each created through the
    // real admin API with a real, concrete, test-chosen api_installations
    // limit — the seeded production plans intentionally carry none (see
    // Part A above), so they cannot exercise "the number changes on
    // upgrade/downgrade" at all.
    // =====================================================================

    private function makeTestPlan(string $slug, int $installationLimit): void
    {
        $this->actingAs($this->superAdmin())->postJson('/api/admin/plans-management', [
            'slug' => $slug,
            'label' => $slug,
            'price' => 100,
            'duration_days' => 30,
            'engine_type' => 'qr',
            'billing_model' => 'flat_quota',
            'capabilities' => ['external_api', InstallationAllowanceResolver::CAPABILITY, 'whatsapp_send'],
            'capability_limits' => [InstallationAllowanceResolver::CAPABILITY => $installationLimit],
        ])->assertStatus(201);
    }

    /**
     * The exact production path: a pending Invoice marked paid through
     * InvoiceCreditService::markPaidAndCreditQuota(), which is what
     * ACTUALLY triggers PlanEntitlementReconciliationService::reconcile()
     * in this codebase — never a shortcut that only fakes the end state.
     * Mirrors PlanLifecycleReconciliationTest::buy() exactly, minus the
     * PlanCatalog dependency (these are custom test plans, not catalog
     * ones) — price/label come from the Plan row itself instead.
     */
    private function buy(Account $account, string $planSlug, ?float $price = null, ?string $label = null): void
    {
        $plan = Plan::where('slug', $planSlug)->first();

        $invoice = Invoice::create([
            'account_id' => $account->id,
            'invoice_number' => 'INV-'.uniqid(),
            'plan_key' => $planSlug,
            'plan_label' => $label ?? $plan?->label ?? $planSlug,
            'amount' => $price ?? (float) ($plan?->price ?? 100),
            'tax_amount' => 0,
            'total_amount' => $price ?? (float) ($plan?->price ?? 100),
            'currency' => 'INR',
            'payment_gateway' => 'razorpay',
            'gateway_order_id' => 'order_'.uniqid(),
            'status' => 'pending',
            'paid_at' => null,
        ]);

        $ok = app(InvoiceCreditService::class)->markPaidAndCreditQuota($invoice->id, 'pay_'.uniqid());
        $this->assertTrue($ok, "markPaidAndCreditQuota failed for plan '{$planSlug}' — fixture is broken.");
    }

    private function resolve(Account $account): array
    {
        return app(InstallationAllowanceResolver::class)->resolveForAccount($account->fresh());
    }

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

    /** 1. Initial plan assignment. */
    public function test_lifecycle_initial_assignment_resolves_the_new_plans_exact_limit(): void
    {
        $this->makeTestPlan('resolver-small', 2);
        $account = Account::factory()->create();

        $this->buy($account, 'resolver-small');

        $result = $this->resolve($account);
        $this->assertSame(2, $result['allowance']);
        $this->assertSame(InstallationAllowanceResolver::SOURCE_EXPLICIT_LIMIT, $result['source']);
    }

    /** 2. Upgrade — a higher-limit plan takes effect immediately. */
    public function test_lifecycle_upgrade_switches_to_the_new_plans_higher_limit(): void
    {
        $this->makeTestPlan('resolver-small', 2);
        $this->makeTestPlan('resolver-large', 9);
        $account = Account::factory()->create();
        $this->buy($account, 'resolver-small');
        $this->assertSame(2, $this->resolve($account)['allowance']);

        $this->buy($account, 'resolver-large');

        $this->assertSame(9, $this->resolve($account)['allowance']);
    }

    /** 3. Downgrade — reverts to the lower plan's limit, matching reconciliation's own entitlement-presence behavior exactly. */
    public function test_lifecycle_downgrade_switches_back_to_the_lower_plans_limit(): void
    {
        $this->makeTestPlan('resolver-small', 2);
        $this->makeTestPlan('resolver-large', 9);
        $account = Account::factory()->create();
        $this->buy($account, 'resolver-large');
        $this->assertSame(9, $this->resolve($account)['allowance']);

        $this->buy($account, 'resolver-small');

        $this->assertSame(2, $this->resolve($account)['allowance']);
        // Cross-check against the already-authoritative, exhaustively
        // tested presence mechanism: both must agree the account is on
        // the small plan's bundle now.
        $this->assertContains(InstallationAllowanceResolver::CAPABILITY, $this->heldBy($account->fresh()));
    }

    /** 4. Renewal — a second paid invoice for the SAME plan changes nothing (idempotent). */
    public function test_lifecycle_renewal_on_the_same_plan_is_a_no_op(): void
    {
        $this->makeTestPlan('resolver-small', 2);
        $account = Account::factory()->create();
        $this->buy($account, 'resolver-small');
        $before = $this->resolve($account);
        $rowCountBefore = AccountEntitlement::where('account_id', $account->id)->count();

        $this->buy($account, 'resolver-small'); // renewal: same plan, a second paid invoice

        $after = $this->resolve($account);
        $this->assertSame($before, $after);
        $this->assertSame($rowCountBefore, AccountEntitlement::where('account_id', $account->id)->count(), 'Renewal must not create duplicate entitlement rows.');
    }

    /**
     * 5. Expiry — the Subscription row lapses (expires_at in the past,
     * status recomputes to 'expired' on read) with NO new invoice paid.
     *
     * PROOF THIS IS CORRECT, NOT MERELY UNNOTICED: currentPlanSlug() only
     * ever queries `invoices` (status = 'paid'), never `subscriptions` —
     * confirmed by reading PlanEntitlementReconciliationService::
     * currentPlanSlug()'s implementation. Nothing in this codebase
     * revokes or reconciles AccountEntitlement on subscription expiry
     * either (grep across app/Jobs and app/Console finds no such call —
     * the ONLY reconciliation triggers are a payment, a plan-bundle
     * edit, and the manual artisan command). So entitlement PRESENCE
     * (canTenant) and the resolved usage_limit NUMBER both continue
     * reflecting the last paid plan after expiry, in lockstep — exactly
     * the same pre-existing property every other capability already has
     * (e.g. `external_api` is not revoked on subscription expiry either).
     * This test proves the resolver does not newly diverge from that
     * established behavior; it does not claim the behavior is ideal.
     */
    public function test_lifecycle_expiry_does_not_change_the_resolved_allowance(): void
    {
        $this->makeTestPlan('resolver-small', 2);
        $account = Account::factory()->create();
        $this->buy($account, 'resolver-small');
        $before = $this->resolve($account);

        $subscription = $account->fresh()->currentSubscription;
        $this->assertNotNull($subscription, 'Fixture assumption: markPaidAndCreditQuota() must create/extend a Subscription.');
        $subscription->forceFill(['expires_at' => now()->subDay()])->save();
        $this->assertSame('expired', $subscription->refreshStatus());

        $after = $this->resolve($account->fresh());

        $this->assertSame($before, $after, 'Subscription expiry must not change the resolved allowance — it has no reconciliation trigger in this codebase.');
        $this->assertContains(InstallationAllowanceResolver::CAPABILITY, $this->heldBy($account->fresh()), 'Presence must move in lockstep with the limit — both are untouched by expiry.');
    }

    /**
     * 6. Cancellation / non-renewal — this codebase has no distinct
     * cancellation flow (confirmed: no 'cancel' action on Subscription,
     * no status value for it); "the customer never pays again" IS
     * cancellation/non-renewal here, and is the same case as expiry
     * above by construction — asserted again explicitly so a future
     * reviewer does not have to infer it from the expiry test alone.
     */
    public function test_lifecycle_non_renewal_leaves_the_last_paid_plans_allowance_in_place_indefinitely(): void
    {
        $this->makeTestPlan('resolver-small', 2);
        $account = Account::factory()->create();
        $this->buy($account, 'resolver-small');
        $before = $this->resolve($account);

        // Time passes; no further invoice is ever paid.
        $this->travel(90)->days();

        $this->assertSame($before, $this->resolve($account->fresh()));
    }

    /** 7. Paid/unpaid invoice transitions — a pending (unpaid) invoice for a DIFFERENT plan must have zero effect until it is actually paid. */
    public function test_lifecycle_a_pending_invoice_for_a_different_plan_has_no_effect_until_paid(): void
    {
        $this->makeTestPlan('resolver-small', 2);
        $this->makeTestPlan('resolver-large', 9);
        $account = Account::factory()->create();
        $this->buy($account, 'resolver-small');
        $before = $this->resolve($account);

        $largePlan = Plan::where('slug', 'resolver-large')->first();
        Invoice::create([
            'account_id' => $account->id,
            'invoice_number' => 'INV-pending-'.uniqid(),
            'plan_key' => 'resolver-large',
            'plan_label' => $largePlan->label,
            'amount' => $largePlan->price,
            'tax_amount' => 0,
            'total_amount' => $largePlan->price,
            'currency' => 'INR',
            'payment_gateway' => 'razorpay',
            'status' => 'pending', // never paid
            'paid_at' => null,
        ]);

        $this->assertSame($before, $this->resolve($account->fresh()), 'An unpaid invoice must not affect the resolved plan/limit at all.');

        // Now actually pay it — the effect appears only now.
        $pending = Invoice::where('plan_key', 'resolver-large')->where('status', 'pending')->first();
        app(InvoiceCreditService::class)->markPaidAndCreditQuota($pending->id, 'pay_'.uniqid());

        $this->assertSame(9, $this->resolve($account->fresh())['allowance']);
    }

    /** 8. Existing reconciliation behavior — a direct reconcile() call (the manual/idempotent path) agrees with the resolver, never drifts from it. */
    public function test_lifecycle_a_direct_reconcile_call_agrees_with_the_resolver(): void
    {
        $this->makeTestPlan('resolver-small', 2);
        $account = Account::factory()->create();
        $this->buy($account, 'resolver-small');

        app(PlanEntitlementReconciliationService::class)->reconcile($account->fresh());
        app(PlanEntitlementReconciliationService::class)->reconcile($account->fresh());

        $result = $this->resolve($account);
        $this->assertSame(2, $result['allowance']);
        $this->assertSame(InstallationAllowanceResolver::SOURCE_EXPLICIT_LIMIT, $result['source']);
    }
}
