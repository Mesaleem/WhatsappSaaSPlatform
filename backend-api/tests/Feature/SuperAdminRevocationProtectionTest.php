<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\AccountEntitlement;
use App\Models\ActivityLog;
use App\Models\AgentSellingEntitlement;
use App\Models\Capability;
use App\Models\Subscription;
use App\Models\User;
use App\Services\Access\AccessControlService;
use App\Services\Access\EntitlementAuditLogger;
use Database\Seeders\Phase1FoundationSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Phase 5 fix P5-11 — an Agent cannot undo a Super Admin's revocation.
 *
 * Before: revokeEntitlement() marks the row revoked (revoked_at,
 * revoked_by_user_id, revoked_reason = manual) — the same representation
 * whoever revoked it — and grantEntitlement()'s updateOrCreate cleared those
 * columns for ANY caller allowed to grant, so an Agent authorized to sell the
 * capability silently restored what the Super Admin had taken away.
 *
 * Now (server-side, from the stored row only): an Agent may undo only a
 * revocation made by a user of its OWN account (or a plan-downgrade
 * revocation, unchanged); a Super Admin's — or any other manual revocation —
 * is refused with 403 ENTITLEMENT_REVOKED_BY_SUPER_ADMIN and a P5-8 `denied`
 * audit row. A Super Admin revoke also claims an already-revoked row.
 */
class SuperAdminRevocationProtectionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        $this->seed(Phase1FoundationSeeder::class);
    }

    // ------------------------------------------------------------------ fixtures

    private function superAdmin(): User
    {
        $user = User::factory()->create(['account_id' => null]);
        $user->assignRole('super_admin');

        return $user;
    }

    /** An Agent (Meta engine) that holds `crm` and `ai` and is authorized to sell both. */
    private function agent(): Account
    {
        $agent = Account::factory()->agent()->create();
        Subscription::factory()->for($agent)->create(['engine_type' => 'meta']);

        foreach (['crm', 'ai'] as $slug) {
            $capability = Capability::where('slug', $slug)->firstOrFail();
            $agent->entitlements()->create(['capability_id' => $capability->id, 'source' => 'manual_grant']);
            AgentSellingEntitlement::create(['agent_account_id' => $agent->id, 'capability_id' => $capability->id, 'sellable' => true]);
        }

        return $agent;
    }

    private function agentUser(Account $agent): User
    {
        $user = User::factory()->create(['account_id' => $agent->id]);
        $user->assignRole(['admin', 'agent']);

        return $user;
    }

    private function client(Account $agent): Account
    {
        $client = Account::factory()->create(['agent_id' => $agent->id]);
        Subscription::factory()->for($client)->create(['engine_type' => 'meta']);

        return $client;
    }

    private function grant(User $actor, Account $account, string $capability = 'crm')
    {
        return $this->actingAs($actor)->postJson("/api/admin/accounts/{$account->id}/entitlements", ['capability' => $capability]);
    }

    private function revoke(User $actor, Account $account, string $capability = 'crm')
    {
        return $this->actingAs($actor)->deleteJson("/api/admin/accounts/{$account->id}/entitlements/{$capability}");
    }

    private function holds(Account $account, string $capability = 'crm'): bool
    {
        return app(AccessControlService::class)->canTenant(Account::query()->findOrFail($account->id), $capability);
    }

    private function row(Account $account, string $capability = 'crm'): ?AccountEntitlement
    {
        return AccountEntitlement::where('account_id', $account->id)->where('capability_id', Capability::where('slug', $capability)->value('id'))->first();
    }

    private function deniedAudits()
    {
        return ActivityLog::where('module_name', EntitlementAuditLogger::MODULE)->where('action_type', 'denied')
            ->get()->filter(fn ($log) => ($log->new_values['category'] ?? null) === 'revoked_by_super_admin')->values();
    }

    /** Super Admin grants then revokes `crm` on the client. */
    private function superAdminRevoked(Account $client, string $capability = 'crm'): User
    {
        $superAdmin = $this->superAdmin();
        $this->grant($superAdmin, $client, $capability)->assertOk();
        $this->revoke($superAdmin, $client, $capability)->assertOk();
        $this->assertFalse($this->holds($client, $capability));

        return $superAdmin;
    }

    // ================================================================== core regression

    public function test_an_agent_cannot_re_grant_a_capability_a_super_admin_revoked(): void
    {
        $agent = $this->agent();
        $client = $this->client($agent);
        $this->superAdminRevoked($client);
        $before = $this->row($client)->only(['revoked_at', 'revoked_by_user_id', 'revoked_reason', 'source', 'granted_by_account_id']);

        $this->grant($this->agentUser($agent), $client)
            ->assertForbidden()
            ->assertJsonPath('error_code', 'ENTITLEMENT_REVOKED_BY_SUPER_ADMIN');

        $this->assertFalse($this->holds($client), 'the client still does not hold the capability');
        $this->assertEquals($before, $this->row($client)->fresh()->only(array_keys($before)), 'the revocation row is unchanged');
    }

    public function test_the_denied_re_grant_is_audited_through_the_p5_8_trail(): void
    {
        $agent = $this->agent();
        $client = $this->client($agent);
        $this->superAdminRevoked($client);
        $agentUser = $this->agentUser($agent);

        $this->grant($agentUser, $client)->assertForbidden();

        $log = $this->deniedAudits()->sole();
        $this->assertSame([$agentUser->id, $client->id, $agent->id], [$log->user_id, $log->account_id, $log->agent_id]);
        $this->assertSame('denied', $log->new_values['decision']);
        $this->assertSame('entitlement.grant', $log->new_values['action']);
        $this->assertSame('crm', $log->new_values['capability']);
        $this->assertSame($agent->id, $log->new_values['actor_account_id']);
        $this->assertSame($client->id, $log->new_values['target_account_id']);
        $this->assertSame('ENTITLEMENT_REVOKED_BY_SUPER_ADMIN', $log->new_values['error_code']);
        $this->assertSame(403, $log->new_values['http_status']);
        $this->assertArrayNotHasKey('password', $log->new_values);
    }

    // ================================================================== legitimate agent grants

    public function test_an_agent_can_still_grant_a_capability_that_was_never_revoked(): void
    {
        $agent = $this->agent();
        $client = $this->client($agent);

        $this->grant($this->agentUser($agent), $client)->assertOk();

        $this->assertTrue($this->holds($client));
        $this->assertSame([AccountEntitlement::SOURCE_AGENT_DELEGATED, $agent->id], [$this->row($client)->source, $this->row($client)->granted_by_account_id]);
        $this->assertCount(0, $this->deniedAudits());
    }

    public function test_an_agent_can_undo_its_own_revocation(): void
    {
        $agent = $this->agent();
        $client = $this->client($agent);
        $agentUser = $this->agentUser($agent);
        $colleague = $this->agentUser($agent); // another user of the same Agent account

        $this->grant($agentUser, $client)->assertOk();
        $this->revoke($colleague, $client)->assertOk();
        $this->assertFalse($this->holds($client));

        $this->grant($agentUser, $client)->assertOk();
        $this->assertTrue($this->holds($client));
    }

    public function test_a_plan_downgrade_revocation_stays_re_grantable_by_the_agent(): void
    {
        $agent = $this->agent();
        $client = $this->client($agent);
        $client->entitlements()->create([
            'capability_id' => Capability::where('slug', 'crm')->value('id'), 'source' => 'plan',
            'revoked_at' => now(), 'revoked_by_user_id' => null, 'revoked_reason' => AccountEntitlement::REVOKED_PLAN_DOWNGRADE,
        ]);

        $this->grant($this->agentUser($agent), $client)->assertOk();

        $this->assertTrue($this->holds($client));
    }

    public function test_the_protection_is_per_capability(): void
    {
        $agent = $this->agent();
        $client = $this->client($agent);
        $this->superAdminRevoked($client, 'crm');

        $this->grant($this->agentUser($agent), $client, 'ai')->assertOk();

        $this->assertTrue($this->holds($client, 'ai'));
        $this->assertFalse($this->holds($client, 'crm'));
    }

    public function test_an_unknown_author_manual_revocation_is_protected(): void
    {
        $agent = $this->agent();
        $client = $this->client($agent);
        // e.g. a legacy row, or the revoking user was deleted: manual, no recorded author
        $client->entitlements()->create([
            'capability_id' => Capability::where('slug', 'crm')->value('id'), 'source' => 'manual_grant',
            'revoked_at' => now(), 'revoked_by_user_id' => null, 'revoked_reason' => null,
        ]);

        $this->grant($this->agentUser($agent), $client)->assertForbidden();
        $this->assertFalse($this->holds($client));
    }

    // ================================================================== super admin

    public function test_super_admin_can_still_grant_and_revoke_freely(): void
    {
        $agent = $this->agent();
        $client = $this->client($agent);
        $superAdmin = $this->superAdminRevoked($client);

        $this->grant($superAdmin, $client)->assertOk();
        $this->assertTrue($this->holds($client), 'a Super Admin may restore its own revocation');
        $this->assertSame(AccountEntitlement::SOURCE_MANUAL_GRANT, $this->row($client)->source);

        // after the Super Admin restored it, it is an ordinary held entitlement
        // again: the Agent can revoke and re-grant it within its own scope
        $agentUser = $this->agentUser($agent);
        $this->revoke($agentUser, $client)->assertOk();
        $this->grant($agentUser, $client)->assertOk();
        $this->assertTrue($this->holds($client));
    }

    public function test_a_super_admin_revocation_claims_a_row_the_agent_already_revoked(): void
    {
        $agent = $this->agent();
        $client = $this->client($agent);
        $agentUser = $this->agentUser($agent);
        $superAdmin = $this->superAdmin();

        $this->grant($agentUser, $client)->assertOk();
        $this->revoke($agentUser, $client)->assertOk();
        $this->revoke($superAdmin, $client)->assertOk(); // the Super Admin now wants it gone for good

        $this->assertSame($superAdmin->id, $this->row($client)->revoked_by_user_id);
        $this->grant($agentUser, $client)->assertForbidden();
        $this->assertFalse($this->holds($client));
    }

    public function test_a_super_admin_revocation_turns_a_plan_downgrade_revocation_into_a_protected_one(): void
    {
        $agent = $this->agent();
        $client = $this->client($agent);
        $client->entitlements()->create([
            'capability_id' => Capability::where('slug', 'crm')->value('id'), 'source' => 'plan',
            'revoked_at' => now(), 'revoked_reason' => AccountEntitlement::REVOKED_PLAN_DOWNGRADE,
        ]);
        $superAdmin = $this->superAdmin();

        $this->revoke($superAdmin, $client)->assertOk();

        $row = $this->row($client);
        $this->assertSame([AccountEntitlement::REVOKED_MANUAL, $superAdmin->id], [$row->revoked_reason, $row->revoked_by_user_id]);
        $this->assertFalse($row->isPlanRevoked(), 'plan reconciliation can no longer restore it either');
        $this->grant($this->agentUser($agent), $client)->assertForbidden();
    }

    // ================================================================== forged requests / ownership

    public function test_forged_request_fields_cannot_bypass_the_protection(): void
    {
        $agent = $this->agent();
        $client = $this->client($agent);
        $this->superAdminRevoked($client);
        $agentUser = $this->agentUser($agent);
        $row = $this->row($client);
        $crmId = Capability::where('slug', 'crm')->value('id');

        foreach ([
            ['capability' => 'crm', 'account_id' => $agent->id],
            ['capability' => 'crm', 'tenant_id' => $agent->id, 'agent_id' => $agent->id, 'parent_account_id' => $agent->id],
            ['capability' => 'crm', 'capability_id' => Capability::where('slug', 'ai')->value('id')],
            ['capability' => 'crm', 'entitlement_id' => 999999, 'revoked_at' => null, 'revoked_by_user_id' => $agentUser->id, 'revoked_reason' => 'plan_downgrade'],
            ['capability' => 'crm', 'source' => 'agent_delegated', 'granted_by_account_id' => $agent->id],
        ] as $body) {
            $this->actingAs($agentUser)->postJson("/api/admin/accounts/{$client->id}/entitlements?".http_build_query(['account_id' => $agent->id, 'entitlement_id' => $row->id]), $body)
                ->assertForbidden();
        }

        // an id instead of the slug is not a capability at all
        $this->actingAs($agentUser)->postJson("/api/admin/accounts/{$client->id}/entitlements", ['capability' => (string) $crmId])->assertStatus(422);

        $this->assertFalse($this->holds($client));
        $this->assertSame(1, AccountEntitlement::where('account_id', $client->id)->count(), 'no second row was created');
        $this->assertEquals($row->only(['revoked_at', 'revoked_by_user_id', 'revoked_reason']), $row->fresh()->only(['revoked_at', 'revoked_by_user_id', 'revoked_reason']));
        $this->assertCount(5, $this->deniedAudits(), 'every forged attempt is audited as denied');
    }

    public function test_an_agent_cannot_reach_another_agents_client_or_an_unrelated_account(): void
    {
        $agentA = $this->agent();
        $agentB = $this->agent();
        $clientOfB = $this->client($agentB);
        $independent = Account::factory()->create(); // no agent at all
        Subscription::factory()->for($independent)->create(['engine_type' => 'meta']);
        $userA = $this->agentUser($agentA);

        $this->grant($userA, $clientOfB)->assertNotFound();
        $this->grant($userA, $independent)->assertNotFound();
        $this->grant($userA, $agentB)->assertNotFound();
        $this->revoke($userA, $clientOfB)->assertNotFound();

        $this->assertFalse($this->holds($clientOfB));
        $this->assertFalse($this->holds($independent));
        $this->assertSame(0, AccountEntitlement::whereIn('account_id', [$clientOfB->id, $independent->id])->count());
    }

    public function test_a_revocation_made_by_another_agent_is_not_undoable_by_this_agent(): void
    {
        // a client re-parented from Agent B to Agent A keeps B's revocation
        $agentA = $this->agent();
        $agentB = $this->agent();
        $client = $this->client($agentB);
        $userB = $this->agentUser($agentB);
        $this->grant($userB, $client)->assertOk();
        $this->revoke($userB, $client)->assertOk();
        $client->forceFill(['agent_id' => $agentA->id])->save();

        $this->grant($this->agentUser($agentA), $client)->assertForbidden();
        $this->assertFalse($this->holds($client));
    }

    public function test_a_plain_client_admin_still_cannot_grant(): void
    {
        $agent = $this->agent();
        $client = $this->client($agent);
        $clientAdmin = User::factory()->create(['account_id' => $client->id]);
        $clientAdmin->assignRole('admin');

        $this->grant($clientAdmin, $client)->assertForbidden();
        $this->assertFalse($this->holds($client));
    }
}
