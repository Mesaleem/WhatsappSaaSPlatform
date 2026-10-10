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
use App\Services\ApiAccess\InstallationAllowanceResolver;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Phase 4 Task 12 — Admin Visibility.
 *
 * All 12 required tests, exercised at the real HTTP/route level
 * (role:super_admin middleware included) wherever the spec's own
 * wording implies an admin-facing endpoint, since this task is
 * specifically about what the admin API/UI exposes — unlike Tasks
 * 6-11, which mostly tested the service layer directly.
 */
class AdminVisibilityTest extends TestCase
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
            'slug' => 'task12-plan-'.Str::random(8),
            'label' => 'Task 12 Test Plan',
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

    // ---- 1. Super Admin can see key + binding state --------------------------------------------------------------

    public function test_super_admin_can_see_key_and_binding_state(): void
    {
        $account = $this->accountWithAllowance();
        [$key, $provisioned] = $this->provisionedKey($account, 'A', '203.0.113.1');
        $admin = $this->superAdmin();

        $response = $this->actingAs($admin)->getJson('/api/admin/api-access');

        $response->assertOk();
        $row = collect($response->json('data'))->firstWhere('id', $key->id);
        $this->assertNotNull($row);
        $this->assertSame($account->id, $row['account_id']);
        $this->assertSame($provisioned['binding']->status, $row['server_binding']['status']);
        $this->assertSame('203.0.113.1', $row['server_binding']['binding']['registered_ip']);
    }

    // ---- 2. Binding credential itself is never returned -----------------------------------------------------------

    public function test_binding_credential_is_never_returned(): void
    {
        $account = $this->accountWithAllowance();
        [$key, $provisioned] = $this->provisionedKey($account, 'A', '203.0.113.1');
        $admin = $this->superAdmin();

        $response = $this->actingAs($admin)->getJson('/api/admin/api-access');

        $response->assertOk();
        $body = $response->getContent();
        $this->assertStringNotContainsString($provisioned['credential'], $body);
        $this->assertStringNotContainsString('installation_hash', $body);
    }

    // ---- 3. Cooldown state is visible --------------------------------------------------------------------------

    public function test_cooldown_state_is_visible(): void
    {
        $account = $this->accountWithAllowance();
        [$key] = $this->provisionedKey($account, 'A', '203.0.113.1');
        $admin = $this->superAdmin();
        $until = now()->addDays(5);
        $this->service()->setCooldown($key->fresh(), $until);

        $response = $this->actingAs($admin)->getJson('/api/admin/api-access');

        $row = collect($response->json('data'))->firstWhere('id', $key->id);
        $this->assertTrue($row['server_binding']['in_cooldown']);
        $this->assertNotNull($row['server_binding']['cooldown_until']);
    }

    // ---- 4. Legacy deadline/state is visible where applicable -------------------------------------------------

    public function test_legacy_state_is_visible_where_applicable(): void
    {
        $account = $this->accountWithAllowance();
        $account->forceFill(['authorized_server_ip' => '203.0.113.10'])->save();
        $key = $this->makeKey($account, 'Legacy');
        // A base deadline (Task 5's own backfill) must exist before it can be
        // extended — effectiveLegacyDeadline() deliberately treats a NULL base
        // as nothing to extend (see ApiKeyBindingService's own docblock).
        $key->forceFill(['legacy_binding_grace_expires_at' => now()->addDays(3)])->save();
        $admin = $this->superAdmin();
        $deadline = now()->addDays(10);
        $this->service()->extendLegacyDeadline($key, $deadline, $admin, 'migration window');

        $response = $this->actingAs($admin)->getJson('/api/admin/api-access');

        $row = collect($response->json('data'))->firstWhere('id', $key->id);
        $this->assertTrue($row['server_binding']['legacy_ip_dependent']);
        $this->assertNotNull($row['server_binding']['legacy_deadline']);
        $this->assertSame('203.0.113.10', $row['server_binding']['legacy_authorized_ip']);
    }

    public function test_legacy_state_remains_visible_past_an_expired_deadline(): void
    {
        // Not automatically resolved — Task 12's own rule.
        $account = $this->accountWithAllowance();
        $account->forceFill(['authorized_server_ip' => '203.0.113.10'])->save();
        $key = $this->makeKey($account, 'Legacy');
        $key->forceFill(['legacy_binding_grace_expires_at' => now()->subDay()])->save();
        $admin = $this->superAdmin();

        $response = $this->actingAs($admin)->getJson('/api/admin/api-access');

        $row = collect($response->json('data'))->firstWhere('id', $key->id);
        $this->assertTrue($row['server_binding']['legacy_ip_dependent'], 'an expired deadline must not be reported as resolved.');
    }

    // ---- 5. Installation usage/allowance is correct ------------------------------------------------------------

    public function test_installation_usage_and_allowance_is_correct(): void
    {
        $account = $this->accountWithAllowance(3);
        $this->provisionedKey($account, 'A', '203.0.113.1');
        $this->provisionedKey($account, 'B', '203.0.113.2');
        $admin = $this->superAdmin();

        $response = $this->actingAs($admin)->getJson('/api/admin/api-access?account_id='.$account->id);

        $rows = $response->json('data');
        $this->assertNotEmpty($rows);
        foreach ($rows as $row) {
            $this->assertSame(2, $row['installation_usage']['live']);
            $this->assertSame(3, $row['installation_usage']['allowance']);
            $this->assertFalse($row['installation_usage']['at_or_over_allowance']);
        }
    }

    // ---- 6. Revoked historical bindings do not inflate the count ------------------------------------------------

    public function test_revoked_historical_bindings_do_not_inflate_the_count(): void
    {
        $account = $this->accountWithAllowance(3);
        [$key] = $this->provisionedKey($account, 'A', '203.0.113.1');
        $admin = $this->superAdmin();
        $this->service()->revoke($key->fresh(), $admin, 'done with this server');
        // Wait out the cooldown so a fresh provision is possible without touching Task 9's policy.
        \App\Models\ApiKey::whereKey($key->id)->update(['cooldown_until' => null]);
        $this->service()->provision($key->fresh(), ['ip_policy' => 'SINGLE_IP', 'authorized_ips' => ['203.0.113.9']]);

        $response = $this->actingAs($admin)->getJson('/api/admin/api-access?account_id='.$account->id);

        $row = collect($response->json('data'))->firstWhere('id', $key->id);
        $this->assertSame(1, $row['installation_usage']['live'], 'the revoked binding row must not be double-counted alongside the new live one.');
        $this->assertSame(1, ApiKeyBinding::where('api_key_id', $key->id)->where('status', ApiKeyBinding::STATUS_REVOKED)->count());
    }

    // ---- 7. Another account's binding cannot be accessed --------------------------------------------------------

    public function test_another_accounts_binding_cannot_be_accessed_through_account_scoping(): void
    {
        $accountA = $this->accountWithAllowance();
        $accountB = $this->accountWithAllowance();
        [$keyA] = $this->provisionedKey($accountA, 'A', '203.0.113.1');
        [$keyB] = $this->provisionedKey($accountB, 'B', '203.0.113.2');
        $admin = $this->superAdmin();

        $response = $this->actingAs($admin)->getJson('/api/admin/api-access?account_id='.$accountA->id);

        $ids = collect($response->json('data'))->pluck('id')->all();
        $this->assertContains($keyA->id, $ids);
        $this->assertNotContains($keyB->id, $ids, "filtering by account A's id must never also return account B's key.");
    }

    public function test_security_events_for_one_key_are_never_mixed_into_anothers_lookup(): void
    {
        $account = $this->accountWithAllowance();
        [$keyA] = $this->provisionedKey($account, 'A', '203.0.113.1');
        [$keyB] = $this->provisionedKey($account, 'B', '203.0.113.2');
        $admin = $this->superAdmin();
        $this->service()->revoke($keyA->fresh(), $admin, 'Key A only');

        $response = $this->actingAs($admin)->getJson("/api/admin/api-access/{$keyB->id}/events");

        $events = collect($response->json('data'))->pluck('event');
        $this->assertNotContains(ApiKeyBindingService::EV_BINDING_REVOKED, $events, "Key B's events lookup must never surface Key A's revoke.");
    }

    // ---- 8. Non-Super-Admin cannot access the admin endpoint -----------------------------------------------------

    public function test_a_non_super_admin_cannot_access_the_admin_index(): void
    {
        $account = $this->accountWithAllowance();
        $buyer = $this->plainUser($account);

        $response = $this->actingAs($buyer)->getJson('/api/admin/api-access');

        $response->assertForbidden();
    }

    public function test_a_non_super_admin_cannot_access_admin_events(): void
    {
        $account = $this->accountWithAllowance();
        [$key] = $this->provisionedKey($account, 'A', '203.0.113.1');
        $buyer = $this->plainUser($account);

        $response = $this->actingAs($buyer)->getJson("/api/admin/api-access/{$key->id}/events");

        $response->assertForbidden();
    }

    // ---- 9. Security events are visible without sensitive payload leakage ----------------------------------------

    public function test_security_events_are_visible_without_sensitive_payload_leakage(): void
    {
        $account = $this->accountWithAllowance();
        [$key, $provisioned] = $this->provisionedKey($account, 'A', '203.0.113.1');
        $admin = $this->superAdmin();
        $this->service()->revoke($key->fresh(), $admin, 'cleanup');

        $response = $this->actingAs($admin)->getJson("/api/admin/api-access/{$key->id}/events");

        $response->assertOk();
        $events = $response->json('data');
        $this->assertNotEmpty($events);
        $body = $response->getContent();
        $this->assertStringNotContainsString($provisioned['credential'], $body);
        $this->assertStringNotContainsString('installation_hash', $body);
        $this->assertStringNotContainsString('secret_hash', $body);
        $this->assertStringNotContainsString('key_hash', $body);
    }

    // ---- 10. Existing admin actions remain functional and correctly scoped ---------------------------------------

    public function test_existing_admin_actions_remain_functional(): void
    {
        $account = $this->accountWithAllowance();
        [$key] = $this->provisionedKey($account, 'A', '203.0.113.1');
        $admin = $this->superAdmin();

        $this->actingAs($admin)->postJson("/api/admin/api-access/{$key->id}/disable")->assertOk();
        $this->assertTrue($key->fresh()->isAccessDisabled());

        $this->actingAs($admin)->postJson("/api/admin/api-access/{$key->id}/enable")->assertOk();
        $this->assertFalse($key->fresh()->isAccessDisabled());

        $this->actingAs($admin)->postJson("/api/admin/api-access/{$key->id}/revoke")->assertOk();
        $this->assertNull($key->fresh()->liveBinding());
    }

    public function test_admin_destroy_action_is_scoped_and_delegates_to_the_existing_destroyKey(): void
    {
        $account = $this->accountWithAllowance();
        [$key] = $this->provisionedKey($account, 'A', '203.0.113.1');
        $admin = $this->superAdmin();

        $response = $this->actingAs($admin)->deleteJson("/api/admin/api-access/{$key->id}");

        $response->assertOk();
        $this->assertNotNull($key->fresh()->revoked_at);
        $this->assertNull($key->fresh()->liveBinding());
    }

    public function test_a_non_super_admin_cannot_call_admin_destroy(): void
    {
        $account = $this->accountWithAllowance();
        [$key] = $this->provisionedKey($account, 'A', '203.0.113.1');
        $buyer = $this->plainUser($account);

        $response = $this->actingAs($buyer)->deleteJson("/api/admin/api-access/{$key->id}");

        $response->assertForbidden();
        $this->assertNull($key->fresh()->revoked_at);
    }

    // ---- 11. Cooldown override remains Super Admin-only and per-key ----------------------------------------------

    public function test_cooldown_override_route_is_super_admin_only_and_per_key(): void
    {
        $account = $this->accountWithAllowance();
        [$keyA] = $this->provisionedKey($account, 'A', '203.0.113.1');
        [$keyB] = $this->provisionedKey($account, 'B', '203.0.113.2');
        $admin = $this->superAdmin();
        $buyer = $this->plainUser($account);
        $this->service()->setCooldown($keyA->fresh(), now()->addDays(10));
        $this->service()->setCooldown($keyB->fresh(), now()->addDays(10));

        $this->actingAs($buyer)->postJson("/api/admin/api-access/{$keyA->id}/cooldown", ['reason' => 'self-service attempt'])
            ->assertForbidden();
        $this->assertNotNull($keyA->fresh()->cooldown_until, 'a non-Super-Admin request must never change the stored cooldown.');

        $this->actingAs($admin)->postJson("/api/admin/api-access/{$keyA->id}/cooldown", ['reason' => 'approved early reconnect'])
            ->assertOk();

        $this->assertNull($keyA->fresh()->cooldown_until, "Key A's cooldown must be cleared.");
        $this->assertNotNull($keyB->fresh()->cooldown_until, "Key B's cooldown must be untouched by Key A's override.");
    }

    // ---- 12. API response does not contain secret hashes or installation hashes -----------------------------------

    public function test_admin_index_response_never_contains_hashes(): void
    {
        $account = $this->accountWithAllowance();
        $this->provisionedKey($account, 'A', '203.0.113.1');
        $admin = $this->superAdmin();

        $response = $this->actingAs($admin)->getJson('/api/admin/api-access');

        $response->assertOk();
        $body = $response->getContent();
        $this->assertStringNotContainsString('secret_hash', $body);
        $this->assertStringNotContainsString('key_hash', $body);
        $this->assertStringNotContainsString('installation_hash', $body);
    }
}
