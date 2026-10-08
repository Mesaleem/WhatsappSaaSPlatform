<?php

namespace Tests\Feature\ApiAccess;

use App\Models\Account;
use App\Models\AccountEntitlement;
use App\Models\ApiKey;
use App\Models\Capability;
use App\Models\Invoice;
use App\Models\Plan;
use App\Models\User;
use App\Services\ApiAccess\ApiKeyBindingService;
use App\Services\ApiAccess\InstallationAllowanceResolver;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Phase 4 Task 14 — Legacy authorized_server_ip Retirement.
 *
 * Covers the 12 required tests. The retirement GATE is new
 * (ApiKeyBindingService::countLegacyIpDependentKeys()/legacyIpDependentKeys(),
 * exposed via GET /api/admin/api-access/legacy-retirement-gate) — this
 * file is its regression proof. Items 8/10 (no account-IP fallback for
 * a bound key; deadline is key-specific) are already exhaustively
 * proven at the gate()/extendLegacyDeadline() level by
 * InstallationBindingAuthenticationTest — this file adds only a thin,
 * non-duplicate cross-check tying those same guarantees to the new
 * gate's own counting, not a second proof of gate() itself.
 */
class LegacyIpRetirementGateTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
    }

    private function service(): ApiKeyBindingService
    {
        return app(ApiKeyBindingService::class);
    }

    private function superAdmin(): User
    {
        $user = User::factory()->create(['account_id' => null]);
        $user->assignRole('super_admin');

        return $user;
    }

    private function plainUser(Account $account): User
    {
        return User::factory()->create(['account_id' => $account->id]);
    }

    private function capability(): Capability
    {
        return Capability::firstOrCreate(
            ['slug' => InstallationAllowanceResolver::CAPABILITY],
            ['label' => 'API Installations', 'category' => 'platform']
        );
    }

    private function accountWithAllowance(int $limit = 5): Account
    {
        $account = Account::factory()->create();
        $capability = $this->capability();

        AccountEntitlement::create([
            'account_id' => $account->id,
            'capability_id' => $capability->id,
            'source' => AccountEntitlement::SOURCE_MANUAL_GRANT,
        ]);

        $plan = Plan::create([
            'slug' => 'task14-plan-'.Str::random(8),
            'label' => 'Task 14 Test Plan',
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

        return $account;
    }

    private function makeKey(Account $account, string $name = 'Key'): ApiKey
    {
        $plain = 'wasaas_live_'.Str::random(40);

        return ApiKey::create([
            'account_id' => $account->id,
            'name' => $name,
            'key_prefix' => substr($plain, 0, 20),
            'key_hash' => ApiKey::hashKey($plain),
            'secret_prefix' => 'wasaas_secret_'.Str::random(6),
            'secret_hash' => ApiKey::hashSecret('wasaas_secret_'.Str::random(40)),
        ]);
    }

    private function provisionedKey(Account $account, string $name, string $ip): array
    {
        $key = $this->makeKey($account, $name);
        $result = $this->service()->provision($key, ['ip_policy' => 'SINGLE_IP', 'authorized_ips' => [$ip]]);

        return [$key->fresh(), $result];
    }

    // ---- 1. One unresolved legacy key blocks retirement ----------------------------------------------------------

    public function test_one_unresolved_legacy_key_blocks_retirement(): void
    {
        $account = $this->accountWithAllowance();
        $account->forceFill(['authorized_server_ip' => '203.0.113.10'])->save();
        $this->makeKey($account, 'Legacy'); // no live binding

        $this->assertSame(1, $this->service()->countLegacyIpDependentKeys());
    }

    // ---- 2. Multiple unresolved legacy keys block retirement ------------------------------------------------------

    public function test_multiple_unresolved_legacy_keys_block_retirement(): void
    {
        $accountA = $this->accountWithAllowance();
        $accountA->forceFill(['authorized_server_ip' => '203.0.113.10'])->save();
        $this->makeKey($accountA, 'Legacy A1');
        $this->makeKey($accountA, 'Legacy A2');

        $accountB = $this->accountWithAllowance();
        $accountB->forceFill(['authorized_server_ip' => '198.51.100.1'])->save();
        $this->makeKey($accountB, 'Legacy B1');

        $this->assertSame(3, $this->service()->countLegacyIpDependentKeys());
    }

    // ---- 3. Expired legacy deadline still blocks retirement --------------------------------------------------------

    public function test_expired_legacy_deadline_still_blocks_retirement(): void
    {
        $account = $this->accountWithAllowance();
        $account->forceFill(['authorized_server_ip' => '203.0.113.10'])->save();
        $key = $this->makeKey($account, 'Legacy');
        $key->forceFill(['legacy_binding_grace_expires_at' => now()->subYear()])->save();

        $this->assertSame(1, $this->service()->countLegacyIpDependentKeys(), 'an expired deadline must not resolve legacy-IP-dependent status.');
    }

    public function test_null_legacy_deadline_still_blocks_retirement(): void
    {
        $account = $this->accountWithAllowance();
        $account->forceFill(['authorized_server_ip' => '203.0.113.10'])->save();
        $this->makeKey($account, 'Legacy'); // legacy_binding_grace_expires_at left null

        $this->assertSame(1, $this->service()->countLegacyIpDependentKeys());
    }

    // ---- 4. Revoked/historical binding does not count as live ------------------------------------------------------

    public function test_revoked_historical_binding_does_not_resolve_legacy_dependent_status(): void
    {
        $account = $this->accountWithAllowance();
        $account->forceFill(['authorized_server_ip' => '203.0.113.10'])->save();
        [$key] = $this->provisionedKey($account, 'A', '203.0.113.1');
        $admin = $this->superAdmin();

        $this->assertSame(0, $this->service()->countLegacyIpDependentKeys(), 'a live binding resolves it first.');

        $this->service()->revoke($key->fresh(), $admin, 'done with this server');

        $this->assertSame(1, $this->service()->countLegacyIpDependentKeys(), 'once the binding is revoked (historical, not live), the key is unresolved again.');
    }

    public function test_a_revoked_api_key_is_still_counted_when_structurally_unresolved(): void
    {
        // The task's own rule: do not silently redefine legacy-IP-dependent
        // state based on the API KEY's own revocation.
        $account = $this->accountWithAllowance();
        $account->forceFill(['authorized_server_ip' => '203.0.113.10'])->save();
        $key = $this->makeKey($account, 'Legacy');
        $key->forceFill(['revoked_at' => now()])->save();

        $this->assertSame(1, $this->service()->countLegacyIpDependentKeys());
    }

    // ---- 5. Live binding removes the key from the count -------------------------------------------------------------

    public function test_a_live_binding_removes_the_key_from_the_legacy_dependent_count(): void
    {
        $account = $this->accountWithAllowance();
        $account->forceFill(['authorized_server_ip' => '203.0.113.10'])->save();
        $this->provisionedKey($account, 'A', '203.0.113.1');

        $this->assertSame(0, $this->service()->countLegacyIpDependentKeys());
    }

    // ---- 6. Two keys on one account are evaluated independently -----------------------------------------------------

    public function test_two_keys_on_one_account_are_evaluated_independently(): void
    {
        $account = $this->accountWithAllowance();
        $account->forceFill(['authorized_server_ip' => '203.0.113.10'])->save();
        [$boundKey] = $this->provisionedKey($account, 'Bound', '203.0.113.1');
        $unboundKey = $this->makeKey($account, 'Unbound');

        $unresolved = $this->service()->legacyIpDependentKeys()->pluck('id')->all();
        $this->assertNotContains($boundKey->id, $unresolved);
        $this->assertContains($unboundKey->id, $unresolved);
        $this->assertSame(1, $this->service()->countLegacyIpDependentKeys());
    }

    // ---- 7. Zero legacy-IP-dependent keys permits retirement -----------------------------------------------------

    public function test_zero_legacy_dependent_keys_reports_retirement_as_safe_via_the_admin_gate(): void
    {
        $account = $this->accountWithAllowance();
        $this->provisionedKey($account, 'A', '203.0.113.1'); // authorized_server_ip never set at all
        $admin = $this->superAdmin();

        $response = $this->actingAs($admin)->getJson('/api/admin/api-access/legacy-retirement-gate');

        $response->assertOk();
        $this->assertSame(0, $response->json('legacy_ip_dependent_key_count'));
        $this->assertTrue($response->json('retirement_safe'));
        $this->assertSame([], $response->json('keys'));
    }

    public function test_a_nonzero_count_reports_retirement_as_unsafe_via_the_admin_gate(): void
    {
        $account = $this->accountWithAllowance();
        $account->forceFill(['authorized_server_ip' => '203.0.113.10'])->save();
        $key = $this->makeKey($account, 'Legacy');
        $admin = $this->superAdmin();

        $response = $this->actingAs($admin)->getJson('/api/admin/api-access/legacy-retirement-gate');

        $response->assertOk();
        $this->assertSame(1, $response->json('legacy_ip_dependent_key_count'));
        $this->assertFalse($response->json('retirement_safe'));
        $this->assertSame($key->id, $response->json('keys.0.id'));
    }

    // ---- 8. No authentication path falls back to account IP for a bound key ----------------------------------------
    //
    // Exhaustively proven already at the gate() level by
    // InstallationBindingAuthenticationTest (e.g.
    // test_bound_key_authenticates_by_credential_even_when_binding_ip_differs_from_account_ip
    // and the credential_pending tests, which explicitly assert the
    // account IP is never consulted once a binding exists). This is a
    // thin, non-duplicate cross-check that a bound key's own request,
    // sent from an IP that does NOT match the account's registered
    // legacy IP, still authenticates purely on the binding credential.

    public function test_a_bound_key_never_falls_back_to_the_account_authorized_ip(): void
    {
        $account = $this->accountWithAllowance();
        $account->forceFill(['authorized_server_ip' => '198.51.100.1'])->save(); // deliberately different
        [$key, $provisioned] = $this->provisionedKey($account, 'A', '203.0.113.1');

        $request = Request::create('/api/v1/probe', 'GET', [], [], [], ['REMOTE_ADDR' => '203.0.113.1']);
        $request->headers->set(config('api_binding.installation_header', 'X-Client-Installation'), $provisioned['credential']);

        $this->assertNull($this->service()->gate($request, $key), 'the bound key must authenticate purely on its own binding credential, never the account-level IP.');
    }

    // ---- 9. ip_registered_at is not used as the legacy deadline ------------------------------------------------------

    public function test_ip_registered_at_is_never_used_as_the_legacy_deadline(): void
    {
        $account = $this->accountWithAllowance();
        $account->forceFill([
            'authorized_server_ip' => '203.0.113.10',
            // Registered long ago — if ip_registered_at were ever
            // mistaken for the deadline, this key would already be
            // expired/binding-required despite its real, still-valid
            // per-key deadline below.
            'ip_registered_at' => now()->subYears(2),
        ])->save();
        $key = $this->makeKey($account, 'Legacy');
        $admin = $this->superAdmin();
        $this->service()->extendLegacyDeadline($key, now()->addDays(10), $admin, 'migration window');

        $request = Request::create('/api/v1/probe', 'GET', [], [], [], ['REMOTE_ADDR' => '203.0.113.10']);
        $this->assertNull($this->service()->gate($request, $key->fresh()), 'authentication must follow the key-specific legacy_binding_grace_expires_at, never Account.ip_registered_at.');
    }

    // ---- 10. Legacy deadline remains key-specific ---------------------------------------------------------------------
    //
    // Exhaustively proven at the gate() level already by
    // InstallationBindingAuthenticationTest::test_extension_for_another_key_does_not_extend_this_key.
    // This cross-checks the SAME guarantee against the new gate's own
    // listing: extending one key's deadline must never resolve, or
    // change the identity of, the other key's unresolved status.

    public function test_legacy_deadline_extension_is_key_specific_in_the_retirement_gate_listing(): void
    {
        $account = $this->accountWithAllowance();
        $account->forceFill(['authorized_server_ip' => '203.0.113.10'])->save();
        $keyA = $this->makeKey($account, 'A');
        $keyB = $this->makeKey($account, 'B');
        $admin = $this->superAdmin();

        $this->service()->extendLegacyDeadline($keyA, now()->addDays(30), $admin, 'Key A only');

        $this->assertSame(2, $this->service()->countLegacyIpDependentKeys(), 'extending a deadline does not resolve legacy-IP-dependent status for either key.');
        $byId = $this->service()->legacyIpDependentKeys()->keyBy('id');
        $this->assertNotNull($byId[$keyA->id]['legacy_deadline']);
        $this->assertNull($byId[$keyB->id]['legacy_deadline'], "Key B's deadline must be untouched by Key A's extension.");
    }

    // ---- 11. No sensitive credentials/hashes are exposed --------------------------------------------------------------

    public function test_the_retirement_gate_never_exposes_credentials_or_hashes(): void
    {
        $account = $this->accountWithAllowance();
        $account->forceFill(['authorized_server_ip' => '203.0.113.10'])->save();
        $this->makeKey($account, 'Legacy');
        $admin = $this->superAdmin();

        $response = $this->actingAs($admin)->getJson('/api/admin/api-access/legacy-retirement-gate');

        $body = $response->getContent();
        $this->assertStringNotContainsString('secret_hash', $body);
        $this->assertStringNotContainsString('key_hash', $body);
        $this->assertStringNotContainsString('installation_hash', $body);
    }

    // ---- 12. Non-Super-Admin users cannot invoke any retirement/admin operation -----------------------------------

    public function test_a_non_super_admin_cannot_access_the_retirement_gate(): void
    {
        $account = $this->accountWithAllowance();
        $account->forceFill(['authorized_server_ip' => '203.0.113.10'])->save();
        $this->makeKey($account, 'Legacy');
        $buyer = $this->plainUser($account);

        $response = $this->actingAs($buyer)->getJson('/api/admin/api-access/legacy-retirement-gate');

        $response->assertForbidden();
    }
}
