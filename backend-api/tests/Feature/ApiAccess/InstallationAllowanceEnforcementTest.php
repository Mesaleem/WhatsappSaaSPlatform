<?php

namespace Tests\Feature\ApiAccess;

use App\Models\Account;
use App\Models\AccountEntitlement;
use App\Models\ApiKey;
use App\Models\ApiKeyBinding;
use App\Models\Capability;
use App\Models\Invoice;
use App\Models\Plan;
use App\Models\User;
use App\Services\ApiAccess\ApiKeyBindingService;
use App\Services\ApiAccess\InstallationAllowanceExceededException;
use App\Services\ApiAccess\InstallationAllowanceResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Phase 4 Task 6 — proves the single enforcement seam
 * (ApiKeyBindingService::createLiveBindingEnforced(), exercised here only
 * through its real callers: provision(), approve(), rebind(), and
 * destroyKey()) against every scenario #1–18 of the task's required test
 * list.
 *
 * Deliberately lightweight account/allowance fixtures: a direct
 * Capability + Plan + paid Invoice + AccountEntitlement, bypassing the
 * admin Plan-management HTTP API and PlanEntitlementReconciliationService
 * entirely (those are Task 2/2A's own tested surface — see
 * tests/Feature/Access/PlanManagementApiInstallationsTest.php). This file
 * only needs InstallationAllowanceResolver::resolveForAccount() to return
 * a specific, deterministic allowance for each test, which these direct
 * rows produce exactly as that resolver already reads them:
 *   - presence: Account::entitlements() (AccountEntitlement, active()).
 *   - the concrete limit: Invoice(status=paid).plan_key -> Plan ->
 *     Plan::capabilities() pivot (plan_entitlements.usage_limit).
 *
 * Does NOT re-test the resolver's own pure rule (that is
 * InstallationAllowanceResolverTest, unit-level) or
 * countLiveInstallations() itself (ApiKeyLiveInstallationCountTest) —
 * only that the enforcement seam correctly WIRES allowance + count +
 * locking + replacement + destroy together.
 */
class InstallationAllowanceEnforcementTest extends TestCase
{
    use RefreshDatabase;

    private function service(): ApiKeyBindingService
    {
        return app(ApiKeyBindingService::class);
    }

    private function admin(): User
    {
        return User::factory()->create();
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

    /** Capability held (AccountEntitlement) + a concrete usage_limit via a real paid Invoice + Plan. Source => SOURCE_EXPLICIT_LIMIT. */
    private function grantAllowance(Account $account, int $limit): void
    {
        $capability = $this->capability();

        AccountEntitlement::create([
            'account_id' => $account->id,
            'capability_id' => $capability->id,
            'source' => AccountEntitlement::SOURCE_MANUAL_GRANT,
        ]);

        $plan = Plan::create([
            'slug' => 'task6-plan-'.Str::random(8),
            'label' => 'Task 6 Test Plan',
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
    }

    /** Capability held, but no plan/invoice behind it at all -> currentUsageLimit() is null -> SOURCE_NULL_LIMIT, allowance 1. */
    private function grantPresenceWithNoConcreteLimit(Account $account): void
    {
        AccountEntitlement::create([
            'account_id' => $account->id,
            'capability_id' => $this->capability()->id,
            'source' => AccountEntitlement::SOURCE_MANUAL_GRANT,
        ]);
    }

    /** After grantAllowance(), defensively corrupt the persisted pivot value to a negative number — bypassing the app's own write paths entirely, as the resolver's own docblock describes (see its Requirement 5 / SOURCE_NEGATIVE_LIMIT). */
    private function corruptAllowanceToNegative(Account $account): void
    {
        $planKey = Invoice::forAccount($account->id)->where('status', 'paid')->value('plan_key');
        $plan = Plan::where('slug', $planKey)->firstOrFail();
        DB::table('plan_entitlements')
            ->where('plan_id', $plan->id)
            ->where('capability_id', $this->capability()->id)
            ->update(['usage_limit' => -1]);
    }

    private function provisionedKey(Account $account, string $name, string $ip): ApiKey
    {
        $key = $this->makeKey($account, $name);
        $this->service()->provision($key, ['ip_policy' => 'SINGLE_IP', 'authorized_ips' => [$ip]]);

        return $key->fresh();
    }

    // =====================================================================
    // Allowance (#1–9)
    // =====================================================================

    /** 1. allowance 0 rejects live-binding creation. */
    public function test_allowance_zero_rejects_live_binding_creation(): void
    {
        $account = Account::factory()->create();
        $this->grantAllowance($account, 0);
        $key = $this->makeKey($account);

        $this->expectException(InstallationAllowanceExceededException::class);
        $this->service()->provision($key, ['ip_policy' => 'SINGLE_IP', 'authorized_ips' => ['203.0.113.1']]);
    }

    /** 2. allowance 1 permits first binding. */
    public function test_allowance_one_permits_first_binding(): void
    {
        $account = Account::factory()->create();
        $this->grantAllowance($account, 1);
        $key = $this->makeKey($account);

        $this->service()->provision($key, ['ip_policy' => 'SINGLE_IP', 'authorized_ips' => ['203.0.113.1']]);

        $this->assertSame(1, $this->service()->countLiveInstallations($account->id));
    }

    /** 3. allowance 1 rejects a second, independent key's binding for the SAME account. */
    public function test_allowance_one_rejects_second_independent_key_binding(): void
    {
        $account = Account::factory()->create();
        $this->grantAllowance($account, 1);
        $this->provisionedKey($account, 'First', '203.0.113.1');

        $second = $this->makeKey($account, 'Second');

        $this->expectException(InstallationAllowanceExceededException::class);
        $this->service()->provision($second, ['ip_policy' => 'SINGLE_IP', 'authorized_ips' => ['203.0.113.2']]);
    }

    /** 4. allowance 2 permits two live bindings. */
    public function test_allowance_two_permits_two_live_bindings(): void
    {
        $account = Account::factory()->create();
        $this->grantAllowance($account, 2);
        $this->provisionedKey($account, 'First', '203.0.113.1');
        $this->provisionedKey($account, 'Second', '203.0.113.2');

        $this->assertSame(2, $this->service()->countLiveInstallations($account->id));
    }

    /** 5. Absent api_installations resolves to 1 -- first binding succeeds, second is rejected. */
    public function test_absent_capability_resolves_to_allowance_one(): void
    {
        $account = Account::factory()->create(); // no entitlement granted at all
        $this->provisionedKey($account, 'First', '203.0.113.1');

        $second = $this->makeKey($account, 'Second');
        $this->expectException(InstallationAllowanceExceededException::class);
        $this->service()->provision($second, ['ip_policy' => 'SINGLE_IP', 'authorized_ips' => ['203.0.113.2']]);
    }

    /** 6. Capability present but no concrete usage_limit resolves to 1 (with a warning, not asserted here). */
    public function test_null_usage_limit_resolves_to_allowance_one(): void
    {
        $account = Account::factory()->create();
        $this->grantPresenceWithNoConcreteLimit($account);
        $this->provisionedKey($account, 'First', '203.0.113.1');

        $second = $this->makeKey($account, 'Second');
        $this->expectException(InstallationAllowanceExceededException::class);
        $this->service()->provision($second, ['ip_policy' => 'SINGLE_IP', 'authorized_ips' => ['203.0.113.2']]);
    }

    /** 7. A positive explicit limit is respected exactly -- 3 permitted, the 4th rejected. */
    public function test_positive_limit_is_respected_exactly(): void
    {
        $account = Account::factory()->create();
        $this->grantAllowance($account, 3);
        $this->provisionedKey($account, 'One', '203.0.113.1');
        $this->provisionedKey($account, 'Two', '203.0.113.2');
        $this->provisionedKey($account, 'Three', '203.0.113.3');

        $this->assertSame(3, $this->service()->countLiveInstallations($account->id));

        $fourth = $this->makeKey($account, 'Four');
        $this->expectException(InstallationAllowanceExceededException::class);
        $this->service()->provision($fourth, ['ip_policy' => 'SINGLE_IP', 'authorized_ips' => ['203.0.113.4']]);
    }

    /** 8. A persisted negative usage_limit is defensively treated as 0 -- not "unlimited", not "ignored". */
    public function test_persisted_negative_limit_is_defensively_treated_as_zero(): void
    {
        $account = Account::factory()->create();
        $this->grantAllowance($account, 5);
        $this->corruptAllowanceToNegative($account);

        $key = $this->makeKey($account);
        $this->expectException(InstallationAllowanceExceededException::class);
        $this->service()->provision($key, ['ip_policy' => 'SINGLE_IP', 'authorized_ips' => ['203.0.113.1']]);
    }

    /** 9. A rejected creation leaves no partial binding/key state behind. */
    public function test_rejected_creation_leaves_no_partial_state(): void
    {
        $account = Account::factory()->create();
        $this->grantAllowance($account, 0);
        $key = $this->makeKey($account);

        try {
            $this->service()->provision($key, ['ip_policy' => 'SINGLE_IP', 'authorized_ips' => ['203.0.113.1']]);
            $this->fail('Expected InstallationAllowanceExceededException.');
        } catch (InstallationAllowanceExceededException $e) {
            // expected
        }

        $this->assertSame(0, ApiKeyBinding::where('api_key_id', $key->id)->count(), 'No binding row of any status must exist after a rejected creation.');
        $this->assertNull($key->fresh()->revoked_at);
    }

    // =====================================================================
    // Replacement (#10–13)
    // =====================================================================

    /** 10. A one-for-one rebind() succeeds even when the account is already at its allowance. */
    public function test_rebind_replacement_succeeds_at_full_allowance(): void
    {
        $account = Account::factory()->create();
        $this->grantAllowance($account, 1);
        $key = $this->provisionedKey($account, 'Key', '203.0.113.1');
        $admin = $this->admin();

        $this->service()->rebind($key, $admin, ['ip_policy' => 'SINGLE_IP', 'authorized_ips' => ['203.0.113.9']]);

        $this->assertSame(1, $this->service()->countLiveInstallations($account->id));
    }

    /** 11. A one-for-one approve() replacement succeeds even when already at the allowance. */
    public function test_approve_replacement_succeeds_at_full_allowance(): void
    {
        $account = Account::factory()->create();
        $this->grantAllowance($account, 1);
        $key = $this->provisionedKey($account, 'Key', '203.0.113.1');
        $admin = $this->admin();
        $buyer = User::factory()->create(['account_id' => $account->id]);

        $changeRequest = $this->service()->requestChange($key, $buyer, [
            'ip_policy' => 'SINGLE_IP',
            'requested_ips' => ['203.0.113.50'],
            'reason' => 'server moved',
        ]);
        $this->service()->approve($changeRequest, $admin);

        $this->assertSame(1, $this->service()->countLiveInstallations($account->id));
    }

    /** 12. Replacement leaves exactly one LIVE binding row for the key (the old row persists, revoked). */
    public function test_replacement_leaves_exactly_one_live_binding_for_the_key(): void
    {
        $account = Account::factory()->create();
        $this->grantAllowance($account, 1);
        $key = $this->provisionedKey($account, 'Key', '203.0.113.1');
        $admin = $this->admin();

        $this->service()->rebind($key, $admin, ['ip_policy' => 'SINGLE_IP', 'authorized_ips' => ['203.0.113.9']]);

        $this->assertSame(2, ApiKeyBinding::where('api_key_id', $key->id)->count(), 'old (revoked) + new (live) rows.');
        $this->assertSame(1, ApiKeyBinding::where('api_key_id', $key->id)->live()->count());
    }

    /**
     * 13. Replacement does not temporarily consume a second slot. Proven
     * behaviorally: at an allowance of exactly 1, with the key already
     * occupying that one slot, rebind() still succeeds. If the
     * implementation created the new binding BEFORE revoking the old one
     * (the two-slots-momentarily-occupied bug this task explicitly
     * forbids), the allowance check inside that same seam would see
     * count=1 >= allowance=1 and reject with
     * InstallationAllowanceExceededException -- so a successful rebind()
     * here is only possible because the old binding was revoked (and its
     * slot freed) BEFORE the count was taken, exactly as
     * createLiveBindingEnforced()'s docblock specifies.
     */
    public function test_replacement_never_requires_two_slots_at_once(): void
    {
        $account = Account::factory()->create();
        $this->grantAllowance($account, 1);
        $key = $this->provisionedKey($account, 'Key', '203.0.113.1');
        $admin = $this->admin();

        // Would throw InstallationAllowanceExceededException if the old
        // binding were not freed before the new one's count check.
        $new = $this->service()->rebind($key, $admin, ['ip_policy' => 'SINGLE_IP', 'authorized_ips' => ['203.0.113.9']]);

        // authorized_ips was declared, so createLive() marks this ACTIVE
        // immediately (credential-pending is a separate concept - see
        // hasCredential() - not reflected in `status`); consistent with
        // every other declared-IP binding in this suite (e.g.
        // test_destroy_does_not_affect_an_unrelated_keys_binding above).
        $this->assertSame(ApiKeyBinding::STATUS_ACTIVE, $new->status);
        $this->assertSame(1, $this->service()->countLiveInstallations($account->id));
    }

    // =====================================================================
    // Destroy (#14–18)
    // =====================================================================

    /** 14. Destroying a key with a live binding releases exactly one slot. */
    public function test_destroy_with_live_binding_releases_exactly_one_slot(): void
    {
        $account = Account::factory()->create();
        $this->grantAllowance($account, 5);
        $key = $this->provisionedKey($account, 'Key', '203.0.113.1');
        $admin = $this->admin();

        $this->assertSame(1, $this->service()->countLiveInstallations($account->id));

        $this->service()->destroyKey($key, $admin);

        $this->assertSame(0, $this->service()->countLiveInstallations($account->id));
    }

    /** 15. Binding history remains -- the row is revoked, never deleted. */
    public function test_destroy_keeps_binding_history_row(): void
    {
        $account = Account::factory()->create();
        $this->grantAllowance($account, 5);
        $key = $this->provisionedKey($account, 'Key', '203.0.113.1');
        $bindingId = $key->liveBinding()->id;
        $admin = $this->admin();

        $this->service()->destroyKey($key, $admin);

        $this->assertDatabaseHas('api_key_bindings', ['id' => $bindingId, 'status' => ApiKeyBinding::STATUS_REVOKED]);
    }

    /** 16. A second destroy() is idempotent -- revoked_at is not re-touched, no error. */
    public function test_second_destroy_is_idempotent(): void
    {
        $account = Account::factory()->create();
        $this->grantAllowance($account, 5);
        $key = $this->provisionedKey($account, 'Key', '203.0.113.1');
        $admin = $this->admin();

        $first = $this->service()->destroyKey($key, $admin);
        $revokedAt = $first->revoked_at;

        $second = $this->service()->destroyKey($key->fresh(), $admin);

        $this->assertNotNull($revokedAt);
        $this->assertSame($revokedAt->toDateTimeString(), $second->revoked_at->toDateTimeString());
        $this->assertSame(0, $this->service()->countLiveInstallations($account->id));
    }

    /** 17. Destroying a key with no live binding at all is safe. */
    public function test_destroy_with_no_binding_is_safe(): void
    {
        $account = Account::factory()->create();
        $key = $this->makeKey($account); // never provisioned
        $admin = $this->admin();

        $result = $this->service()->destroyKey($key, $admin);

        $this->assertNotNull($result->revoked_at);
        $this->assertSame(0, ApiKeyBinding::where('api_key_id', $key->id)->count());
    }

    /** 18. Destroying one key's binding must never affect an unrelated key's live binding. */
    public function test_destroy_does_not_affect_an_unrelated_keys_binding(): void
    {
        $account = Account::factory()->create();
        $this->grantAllowance($account, 5);
        $keyA = $this->provisionedKey($account, 'A', '203.0.113.1');
        $keyB = $this->provisionedKey($account, 'B', '203.0.113.2');
        $admin = $this->admin();

        $this->service()->destroyKey($keyA, $admin);

        $this->assertSame(ApiKeyBinding::STATUS_ACTIVE, $keyB->fresh()->liveBinding()->status);
        $this->assertSame(1, $this->service()->countLiveInstallations($account->id));
    }
}
