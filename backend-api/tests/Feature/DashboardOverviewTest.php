<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\AgentCommission;
use App\Models\ContactMessage;
use App\Models\CrmLead;
use App\Models\Subscription;
use App\Models\User;
use Database\Seeders\Phase1FoundationSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** GET /api/dashboard/overview — role- and plan-aware dashboard widgets. */
class DashboardOverviewTest extends TestCase
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

    private function agent(): User
    {
        $account = Account::factory()->create(['company_name' => 'Agent Co', 'account_type' => 'agent', 'agent_id' => null]);
        Subscription::factory()->for($account)->create(['engine_type' => 'qr']);
        $user = User::factory()->create(['account_id' => $account->id]);
        $user->assignRole('admin');
        $user->assignRole('agent');

        return $user;
    }

    /** A client created the real way, on a plan (invoice + entitlements). */
    private function clientOnPlan(User $creator, string $plan, string $email): Account
    {
        $id = $this->actingAs($creator)->postJson('/api/admin/accounts', [
            'company_name' => "Client {$plan}", 'admin_name' => 'Owner', 'admin_email' => $email,
            'admin_password' => 'secret-pass', 'module_assignment' => 'both', 'plan_key' => $plan, 'payment_method' => 'cash',
        ])->assertCreated()->json('id');

        return Account::findOrFail($id);
    }

    private function ownerOf(Account $account): User
    {
        return User::where('account_id', $account->id)->firstOrFail();
    }

    public function test_super_admin_sees_attention_items_and_clients_per_plan(): void
    {
        $admin = $this->superAdmin();
        $this->clientOnPlan($admin, 'starter', 'a@x.test');
        $this->clientOnPlan($admin, 'growth', 'b@x.test');
        ContactMessage::create(['name' => 'R', 'email' => 'r@x.test', 'message' => 'Hi']);

        $res = $this->actingAs($admin)->getJson('/api/dashboard/overview')->assertOk()
            ->assertJsonPath('scope', 'platform')
            ->assertJsonPath('platform.attention.contact_messages', 1)
            ->assertJsonPath('platform.attention.pending_invoices', 0);

        $split = collect($res->json('platform.plan_split'))->pluck('clients', 'plan_key');
        $this->assertSame(1, $split['starter']);
        $this->assertSame(1, $split['growth']);
        $this->assertSame(0, $split['business']);
    }

    public function test_a_client_only_gets_the_widgets_its_plan_includes(): void
    {
        $admin = $this->superAdmin();
        $starter = $this->clientOnPlan($admin, 'starter', 's@x.test');
        $growth = $this->clientOnPlan($admin, 'growth', 'g@x.test');
        CrmLead::factory()->create(['account_id' => $growth->id, 'status' => CrmLead::STATUS_NEW]);

        $this->actingAs($this->ownerOf($starter))->getJson('/api/dashboard/overview')->assertOk()
            ->assertJsonPath('scope', 'tenant')
            ->assertJsonPath('tenant.plan.key', 'starter')
            ->assertJsonPath('tenant.capabilities.crm', false)
            ->assertJsonMissingPath('tenant.crm')
            ->assertJsonMissingPath('tenant.journeys')
            ->assertJsonMissingPath('tenant.ads');

        $this->actingAs($this->ownerOf($growth))->getJson('/api/dashboard/overview')->assertOk()
            ->assertJsonPath('tenant.plan.key', 'growth')
            ->assertJsonPath('tenant.crm.total', 1)
            ->assertJsonPath('tenant.crm.new', 1)
            ->assertJsonPath('tenant.journeys.active', 0)
            // The seeded Growth plan has a 2,500 quota (live DBs are moved to unlimited by migration).
            ->assertJsonPath('tenant.subscription.billing_model', 'flat_quota')
            ->assertJsonPath('tenant.subscription.messages_left', 2500)
            ->assertJsonMissingPath('tenant.ads'); // Growth does not include Ads
    }

    public function test_super_admin_viewing_a_selected_client_gets_that_clients_widgets(): void
    {
        $admin = $this->superAdmin();
        $growth = $this->clientOnPlan($admin, 'growth', 'g@x.test');

        $this->actingAs($admin)->getJson("/api/dashboard/overview?account_id={$growth->id}")->assertOk()
            ->assertJsonPath('scope', 'tenant')
            ->assertJsonPath('tenant.account_id', $growth->id)
            ->assertJsonPath('tenant.capabilities.crm', true);
    }

    public function test_an_agent_sees_its_clients_and_commission_but_not_other_agents_clients(): void
    {
        $agent = $this->agent();
        $client = $this->clientOnPlan($agent, 'starter', 'c@x.test');
        $client->currentSubscription->update(['expires_at' => now()->addDays(3)]);

        $res = $this->actingAs($agent)->getJson('/api/dashboard/overview')->assertOk()
            ->assertJsonPath('agent.clients_total', 1)
            ->assertJsonPath('agent.clients_active', 1)
            ->assertJsonPath('agent.expiring.0.account_id', $client->id);
        $this->assertEquals(
            round((float) AgentCommission::where('agent_account_id', $agent->account_id)->sum('amount'), 2),
            $res->json('agent.commission_total'),
        );

        $other = $this->clientOnPlan($this->superAdmin(), 'starter', 'o@x.test');
        $this->actingAs($agent)->getJson("/api/dashboard/overview?account_id={$other->id}")->assertNotFound();
    }
}
