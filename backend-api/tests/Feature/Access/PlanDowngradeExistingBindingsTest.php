<?php

namespace Tests\Feature\Access;

use App\Models\Account;
use App\Models\AccountEntitlement;
use App\Models\ApiKey;
use App\Models\ApiKeyBinding;
use App\Models\Capability;
use App\Models\Invoice;
use App\Models\Plan;
use App\Services\ApiAccess\ApiKeyBindingService;
use App\Services\ApiAccess\InstallationAllowanceExceededException;
use App\Services\ApiAccess\InstallationAllowanceResolver;
use App\Services\Access\PlanManagementService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Phase 4 Task 10 — Plan Changes Verification, scenarios 9/10/11/12.
 *
 * AUDIT FINDING: neither PlanManagementService::modify() nor
 * ReconcilePlanAccountsJob (nor anywhere else in this codebase — grep
 * confirms ApiKeyBinding is referenced nowhere under app/Jobs or
 * app/Services/Access) ever reads or writes ApiKeyBinding at all.
 * InstallationAllowanceResolver::resolveForAccount() is also always
 * called LIVE, fresh, every time createLiveBindingEnforced() needs it
 * (Task 6) — there is no cached/stored "allowance at time of creation"
 * anywhere. Together these two facts mean a plan's installation limit
 * changing can only ever affect a FUTURE binding-creation DECISION; it
 * is structurally incapable of deleting, revoking, or otherwise
 * touching an EXISTING binding, because no code path exists that
 * would do so. This file is the regression proof of that conclusion —
 * no production code change was needed for these scenarios.
 *
 * Fixture helpers are the same established pattern as
 * InstallationAllowanceEnforcementTest.php (Task 6) and
 * TransferAtomicityTest.php (Task 8), except the DOWNGRADE/UPGRADE
 * step itself goes through the REAL production edit path —
 * PlanManagementService::modify()'s limit-only update — rather than a
 * raw DB::table() write, since this task is specifically about
 * verifying that real edit path's effect on existing installations.
 */
class PlanDowngradeExistingBindingsTest extends TestCase
{
    use RefreshDatabase;

    private function bindings(): ApiKeyBindingService
    {
        return app(ApiKeyBindingService::class);
    }

    private function resolver(): InstallationAllowanceResolver
    {
        return app(InstallationAllowanceResolver::class);
    }

    private function makeKey(Account $account, string $name = 'Key'): ApiKey
    {
        $plain = 'wasaas_live_'.Str::random(40);

        return ApiKey::create([
            'account_id' => $account->id,
            'name' => $name,
            'key_prefix' => substr($plain, 0, 20),
            'key_hash' => ApiKey::hashKey($plain),
        ]);
    }

    private function capability(): Capability
    {
        return Capability::firstOrCreate(
            ['slug' => InstallationAllowanceResolver::CAPABILITY],
            ['label' => 'API Installations', 'category' => 'platform']
        );
    }

    /** Same real-fixture shape as InstallationAllowanceEnforcementTest::grantAllowance(), but returns the Plan so the test can edit it afterward through PlanManagementService. */
    private function grantAllowanceViaPlan(Account $account, int $limit): Plan
    {
        $capability = $this->capability();

        AccountEntitlement::create([
            'account_id' => $account->id,
            'capability_id' => $capability->id,
            'source' => AccountEntitlement::SOURCE_MANUAL_GRANT,
        ]);

        $plan = Plan::create([
            'slug' => 'task10-plan-'.Str::random(8),
            'label' => 'Task 10 Test Plan',
            'price' => 100,
            'duration_days' => 30,
            'engine_type' => 'qr',
            'billing_model' => 'flat_quota',
        ]);
        $plan->capabilities()->attach($capability->id, ['usage_limit' => $limit]);

        Invoice::create([
            'account_id' => $account->id,
            'invoice_number' => 'INV-'.Str::random(10),
            'plan_key' => $plan->slug,
            'plan_label' => $plan->label,
            'amount' => 100,
            'tax_amount' => 0,
            'total_amount' => 100,
            'currency' => 'INR',
            'payment_gateway' => 'razorpay',
            'gateway_order_id' => 'order_'.Str::random(10),
            'status' => 'paid',
            'paid_at' => now(),
        ]);

        return $plan;
    }

    private function provisionedKey(Account $account, string $name, string $ip): ApiKey
    {
        $key = $this->makeKey($account, $name);
        $this->bindings()->provision($key, ['ip_policy' => 'SINGLE_IP', 'authorized_ips' => [$ip]]);

        return $key->fresh();
    }

    /** Real production limit-only edit path — PlanManagementController::update() with capabilities omitted routes here identically. */
    private function editInstallationLimit(Plan $plan, int $newLimit): void
    {
        app(PlanManagementService::class)->modify(
            $plan,
            attributes: [],
            capabilities: null, // bundle untouched — limit-only update
            capabilityLimits: [InstallationAllowanceResolver::CAPABILITY => $newLimit],
        );
    }

    // ---- 9 & 12: downgrade never touches existing bindings; never auto-revokes ------------------------------------

    public function test_downgrading_the_plans_limit_through_the_real_edit_path_leaves_every_existing_live_binding_untouched(): void
    {
        $account = Account::factory()->create();
        $plan = $this->grantAllowanceViaPlan($account, 3);
        $keyA = $this->provisionedKey($account, 'A', '203.0.113.1');
        $keyB = $this->provisionedKey($account, 'B', '203.0.113.2');
        $keyC = $this->provisionedKey($account, 'C', '203.0.113.3');
        $bindingIdA = $keyA->liveBinding()->id;
        $bindingIdB = $keyB->liveBinding()->id;
        $bindingIdC = $keyC->liveBinding()->id;
        $this->assertSame(3, $this->bindings()->countLiveInstallations($account->id));

        // The downgrade: 3 -> 1, through the real edit path.
        $this->editInstallationLimit($plan, 1);

        // 8. The resolved allowance reflects the new limit immediately.
        $this->assertSame(1, $this->resolver()->resolveForAccount($account->fresh())['allowance']);

        // 9/12. All three pre-existing bindings are still exactly as
        // they were — same ids, same ACTIVE status, nothing deleted or
        // auto-revoked by the plan edit itself.
        $this->assertSame(3, ApiKeyBinding::where('account_id', $account->id)->live()->count());
        foreach ([$bindingIdA, $bindingIdB, $bindingIdC] as $id) {
            $this->assertDatabaseHas('api_key_bindings', ['id' => $id, 'status' => ApiKeyBinding::STATUS_ACTIVE]);
        }
    }

    // ---- 10: new creation is blocked while the existing, now-over-allowance bindings remain intact -----------------

    public function test_a_new_installation_is_blocked_after_downgrade_while_the_existing_ones_remain_live(): void
    {
        $account = Account::factory()->create();
        $plan = $this->grantAllowanceViaPlan($account, 3);
        $this->provisionedKey($account, 'A', '203.0.113.1');
        $this->provisionedKey($account, 'B', '203.0.113.2');
        $this->provisionedKey($account, 'C', '203.0.113.3');

        $this->editInstallationLimit($plan, 1);

        $keyD = $this->makeKey($account, 'D');
        try {
            $this->bindings()->provision($keyD, ['ip_policy' => 'SINGLE_IP', 'authorized_ips' => ['203.0.113.4']]);
            $this->fail('Expected InstallationAllowanceExceededException — the account is now 3-over its reduced allowance of 1.');
        } catch (InstallationAllowanceExceededException $e) {
            // expected
        }

        // The rejection did not touch the three pre-existing bindings.
        $this->assertSame(3, ApiKeyBinding::where('account_id', $account->id)->live()->count());
        // Nor did it leave any partial row for the rejected key D.
        $this->assertSame(0, ApiKeyBinding::where('api_key_id', $keyD->id)->count());
    }

    // ---- 11: upgrade immediately permits an additional installation -----------------------------------------------

    public function test_upgrading_the_plans_limit_through_the_real_edit_path_immediately_permits_an_additional_installation(): void
    {
        $account = Account::factory()->create();
        $plan = $this->grantAllowanceViaPlan($account, 1);
        $this->provisionedKey($account, 'A', '203.0.113.1');

        $keyB = $this->makeKey($account, 'B');
        try {
            $this->bindings()->provision($keyB, ['ip_policy' => 'SINGLE_IP', 'authorized_ips' => ['203.0.113.2']]);
            $this->fail('Expected rejection before the upgrade.');
        } catch (InstallationAllowanceExceededException $e) {
            // expected — still at the old limit of 1.
        }

        // The upgrade: 1 -> 3, through the real edit path.
        $this->editInstallationLimit($plan, 3);

        $this->assertSame(3, $this->resolver()->resolveForAccount($account->fresh())['allowance']);

        // The SAME key B, retried after the upgrade, now succeeds.
        $result = $this->bindings()->provision($keyB->fresh(), ['ip_policy' => 'SINGLE_IP', 'authorized_ips' => ['203.0.113.2']]);
        $this->assertTrue($result['binding']->isLive());
        $this->assertSame(2, $this->bindings()->countLiveInstallations($account->id));
    }
}
