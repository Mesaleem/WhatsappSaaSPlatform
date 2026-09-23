<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\AgentSellingEntitlement;
use App\Models\Capability;
use App\Models\Subscription;
use App\Models\User;
use Database\Seeders\Phase1FoundationSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Phase 1 Foundation, Task 12 — Agent Selling Entitlements: which
 * Capabilities an Agent may resell to its own sub-clients, kept
 * separate from Spatie's Agent role permissions and from the Agent's
 * own account_entitlements (see AgentSellingEntitlement migration's
 * docblock for the locked "3 separate dimensions" decision).
 */
class AgentSellingEntitlementTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        $this->seed(Phase1FoundationSeeder::class);
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

    /** Agent account with its OWN 'crm' entitlement already granted (Meta engine, which supports crm). */
    private function makeAgentThatHoldsCrm(): Account
    {
        $agentAccount = Account::factory()->agent()->create();
        Subscription::factory()->for($agentAccount)->create(['engine_type' => 'meta']);
        $capability = Capability::where('slug', 'crm')->firstOrFail();
        $agentAccount->entitlements()->create(['capability_id' => $capability->id, 'source' => 'manual_grant']);

        return $agentAccount;
    }

    // 1. Super Admin can grant Agent selling entitlement.
    public function test_super_admin_can_grant_agent_selling_entitlement(): void
    {
        $agentAccount = $this->makeAgentThatHoldsCrm();
        $superAdmin = $this->makeSuperAdmin();

        $response = $this->actingAs($superAdmin)->postJson(
            "/api/admin/accounts/{$agentAccount->id}/selling-entitlements",
            ['capability' => 'crm']
        );

        $response->assertOk();
        $this->assertDatabaseHas('agent_selling_entitlements', [
            'agent_account_id' => $agentAccount->id,
            'capability_id' => Capability::where('slug', 'crm')->value('id'),
            'sellable' => 1,
        ]);
    }

    // 2. Super Admin can revoke it.
    public function test_super_admin_can_revoke_agent_selling_entitlement(): void
    {
        $agentAccount = $this->makeAgentThatHoldsCrm();
        $capability = Capability::where('slug', 'crm')->firstOrFail();
        AgentSellingEntitlement::create(['agent_account_id' => $agentAccount->id, 'capability_id' => $capability->id, 'sellable' => true]);
        $superAdmin = $this->makeSuperAdmin();

        $response = $this->actingAs($superAdmin)->deleteJson(
            "/api/admin/accounts/{$agentAccount->id}/selling-entitlements/crm"
        );

        $response->assertOk();
        $this->assertDatabaseMissing('agent_selling_entitlements', [
            'agent_account_id' => $agentAccount->id,
            'capability_id' => $capability->id,
        ]);
    }

    // 3. Agent can see only its own selling entitlements.
    public function test_agent_sees_only_its_own_selling_entitlements(): void
    {
        $agentAccountA = $this->makeAgentThatHoldsCrm();
        $capability = Capability::where('slug', 'crm')->firstOrFail();
        AgentSellingEntitlement::create(['agent_account_id' => $agentAccountA->id, 'capability_id' => $capability->id, 'sellable' => true]);
        $agentUserA = $this->makeAgentAdmin($agentAccountA);

        $response = $this->actingAs($agentUserA)->getJson("/api/admin/accounts/{$agentAccountA->id}/selling-entitlements");

        $response->assertOk();
        $this->assertCount(1, $response->json('data'));
        $this->assertSame('crm', $response->json('data.0.capability.slug'));
    }

    // 4. Agent cannot grant a capability it does not possess as a selling entitlement.
    public function test_super_admin_cannot_grant_a_selling_entitlement_the_agent_does_not_itself_hold(): void
    {
        $agentAccount = Account::factory()->agent()->create();
        Subscription::factory()->for($agentAccount)->create(['engine_type' => 'meta']);
        // Deliberately no account_entitlements grant for this Agent.
        $superAdmin = $this->makeSuperAdmin();

        $response = $this->actingAs($superAdmin)->postJson(
            "/api/admin/accounts/{$agentAccount->id}/selling-entitlements",
            ['capability' => 'crm']
        );

        $response->assertStatus(422);
        $this->assertDatabaseMissing('agent_selling_entitlements', [
            'agent_account_id' => $agentAccount->id,
        ]);
    }

    // 4b. And the reverse: an Agent that IS authorized to sell CANNOT
    // grant a capability it holds no selling entitlement for at all.
    public function test_agent_cannot_grant_a_capability_it_has_no_selling_entitlement_for(): void
    {
        $agentAccount = $this->makeAgentThatHoldsCrm();
        // No AgentSellingEntitlement row created — Super Admin never authorized resale.
        $subClient = Account::factory()->client($agentAccount)->create();
        Subscription::factory()->for($subClient)->create(['engine_type' => 'meta']);
        $agentUser = $this->makeAgentAdmin($agentAccount);

        $response = $this->actingAs($agentUser)->postJson(
            "/api/admin/accounts/{$subClient->id}/entitlements",
            ['capability' => 'crm']
        );

        $response->assertForbidden();
        $this->assertDatabaseMissing('account_entitlements', [
            'account_id' => $subClient->id,
        ]);
    }

    // 5. Agent cannot sell/grant a capability outside its allowed provider capability.
    public function test_agent_cannot_grant_a_capability_unsupported_by_the_subclients_provider(): void
    {
        $agentAccount = $this->makeAgentThatHoldsCrm();
        /*
         * Phase 5 Task 9 FOLLOW-UP: 'crm' is no longer QR-incompatible
         * (CRM is engine-agnostic; the confirmed matrix bundles it into
         * the QR-engine Growth plan). 'journey_automation' is the
         * standing QR-incompatible example now, and the rule under test
         * — an Agent cannot grant past the sub-client's provider — is
         * unchanged. The Agent is given the matching selling entitlement
         * so the ONLY thing left that can refuse the grant is the
         * provider check.
         *
         * Phase 7 Task 1.5: journey_automation became QR-supported (owner
         * decision), so the example is now 'ads' — still a QR refusal.
         */
        $capability = Capability::where('slug', 'ads')->firstOrFail();
        AgentSellingEntitlement::create(['agent_account_id' => $agentAccount->id, 'capability_id' => $capability->id, 'sellable' => true]);
        $agentAccount->entitlements()->firstOrCreate(
            ['capability_id' => $capability->id],
            ['source' => 'manual_grant'],
        );

        $subClient = Account::factory()->client($agentAccount)->create();
        Subscription::factory()->for($subClient)->create(['engine_type' => 'qr']);
        $agentUser = $this->makeAgentAdmin($agentAccount);

        $response = $this->actingAs($agentUser)->postJson(
            "/api/admin/accounts/{$subClient->id}/entitlements",
            ['capability' => 'ads']
        );

        $response->assertStatus(422);
        $this->assertDatabaseMissing('account_entitlements', [
            'account_id' => $subClient->id,
        ]);
    }

    // Positive counterpart of 4/5: an Agent authorized to sell CAN grant
    // to its own sub-client when the provider also supports it.
    public function test_agent_can_grant_an_authorized_capability_to_its_own_subclient(): void
    {
        $agentAccount = $this->makeAgentThatHoldsCrm();
        $capability = Capability::where('slug', 'crm')->firstOrFail();
        AgentSellingEntitlement::create(['agent_account_id' => $agentAccount->id, 'capability_id' => $capability->id, 'sellable' => true]);

        $subClient = Account::factory()->client($agentAccount)->create();
        Subscription::factory()->for($subClient)->create(['engine_type' => 'meta']);
        $agentUser = $this->makeAgentAdmin($agentAccount);

        $response = $this->actingAs($agentUser)->postJson(
            "/api/admin/accounts/{$subClient->id}/entitlements",
            ['capability' => 'crm']
        );

        $response->assertOk();
        $this->assertDatabaseHas('account_entitlements', [
            'account_id' => $subClient->id,
            'capability_id' => $capability->id,
            'source' => 'agent_delegated',
            'granted_by_account_id' => $agentAccount->id,
        ]);
    }

    // 6. Agent A cannot access Agent B's selling entitlements.
    public function test_agent_a_cannot_access_agent_bs_selling_entitlements(): void
    {
        $agentAccountA = $this->makeAgentThatHoldsCrm();
        $agentAccountB = $this->makeAgentThatHoldsCrm();
        $capability = Capability::where('slug', 'crm')->firstOrFail();
        AgentSellingEntitlement::create(['agent_account_id' => $agentAccountB->id, 'capability_id' => $capability->id, 'sellable' => true]);
        $agentUserA = $this->makeAgentAdmin($agentAccountA);

        $response = $this->actingAs($agentUserA)->getJson("/api/admin/accounts/{$agentAccountB->id}/selling-entitlements");

        $response->assertNotFound();
    }

    // 7. Revoked selling entitlement immediately prevents further grant/sale.
    public function test_revoking_a_selling_entitlement_immediately_blocks_further_grants(): void
    {
        $agentAccount = $this->makeAgentThatHoldsCrm();
        $capability = Capability::where('slug', 'crm')->firstOrFail();
        AgentSellingEntitlement::create(['agent_account_id' => $agentAccount->id, 'capability_id' => $capability->id, 'sellable' => true]);
        $subClient = Account::factory()->client($agentAccount)->create();
        Subscription::factory()->for($subClient)->create(['engine_type' => 'meta']);
        $agentUser = $this->makeAgentAdmin($agentAccount);
        $superAdmin = $this->makeSuperAdmin();

        // Revoke it.
        $this->actingAs($superAdmin)
            ->deleteJson("/api/admin/accounts/{$agentAccount->id}/selling-entitlements/crm")
            ->assertOk();

        // Agent's next grant attempt must now fail.
        $response = $this->actingAs($agentUser)->postJson(
            "/api/admin/accounts/{$subClient->id}/entitlements",
            ['capability' => 'crm']
        );

        $response->assertForbidden();
        $this->assertDatabaseMissing('account_entitlements', [
            'account_id' => $subClient->id,
        ]);
    }

    // 8. Unauthorized user receives 403.
    public function test_plain_client_admin_is_forbidden_from_selling_entitlement_endpoints(): void
    {
        $agentAccount = $this->makeAgentThatHoldsCrm();
        $unrelatedAccount = Account::factory()->create();
        $plainAdmin = $this->makePlainAdmin($unrelatedAccount);

        $response = $this->actingAs($plainAdmin)->postJson(
            "/api/admin/accounts/{$agentAccount->id}/selling-entitlements",
            ['capability' => 'crm']
        );

        $response->assertForbidden();
    }
}
