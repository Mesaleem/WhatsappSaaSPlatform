<?php

namespace Tests\Feature\ApiAccess;

use App\Models\Account;
use App\Models\AccountEntitlement;
use App\Models\ApiKey;
use App\Models\ApiKeyBinding;
use App\Models\ApiKeyChangeRequest;
use App\Models\Capability;
use App\Models\Invoice;
use App\Models\Plan;
use App\Models\User;
use App\Services\ApiAccess\ApiKeyBindingService;
use App\Services\ApiAccess\ApiKeyCooldownActiveException;
use App\Services\ApiAccess\InstallationAllowanceResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Phase 4 Task 9 — cooldown wiring. Covers required tests 1-11 and 17
 * from the finalized Task 9 spec (override-specific tests 12-16/18 live
 * in CooldownOverrideTest.php; the lock-order concurrency test required
 * by item 19 lives in CooldownLockOrderConcurrencyTest.php).
 *
 * Fixture helpers (makeKey/capability/grantAllowance/provisionedKey) are
 * copied verbatim from TransferAtomicityTest.php's own established
 * pattern — same real Capability/Plan/Invoice fixture Task 6/8 already
 * use, rather than inventing a second, parallel fixture style. ApiKey
 * has no model factory in this codebase (confirmed: only Account and
 * User do), so it is always built with ApiKey::create() exactly as
 * that file does.
 */
class ApiKeyCooldownEnforcementTest extends TestCase
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

    private function grantAllowance(Account $account, int $limit): void
    {
        $capability = $this->capability();

        AccountEntitlement::create([
            'account_id' => $account->id,
            'capability_id' => $capability->id,
            'source' => AccountEntitlement::SOURCE_MANUAL_GRANT,
        ]);

        $plan = Plan::create([
            'slug' => 'task9-plan-'.Str::random(8),
            'label' => 'Task 9 Test Plan',
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

    private function accountWithAllowance(int $limit = 5): Account
    {
        $account = Account::factory()->create();
        $this->grantAllowance($account, $limit);

        return $account;
    }

    private function provisionedKey(Account $account, string $ip = '203.0.113.10', string $name = 'Key'): ApiKey
    {
        $key = $this->makeKey($account, $name);
        $this->service()->provision($key, ['ip_policy' => 'SINGLE_IP', 'authorized_ips' => [$ip]]);

        return $key->fresh();
    }

    // ---- 1. Key A cooldown blocks Key A from creating a new binding -------------------------------------------

    public function test_cooldown_blocks_a_new_binding_for_the_same_key(): void
    {
        $account = $this->accountWithAllowance();
        $key = $this->makeKey($account);
        $this->service()->setCooldown($key, now()->addDays(14));

        $this->expectException(ApiKeyCooldownActiveException::class);
        $this->service()->provision($key->fresh(), ['ip_policy' => 'SINGLE_IP', 'authorized_ips' => ['203.0.113.20']], $this->admin());
    }

    // ---- 2. Key B on the same account remains unaffected -------------------------------------------------------

    public function test_another_keys_cooldown_does_not_affect_this_key(): void
    {
        $account = $this->accountWithAllowance();
        $keyA = $this->makeKey($account, 'Key A');
        $keyB = $this->makeKey($account, 'Key B');
        $this->service()->setCooldown($keyA, now()->addDays(14));

        $binding = $this->service()->provision($keyB->fresh(), ['ip_policy' => 'SINGLE_IP', 'authorized_ips' => ['203.0.113.21']], $this->admin());

        $this->assertTrue($binding['binding']->isLive());
    }

    // ---- 3. Cooldown blocks registerServer() (via provision(), the method it calls) ----------------------------

    public function test_cooldown_blocks_registerServer_style_provisioning(): void
    {
        $account = $this->accountWithAllowance();
        $key = $this->makeKey($account);
        $this->service()->setCooldown($key, now()->addHour());

        try {
            $this->service()->provision($key->fresh(), ['ip_policy' => 'SINGLE_IP', 'authorized_ips' => ['203.0.113.22']], $this->admin());
            $this->fail('Expected ApiKeyCooldownActiveException.');
        } catch (ApiKeyCooldownActiveException $e) {
            $this->assertSame(ApiKeyCooldownActiveException::COOLDOWN_ACTIVE, $e->reason);
        }
    }

    // ---- 4. Cooldown blocks requestChange() ---------------------------------------------------------------------

    public function test_cooldown_blocks_requestChange(): void
    {
        $account = $this->accountWithAllowance();
        $key = $this->provisionedKey($account);
        $this->service()->setCooldown($key, now()->addDays(14));

        $this->expectException(ApiKeyCooldownActiveException::class);
        $this->service()->requestChange($key->fresh(), $this->admin(), [
            'reason' => 'moving servers',
            'ip_policy' => 'SINGLE_IP',
            'requested_ips' => ['203.0.113.30'],
        ]);
    }

    // ---- 5. Independent admin rebind() respects cooldown ----------------------------------------------------------

    public function test_cooldown_blocks_an_independent_rebind(): void
    {
        $account = $this->accountWithAllowance();
        $key = $this->provisionedKey($account);
        // A cooldown left by an earlier, already-completed operation —
        // not the rebind under test — before attempting a second,
        // independent one.
        $this->service()->setCooldown($key, now()->addDays(14));

        $this->expectException(ApiKeyCooldownActiveException::class);
        $this->service()->rebind($key->fresh(), $this->admin(), ['ip_policy' => 'SINGLE_IP', 'authorized_ips' => ['203.0.113.31']]);
    }

    // ---- 6. Successful transfer sets a 14-day cooldown on the same API key ----------------------------------------

    public function test_a_successful_rebind_transfer_sets_a_fourteen_day_cooldown(): void
    {
        $account = $this->accountWithAllowance();
        $key = $this->provisionedKey($account);

        $before = now();
        $this->service()->rebind($key->fresh(), $this->admin(), ['ip_policy' => 'SINGLE_IP', 'authorized_ips' => ['203.0.113.32']]);

        $fresh = $key->fresh();
        $this->assertNotNull($fresh->cooldown_until);
        $this->assertTrue($fresh->cooldown_until->greaterThanOrEqualTo($before->copy()->addDays(14)->subMinute()));
        $this->assertTrue($fresh->cooldown_until->lessThanOrEqualTo($before->copy()->addDays(14)->addMinute()));
    }

    public function test_a_successful_approve_transfer_sets_a_fourteen_day_cooldown(): void
    {
        $account = $this->accountWithAllowance();
        $key = $this->provisionedKey($account);
        $request = $this->service()->requestChange($key->fresh(), $this->admin(), [
            'reason' => 'moving servers',
            'ip_policy' => 'SINGLE_IP',
            'requested_ips' => ['203.0.113.33'],
        ]);

        $before = now();
        $this->service()->approve(ApiKeyChangeRequest::find($request->id), $this->admin());

        $fresh = $key->fresh();
        $this->assertNotNull($fresh->cooldown_until);
        $this->assertTrue($fresh->cooldown_until->greaterThanOrEqualTo($before->copy()->addDays(14)->subMinute()));
    }

    // ---- 7. Successful transfer does not self-block --------------------------------------------------------------

    public function test_a_successful_rebind_transfer_does_not_self_block(): void
    {
        $account = $this->accountWithAllowance();
        $key = $this->provisionedKey($account);

        // No prior cooldown exists; the transfer must not throw merely
        // because it is itself about to set one.
        $new = $this->service()->rebind($key->fresh(), $this->admin(), ['ip_policy' => 'SINGLE_IP', 'authorized_ips' => ['203.0.113.34']]);

        $this->assertTrue($new->isLive());
        $this->assertSame(['203.0.113.34'], $new->authorized_ips);
    }

    // ---- 8. Explicit key revoke sets a 14-day cooldown -------------------------------------------------------------

    public function test_explicit_revoke_sets_a_fourteen_day_cooldown(): void
    {
        $account = $this->accountWithAllowance();
        $key = $this->provisionedKey($account);

        $before = now();
        $revoked = $this->service()->revoke($key->fresh(), $this->admin(), 'no longer needed');

        $this->assertTrue($revoked);
        $fresh = $key->fresh();
        $this->assertNotNull($fresh->cooldown_until);
        $this->assertTrue($fresh->cooldown_until->greaterThanOrEqualTo($before->copy()->addDays(14)->subMinute()));
        // The historical binding record itself is preserved (revoked, not deleted).
        $this->assertSame(ApiKeyBinding::STATUS_REVOKED, ApiKeyBinding::where('api_key_id', $key->id)->first()->status);
    }

    // ---- 9. Secret regeneration does not change cooldown ------------------------------------------------------------

    public function test_secret_regeneration_leaves_cooldown_untouched(): void
    {
        $account = $this->accountWithAllowance();
        $key = $this->provisionedKey($account);
        // Truncated to whole seconds: the datetime column has no
        // sub-second precision, so comparing the reloaded value against
        // an un-truncated in-memory Carbon via equalTo() would otherwise
        // always fail by a few microseconds.
        $until = now()->addDays(14)->startOfSecond();
        $this->service()->setCooldown($key, $until);

        // regenerateSecret() (ApiKeyController) only ever touches
        // secret_prefix/secret_hash via forceFill()->save(); reproduce
        // that exact write here and assert cooldown_until is
        // bit-for-bit unchanged afterward.
        $key->fresh()->forceFill(['secret_prefix' => 'x', 'secret_hash' => 'y'])->save();

        $fresh = $key->fresh();
        $this->assertTrue($fresh->cooldown_until->equalTo($until));
    }

    // ---- 10. Expired cooldown permits the intended new binding -----------------------------------------------------

    public function test_an_expired_cooldown_permits_a_new_binding(): void
    {
        $account = $this->accountWithAllowance();
        $key = $this->makeKey($account);
        $this->service()->setCooldown($key, now()->subDay());

        $result = $this->service()->provision($key->fresh(), ['ip_policy' => 'SINGLE_IP', 'authorized_ips' => ['203.0.113.40']], $this->admin());

        $this->assertTrue($result['binding']->isLive());
    }

    // ---- 11. Past cooldown_until remains stored (never auto-cleared just for being in the past) --------------------

    public function test_an_expired_cooldown_is_not_auto_cleared_by_merely_checking_it(): void
    {
        $account = $this->accountWithAllowance();
        $key = $this->makeKey($account);
        // Truncated to whole seconds — see the equalTo() precision note above.
        $past = now()->subDay()->startOfSecond();
        $this->service()->setCooldown($key, $past);

        // provision() is not a transfer ($replacing === null), so it
        // never writes cooldown_until itself; the original past value
        // must still be exactly what was stored, not NULL and not
        // refreshed to "now".
        $this->service()->provision($key->fresh(), ['ip_policy' => 'SINGLE_IP', 'authorized_ips' => ['203.0.113.41']], $this->admin());

        $stillStored = ApiKey::find($key->id)->cooldown_until;
        $this->assertNotNull($stillStored);
        $this->assertTrue($stillStored->equalTo($past));
    }

    // ---- 17. No account-wide historical timestamp can place another key into cooldown ------------------------------

    public function test_no_account_wide_signal_places_an_unrelated_key_into_cooldown(): void
    {
        $account = $this->accountWithAllowance();
        $keyA = $this->provisionedKey($account, '203.0.113.50', 'Key A');
        $keyB = $this->makeKey($account, 'Key B');

        // Key A is revoked (sets a cooldown on A only) and then fully
        // destroyed, both of which touch only api_keys.cooldown_until
        // for that one row.
        $this->service()->revoke($keyA->fresh(), $this->admin());
        $this->service()->destroyKey($keyA->fresh(), $this->admin());

        $this->assertNull(ApiKey::find($keyB->id)->cooldown_until);
        // Key B, never touched, must still be free to provision.
        $result = $this->service()->provision($keyB->fresh(), ['ip_policy' => 'SINGLE_IP', 'authorized_ips' => ['203.0.113.51']], $this->admin());
        $this->assertTrue($result['binding']->isLive());
    }
}
