<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\AccountEntitlement;
use App\Models\AiAgent;
use App\Models\AiAgentToolInvocation;
use App\Models\AiAgentVersion;
use App\Models\Capability;
use App\Models\Invoice;
use App\Models\User;
use App\Models\WhatsAppFlow;
use App\Models\WhatsAppNumber;
use App\Services\Ai\Agents\Tools\CrmCaptureCurrentLeadTool;
use App\Services\Ai\Agents\Tools\CrmFindCurrentLeadTool;
use App\Services\Ai\Agents\Tools\CrmUpdateLeadStatusTool;
use App\Services\Ai\Agents\Tools\JourneyVariableTool;
use App\Services\Billing\InvoiceCreditService;
use App\Support\PlanCatalog;
use Database\Seeders\Phase1FoundationSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use LogicException;
use Tests\TestCase;

/**
 * Phase 8 Task 11 — the AI Agent Registry API (/api/ai-agents): CRUD,
 * versioning, enable/disable, tool grants, and the authorization/tenant
 * isolation model (route permission + AiAuthorizer::forRequest on the TARGET
 * account — no Super Admin entitlement bypass), plus the Journey save-time
 * ownership check of `registeredAgentId`.
 */
class AiAgentRegistryTest extends TestCase
{
    use RefreshDatabase;

    private const API = '/api/ai-agents';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        $this->seed(Phase1FoundationSeeder::class);

        config(['ai.agents.tools' => [JourneyVariableTool::NAME, CrmFindCurrentLeadTool::NAME, CrmCaptureCurrentLeadTool::NAME, CrmUpdateLeadStatusTool::NAME]]);
    }

    // ------------------------------------------------------------------ fixtures

    private function tenant(string $plan = 'growth', array $attributes = [], array $grants = ['ai', 'crm']): Account
    {
        $account = Account::factory()->create($attributes);
        $p = PlanCatalog::find($plan);
        $invoice = Invoice::create([
            'account_id' => $account->id, 'invoice_number' => 'INV-'.uniqid(), 'plan_key' => $plan,
            'plan_label' => $p['label'], 'amount' => $p['price'], 'tax_amount' => 0,
            'total_amount' => $p['price'], 'currency' => 'INR', 'payment_gateway' => 'razorpay',
            'gateway_order_id' => 'order_'.uniqid(), 'status' => 'pending',
        ]);
        app(InvoiceCreditService::class)->markPaidAndCreditQuota($invoice->id, 'pay_'.uniqid());
        foreach ($grants as $slug) {
            AccountEntitlement::firstOrCreate(
                ['account_id' => $account->id, 'capability_id' => Capability::where('slug', $slug)->value('id')],
                ['source' => 'manual_grant', 'granted_by_account_id' => null],
            );
        }

        return $account->fresh();
    }

    private function user(?Account $account, string|array $roles = 'admin'): User
    {
        $user = User::factory()->create(['account_id' => $account?->id, 'is_active' => true]);
        $user->assignRole((array) $roles);

        return $user->fresh();
    }

    private function body(array $overrides = []): array
    {
        return $overrides + ['name' => 'Support agent', 'description' => 'Answers questions', 'instructions' => 'You are Acme support.', 'tools' => []];
    }

    private function create(User $actor, array $overrides = [], string $query = ''): int
    {
        return (int) $this->actingAs($actor)->postJson(self::API.$query, $this->body($overrides))->assertCreated()->json('data.id');
    }

    // ================================================================== registry

    public function test_an_agent_is_created_with_version_one_in_the_callers_account(): void
    {
        $account = $this->tenant();
        $other = $this->tenant();
        $admin = $this->user($account);

        $response = $this->actingAs($admin)->postJson(self::API, $this->body([
            'tools' => [CrmFindCurrentLeadTool::NAME, JourneyVariableTool::NAME], 'model' => 'fast-model', 'settings' => ['max_tool_calls' => 2],
            'account_id' => $other->id, // ignored: the target is resolved by tenant isolation
        ]))->assertCreated();

        $response->assertJsonPath('data.account_id', $account->id)
            ->assertJsonPath('data.version', 1)
            ->assertJsonPath('data.is_enabled', true)
            ->assertJsonPath('data.instructions', 'You are Acme support.')
            ->assertJsonPath('data.tools', [CrmFindCurrentLeadTool::NAME, JourneyVariableTool::NAME])
            ->assertJsonPath('data.settings.max_tool_calls', 2);

        $agent = AiAgent::findOrFail($response->json('data.id'));
        $version = AiAgentVersion::findOrFail($agent->current_version_id);
        $this->assertSame([$account->id, $agent->id, 1, $admin->id], [(int) $version->account_id, (int) $version->ai_agent_id, $version->version, (int) $version->created_by_user_id]);
        $this->assertSame(0, AiAgent::where('account_id', $other->id)->count());
        $this->assertStringNotContainsString('api_key', json_encode($response->json('data')));
    }

    public function test_only_executable_changes_create_a_new_version(): void
    {
        $account = $this->tenant();
        $admin = $this->user($account);
        $id = $this->create($admin);
        $v1 = (int) AiAgent::find($id)->current_version_id;

        $this->actingAs($admin)->putJson(self::API."/{$id}", ['name' => 'Renamed', 'description' => 'New text'])->assertOk()->assertJsonPath('data.version', 1);
        $this->actingAs($admin)->putJson(self::API."/{$id}", ['instructions' => 'You are Acme support.'])->assertOk()->assertJsonPath('data.version', 1);

        $this->actingAs($admin)->putJson(self::API."/{$id}", ['instructions' => 'You are Acme sales.'])->assertOk()
            ->assertJsonPath('data.version', 2)->assertJsonPath('data.name', 'Renamed')->assertJsonPath('data.instructions', 'You are Acme sales.');
        $this->actingAs($admin)->putJson(self::API."/{$id}", ['tools' => [JourneyVariableTool::NAME]])->assertOk()
            ->assertJsonPath('data.version', 3)->assertJsonPath('data.instructions', 'You are Acme sales.');

        $this->assertSame('You are Acme support.', AiAgentVersion::find($v1)->instructions, 'an old version is never changed');
        $this->assertSame([3, 2, 1], array_column($this->actingAs($admin)->getJson(self::API."/{$id}/versions")->assertOk()->json('data'), 'version'));
    }

    public function test_versions_are_immutable(): void
    {
        $account = $this->tenant();
        $id = $this->create($this->user($account));

        $this->expectException(LogicException::class);
        AiAgentVersion::where('ai_agent_id', $id)->firstOrFail()->update(['instructions' => 'changed']);
    }

    public function test_enable_disable_and_delete(): void
    {
        $account = $this->tenant();
        $admin = $this->user($account);
        $id = $this->create($admin);

        $this->actingAs($admin)->postJson(self::API."/{$id}/disable")->assertOk()->assertJsonPath('data.is_enabled', false);
        $this->actingAs($admin)->postJson(self::API."/{$id}/enable")->assertOk()->assertJsonPath('data.is_enabled', true);
        $this->actingAs($admin)->putJson(self::API."/{$id}", ['is_enabled' => false])->assertOk()->assertJsonPath('data.is_enabled', false);

        AiAgentToolInvocation::create(['account_id' => $account->id, 'ai_agent_id' => $id, 'invocation_key' => 'k', 'tool' => JourneyVariableTool::NAME, 'status' => 'succeeded', 'arguments_hash' => str_repeat('0', 64)]);

        $this->actingAs($admin)->deleteJson(self::API."/{$id}")->assertOk();
        $this->assertSame([0, 0, 1], [AiAgent::count(), AiAgentVersion::count(), AiAgentToolInvocation::count()], 'versions cascade; the audit record stays');
        $this->actingAs($admin)->getJson(self::API."/{$id}")->assertNotFound()->assertJsonPath('error_code', 'AI_AGENT_NOT_FOUND');
    }

    public function test_names_are_unique_per_account(): void
    {
        $account = $this->tenant();
        $admin = $this->user($account);
        $this->create($admin);
        $second = $this->create($admin, ['name' => 'Second']);

        $this->actingAs($admin)->postJson(self::API, $this->body())->assertStatus(422)->assertJsonPath('error_code', 'AI_AGENT_NAME_TAKEN');
        $this->actingAs($admin)->putJson(self::API."/{$second}", ['name' => 'Support agent'])->assertStatus(422)->assertJsonPath('error_code', 'AI_AGENT_NAME_TAKEN');
        $this->create($this->user($this->tenant())); // the same name in another account is fine
    }

    public function test_configuration_limits_are_enforced(): void
    {
        config(['ai.agents.max_instructions_chars' => 200, 'ai.agents.max_model_calls' => 4]);
        $admin = $this->user($this->tenant());

        $this->actingAs($admin)->postJson(self::API, $this->body(['instructions' => str_repeat('a', 201)]))->assertStatus(422);
        $this->actingAs($admin)->postJson(self::API, $this->body(['instructions' => '   ']))->assertStatus(422);
        $this->actingAs($admin)->postJson(self::API, $this->body(['settings' => ['max_model_calls' => 5]]))->assertStatus(422)->assertJsonPath('error_code', 'AI_AGENT_INVALID_CONFIGURATION');
        $this->actingAs($admin)->postJson(self::API, $this->body(['settings' => ['max_loops' => 1]]))->assertStatus(422)->assertJsonPath('error_code', 'AI_AGENT_INVALID_CONFIGURATION');
        $this->actingAs($admin)->postJson(self::API, $this->body(['model' => 'gpt; rm -rf /']))->assertStatus(422);
        $this->assertSame(0, AiAgent::count());
    }

    // ================================================================== tool grants

    public function test_tool_grants_are_checked_per_tool(): void
    {
        $account = $this->tenant();
        $admin = $this->user($account);

        $this->actingAs($admin)->postJson(self::API, $this->body(['tools' => ['shell.exec']]))->assertStatus(422)->assertJsonPath('error_code', 'AI_AGENT_UNKNOWN_TOOL');

        $chatbotOnly = $this->user($account, 'user');
        $chatbotOnly->givePermissionTo('manage-chatbot');
        $this->actingAs($chatbotOnly->fresh())->postJson(self::API, $this->body(['tools' => [CrmCaptureCurrentLeadTool::NAME]]))->assertForbidden()->assertJsonPath('error_code', 'AI_AGENT_TOOL_NOT_PERMITTED');
        $this->actingAs($chatbotOnly->fresh())->postJson(self::API, $this->body(['tools' => [JourneyVariableTool::NAME]]))->assertCreated();

        $noCrm = $this->tenant('starter', grants: ['ai']); // starter bundles no crm
        $this->actingAs($this->user($noCrm))->postJson(self::API, $this->body(['tools' => [CrmFindCurrentLeadTool::NAME]]))->assertStatus(422)->assertJsonPath('error_code', 'AI_AGENT_TOOL_UNAVAILABLE');

        $tools = collect($this->actingAs($this->user($noCrm))->getJson(self::API.'/tools')->assertOk()->json('data'))->keyBy('name');
        $this->assertSame([true, false], [$tools[JourneyVariableTool::NAME]['grantable'], $tools[CrmFindCurrentLeadTool::NAME]['grantable']]);
        $this->assertTrue($tools[CrmCaptureCurrentLeadTool::NAME]['side_effect']);
    }

    public function test_a_user_without_the_tools_permission_cannot_carry_it_into_a_new_version_or_re_enable_it(): void
    {
        $account = $this->tenant();
        $id = $this->create($this->user($account), ['tools' => [CrmCaptureCurrentLeadTool::NAME]]);
        $this->actingAs($this->user($account))->postJson(self::API."/{$id}/disable")->assertOk();

        $chatbotOnly = $this->user($account, 'user');
        $chatbotOnly->givePermissionTo('manage-chatbot');
        $chatbotOnly = $chatbotOnly->fresh();

        $this->actingAs($chatbotOnly)->putJson(self::API."/{$id}", ['instructions' => 'Changed'])->assertForbidden();
        $this->actingAs($chatbotOnly)->postJson(self::API."/{$id}/enable")->assertForbidden();
        $this->assertSame([1, false], [AiAgentVersion::where('ai_agent_id', $id)->count(), AiAgent::find($id)->is_enabled]);
    }

    // ================================================================== authorization / isolation

    public function test_another_accounts_agent_is_invisible_and_untouchable(): void
    {
        $mine = $this->tenant();
        $other = $this->tenant();
        $admin = $this->user($mine);
        $this->create($admin, ['name' => 'Mine']);
        $foreign = $this->create($this->user($other), ['name' => 'Theirs']);

        $this->assertSame(['Mine'], array_column($this->actingAs($admin)->getJson(self::API)->assertOk()->json('data'), 'name'));
        $this->assertSame(['Mine'], array_column($this->actingAs($admin)->getJson(self::API.'?account_id='.$other->id)->assertOk()->json('data'), 'name'), 'a client user cannot redirect with ?account_id=');

        $this->actingAs($admin)->getJson(self::API."/{$foreign}")->assertNotFound();
        $this->actingAs($admin)->putJson(self::API."/{$foreign}", ['instructions' => 'hijack'])->assertNotFound();
        $this->actingAs($admin)->postJson(self::API."/{$foreign}/disable")->assertNotFound();
        $this->actingAs($admin)->getJson(self::API."/{$foreign}/versions")->assertNotFound();
        $this->actingAs($admin)->deleteJson(self::API."/{$foreign}")->assertNotFound();

        $agent = AiAgent::find($foreign);
        $this->assertSame([1, true, 'Theirs'], [$agent->version_count, $agent->is_enabled, $agent->name]);
    }

    public function test_the_route_permission_ai_capability_module_and_subscription_are_required(): void
    {
        $plain = $this->tenant();
        $this->actingAs($this->user($plain, 'user'))->getJson(self::API)->assertForbidden(); // no manage-chatbot

        $noAi = $this->tenant('starter', grants: []);
        $this->actingAs($this->user($noAi))->postJson(self::API, $this->body())->assertForbidden()->assertJsonPath('error_code', 'AI_CAPABILITY_UNAVAILABLE');

        $noModule = $this->tenant(attributes: ['allowed_modules' => array_values(array_diff(Account::MODULES, ['chatbot']))]);
        $this->actingAs($this->user($noModule))->postJson(self::API, $this->body())->assertForbidden();

        $expired = $this->tenant();
        $expiredUser = $this->user($expired);
        $expired->currentSubscription->forceFill(['expires_at' => now()->subDay(), 'status' => 'expired'])->save();
        $this->actingAs($expiredUser)->postJson(self::API, $this->body())->assertForbidden();

        $suspended = $this->tenant(attributes: ['status' => 'suspended']);
        $this->actingAs($this->user($suspended))->getJson(self::API)->assertForbidden();

        $this->assertSame(0, AiAgent::count());
    }

    public function test_super_admin_needs_a_selected_client_and_gets_no_entitlement_bypass(): void
    {
        $superAdmin = $this->user(null, 'super_admin');
        $client = $this->tenant();
        $noAi = $this->tenant('starter', grants: []);

        $this->actingAs($superAdmin)->postJson(self::API, $this->body())->assertStatus(422)->assertJsonPath('error_code', 'AI_TARGET_ACCOUNT_REQUIRED');
        $this->actingAs($superAdmin)->postJson(self::API.'?account_id='.$noAi->id, $this->body())->assertForbidden()->assertJsonPath('error_code', 'AI_CAPABILITY_UNAVAILABLE');

        $id = $this->create($superAdmin, ['tools' => [CrmCaptureCurrentLeadTool::NAME]], '?account_id='.$client->id);
        $this->assertSame($client->id, (int) AiAgent::find($id)->account_id);
        $this->actingAs($superAdmin)->getJson(self::API."/{$id}?account_id=".$noAi->id)->assertForbidden(); // no bypass on another target either
    }

    public function test_a_reseller_agent_manages_its_own_sub_clients_agents_only(): void
    {
        $reseller = $this->tenant(attributes: ['account_type' => 'agent']);
        $sub = $this->tenant(attributes: ['agent_id' => $reseller->id]);
        $stranger = $this->tenant();
        $resellerUser = $this->user($reseller, ['admin', 'agent']);

        $id = $this->create($resellerUser, [], '?account_id='.$sub->id);
        $this->assertSame($sub->id, (int) AiAgent::find($id)->account_id);

        $this->actingAs($resellerUser)->postJson(self::API.'?account_id='.$stranger->id, $this->body())->assertNotFound();
        $strangerAgent = $this->create($this->user($stranger));
        $this->actingAs($resellerUser)->getJson(self::API."/{$strangerAgent}?account_id=".$sub->id)->assertNotFound();
        $this->assertSame(0, AiAgent::where('account_id', $stranger->id)->where('id', '!=', $strangerAgent)->count());
    }

    // ================================================================== Journey save-time check

    public function test_a_journey_can_only_reference_its_own_accounts_agents(): void
    {
        $account = $this->tenant();
        $admin = $this->user($account);
        $mine = $this->create($admin, ['name' => 'Mine']);
        $foreign = $this->create($this->user($this->tenant()), ['name' => 'Theirs']);
        // Channel-binding gate (Connexxa parity, Task 21) — assertCreated()
        // below needs a real channel; the 422 assertions elsewhere in this
        // test are already failing for their own reason, so this extra key
        // doesn't change what they're actually checking.
        $number = WhatsAppNumber::create(['account_id' => $account->id, 'phone_number' => '91901'.str_pad((string) $account->id, 6, '0', STR_PAD_LEFT), 'status' => WhatsAppNumber::STATUS_LINKED]);
        $payload = fn (int|string $agentId) => [
            'name' => 'Agent journey', 'trigger_type' => 'keyword', 'trigger_value' => 'go',
            'whatsapp_number_ids' => [$number->id],
            'graph_data' => ['nodes' => [
                ['id' => 't', 'type' => 'trigger', 'data' => []],
                ['id' => 'a', 'type' => 'agent', 'data' => ['registeredAgentId' => (string) $agentId, 'outputVariable' => 'reply']],
            ], 'edges' => [['id' => 'e', 'source' => 't', 'target' => 'a']]],
        ];

        $missing = $this->actingAs($admin)->postJson('/api/whatsapp/flows', $payload(999999))->assertStatus(422);
        $foreignResponse = $this->actingAs($admin)->postJson('/api/whatsapp/flows', $payload($foreign))->assertStatus(422);
        $this->assertSame(['AI agent not found.'], $foreignResponse->json('errors')['graph_data.nodes.1.data.registeredAgentId']);
        $this->assertSame($missing->json('errors'), $foreignResponse->json('errors'), 'a foreign id is indistinguishable from a missing one');
        $this->actingAs($admin)->postJson('/api/whatsapp/flows', $payload('agent-1'))->assertStatus(422);

        $id = $this->actingAs($admin)->postJson('/api/whatsapp/flows', $payload($mine))->assertCreated()->json('data.id');
        $this->actingAs($admin)->putJson("/api/whatsapp/flows/{$id}", $payload($foreign))->assertStatus(422);
        $this->assertSame((string) $mine, WhatsAppFlow::find($id)->graph_data['nodes'][1]['data']['registeredAgentId']);
    }

    public function test_a_journey_author_must_be_allowed_to_use_the_agents_tools(): void
    {
        $account = $this->tenant();
        $withCrm = $this->create($this->user($account), ['tools' => [CrmCaptureCurrentLeadTool::NAME]]);
        $author = $this->user($account, 'user');
        $author->givePermissionTo(['manage-chatbot']);

        $response = $this->actingAs($author->fresh())->postJson('/api/whatsapp/flows', [
            'name' => 'J', 'trigger_type' => 'keyword', 'trigger_value' => 'go',
            'graph_data' => ['nodes' => [
                ['id' => 't', 'type' => 'trigger', 'data' => []],
                ['id' => 'a', 'type' => 'agent', 'data' => ['registeredAgentId' => (string) $withCrm, 'outputVariable' => 'reply']],
            ], 'edges' => [['id' => 'e', 'source' => 't', 'target' => 'a']]],
        ]);

        $response->assertStatus(422);
        $this->assertStringContainsString(CrmCaptureCurrentLeadTool::NAME, $response->json('errors')['graph_data.nodes.1.data.registeredAgentId'][0]);
    }
}
