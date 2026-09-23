<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Phase 1 Foundation, Task 2 — regression coverage for the 3-Tier
 * Hierarchy/Agent-Client scope engine (AccountController) and the
 * module.guard extension (Task 8). Exercises existing, unmodified
 * behavior only; introduces no new authorization logic.
 *
 * Phase 2 (Agent/Reseller foundation) — the Agent Foundation this phase
 * asks for (Super Admin -> Agent, Agent -> own customers, strict
 * cross-agent isolation, Agent customer ownership) was already fully
 * implemented by the pre-existing 3-Tier Hierarchy engine above; the
 * four tests appended below close the one real gap found on inspection:
 * cross-agent isolation was only ever tested against the read (show)
 * endpoint, never against a mutating endpoint, even though
 * AccountController::update()/updateSubscription() already reuse the
 * exact same assertCallerCanAccessAccount() guard.
 */
class TenantIsolationAndRbacTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
    }

    private function makeSuperAdmin(): User
    {
        $user = User::factory()->create(['account_id' => null]);
        $user->assignRole('super_admin');

        return $user;
    }

    private function makeAgentAdmin(Account $agentAccount): User
    {
        $user = User::factory()->create(['account_id' => $agentAccount->id]);
        $user->assignRole(['admin', 'agent']);

        return $user;
    }

    private function makePlainAdmin(Account $account): User
    {
        $user = User::factory()->create(['account_id' => $account->id]);
        $user->assignRole('admin');

        return $user;
    }

    public function test_super_admin_sees_every_account(): void
    {
        Account::factory()->count(3)->create();
        $superAdmin = $this->makeSuperAdmin();

        $response = $this->actingAs($superAdmin)->getJson('/api/admin/accounts?per_page=50');

        $response->assertOk();
        $rows = $response->json('data.data') ?? $response->json('data');
        $this->assertGreaterThanOrEqual(3, count($rows));
    }

    public function test_agent_only_sees_its_own_subclients(): void
    {
        $agentAccountA = Account::factory()->agent()->create();
        $agentAccountB = Account::factory()->agent()->create();
        Account::factory()->count(2)->client($agentAccountA)->create();
        Account::factory()->count(3)->client($agentAccountB)->create();

        $agentUserA = $this->makeAgentAdmin($agentAccountA);

        $response = $this->actingAs($agentUserA)->getJson('/api/admin/accounts?per_page=50');

        $response->assertOk();
        $rows = $response->json('data.data') ?? $response->json('data');
        $agentIds = collect($rows)->pluck('agent_id')->unique();

        $this->assertEqualsCanonicalizing([$agentAccountA->id], $agentIds->all());
    }

    public function test_agent_cannot_access_another_agents_subclient(): void
    {
        $agentAccountA = Account::factory()->agent()->create();
        $agentAccountB = Account::factory()->agent()->create();
        $clientOfB = Account::factory()->client($agentAccountB)->create();

        $agentUserA = $this->makeAgentAdmin($agentAccountA);

        $response = $this->actingAs($agentUserA)->getJson("/api/admin/accounts/{$clientOfB->id}");

        $response->assertNotFound();
    }

    public function test_plain_client_admin_cannot_reach_admin_accounts_endpoint(): void
    {
        $account = Account::factory()->create();
        $plainAdmin = $this->makePlainAdmin($account);

        $response = $this->actingAs($plainAdmin)->getJson('/api/admin/accounts');

        $response->assertForbidden();
    }

    public function test_client_admin_cannot_grant_a_non_whitelisted_permission_via_team_matrix(): void
    {
        $account = Account::factory()->create();
        \App\Models\Subscription::factory()->for($account)->create();
        $admin = $this->makePlainAdmin($account);
        $teamMember = User::factory()->create(['account_id' => $account->id]);

        $response = $this->actingAs($admin)->patchJson(
            "/api/team/users/{$teamMember->id}/permissions",
            ['permissions' => ['manage-roles']]
        );

        $response->assertStatus(422);
        $this->assertFalse($teamMember->fresh()->can('manage-roles'));
    }

    public function test_module_guard_blocks_a_disabled_module_even_with_the_matching_permission(): void
    {
        $account = Account::factory()->create([
            'allowed_modules' => array_values(array_diff(Account::MODULES, ['team_management'])),
        ]);
        $admin = $this->makePlainAdmin($account);

        $response = $this->actingAs($admin)->getJson('/api/team/users');

        $response->assertStatus(403);
        $response->assertJson(['error_code' => 'MODULE_DISABLED']);
    }

    public function test_module_guard_allows_an_enabled_module(): void
    {
        $account = Account::factory()->create(['allowed_modules' => null]);
        $admin = $this->makePlainAdmin($account);

        $response = $this->actingAs($admin)->getJson('/api/team/users');

        $response->assertOk();
    }

    public function test_super_admin_bypasses_module_guard(): void
    {
        $account = Account::factory()->create([
            'allowed_modules' => array_values(array_diff(Account::MODULES, ['team_management'])),
        ]);
        $superAdmin = $this->makeSuperAdmin();

        $response = $this->actingAs($superAdmin)
            ->getJson("/api/team/users?account_id={$account->id}");

        $response->assertOk();
    }

    // --- Phase 2, Agent Foundation gap coverage -----------------------

    // Super Admin can access Agent customers (a specific sub-client
    // record, not just the aggregate list already covered above).
    public function test_super_admin_can_view_a_specific_agents_subclient(): void
    {
        $agentAccount = Account::factory()->agent()->create();
        $subClient = Account::factory()->client($agentAccount)->create();
        $superAdmin = $this->makeSuperAdmin();

        $response = $this->actingAs($superAdmin)->getJson("/api/admin/accounts/{$subClient->id}");

        $response->assertOk();
        $response->assertJsonPath('id', $subClient->id);
        $response->assertJsonPath('agent_id', $agentAccount->id);
    }

    // Agent can access its own customer.
    public function test_agent_can_view_its_own_subclient(): void
    {
        $agentAccount = Account::factory()->agent()->create();
        $ownClient = Account::factory()->client($agentAccount)->create();
        $agentUser = $this->makeAgentAdmin($agentAccount);

        $response = $this->actingAs($agentUser)->getJson("/api/admin/accounts/{$ownClient->id}");

        $response->assertOk();
        $response->assertJsonPath('id', $ownClient->id);
    }

    // Agent cannot manipulate another Agent's customer — update() reuses
    // the same assertCallerCanAccessAccount() guard as show(); this
    // proves the guard actually blocks a WRITE, not just a read.
    public function test_agent_cannot_update_another_agents_subclient(): void
    {
        $agentAccountA = Account::factory()->agent()->create();
        $agentAccountB = Account::factory()->agent()->create();
        $clientOfB = Account::factory()->client($agentAccountB)->create(['company_name' => 'Original Co']);

        $agentUserA = $this->makeAgentAdmin($agentAccountA);

        $response = $this->actingAs($agentUserA)->putJson(
            "/api/admin/accounts/{$clientOfB->id}",
            ['company_name' => 'Hijacked Co']
        );

        $response->assertNotFound();
        $this->assertSame('Original Co', $clientOfB->fresh()->company_name);
    }

    // Same guard, exercised on the subscription-mutation endpoint
    // (updateSubscription()) rather than the account-fields endpoint —
    // both reuse assertCallerCanAccessAccount(), and both must actually
    // block the write, not merely the read.
    public function test_agent_cannot_update_another_agents_subclient_subscription(): void
    {
        $agentAccountA = Account::factory()->agent()->create();
        $agentAccountB = Account::factory()->agent()->create();
        $clientOfB = Account::factory()->client($agentAccountB)->create();
        $subscription = \App\Models\Subscription::factory()->for($clientOfB)->create([
            'total_allocated_messages' => 500,
        ]);

        $agentUserA = $this->makeAgentAdmin($agentAccountA);

        $response = $this->actingAs($agentUserA)->putJson(
            "/api/admin/accounts/{$clientOfB->id}/subscription",
            ['total_allocated_messages' => 999999]
        );

        $response->assertNotFound();
        $this->assertSame(500, $subscription->fresh()->total_allocated_messages);
    }

    // --- Agent -> Customer lifecycle hardening -------------------------

    /**
     * Minimal valid POST /api/admin/accounts payload (store()'s required
     * fields only) — a single place to keep in sync with
     * AccountController::store()'s validation rules rather than
     * duplicating the field list in every test below.
     */
    private function validAccountCreationPayload(array $overrides = []): array
    {
        return array_merge([
            'company_name' => 'Test Co '.uniqid(),
            'admin_name' => 'Test Admin',
            'admin_email' => 'admin-'.uniqid().'@example.com',
            'admin_password' => 'password123',
            'engine_type' => 'qr',
            'billing_model' => 'flat_quota',
            'total_allocated_messages' => 500,
            'price_paid' => 499,
            'payment_mode' => 'cash',
            'starts_at' => now()->toDateString(),
            'expires_at' => now()->addDays(30)->toDateString(),
            'module_assignment' => 'whatsapp_messaging',
        ], $overrides);
    }

    // 1. Agent creates client successfully.
    public function test_agent_creates_a_client_successfully(): void
    {
        $agentAccount = Account::factory()->agent()->create();
        $agentUser = $this->makeAgentAdmin($agentAccount);

        $response = $this->actingAs($agentUser)->postJson(
            '/api/admin/accounts',
            $this->validAccountCreationPayload()
        );

        $response->assertCreated();
        $response->assertJsonPath('account_type', 'client');
        $response->assertJsonPath('agent_id', $agentAccount->id);
        $this->assertDatabaseHas('accounts', [
            'id' => $response->json('id'),
            'account_type' => 'client',
            'agent_id' => $agentAccount->id,
        ]);
    }

    // 2. Agent cannot create an Agent account — a submitted account_type
    // is never trusted from an Agent caller (store() only validates/
    // reads account_type at all for Super Admin); the created account
    // is always forced to 'client'.
    public function test_agent_cannot_create_agent_account(): void
    {
        $agentAccount = Account::factory()->agent()->create();
        $agentUser = $this->makeAgentAdmin($agentAccount);

        $response = $this->actingAs($agentUser)->postJson(
            '/api/admin/accounts',
            $this->validAccountCreationPayload(['account_type' => 'agent'])
        );

        $response->assertCreated();
        $response->assertJsonPath('account_type', 'client');
        $this->assertDatabaseHas('accounts', [
            'id' => $response->json('id'),
            'account_type' => 'client',
        ]);
    }

    // 3. Agent cannot spoof another Agent's agent_id — a submitted
    // agent_id is never trusted from an Agent caller; the created
    // customer is always forced under the CALLER's own account_id.
    public function test_agent_cannot_spoof_another_agents_agent_id(): void
    {
        $agentAccountA = Account::factory()->agent()->create();
        $agentAccountB = Account::factory()->agent()->create();
        $agentUserA = $this->makeAgentAdmin($agentAccountA);

        $response = $this->actingAs($agentUserA)->postJson(
            '/api/admin/accounts',
            $this->validAccountCreationPayload(['agent_id' => $agentAccountB->id])
        );

        $response->assertCreated();
        $response->assertJsonPath('agent_id', $agentAccountA->id);
        $this->assertDatabaseHas('accounts', [
            'id' => $response->json('id'),
            'agent_id' => $agentAccountA->id,
        ]);
        $this->assertDatabaseMissing('accounts', [
            'id' => $response->json('id'),
            'agent_id' => $agentAccountB->id,
        ]);
    }

    // 4. Agent updates its own customer (positive counterpart of
    // test_agent_cannot_update_another_agents_subclient above).
    public function test_agent_updates_own_customer_successfully(): void
    {
        $agentAccount = Account::factory()->agent()->create();
        $ownClient = Account::factory()->client($agentAccount)->create(['company_name' => 'Old Name']);
        $agentUser = $this->makeAgentAdmin($agentAccount);

        $response = $this->actingAs($agentUser)->putJson(
            "/api/admin/accounts/{$ownClient->id}",
            ['company_name' => 'New Name']
        );

        $response->assertOk();
        $this->assertSame('New Name', $ownClient->fresh()->company_name);
    }

    // 6. Agent lifecycle/deactivation of its own customer. No dedicated
    // delete/deactivate endpoint exists for accounts (grepped
    // routes/api.php — the only account-lifecycle route beyond store()
    // is the generic update() PUT, whose `status` field is the existing
    // deactivation mechanism: self::ACCOUNT_STATUSES includes
    // 'suspended'). Reuses the same update() guard as every other test
    // in this class; no new endpoint added.
    public function test_agent_can_deactivate_its_own_customer(): void
    {
        $agentAccount = Account::factory()->agent()->create();
        $ownClient = Account::factory()->client($agentAccount)->create(['status' => 'active']);
        $agentUser = $this->makeAgentAdmin($agentAccount);

        $response = $this->actingAs($agentUser)->putJson(
            "/api/admin/accounts/{$ownClient->id}",
            ['status' => 'suspended']
        );

        $response->assertOk();
        $this->assertSame('suspended', $ownClient->fresh()->status);
    }

    // 7. Cross-agent lifecycle (deactivation) action returns 404 and
    // does not mutate data — same assertCallerCanAccessAccount() guard,
    // exercised specifically against the status/deactivation field.
    public function test_agent_cannot_deactivate_another_agents_customer(): void
    {
        $agentAccountA = Account::factory()->agent()->create();
        $agentAccountB = Account::factory()->agent()->create();
        $clientOfB = Account::factory()->client($agentAccountB)->create(['status' => 'active']);
        $agentUserA = $this->makeAgentAdmin($agentAccountA);

        $response = $this->actingAs($agentUserA)->putJson(
            "/api/admin/accounts/{$clientOfB->id}",
            ['status' => 'suspended']
        );

        $response->assertNotFound();
        $this->assertSame('active', $clientOfB->fresh()->status);
    }

    // 8. Super Admin behavior remains unchanged: still able to create
    // both a direct client (no agent) and an Agent account itself,
    // exactly as store() already allowed before this hardening pass.
    // 1. Agent can manage/sell a plan for its own customer via the
    // dedicated subscription endpoint — positive counterpart of
    // test_agent_cannot_update_another_agents_subclient_subscription
    // above, which only ever exercised the negative (cross-agent) case
    // for this specific endpoint. Reuses the exact same
    // assertCallerCanAccessAccount() guard as every other {id}-addressed
    // mutation in this class; no new authorization logic involved.
    public function test_agent_updates_its_own_customers_subscription_successfully(): void
    {
        $agentAccount = Account::factory()->agent()->create();
        $ownClient = Account::factory()->client($agentAccount)->create();
        $subscription = \App\Models\Subscription::factory()->for($ownClient)->create([
            'total_allocated_messages' => 500,
        ]);
        $agentUser = $this->makeAgentAdmin($agentAccount);

        $response = $this->actingAs($agentUser)->putJson(
            "/api/admin/accounts/{$ownClient->id}/subscription",
            ['total_allocated_messages' => 750]
        );

        $response->assertOk();
        $this->assertSame(750, $subscription->fresh()->total_allocated_messages);
    }

    public function test_super_admin_creates_a_direct_client_and_an_agent_account_successfully(): void
    {
        $superAdmin = $this->makeSuperAdmin();

        $directClientResponse = $this->actingAs($superAdmin)->postJson(
            '/api/admin/accounts',
            $this->validAccountCreationPayload()
        );

        $directClientResponse->assertCreated();
        $directClientResponse->assertJsonPath('account_type', 'client');
        $directClientResponse->assertJsonPath('agent_id', null);

        $agentResponse = $this->actingAs($superAdmin)->postJson(
            '/api/admin/accounts',
            $this->validAccountCreationPayload(['account_type' => 'agent'])
        );

        $agentResponse->assertCreated();
        $agentResponse->assertJsonPath('account_type', 'agent');
        $agentResponse->assertJsonPath('agent_id', null);
    }
}
