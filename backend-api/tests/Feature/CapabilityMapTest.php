<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Capability;
use App\Models\User;
use App\Services\Access\AccessControlService;
use Database\Seeders\Phase1FoundationSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Phase 1 Foundation, Task 9 — GET /api/auth/me exposes a `capabilities`
 * map driven by AccessControlService, additive alongside the existing
 * `account.effective_modules`.
 *
 * Query-count regression tests below guard AccessControlService::
 * capabilityMap()'s single-query implementation (Capability::pluck() for
 * Super Admin / no-account; one LEFT JOIN against account_entitlements
 * otherwise) against ever regressing into a per-capability query loop.
 */
class CapabilityMapTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        $this->seed(Phase1FoundationSeeder::class);
    }

    public function test_super_admin_holds_every_seeded_capability(): void
    {
        $superAdmin = User::factory()->create(['account_id' => null]);
        $superAdmin->assignRole('super_admin');

        $response = $this->actingAs($superAdmin)->getJson('/api/auth/me');

        $response->assertOk();
        $capabilities = $response->json('user.capabilities');
        $this->assertNotEmpty($capabilities);
        $this->assertTrue(collect($capabilities)->every(fn (bool $v) => $v === true));
    }

    public function test_a_tenant_with_no_entitlements_holds_no_capabilities(): void
    {
        $account = Account::factory()->create();
        $admin = User::factory()->create(['account_id' => $account->id]);
        $admin->assignRole('admin');

        $response = $this->actingAs($admin)->getJson('/api/auth/me');

        $response->assertOk();
        $capabilities = $response->json('user.capabilities');
        $this->assertNotEmpty($capabilities);
        $this->assertTrue(collect($capabilities)->every(fn (bool $v) => $v === false));
    }

    public function test_a_tenant_with_a_granted_entitlement_holds_only_that_capability(): void
    {
        $account = Account::factory()->create();
        $capability = \App\Models\Capability::where('slug', 'crm')->firstOrFail();
        $account->entitlements()->create(['capability_id' => $capability->id, 'source' => 'manual_grant']);
        $admin = User::factory()->create(['account_id' => $account->id]);
        $admin->assignRole('admin');

        $response = $this->actingAs($admin)->getJson('/api/auth/me');

        $response->assertOk();
        $capabilities = $response->json('user.capabilities');
        $this->assertTrue($capabilities['crm']);
        $this->assertFalse($capabilities['ads']);
    }

    /**
     * Query-count regression guard for a tenant account. Preloads
     * `account`/`roles` exactly as AuthController::formatUser() does
     * before calling capabilityMap(), so the query log below captures
     * only what capabilityMap() itself runs. Seeds extra capabilities
     * specifically so a reintroduced per-capability loop would make the
     * query count scale with capability count and fail this assertion.
     */
    public function test_capability_map_runs_a_single_query_for_a_tenant_regardless_of_capability_count(): void
    {
        $account = Account::factory()->create();
        $capability = Capability::where('slug', 'crm')->firstOrFail();
        $account->entitlements()->create(['capability_id' => $capability->id, 'source' => 'manual_grant']);
        $admin = User::factory()->create(['account_id' => $account->id]);
        $admin->assignRole('admin');

        for ($i = 0; $i < 10; $i++) {
            Capability::create(['slug' => "extra_capability_{$i}", 'label' => "Extra {$i}", 'category' => 'test']);
        }

        $admin->load(['account', 'roles.permissions']);

        DB::enableQueryLog();
        $map = app(AccessControlService::class)->capabilityMap($admin);
        $queryCount = count(DB::getQueryLog());
        DB::disableQueryLog();

        $this->assertGreaterThanOrEqual(17, count($map));
        $this->assertTrue($map['crm']);
        $this->assertSame(1, $queryCount, 'capabilityMap() must run a single query for a tenant account, not one per capability.');
    }

    /**
     * Same guard for the Super Admin branch (Capability::pluck('slug')).
     */
    public function test_capability_map_runs_a_single_query_for_super_admin_regardless_of_capability_count(): void
    {
        $superAdmin = User::factory()->create(['account_id' => null]);
        $superAdmin->assignRole('super_admin');

        for ($i = 0; $i < 10; $i++) {
            Capability::create(['slug' => "extra_super_capability_{$i}", 'label' => "Extra {$i}", 'category' => 'test']);
        }

        $superAdmin->load(['roles.permissions']);

        DB::enableQueryLog();
        $map = app(AccessControlService::class)->capabilityMap($superAdmin);
        $queryCount = count(DB::getQueryLog());
        DB::disableQueryLog();

        $this->assertGreaterThanOrEqual(17, count($map));
        $this->assertTrue(collect($map)->every(fn (bool $v) => $v === true));
        $this->assertSame(1, $queryCount, 'capabilityMap() must run a single query for Super Admin, not one per capability.');
    }
}
