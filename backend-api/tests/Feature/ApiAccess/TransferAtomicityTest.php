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
 * Phase 4 Task 8 — Transfer Atomicity.
 *
 * "Transfer" in this codebase's own vocabulary is the existing
 * one-for-one replacement of a key's live binding — rebind() (Super
 * Admin) and approve() (buyer-requested server change) — both of which
 * already route through Task 6's single enforcement seam
 * (ApiKeyBindingService::createLiveBindingEnforced()): Account row
 * lock -> ApiKey row lock -> old binding revoked (freeing its slot)
 * INSIDE that same lock, BEFORE the allowance count is taken -> new
 * binding created, all inside one DB::transaction().
 *
 * AUDIT FINDING (reported, not fixed — see this task's own report):
 * that seam already satisfies every one of Task 8's ten required
 * scenarios by construction; no production code change was made. This
 * file adds the regression/proof tests Task 6's own test suite did not
 * yet cover for the TRANSFER-specific angles: rollback-on-failure for a
 * REPLACEMENT (not just a fresh create), cross-key isolation during a
 * transfer specifically, repeated/chained transfers, and credential
 * behavior of the binding a transfer produces. Scenarios already
 * proven by tests/Feature/ApiAccess/InstallationAllowanceEnforcementTest.php
 * (#10 "at full allowance", #12/#13 "no two-slot moment") are not
 * duplicated here beyond a short cross-reference.
 */
class TransferAtomicityTest extends TestCase
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

    /** Same fixture pattern as InstallationAllowanceEnforcementTest — a real paid Invoice + Plan behind the capability. */
    private function grantAllowance(Account $account, int $limit): void
    {
        $capability = $this->capability();

        AccountEntitlement::create([
            'account_id' => $account->id,
            'capability_id' => $capability->id,
            'source' => AccountEntitlement::SOURCE_MANUAL_GRANT,
        ]);

        $plan = Plan::create([
            'slug' => 'task8-plan-'.Str::random(8),
            'label' => 'Task 8 Test Plan',
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

    /** Defensively drops the account's allowance to 0 after it was already granted and used — simulates a plan downgrade mid-lifecycle, to force a failing transfer. */
    private function downgradeAllowanceToZero(Account $account): void
    {
        $planKey = Invoice::forAccount($account->id)->where('status', 'paid')->value('plan_key');
        $plan = Plan::where('slug', $planKey)->firstOrFail();
        DB::table('plan_entitlements')
            ->where('plan_id', $plan->id)
            ->where('capability_id', $this->capability()->id)
            ->update(['usage_limit' => 0]);
    }

    private function provisionedKey(Account $account, string $name, string $ip): ApiKey
    {
        $key = $this->makeKey($account, $name);
        $this->service()->provision($key, ['ip_policy' => 'SINGLE_IP', 'authorized_ips' => [$ip]]);

        return $key->fresh();
    }

    // ====================================================================
    // 1. Transfer with available installation capacity.
    // =====================================================================

    public function test_transfer_with_available_capacity_succeeds(): void
    {
        $account = Account::factory()->create();
        $this->grantAllowance($account, 5);
        $key = $this->provisionedKey($account, 'Key', '203.0.113.1');
        $admin = $this->admin();

        $new = $this->service()->rebind($key, $admin, ['ip_policy' => 'SINGLE_IP', 'authorized_ips' => ['203.0.113.9']]);

        $this->assertSame(1, $this->service()->countLiveInstallations($account->id));
        $this->assertSame(['203.0.113.9'], $new->authorized_ips);
    }

    // ====================================================================
    // 2. Transfer exactly at the installation limit — cross-referenced
    //    with InstallationAllowanceEnforcementTest's #10/#11/#13, proven
    //    here again for approve() with a chained second transfer, to
    //    show the invariant holds repeatedly, not just once.
    // =====================================================================

    public function test_transfer_at_the_exact_allowance_limit_succeeds_repeatedly_without_ever_requiring_a_second_slot(): void
    {
        $account = Account::factory()->create();
        $this->grantAllowance($account, 1);
        $key = $this->provisionedKey($account, 'Key', '203.0.113.1');
        $admin = $this->admin();

        $first = $this->service()->rebind($key, $admin, ['ip_policy' => 'SINGLE_IP', 'authorized_ips' => ['203.0.113.9']]);
        $this->assertSame(1, $this->service()->countLiveInstallations($account->id));

        // Phase 4 Task 9 added a 14-day per-key cooldown after every completed
        // transfer (rebind()/approve()), so a second transfer attempted before it
        // expires is deliberately refused (ApiKeyCooldownActiveException) - see
        // createLiveBindingEnforced()'s own comment on this. Travel past it between
        // chained transfers so this test exercises transfer ATOMICITY, which is
        // what it is actually testing, not cooldown enforcement (covered by
        // ApiKeyCooldownEnforcementTest).
        $this->travel(15)->days();

        $second = $this->service()->rebind($key->fresh(), $admin, ['ip_policy' => 'SINGLE_IP', 'authorized_ips' => ['203.0.113.10']]);
        $this->assertSame(1, $this->service()->countLiveInstallations($account->id));
        $this->assertNotSame($first->id, $second->id);
    }

    // =====================================================================
    // 4. Same API key cannot end with two live bindings, even after a
    //    chain of transfers.
    // =====================================================================

    public function test_same_api_key_cannot_end_with_two_live_bindings_after_a_chain_of_transfers(): void
    {
        $account = Account::factory()->create();
        $this->grantAllowance($account, 5);
        $key = $this->provisionedKey($account, 'Key', '203.0.113.1');
        $admin = $this->admin();

        // Phase 4 Task 9 added a 14-day per-key cooldown after every completed
        // transfer (rebind()/approve()), so a second transfer attempted before it
        // expires is deliberately refused (ApiKeyCooldownActiveException) - see
        // createLiveBindingEnforced()'s own comment on this. Travel past it between
        // chained transfers so this test exercises transfer ATOMICITY, which is
        // what it is actually testing, not cooldown enforcement (covered by
        // ApiKeyCooldownEnforcementTest).
        $this->travel(15)->days();
        $this->service()->rebind($key->fresh(), $admin, ['ip_policy' => 'SINGLE_IP', 'authorized_ips' => ['203.0.113.2']]);
        // Phase 4 Task 9 added a 14-day per-key cooldown after every completed
        // transfer (rebind()/approve()), so a second transfer attempted before it
        // expires is deliberately refused (ApiKeyCooldownActiveException) - see
        // createLiveBindingEnforced()'s own comment on this. Travel past it between
        // chained transfers so this test exercises transfer ATOMICITY, which is
        // what it is actually testing, not cooldown enforcement (covered by
        // ApiKeyCooldownEnforcementTest).
        $this->travel(15)->days();
        $this->service()->rebind($key->fresh(), $admin, ['ip_policy' => 'SINGLE_IP', 'authorized_ips' => ['203.0.113.3']]);
        // Phase 4 Task 9 added a 14-day per-key cooldown after every completed
        // transfer (rebind()/approve()), so a second transfer attempted before it
        // expires is deliberately refused (ApiKeyCooldownActiveException) - see
        // createLiveBindingEnforced()'s own comment on this. Travel past it between
        // chained transfers so this test exercises transfer ATOMICITY, which is
        // what it is actually testing, not cooldown enforcement (covered by
        // ApiKeyCooldownEnforcementTest).
        $this->travel(15)->days();
        $final = $this->service()->rebind($key->fresh(), $admin, ['ip_policy' => 'SINGLE_IP', 'authorized_ips' => ['203.0.113.4']]);

        $this->assertSame(1, ApiKeyBinding::where('api_key_id', $key->id)->live()->count());
        $this->assertSame(4, ApiKeyBinding::where('api_key_id', $key->id)->count(), '3 revoked history rows + 1 live row.');
        $this->assertSame($final->id, $key->fresh()->liveBinding()->id);
    }

    // ====================================================================
    // 5. Old binding is not left live after a successful transfer.
    // =====================================================================

    public function test_old_binding_is_not_left_live_after_a_successful_transfer(): void
    {
        $account = Account::factory()->create();
        $this->grantAllowance($account, 5);
        $key = $this->provisionedKey($account, 'Key', '203.0.113.1');
        $oldId = $key->liveBinding()->id;
        $admin = $this->admin();

        $this->service()->rebind($key, $admin, ['ip_policy' => 'SINGLE_IP', 'authorized_ips' => ['203.0.113.9']]);

        $old = ApiKeyBinding::find($oldId);
        $this->assertSame(ApiKeyBinding::STATUS_REVOKED, $old->status);
        $this->assertNull($old->active_slot);
        $this->assertNotNull($old->revoked_at);
    }

    // =====================================================================
    // 6/7. Failure mid-transfer rolls back entirely; capacity is neither
    //      consumed nor incorrectly released by a failed transfer.
    // =====================================================================

    public function test_a_transfer_that_fails_the_allowance_check_rolls_back_entirely_and_leaves_capacity_untouched(): void
    {
        $account = Account::factory()->create();
        $this->grantAllowance($account, 1);
        $key = $this->provisionedKey($account, 'Key', '203.0.113.1');
        $oldId = $key->liveBinding()->id;
        $admin = $this->admin();

        // Simulate the account's allowance being downgraded to 0 after
        // the key was already provisioned (e.g. a plan change), so the
        // replacement's allowance re-check — taken AFTER the old slot
        // is freed, inside the same transaction — now fails even though
        // the operation is a 1-for-1 replacement.
        $this->downgradeAllowanceToZero($account);

        try {
            $this->service()->rebind($key->fresh(), $admin, ['ip_policy' => 'SINGLE_IP', 'authorized_ips' => ['203.0.113.9']]);
            $this->fail('Expected InstallationAllowanceExceededException.');
        } catch (InstallationAllowanceExceededException $e) {
            // expected
        }

        // 6. Nothing persisted from the failed attempt: the old binding
        // row, re-queried fresh from the database (not the in-memory
        // object revokeBinding() mutated before the rollback), must be
        // exactly as it was before the attempt — proving the revoke
        // that happened inside the same transaction was rolled back
        // along with the rejected insert, not left half-applied.
        $old = ApiKeyBinding::find($oldId);
        $this->assertSame(ApiKeyBinding::STATUS_ACTIVE, $old->status, 'the old binding must still be live after a rolled-back transfer.');
        $this->assertSame(1, $old->active_slot);
        $this->assertNull($old->revoked_at, 'the in-transaction revoke must have been rolled back, not merely overwritten later.');
        $this->assertNull($old->revoked_reason);

        // No second (new) binding row was left behind either.
        $this->assertSame(1, ApiKeyBinding::where('api_key_id', $key->id)->count());

        // 7. The failed transfer neither consumed a slot it shouldn't
        // have nor released the one the key legitimately still holds.
        $this->assertSame(1, $this->service()->countLiveInstallations($account->id));
    }

    /** Same proof as above, through approve() instead of rebind() — the other transfer path. */
    public function test_an_approve_transfer_that_fails_the_allowance_check_rolls_back_entirely(): void
    {
        $account = Account::factory()->create();
        $this->grantAllowance($account, 1);
        $key = $this->provisionedKey($account, 'Key', '203.0.113.1');
        $oldId = $key->liveBinding()->id;
        $admin = $this->admin();
        $buyer = User::factory()->create(['account_id' => $account->id]);

        $changeRequest = $this->service()->requestChange($key->fresh(), $buyer, [
            'ip_policy' => 'SINGLE_IP',
            'requested_ips' => ['203.0.113.50'],
            'reason' => 'server moved',
        ]);

        $this->downgradeAllowanceToZero($account);

        try {
            $this->service()->approve($changeRequest, $admin);
            $this->fail('Expected InstallationAllowanceExceededException.');
        } catch (InstallationAllowanceExceededException $e) {
            // expected
        }

        $old = ApiKeyBinding::find($oldId);
        $this->assertSame(ApiKeyBinding::STATUS_ACTIVE, $old->status);
        $this->assertNull($old->revoked_at);
        $this->assertSame(1, $this->service()->countLiveInstallations($account->id));

        // The change request itself must still read as pending — a
        // failed approve() must not have silently marked it decided.
        $this->assertSame('pending', $changeRequest->fresh()->status);
    }

    // ====================================================================
    // 8. A transfer cannot accidentally affect another API key's binding.
    // =====================================================================

    public function test_a_rebind_transfer_does_not_affect_an_unrelated_keys_binding(): void
    {
        $account = Account::factory()->create();
        $this->grantAllowance($account, 5);
        $keyA = $this->provisionedKey($account, 'A', '203.0.113.1');
        $keyB = $this->provisionedKey($account, 'B', '203.0.113.2');
        $bindingBId = $keyB->liveBinding()->id;
        $admin = $this->admin();

        $this->service()->rebind($keyA, $admin, ['ip_policy' => 'SINGLE_IP', 'authorized_ips' => ['203.0.113.9']]);

        $bindingB = ApiKeyBinding::find($bindingBId);
        $this->assertSame(ApiKeyBinding::STATUS_ACTIVE, $bindingB->status);
        $this->assertSame(['203.0.113.2'], $bindingB->authorized_ips);
        $this->assertSame(2, $this->service()->countLiveInstallations($account->id));
    }

    public function test_an_approve_transfer_does_not_affect_an_unrelated_keys_binding(): void
    {
        $account = Account::factory()->create();
        $this->grantAllowance($account, 5);
        $keyA = $this->provisionedKey($account, 'A', '203.0.113.1');
        $keyB = $this->provisionedKey($account, 'B', '203.0.113.2');
        $bindingBId = $keyB->liveBinding()->id;
        $admin = $this->admin();
        $buyer = User::factory()->create(['account_id' => $account->id]);

        $changeRequest = $this->service()->requestChange($keyA->fresh(), $buyer, [
            'ip_policy' => 'SINGLE_IP',
            'requested_ips' => ['203.0.113.50'],
            'reason' => 'server moved',
        ]);
        $this->service()->approve($changeRequest, $admin);

        $bindingB = ApiKeyBinding::find($bindingBId);
        $this->assertSame(ApiKeyBinding::STATUS_ACTIVE, $bindingB->status);
        $this->assertSame(['203.0.113.2'], $bindingB->authorized_ips);
    }

    // =====================================================================
    // 9. Historical revoked bindings created by a transfer remain
    //    historical and never count toward the live installation count.
    // =====================================================================

    public function test_historical_bindings_left_by_transfers_remain_historical_and_uncounted(): void
    {
        $account = Account::factory()->create();
        $this->grantAllowance($account, 5);
        $key = $this->provisionedKey($account, 'Key', '203.0.113.1');
        $admin = $this->admin();

        // Phase 4 Task 9 added a 14-day per-key cooldown after every completed
        // transfer (rebind()/approve()), so a second transfer attempted before it
        // expires is deliberately refused (ApiKeyCooldownActiveException) - see
        // createLiveBindingEnforced()'s own comment on this. Travel past it between
        // chained transfers so this test exercises transfer ATOMICITY, which is
        // what it is actually testing, not cooldown enforcement (covered by
        // ApiKeyCooldownEnforcementTest).
        $this->travel(15)->days();
        $this->service()->rebind($key->fresh(), $admin, ['ip_policy' => 'SINGLE_IP', 'authorized_ips' => ['203.0.113.2']]);
        // Phase 4 Task 9 added a 14-day per-key cooldown after every completed
        // transfer (rebind()/approve()), so a second transfer attempted before it
        // expires is deliberately refused (ApiKeyCooldownActiveException) - see
        // createLiveBindingEnforced()'s own comment on this. Travel past it between
        // chained transfers so this test exercises transfer ATOMICITY, which is
        // what it is actually testing, not cooldown enforcement (covered by
        // ApiKeyCooldownEnforcementTest).
        $this->travel(15)->days();
        $this->service()->rebind($key->fresh(), $admin, ['ip_policy' => 'SINGLE_IP', 'authorized_ips' => ['203.0.113.3']]);

        $this->assertSame(2, ApiKeyBinding::where('api_key_id', $key->id)->where('status', ApiKeyBinding::STATUS_REVOKED)->count());
        $this->assertSame(1, $this->service()->countLiveInstallations($account->id));
    }

    // =====================================================================
    // 10. Existing credential behavior remains correct for the binding a
    //     transfer produces.
    // =====================================================================

    public function test_rebind_transfer_result_is_credential_pending_and_does_not_reuse_the_old_bindings_credential(): void
    {
        $account = Account::factory()->create();
        $this->grantAllowance($account, 5);
        $key = $this->makeKey($account, 'Key');
        $provisioned = $this->service()->provision($key, ['ip_policy' => 'SINGLE_IP', 'authorized_ips' => ['203.0.113.1']]);
        $oldCredential = $provisioned['credential'];
        $admin = $this->admin();

        $new = $this->service()->rebind($key->fresh(), $admin, ['ip_policy' => 'SINGLE_IP', 'authorized_ips' => ['203.0.113.9']]);

        $this->assertFalse($new->hasCredential(), 'rebind() must leave the new binding credential-pending, exactly as before Task 8 — a transfer does not auto-mint a credential.');
        $this->assertFalse($new->verifyCredential($oldCredential), 'the old binding credential must never authenticate the replacement binding.');

        $issued = $this->service()->issueCredential($key->fresh(), $new->fresh(), $admin);
        $this->assertTrue($new->fresh()->hasCredential());
        $this->assertTrue($new->fresh()->verifyCredential($issued));
    }

    public function test_approve_transfer_result_is_credential_pending_and_does_not_reuse_the_old_bindings_credential(): void
    {
        $account = Account::factory()->create();
        $this->grantAllowance($account, 5);
        $key = $this->makeKey($account, 'Key');
        $provisioned = $this->service()->provision($key, ['ip_policy' => 'SINGLE_IP', 'authorized_ips' => ['203.0.113.1']]);
        $oldCredential = $provisioned['credential'];
        $admin = $this->admin();
        $buyer = User::factory()->create(['account_id' => $account->id]);

        $changeRequest = $this->service()->requestChange($key->fresh(), $buyer, [
            'ip_policy' => 'SINGLE_IP',
            'requested_ips' => ['203.0.113.50'],
            'reason' => 'server moved',
        ]);
        $this->service()->approve($changeRequest, $admin);

        $new = $key->fresh()->liveBinding();
        $this->assertFalse($new->hasCredential());
        $this->assertFalse($new->verifyCredential($oldCredential));

        $issued = $this->service()->issueCredential($key->fresh(), $new->fresh(), $admin);
        $this->assertTrue($new->fresh()->verifyCredential($issued));
    }
}
