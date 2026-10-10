<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\AiOperation;
use App\Models\User;
use App\Models\WhatsAppFlow;
use App\Services\Billing\InvoiceCreditService;
use App\Services\Credits\CreditService;
use App\Support\PlanCatalog;
use Database\Seeders\Phase1FoundationSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Phase 8 Task 14 — "Generate with AI" for the Journey Builder
 * (POST /api/whatsapp/flows/ai-generate), the same metered-AI shape as
 * TemplateAiGenerateTest (Task 13):
 *
 *   controller → AiAuthorizer (target account, chatbot, manage-chatbot, ai)
 *              → JourneyCopywriterService → MeteredAiService → AiService → AiManager → provider
 *
 * The behavioural assertions this suite exists for, beyond the ordinary
 * authorization/billing/idempotency contract every AI endpoint shares:
 *
 *   - the generated graph is returned UNCHANGED when it validates (no
 *     silent rewrite — the same anti-ConnexxaIQ-bug property as Task 13)
 *   - a graph using any node type outside JourneyCopywriterService::
 *     SAFE_NODE_TYPES, or missing exactly one trigger node, is NEVER
 *     passed through — it falls back to the deterministic skeleton
 *   - no WhatsAppFlow row is ever created by this endpoint
 *
 * UNVERIFIED: written without a reachable PHP/phpunit runtime in the
 * authoring session — run `php artisan test --filter=JourneyAiGenerate`
 * before trusting this file; see Phase 8 Task 14's row in PROJECT_STATE.md.
 */
class JourneyAiGenerateTest extends TestCase
{
    use RefreshDatabase;

    private const URL = '/api/whatsapp/flows/ai-generate';

    private const OPENAI = 'https://api.openai.com/v1/chat/completions';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        $this->seed(Phase1FoundationSeeder::class);
        config([
            'ai.default' => 'openai', 'ai.enabled' => ['openai'], 'ai.retries' => 0,
            'ai.providers.openai.api_key' => 'sk-test-journey', 'ai.providers.openai.model' => 'gpt-test',
            'ai.providers.openai.base_url' => self::OPENAI,
            'ai.credits.tokens_per_credit' => 1000, 'ai.credits.minimum_per_operation' => 1, 'ai.credits.missing_usage' => 'minimum',
        ]);
    }

    // ------------------------------------------------------------------ fixtures

    private function tenant(string $planKey = 'growth', int $credits = 100): Account
    {
        $account = Account::factory()->create();
        $plan = PlanCatalog::find($planKey);
        $invoice = \App\Models\Invoice::create([
            'account_id' => $account->id, 'invoice_number' => 'INV-'.uniqid(), 'plan_key' => $planKey,
            'plan_label' => $plan['label'], 'amount' => $plan['price'], 'tax_amount' => 0,
            'total_amount' => $plan['price'], 'currency' => 'INR', 'payment_gateway' => 'razorpay',
            'gateway_order_id' => 'order_'.uniqid(), 'status' => 'pending',
        ]);
        app(InvoiceCreditService::class)->markPaidAndCreditQuota($invoice->id, 'pay_'.uniqid());
        if ($credits > 0) {
            app(CreditService::class)->grant($account->fresh(), $credits, 'test:grant:'.uniqid());
        }

        return $account->fresh();
    }

    private function user(?Account $account, string $role = 'admin'): User
    {
        $user = User::factory()->create(['account_id' => $account?->id, 'is_active' => true]);
        $user->assignRole($role);

        return $user->fresh();
    }

    private function payload(array $overrides = []): array
    {
        return array_merge([
            'description' => 'A support bot that greets the customer, asks for their email, then hands off to a human',
            'goal' => 'Capture the lead and escalate to support',
        ], $overrides);
    }

    /** A valid, safe 4-node graph: trigger -> text -> question -> save_lead. */
    private function validGraph(): array
    {
        return [
            'nodes' => [
                ['id' => 't1', 'type' => 'trigger', 'position' => ['x' => 40, 'y' => 40], 'data' => []],
                ['id' => 't2', 'type' => 'text', 'position' => ['x' => 40, 'y' => 200], 'data' => ['text' => 'Hi! How can we help today?']],
                ['id' => 't3', 'type' => 'question', 'position' => ['x' => 40, 'y' => 360], 'data' => ['prompt_text' => 'What is your email?', 'variable_name' => 'email', 'input_type' => 'text']],
                ['id' => 't4', 'type' => 'save_lead', 'position' => ['x' => 40, 'y' => 520], 'data' => ['email_variable' => 'email', 'name_variable' => null, 'phone_variable' => null, 'completion_message' => null]],
            ],
            'edges' => [
                ['id' => 'e1', 'source' => 't1', 'target' => 't2'],
                ['id' => 'e2', 'source' => 't2', 'target' => 't3'],
                ['id' => 'e3', 'source' => 't3', 'target' => 't4'],
            ],
        ];
    }

    private function openAiAnswer(array $graph): array
    {
        return [
            'choices' => [['message' => ['role' => 'assistant', 'content' => json_encode($graph)], 'finish_reason' => 'stop']],
            'usage' => ['prompt_tokens' => 220, 'completion_tokens' => 160, 'total_tokens' => 380],
        ];
    }

    // ==================================================================

    public function test_a_super_admin_must_name_an_account_to_authorize_and_charge(): void
    {
        $superAdmin = User::factory()->create(['account_id' => null]);
        $superAdmin->assignRole('super_admin');

        $this->actingAs($superAdmin)->postJson(self::URL, $this->payload(['account_id' => null]))
            ->assertStatus(422); // validation: account_id required
    }

    public function test_a_super_admin_can_generate_a_draft_against_a_chosen_account(): void
    {
        Http::fake(fn () => Http::response($this->openAiAnswer($this->validGraph()), 200));
        $superAdmin = User::factory()->create(['account_id' => null]);
        $superAdmin->assignRole('super_admin');
        $account = $this->tenant();

        $response = $this->actingAs($superAdmin)->postJson(self::URL, $this->payload(['account_id' => $account->id]))
            ->assertOk();

        $response->assertJsonPath('data.provider', 'openai');
        $this->assertCount(4, $response->json('data.graph_data.nodes'));
        $this->assertCount(3, $response->json('data.graph_data.edges'));
    }

    public function test_an_agent_may_generate_for_its_own_sub_client_but_not_an_unrelated_account(): void
    {
        Http::fake(fn () => Http::response($this->openAiAnswer($this->validGraph()), 200));
        $agentAccount = $this->tenant();
        // AiAuthorizer::resolveTarget() checks the ACCOUNT's account_type
        // (default 'client' per AccountFactory), not the user's RBAC role -
        // same distinction TenantIsolationMiddleware relies on.
        $agentAccount->update(['account_type' => 'agent']);
        $agent = $this->user($agentAccount, 'agent');
        $subClient = $this->tenant();
        $subClient->update(['agent_id' => $agentAccount->id]);
        $unrelated = $this->tenant();

        $this->actingAs($agent)->postJson(self::URL, $this->payload(['account_id' => $subClient->id]))->assertOk();
        // AiException::targetAccountForbidden() is a 404 — "not found or
        // you may not act on it" — never confirms the id exists.
        $this->actingAs($agent)->postJson(self::URL, $this->payload(['account_id' => $unrelated->id]))->assertStatus(404);
    }

    public function test_a_plain_admin_without_manage_chatbot_is_forbidden(): void
    {
        $account = $this->tenant();
        $user = $this->user($account, 'user');

        $this->actingAs($user)->postJson(self::URL, $this->payload(['account_id' => $account->id]))->assertForbidden();
    }

    public function test_a_valid_graph_is_returned_unchanged_no_silent_rewrite(): void
    {
        $graph = $this->validGraph();
        $graph['nodes'][1]['data']['text'] = 'A VERY distinctive, unusual greeting a generic fallback would never produce verbatim.';
        Http::fake(fn () => Http::response($this->openAiAnswer($graph), 200));
        $superAdmin = User::factory()->create(['account_id' => null]);
        $superAdmin->assignRole('super_admin');
        $account = $this->tenant();

        $response = $this->actingAs($superAdmin)->postJson(self::URL, $this->payload(['account_id' => $account->id]))
            ->assertOk()
            ->assertJsonPath('data.provider', 'openai');

        $this->assertSame('A VERY distinctive, unusual greeting a generic fallback would never produce verbatim.', $response->json('data.graph_data.nodes.1.data.text'));
    }

    public function test_no_whatsapp_flow_row_is_ever_created_by_this_endpoint(): void
    {
        Http::fake(fn () => Http::response($this->openAiAnswer($this->validGraph()), 200));
        $superAdmin = User::factory()->create(['account_id' => null]);
        $superAdmin->assignRole('super_admin');
        $account = $this->tenant();

        $this->actingAs($superAdmin)->postJson(self::URL, $this->payload(['account_id' => $account->id]))->assertOk();

        $this->assertSame(0, WhatsAppFlow::count());
    }

    public function test_an_unsafe_node_type_is_never_passed_through_falls_back_instead(): void
    {
        // A model that tries to invent an 'api' node (a credential/URL
        // surface this feature deliberately never generates) must get the
        // deterministic fallback, not a graph containing it.
        $graph = $this->validGraph();
        $graph['nodes'][] = ['id' => 'bad', 'type' => 'api', 'position' => ['x' => 40, 'y' => 680], 'data' => ['url' => 'https://evil.example/exfiltrate']];
        Http::fake(fn () => Http::response($this->openAiAnswer($graph), 200));
        $superAdmin = User::factory()->create(['account_id' => null]);
        $superAdmin->assignRole('super_admin');
        $account = $this->tenant(credits: 100);

        $response = $this->actingAs($superAdmin)->postJson(self::URL, $this->payload(['account_id' => $account->id]))->assertOk();

        $response->assertJsonPath('data.provider', 'template');
        $nodeTypes = array_column($response->json('data.graph_data.nodes'), 'type');
        $this->assertNotContains('api', $nodeTypes);
    }

    public function test_a_graph_without_exactly_one_trigger_falls_back(): void
    {
        $graph = $this->validGraph();
        unset($graph['nodes'][0]); // drop the only trigger node
        $graph['nodes'] = array_values($graph['nodes']);
        Http::fake(fn () => Http::response($this->openAiAnswer($graph), 200));
        $superAdmin = User::factory()->create(['account_id' => null]);
        $superAdmin->assignRole('super_admin');
        $account = $this->tenant(credits: 100);

        $this->actingAs($superAdmin)->postJson(self::URL, $this->payload(['account_id' => $account->id]))
            ->assertOk()
            ->assertJsonPath('data.provider', 'template');
    }

    public function test_a_conditional_edge_missing_source_handle_falls_back(): void
    {
        $graph = [
            'nodes' => [
                ['id' => 'n1', 'type' => 'trigger', 'position' => ['x' => 0, 'y' => 0], 'data' => []],
                ['id' => 'n2', 'type' => 'conditional', 'position' => ['x' => 0, 'y' => 160], 'data' => ['match' => 'all', 'conditions' => [['variable' => 'email', 'operator' => 'exists']]]],
                ['id' => 'n3', 'type' => 'text', 'position' => ['x' => 0, 'y' => 320], 'data' => ['text' => 'ok']],
            ],
            'edges' => [
                ['id' => 'e1', 'source' => 'n1', 'target' => 'n2'],
                ['id' => 'e2', 'source' => 'n2', 'target' => 'n3'], // missing sourceHandle — invalid
            ],
        ];
        Http::fake(fn () => Http::response($this->openAiAnswer($graph), 200));
        $superAdmin = User::factory()->create(['account_id' => null]);
        $superAdmin->assignRole('super_admin');
        $account = $this->tenant(credits: 100);

        $this->actingAs($superAdmin)->postJson(self::URL, $this->payload(['account_id' => $account->id]))
            ->assertOk()
            ->assertJsonPath('data.provider', 'template');
    }

    public function test_credits_are_charged_to_the_named_account_and_metered(): void
    {
        Http::fake(fn () => Http::response($this->openAiAnswer($this->validGraph()), 200));
        $superAdmin = User::factory()->create(['account_id' => null]);
        $superAdmin->assignRole('super_admin');
        $account = $this->tenant(credits: 100);

        $this->actingAs($superAdmin)->postJson(self::URL, $this->payload(['account_id' => $account->id]))->assertOk();

        $balance = app(CreditService::class)->balance($account->fresh());
        $this->assertLessThan(100, $balance['balance'], 'the named account paid, not the Super Admin');
        $op = AiOperation::where('account_id', $account->id)->sole();
        $this->assertSame([AiOperation::STATUS_SETTLED, 'journeys.ai_generate'], [$op->status, $op->operation]);
    }

    public function test_a_provider_failure_falls_back_to_a_deterministic_draft_uncharged(): void
    {
        Http::fake(fn () => Http::response(['error' => ['message' => 'overloaded', 'code' => 'service_unavailable']], 503));
        $superAdmin = User::factory()->create(['account_id' => null]);
        $superAdmin->assignRole('super_admin');
        $account = $this->tenant(credits: 100);

        $response = $this->actingAs($superAdmin)->postJson(self::URL, $this->payload(['account_id' => $account->id]))->assertOk();

        $response->assertJsonPath('data.provider', 'template');
        $this->assertNotEmpty($response->json('data.graph_data.nodes'));
        $this->assertSame(1, count(array_filter($response->json('data.graph_data.nodes'), fn ($n) => $n['type'] === 'trigger')));
        $balance = app(CreditService::class)->balance($account->fresh());
        $this->assertSame(100, $balance['balance'], 'a provider failure is never charged — same contract as TemplateCopywriterService');
    }

    public function test_a_malformed_answer_also_falls_back_to_the_deterministic_draft(): void
    {
        Http::fake(fn () => Http::response([
            'choices' => [['message' => ['role' => 'assistant', 'content' => 'not json at all'], 'finish_reason' => 'stop']],
            'usage' => ['prompt_tokens' => 10, 'completion_tokens' => 5],
        ], 200));
        $superAdmin = User::factory()->create(['account_id' => null]);
        $superAdmin->assignRole('super_admin');
        $account = $this->tenant(credits: 100);

        $this->actingAs($superAdmin)->postJson(self::URL, $this->payload(['account_id' => $account->id]))
            ->assertOk()
            ->assertJsonPath('data.provider', 'template');
    }

    public function test_the_request_asks_for_json_mode(): void
    {
        Http::fake(fn () => Http::response($this->openAiAnswer($this->validGraph()), 200));
        $superAdmin = User::factory()->create(['account_id' => null]);
        $superAdmin->assignRole('super_admin');
        $account = $this->tenant();

        $this->actingAs($superAdmin)->postJson(self::URL, $this->payload(['account_id' => $account->id]))->assertOk();

        Http::assertSent(fn (HttpRequest $r) => ($r['response_format']['type'] ?? null) === 'json_object');
    }

    public function test_an_empty_description_is_rejected(): void
    {
        $superAdmin = User::factory()->create(['account_id' => null]);
        $superAdmin->assignRole('super_admin');
        $account = $this->tenant();

        $this->actingAs($superAdmin)->postJson(self::URL, $this->payload(['account_id' => $account->id, 'description' => '']))
            ->assertStatus(422);
    }

    public function test_an_idempotent_retry_with_the_same_header_is_not_double_charged(): void
    {
        Http::fake(fn () => Http::response($this->openAiAnswer($this->validGraph()), 200));
        $superAdmin = User::factory()->create(['account_id' => null]);
        $superAdmin->assignRole('super_admin');
        $account = $this->tenant(credits: 100);

        $this->withHeader('Idempotency-Key', 'retry-key-1')
            ->actingAs($superAdmin)->postJson(self::URL, $this->payload(['account_id' => $account->id]))->assertOk();

        $balanceAfterFirst = app(CreditService::class)->balance($account->fresh())['balance'];

        $this->withHeader('Idempotency-Key', 'retry-key-1')
            ->actingAs($superAdmin)->postJson(self::URL, $this->payload(['account_id' => $account->id]))->assertStatus(409);

        $this->assertSame($balanceAfterFirst, app(CreditService::class)->balance($account->fresh())['balance']);
    }
}
