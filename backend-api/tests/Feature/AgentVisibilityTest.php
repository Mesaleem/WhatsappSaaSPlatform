<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Subscription;
use App\Models\User;
use Database\Seeders\Phase1FoundationSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** A Super Admin sees every agent (in the Parent Agent list and in Team) and every agent's login. */
class AgentVisibilityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        $this->seed(Phase1FoundationSeeder::class);
    }

    private function superAdmin(): User
    {
        $user = User::factory()->create(['account_id' => null]);
        $user->assignRole('super_admin');

        return $user;
    }

    private function agent(string $name): array
    {
        $account = Account::factory()->create(['company_name' => $name, 'account_type' => 'agent', 'agent_id' => null]);
        Subscription::factory()->for($account)->create(['engine_type' => 'qr']);
        $user = User::factory()->create(['account_id' => $account->id, 'name' => $name.' Owner']);
        $user->assignRole('admin');
        $user->assignRole('agent');

        return [$account, $user];
    }

    public function test_super_admin_gets_every_agent_for_the_parent_agent_list(): void
    {
        [$agent] = $this->agent('Reseller One');

        $response = $this->actingAs($this->superAdmin())->getJson('/api/admin/accounts?account_type=agent&per_page=100')
            ->assertOk();

        $this->assertContains($agent->id, collect($response->json('data'))->pluck('id')->all());
    }

    public function test_the_agent_summary_counts_each_agents_clients_and_the_direct_clients(): void
    {
        [$agent, $agentUser] = $this->agent('Reseller Three');

        Account::factory()->create(['account_type' => 'client', 'agent_id' => $agent->id, 'status' => 'active']);
        Account::factory()->create(['account_type' => 'client', 'agent_id' => $agent->id, 'status' => 'suspended']);
        Account::factory()->create(['account_type' => 'client', 'agent_id' => null, 'status' => 'active']);

        $response = $this->actingAs($this->superAdmin())->getJson('/api/admin/accounts/agent-summary')->assertOk();

        $row = collect($response->json('agents'))->firstWhere('id', $agent->id);
        $this->assertSame(['total' => 2, 'active' => 1, 'suspended' => 1, 'expired' => 0], $row['clients']);
        $this->assertSame($agentUser->email, $row['login']['email']);
        $this->assertSame(1, $response->json('direct_clients.total'));
    }

    public function test_an_agent_cannot_read_the_agent_summary(): void
    {
        [, $agentUser] = $this->agent('Reseller Four');

        $this->actingAs($agentUser)->getJson('/api/admin/accounts/agent-summary')->assertForbidden();
    }

    public function test_super_admin_sees_each_agents_login_in_the_global_team_list(): void
    {
        [, $agentUser] = $this->agent('Reseller Two');

        $response = $this->actingAs($this->superAdmin())->getJson('/api/team/users')
            ->assertOk()
            ->assertJsonPath('scope', 'global');

        $this->assertContains($agentUser->id, collect($response->json('data'))->pluck('id')->all());
    }
}
