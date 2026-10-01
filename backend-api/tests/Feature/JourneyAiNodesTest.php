<?php

namespace Tests\Feature;

use App\Jobs\ResumeJourneySessionJob;
use App\Models\Account;
use App\Models\AccountEntitlement;
use App\Models\AiOperation;
use App\Models\Capability;
use App\Models\CreditLedgerEntry;
use App\Models\CreditReservation;
use App\Models\Invoice;
use App\Models\JourneyExecutionEvent;
use App\Models\MessageDispatchLog;
use App\Models\WhatsAppFlow;
use App\Models\WhatsAppFlowSession;
use App\Models\WhatsAppSession;
use App\Services\Ai\AiException;
use App\Services\Ai\AiManager;
use App\Services\Ai\Contracts\AiProvider;
use App\Services\Ai\Data\AiRequest;
use App\Services\Ai\Data\AiResponse;
use App\Services\Ai\Retrieval\KnowledgeRetriever;
use App\Services\Billing\InvoiceCreditService;
use App\Services\Chatbot\ChatbotEngineService;
use App\Services\Credits\CreditService;
use App\Services\WhatsApp\JourneyActionConfig;
use App\Services\WhatsApp\JourneyAiNodeRunner;
use App\Services\WhatsApp\JourneyPublishValidator;
use App\Services\WhatsApp\WhatsAppJourneyEngine;
use App\Support\JourneyNodeCatalog;
use App\Support\PlanCatalog;
use Database\Seeders\Phase1FoundationSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Phase 8 Task 7 — Journey AI nodes (prompt, agent; rag stays unavailable).
 *
 * Driven through the real inbound path (ChatbotEngineService → Journey
 * engine), the real scheduler resume (resumeDueSession) and the real
 * metering (MeteredAiService → CreditService). Only the AI vendor is a fake
 * provider registered on AiManager, and WhatsApp sends are faked at the
 * HTTP boundary of the unified driver.
 *
 * Pricing: 1000 tokens per credit, minimum 1; ai.journey.max_tokens 2000
 * (hold 3 credits). The fake answers with 1500 + 500 tokens → 2 credits
 * charged per call.
 */
class JourneyAiNodesTest extends TestCase
{
    use RefreshDatabase;

    private const PHONE = '919812345678';

    /** @var array{text: string, fail: ?AiException, calls: int, requests: list<AiRequest>, during: ?\Closure} */
    private array $ai;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        $this->seed(Phase1FoundationSeeder::class);

        Http::fake(function (HttpRequest $request) {
            if (str_contains($request->url(), 'graph.facebook.com')) {
                return Http::response(['messages' => [['id' => 'wamid.'.uniqid()]]], 200);
            }

            return Http::response(['success' => true, 'message_id' => 'QR_'.uniqid()], 200);
        });

        config([
            'ai.default' => 'fake', 'ai.enabled' => ['fake'], 'ai.providers.fake' => ['model' => 'fake-model'],
            'ai.credits.tokens_per_credit' => 1000, 'ai.credits.minimum_per_operation' => 1,
            'ai.credits.tokens_per_credit_overrides' => [], 'ai.credits.missing_usage' => 'minimum',
            'ai.credits.stale_after_seconds' => 900,
            'ai.journey.max_tokens' => 2000, 'ai.journey.allowed_models' => [],
        ]);

        $this->ai = ['text' => 'AI says hi', 'fail' => null, 'calls' => 0, 'requests' => [], 'during' => null];
        $test = $this;
        app(AiManager::class)->extend('fake', fn () => new class($test) implements AiProvider {
            public function __construct(private readonly JourneyAiNodesTest $test) {}
            public function name(): string { return 'fake'; }
            public function generateText(AiRequest $request): AiResponse { return $this->test->fakeAnswer($request); }
            public function generateStructured(AiRequest $request): AiResponse { return $this->test->fakeAnswer($request)->withData([]); }
        });
    }

    /** @internal called by the fake provider */
    public function fakeAnswer(AiRequest $request): AiResponse
    {
        $this->ai['calls']++;
        $this->ai['requests'][] = $request;

        if ($this->ai['during']) {
            $during = $this->ai['during'];
            $this->ai['during'] = null;
            $during();
        }

        if ($this->ai['fail'] !== null) {
            throw $this->ai['fail'];
        }

        return new AiResponse('fake', $request->model ?? 'fake-model', $this->ai['text'], null, 1500, 500, 'stop');
    }

    // ------------------------------------------------------------------ fixtures

    private function account(int $credits = 100, string $plan = 'growth', array $attributes = []): Account
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
        WhatsAppSession::create(['account_id' => $account->id, 'status' => 'connected'] + ($p['engine_type'] === 'meta' ? [
            'meta_phone_number_id' => '1098'.random_int(100000000, 999999999),
            'meta_waba_id' => '123456789012345',
            'meta_access_token' => 'EAAG_journey_ai_token_0123456789',
        ] : []));

        if ($credits > 0) {
            app(CreditService::class)->grant($account->fresh(), $credits, 'test:grant:'.uniqid());
        }

        return $account->fresh();
    }

    private function prompt(string $id, string $prompt, string $output = 'summary', array $extra = []): array
    {
        return ['id' => $id, 'type' => 'prompt', 'data' => ['prompt' => $prompt, 'outputVariable' => $output] + $extra];
    }

    private function agent(string $id, string $instructions, ?string $input, string $output = 'reply'): array
    {
        return ['id' => $id, 'type' => 'agent', 'data' => ['agentId' => 'Support', 'instructions' => $instructions, 'inputVariable' => $input, 'outputVariable' => $output]];
    }

    private function text(string $id, string $text): array
    {
        return ['id' => $id, 'type' => 'text', 'data' => ['text' => $text]];
    }

    private function q(string $id, string $variable, string $prompt): array
    {
        return ['id' => $id, 'type' => 'question', 'data' => ['prompt_text' => $prompt, 'variable_name' => $variable, 'input_type' => 'text']];
    }

    /** $edges as [source, target]; default: a chain in node order. */
    private function flow(Account $account, array $nodes, ?array $edges = null, string $keyword = 'go'): WhatsAppFlow
    {
        if ($edges === null) {
            $edges = [];
            for ($i = 1; $i < count($nodes); $i++) {
                $edges[] = [$nodes[$i - 1]['id'], $nodes[$i]['id']];
            }
        }

        $allEdges = [['id' => 'e_t', 'source' => 't', 'target' => $nodes[0]['id']]];
        foreach ($edges as $i => $edge) {
            $allEdges[] = ['id' => "e{$i}", 'source' => $edge[0], 'target' => $edge[1]];
        }

        return WhatsAppFlow::create([
            'account_id' => $account->id, 'name' => 'AI journey', 'trigger_type' => 'keyword', 'trigger_value' => $keyword,
            'is_active' => true,
            'graph_data' => ['nodes' => [['id' => 't', 'type' => 'trigger', 'data' => []], ...$nodes], 'edges' => $allEdges],
        ]);
    }

    private function inbound(Account $account, string $text): void
    {
        app(ChatbotEngineService::class)->handleInboundMessage($account->id, self::PHONE, $text, null, 'qr', 'wamsg:'.uniqid());
    }

    private function flowSession(Account $account): WhatsAppFlowSession
    {
        return WhatsAppFlowSession::where('account_id', $account->id)->where('phone_number', self::PHONE)->latest('id')->firstOrFail();
    }

    private function resume(WhatsAppFlowSession $session): string
    {
        return app(WhatsAppJourneyEngine::class)->resumeDueSession($session->id);
    }

    /** Make a parked session due now and resume it (one scheduler claim). */
    private function resumeDue(WhatsAppFlowSession $session): string
    {
        WhatsAppFlowSession::whereKey($session->id)->where('status', WhatsAppFlowSession::STATUS_WAITING)->update(['wait_until' => now()->subSecond()]);

        return $this->resume($session);
    }

    /** @return list<string> */
    private function sent(Account $account): array
    {
        return MessageDispatchLog::where('account_id', $account->id)->where('source', 'journey')->where('status', 'sent')
            ->orderBy('id')->pluck('message_preview')->all();
    }

    private function available(Account $account): int
    {
        return (int) app(CreditService::class)->balance($account->fresh())['available'];
    }

    private function revoke(Account $account, string $slug): void
    {
        AccountEntitlement::updateOrCreate(
            ['account_id' => $account->id, 'capability_id' => Capability::where('slug', $slug)->value('id')],
            ['source' => 'manual_grant', 'granted_by_account_id' => null, 'revoked_at' => now()],
        );
    }

    private function aiOps(Account $account)
    {
        return AiOperation::where('account_id', $account->id)->orderBy('id')->get();
    }

    private function events(WhatsAppFlowSession $session, string $event)
    {
        return JourneyExecutionEvent::where('session_id', $session->id)->where('event', $event)->orderBy('id')->get();
    }

    // ================================================================== prompt

    public function test_a_prompt_node_is_handed_to_the_worker_then_runs_metered_and_stores_its_reply(): void
    {
        $account = $this->account(100);
        $flow = $this->flow($account, [$this->prompt('p', 'Write a greeting.'), $this->text('s', 'Answer: {{ summary }}')]);

        $this->inbound($account, 'go');

        // Immediate (webhook) path: no provider call, parked due-now at the AI node for the journeys worker.
        $session = $this->flowSession($account);
        $this->assertSame(0, $this->ai['calls']);
        $this->assertSame(WhatsAppFlowSession::STATUS_WAITING, $session->status);
        $this->assertSame('p', $session->current_node_id);
        $this->assertNull($session->last_error);
        $this->assertSame('ai_queued', $this->events($session, JourneyExecutionEvent::SESSION_WAITING)->last()->result);
        $job = DB::table('jobs')->where('queue', 'journeys')->first();
        $this->assertNotNull($job, 'a ResumeJourneySessionJob was queued on database:journeys');
        $this->assertSame(ResumeJourneySessionJob::class, json_decode($job->payload, true)['displayName']);
        $this->assertSame(100, $this->available($account));

        $this->assertSame(WhatsAppFlowSession::STATUS_COMPLETED, $this->resume($session));

        $session->refresh();
        $this->assertSame(1, $this->ai['calls']);
        $this->assertSame('AI says hi', $session->context_data['summary']);
        $this->assertSame(['Answer: AI says hi'], $this->sent($account));
        $this->assertSame(98, $this->available($account), '2 credits charged (1500 + 500 tokens)');

        $op = $this->aiOps($account)->sole();
        $this->assertSame(AiOperation::STATUS_SETTLED, $op->status);
        $this->assertSame(sprintf('journey:f%d:s%d:n%s:v0', $flow->id, $session->id, JourneyAiNodeRunner::nodeKey('p')), $op->operation_key);
        $this->assertSame(['journey', 'journey.prompt', 2], [$op->source, $op->operation, (int) $op->credits_charged]);
        $this->assertNull($op->actor_user_id, 'automated: no user');

        $succeeded = $this->events($session, JourneyExecutionEvent::NODE_SUCCEEDED)->firstWhere('node_id', 'p');
        $this->assertSame('ai_response', $succeeded->result);
        $this->assertSame($op->id, $succeeded->details['ai_operation_id']);
    }

    public function test_the_prompt_receives_only_the_variables_it_names_and_no_history(): void
    {
        $account = $this->account(100);
        $this->flow($account, [
            $this->q('q1', 'city', 'Your city?'),
            $this->q('q2', 'secret', 'Your PIN?'),
            $this->prompt('p', 'Suggest a cafe in {{ city }}.'),
        ]);

        $this->inbound($account, 'go');
        $this->inbound($account, 'Pune');
        $this->inbound($account, '4321');
        $this->resume($this->flowSession($account));

        $request = $this->ai['requests'][0];
        $this->assertSame('Suggest a cafe in Pune.', $request->prompt);
        $this->assertNull($request->system);
        $this->assertSame(2000, $request->maxTokens, 'bounded by ai.journey.max_tokens');
        $this->assertSame('journey.prompt', $request->operation);
        foreach (['4321', self::PHONE, 'go'] as $absent) {
            $this->assertStringNotContainsString($absent, $request->prompt.' '.$request->system);
        }
    }

    public function test_the_model_hint_is_used_only_when_the_platform_allows_it(): void
    {
        $account = $this->account(100);
        $this->flow($account, [$this->prompt('p', 'Hi', 'a', ['model' => 'expensive-model'])]);
        $this->inbound($account, 'go');
        $this->resume($this->flowSession($account));
        $this->assertNull($this->ai['requests'][0]->model, 'not allow-listed: provider default');

        config(['ai.journey.allowed_models' => ['fake:expensive-model']]);
        $other = $this->account(100);
        $this->flow($other, [$this->prompt('p', 'Hi', 'a', ['model' => 'expensive-model'])]);
        $this->inbound($other, 'go');
        $this->resume($this->flowSession($other));
        $this->assertSame('expensive-model', $this->ai['requests'][1]->model);
    }

    public function test_a_long_reply_is_stored_trimmed_and_cut_to_the_configured_length(): void
    {
        config(['ai.journey.max_output_chars' => 10]);
        $this->ai['text'] = "  0123456789ABCDEF \n";
        $account = $this->account(100);
        $this->flow($account, [$this->prompt('p', 'Hi')]);
        $this->inbound($account, 'go');
        $this->resume($this->flowSession($account));

        $this->assertSame('0123456789', $this->flowSession($account)->context_data['summary']);
    }

    public function test_a_prompt_that_renders_empty_fails_without_a_call_or_charge(): void
    {
        $account = $this->account(100);
        $this->flow($account, [$this->prompt('p', '{{ nothing }}'), $this->text('s', 'never')]);
        $this->inbound($account, 'go');
        $this->resume($session = $this->flowSession($account));

        $session->refresh();
        $this->assertSame(WhatsAppFlowSession::STATUS_FAILED, $session->status);
        $this->assertStringContainsString('empty once its variables are filled in', $session->last_error);
        $this->assertSame([0, [], 100, 0], [$this->ai['calls'], $this->sent($account), $this->available($account), $this->aiOps($account)->count()]);
    }

    // ================================================================== agent

    public function test_an_agent_node_sends_its_instructions_and_one_input_variable_and_stores_the_reply(): void
    {
        $this->ai['text'] = 'We open at 9.';
        $account = $this->account(100);
        $this->flow($account, [
            $this->q('q', 'question', 'Ask us anything'),
            $this->agent('a', 'You are the helpdesk of {{ shop }}. Answer briefly.', 'question'),
            $this->text('s', '{{ reply }}'),
        ]);

        $this->inbound($account, 'go');
        $this->inbound($account, 'When do you open?');
        $session = $this->flowSession($account);
        $this->assertSame([WhatsAppFlowSession::STATUS_WAITING, 'a', 0], [$session->status, $session->current_node_id, $this->ai['calls']]);

        $this->assertSame(WhatsAppFlowSession::STATUS_COMPLETED, $this->resume($session));

        $request = $this->ai['requests'][0];
        $this->assertSame('You are the helpdesk of . Answer briefly.', $request->system);
        $this->assertSame('When do you open?', $request->prompt);
        $this->assertSame('journey.agent', $request->operation);
        $this->assertSame('We open at 9.', $session->fresh()->context_data['reply']);
        $this->assertSame(['Ask us anything', 'We open at 9.'], $this->sent($account));
        $this->assertSame(1, $this->ai['calls'], 'bounded: exactly one model call, no tool loop');
        $this->assertSame(98, $this->available($account));
    }

    public function test_an_agent_without_an_input_variable_acts_on_its_instructions_alone(): void
    {
        $account = $this->account(100);
        $this->flow($account, [$this->agent('a', 'Say hello.', null)]);
        $this->inbound($account, 'go');
        $this->resume($this->flowSession($account));

        $this->assertSame('Say hello.', $this->ai['requests'][0]->prompt);
        $this->assertNull($this->ai['requests'][0]->system);
    }

    public function test_an_agent_whose_input_variable_is_empty_fails_without_a_call_or_charge(): void
    {
        $account = $this->account(100);
        $this->flow($account, [$this->agent('a', 'Answer.', 'never_collected')]);
        $this->inbound($account, 'go');
        $this->resume($session = $this->flowSession($account));

        $session->refresh();
        $this->assertSame(WhatsAppFlowSession::STATUS_FAILED, $session->status);
        $this->assertStringContainsString("input variable 'never_collected' is empty", $session->last_error);
        $this->assertSame([0, 100, 0], [$this->ai['calls'], $this->available($account), $this->aiOps($account)->count()]);
    }

    // ================================================================== rag

    public function test_a_rag_node_is_executable_but_never_publishable_with_an_invalid_knowledge_base_id(): void
    {
        // Phase 8 Task 10 — rag runs now (JourneyRagNodeTest covers execution);
        // a non-numeric id could never resolve, so it is refused at publish.
        $this->assertTrue(JourneyNodeCatalog::isRuntimeExecutable('rag'));
        $this->assertInstanceOf(\App\Services\Knowledge\DatabaseKnowledgeRetriever::class, app(KnowledgeRetriever::class));

        $rag = ['id' => 'r', 'type' => 'rag', 'data' => ['knowledgeBaseId' => 'kb1', 'queryVariable' => 'q', 'topK' => 3, 'outputVariable' => 'answer']];
        $errors = JourneyPublishValidator::errors([['id' => 't', 'type' => 'trigger', 'data' => []], $rag], [['id' => 'e', 'source' => 't', 'target' => 'r']]);
        $this->assertNotEmpty($errors);
    }

    public function test_prompt_and_agent_journeys_are_publishable(): void
    {
        $nodes = [['id' => 't', 'type' => 'trigger', 'data' => []], $this->prompt('p', 'Hi'), $this->agent('a', 'Be kind.', 'summary')];
        $edges = [['id' => 'e1', 'source' => 't', 'target' => 'p'], ['id' => 'e2', 'source' => 'p', 'target' => 'a']];

        $this->assertSame([], JourneyPublishValidator::errors($nodes, $edges));

        $nodes[1]['data']['outputVariable'] = '';
        $this->assertNotEmpty(JourneyPublishValidator::errors($nodes, $edges), 'an unconfigured AI node is not publishable');
    }

    // ================================================================== authorization

    public function test_without_the_ai_capability_the_node_is_refused_with_no_call_and_no_charge(): void
    {
        $account = $this->account(100);
        $this->flow($account, [$this->prompt('p', 'Hi'), $this->text('s', 'never')]);
        $this->inbound($account, 'go');
        $this->revoke($account, 'ai');

        $this->resume($session = $this->flowSession($account));

        $session->refresh();
        $this->assertSame([WhatsAppFlowSession::STATUS_FAILED, 'p'], [$session->status, $session->current_node_id]);
        $this->assertSame('entitlement_blocked', $this->events($session, JourneyExecutionEvent::SESSION_FAILED)->last()->error_category);
        $this->assertSame([0, [], 100, 0], [$this->ai['calls'], $this->sent($account), $this->available($account), $this->aiOps($account)->count()]);
    }

    public function test_a_suspended_account_is_refused_by_the_ai_authorizer(): void
    {
        $account = $this->account(100);
        $this->flow($account, [$this->prompt('p', 'Hi')]);
        $this->inbound($account, 'go');
        $account->forceFill(['status' => 'suspended'])->save();

        $this->resume($session = $this->flowSession($account));

        $session->refresh();
        $this->assertSame(WhatsAppFlowSession::STATUS_FAILED, $session->status, 'permanent, as JourneySendGate treats a suspended account');
        $this->assertStringContainsString('CLIENT_ACCOUNT_SUSPENDED', $session->last_error);
        $this->assertSame('entitlement_blocked', $this->events($session, JourneyExecutionEvent::SESSION_FAILED)->last()->error_category);
        $this->assertSame([0, 0], [$this->ai['calls'], $this->aiOps($account)->count()]);
        $this->assertSame(100, $this->available($account));
    }

    public function test_an_expired_subscription_is_retried_like_an_expired_send_never_called(): void
    {
        $account = $this->account(100);
        $this->flow($account, [$this->prompt('p', 'Hi')]);
        $this->inbound($account, 'go');
        $account->currentSubscription->forceFill(['expires_at' => now()->subDay()])->save();

        $this->assertSame('retrying', $this->resume($session = $this->flowSession($account)));
        $this->assertStringContainsString('SUBSCRIPTION_EXPIRED', $session->fresh()->last_error);
        $this->assertSame('quota_failure', $this->events($session, JourneyExecutionEvent::NODE_RETRY_SCHEDULED)->last()->error_category);
        $this->assertSame([0, 100], [$this->ai['calls'], $this->available($account)]);
    }

    public function test_with_the_journey_module_disabled_the_session_is_blocked_before_the_ai_node(): void
    {
        $account = $this->account(100);
        $this->flow($account, [$this->prompt('p', 'Hi')]);
        $this->inbound($account, 'go');
        $account->forceFill(['allowed_modules' => array_values(array_diff(Account::MODULES, ['chatbot']))])->save();

        $this->assertSame(WhatsAppFlowSession::STATUS_BLOCKED, $this->resume($session = $this->flowSession($account)));
        $this->assertSame('p', $session->fresh()->current_node_id, 'state kept for restoration');
        $this->assertSame([0, 100], [$this->ai['calls'], $this->available($account)]);
    }

    public function test_the_journeys_own_account_pays_even_for_an_agents_client(): void
    {
        $agent = $this->account(50, 'growth', ['account_type' => 'agent']);
        $client = $this->account(100, 'growth', ['agent_id' => $agent->id]);
        $this->flow($client, [$this->prompt('p', 'Hi')]);

        $this->inbound($client, 'go');
        $this->resume($this->flowSession($client));

        $this->assertSame([98, 50], [$this->available($client), $this->available($agent)]);
        $this->assertSame((int) $client->id, (int) $this->aiOps($client)->sole()->account_id);
        $this->assertSame(0, AiOperation::where('account_id', $agent->id)->count());
    }

    // ================================================================== credits

    public function test_insufficient_credits_never_call_the_provider_or_continue_and_fail_once_retries_run_out(): void
    {
        $account = $this->account(0);
        $this->flow($account, [$this->prompt('p', 'Hi'), $this->text('s', 'never')]);
        $this->inbound($account, 'go');
        $session = $this->flowSession($account);

        $this->assertSame('retrying', $this->resume($session));
        $session->refresh();
        $this->assertSame([WhatsAppFlowSession::STATUS_WAITING, 'p'], [$session->status, $session->current_node_id]);
        $this->assertStringContainsString('INSUFFICIENT_CREDITS', $session->last_error);
        $this->assertSame('quota_failure', $this->events($session, JourneyExecutionEvent::NODE_RETRY_SCHEDULED)->last()->error_category);

        for ($i = 2; $i <= WhatsAppJourneyEngine::MAX_RESUME_ATTEMPTS; $i++) {
            $this->resumeDue($session);
        }

        $session->refresh();
        $this->assertSame(WhatsAppFlowSession::STATUS_FAILED, $session->status);
        $this->assertStringContainsString('INSUFFICIENT_CREDITS', $session->last_error);
        $this->assertSame(0, $this->ai['calls']);
        $this->assertSame([], $this->sent($account), 'never continued past the AI node');
        $this->assertSame(0, CreditReservation::where('account_id', $account->id)->count(), 'no hold, no partial debit');
        $this->assertSame(0, CreditLedgerEntry::where('account_id', $account->id)->count());
    }

    public function test_a_topped_up_account_succeeds_on_the_retry(): void
    {
        $account = $this->account(0);
        $this->flow($account, [$this->prompt('p', 'Hi'), $this->text('s', '{{summary}}')]);
        $this->inbound($account, 'go');
        $session = $this->flowSession($account);
        $this->resume($session);

        app(CreditService::class)->grant($account, 10, 'test:topup');
        $this->assertSame(WhatsAppFlowSession::STATUS_COMPLETED, $this->resumeDue($session));
        $this->assertSame(['AI says hi'], $this->sent($account));
        $this->assertSame(8, $this->available($account));
    }

    // ================================================================== provider failure

    public function test_a_provider_failure_releases_the_hold_charges_nothing_and_retries_the_same_operation(): void
    {
        $this->ai['fail'] = AiException::timeout('fake');
        $account = $this->account(100);
        $this->flow($account, [$this->prompt('p', 'Hi'), $this->text('s', '{{summary}}')]);
        $this->inbound($account, 'go');
        $session = $this->flowSession($account);

        $this->assertSame('retrying', $this->resume($session));
        $session->refresh();
        $this->assertSame([WhatsAppFlowSession::STATUS_WAITING, 'p'], [$session->status, $session->current_node_id]);
        $this->assertStringContainsString('AI_PROVIDER_TIMEOUT', $session->last_error);
        $this->assertSame('provider_failure', $this->events($session, JourneyExecutionEvent::NODE_RETRY_SCHEDULED)->last()->error_category);
        $this->assertSame(100, $this->available($account), 'hold released');
        $this->assertSame(AiOperation::STATUS_FAILED, $this->aiOps($account)->sole()->status);
        $this->assertSame([], $this->sent($account));

        $this->ai['fail'] = null;
        $this->assertSame(WhatsAppFlowSession::STATUS_COMPLETED, $this->resumeDue($session));

        $op = $this->aiOps($account)->sole();
        $this->assertSame([AiOperation::STATUS_SETTLED, 2], [$op->status, (int) $op->attempt], 'same key, second attempt');
        $this->assertSame(98, $this->available($account), 'charged exactly once');
        $this->assertSame(['AI says hi'], $this->sent($account));
    }

    public function test_exhausted_provider_retries_fail_the_session_safely_with_no_charge(): void
    {
        $this->ai['fail'] = AiException::providerFailed('fake');
        $account = $this->account(100);
        $this->flow($account, [$this->prompt('p', 'Hi'), $this->text('s', 'never')]);
        $this->inbound($account, 'go');
        $session = $this->flowSession($account);

        $this->resume($session);
        for ($i = 2; $i <= WhatsAppJourneyEngine::MAX_RESUME_ATTEMPTS; $i++) {
            $this->resumeDue($session);
        }

        $session->refresh();
        $this->assertSame([WhatsAppFlowSession::STATUS_FAILED, 'p'], [$session->status, $session->current_node_id]);
        $this->assertSame("Node 'p' (prompt): AI_PROVIDER_FAILED — The AI provider 'fake' could not complete the request.", $session->last_error, 'a safe message: no vendor body, no prompt');
        $this->assertSame(WhatsAppJourneyEngine::MAX_RESUME_ATTEMPTS, $this->ai['calls']);
        $this->assertSame(100, $this->available($account));
        $this->assertSame(0, CreditReservation::where('account_id', $account->id)->where('status', CreditReservation::STATUS_RESERVED)->count());
        $this->assertSame([], $this->sent($account));
    }

    public function test_an_empty_ai_reply_is_not_charged_and_is_retried(): void
    {
        $this->ai['text'] = "   \n";
        $account = $this->account(100);
        $this->flow($account, [$this->prompt('p', 'Hi')]);
        $this->inbound($account, 'go');

        $this->assertSame('retrying', $this->resume($session = $this->flowSession($account)));
        $this->assertStringContainsString('AI_MALFORMED_RESPONSE', $session->fresh()->last_error);
        $this->assertSame(100, $this->available($account));
    }

    // ================================================================== delay / resume

    public function test_an_ai_node_after_a_delay_runs_in_the_resumed_worker_run(): void
    {
        $account = $this->account(100);
        $this->flow($account, [
            ['id' => 'd', 'type' => 'delay', 'data' => ['amount' => 5, 'unit' => 'minutes']],
            $this->prompt('p', 'Hi'),
            $this->text('s', 'Later: {{summary}}'),
        ]);

        $this->inbound($account, 'go');
        $session = $this->flowSession($account);
        $this->assertSame([WhatsAppFlowSession::STATUS_WAITING, 'd'], [$session->status, $session->current_node_id]);

        $this->travel(6)->minutes();
        $this->assertSame(WhatsAppFlowSession::STATUS_COMPLETED, $this->resume($session));
        $this->assertSame(['Later: AI says hi'], $this->sent($account));
        $this->assertSame(1, $this->ai['calls']);
    }

    public function test_the_scheduler_command_picks_up_a_handed_off_ai_node(): void
    {
        $account = $this->account(100);
        $this->flow($account, [$this->prompt('p', 'Hi'), $this->text('s', '{{summary}}')]);
        $this->inbound($account, 'go');

        // The worker drains database:journeys (the job queued by the hand-off; the
        // resume-due scan would dispatch it too — the claim makes duplicates no-ops).
        $this->artisan('journeys:resume-due')->assertSuccessful();
        $this->artisan('queue:work', ['connection' => 'database', '--queue' => 'journeys', '--stop-when-empty' => true, '--memory' => 4096])->assertSuccessful();

        $this->assertSame(WhatsAppFlowSession::STATUS_COMPLETED, $this->flowSession($account)->status);
        $this->assertSame(['AI says hi'], $this->sent($account));
        $this->assertSame(1, $this->ai['calls'], 'duplicate jobs did not run the node twice');
        $this->assertSame(98, $this->available($account));
    }

    public function test_a_cancellation_during_the_ai_call_keeps_the_session_cancelled(): void
    {
        $account = $this->account(100);
        $this->flow($account, [$this->prompt('p', 'Hi'), $this->text('s', 'never')]);
        $this->inbound($account, 'go');
        $session = $this->flowSession($account);
        $this->ai['during'] = fn () => WhatsAppFlowSession::whereKey($session->id)->update(['status' => WhatsAppFlowSession::STATUS_CANCELLED]);

        $this->resume($session);

        $session->refresh();
        $this->assertSame(WhatsAppFlowSession::STATUS_CANCELLED, $session->status);
        $this->assertArrayNotHasKey('summary', $session->context_data ?? []);
        $this->assertSame([], $this->sent($account));
        $this->assertSame(98, $this->available($account), 'the answer that was produced is charged once');
    }

    // ================================================================== idempotency

    public function test_a_lost_reply_is_never_re_run_or_charged_twice(): void
    {
        $account = $this->account(100);
        $this->flow($account, [$this->prompt('p', 'Hi'), $this->text('s', 'after')]);
        $this->inbound($account, 'go');
        $session = $this->flowSession($account);
        $this->resume($session);
        $this->assertSame(98, $this->available($account));

        // Simulate a worker that died after the charge but before the checkpoint write:
        // the session is back at the AI node with the pre-run context.
        WhatsAppFlowSession::whereKey($session->id)->update([
            'status' => WhatsAppFlowSession::STATUS_WAITING, 'current_node_id' => 'p', 'context_data' => json_encode([]),
            'wait_until' => now()->subSecond(), 'attempts' => 0, 'last_error' => null,
        ]);

        $this->resume($session);

        $session->refresh();
        $this->assertSame(WhatsAppFlowSession::STATUS_FAILED, $session->status);
        $this->assertStringContainsString('not run again, to avoid a second charge', $session->last_error);
        $this->assertSame(1, $this->ai['calls']);
        $this->assertSame(98, $this->available($account), 'charged once');
        $this->assertSame(1, $this->aiOps($account)->count());
    }

    public function test_a_step_whose_previous_run_died_mid_call_waits_until_that_run_can_be_abandoned(): void
    {
        $account = $this->account(100);
        $flow = $this->flow($account, [$this->prompt('p', 'Hi'), $this->text('s', '{{summary}}')]);
        $this->inbound($account, 'go');
        $session = $this->flowSession($account);
        $key = sprintf('journey:f%d:s%d:n%s:v0', $flow->id, $session->id, JourneyAiNodeRunner::nodeKey('p'));

        // A previous worker claimed the operation and died (row 'running', recent).
        DB::table('ai_operations')->insert([
            'account_id' => $account->id, 'operation_key' => $key, 'attempt' => 1, 'source' => 'journey', 'operation' => 'journey.prompt',
            'provider' => 'fake', 'status' => AiOperation::STATUS_RUNNING, 'credits_reserved' => 0, 'credits_uncharged' => 0,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->assertSame('retrying', $this->resume($session));
        $session->refresh();
        $this->assertSame(0, $this->ai['calls']);
        $this->assertTrue($session->wait_until->greaterThanOrEqualTo(now()->addSeconds(900)), 'not retried before the stale window');

        $this->travel(906)->seconds();
        $this->assertSame(WhatsAppFlowSession::STATUS_COMPLETED, $this->resume($session));

        $op = AiOperation::where('operation_key', $key)->sole();
        $this->assertSame([AiOperation::STATUS_SETTLED, 2], [$op->status, (int) $op->attempt]);
        $this->assertSame(1, $this->ai['calls']);
        $this->assertSame(98, $this->available($account));
    }

    public function test_a_loop_back_to_the_node_is_a_legitimate_second_execution(): void
    {
        $account = $this->account(100);
        $flow = $this->flow($account, [$this->q('q', 'topic', 'Topic?'), $this->prompt('p', 'About {{topic}}')], [['q', 'p'], ['p', 'q']]);

        $this->inbound($account, 'go');
        $this->inbound($account, 'tea');
        $this->resume($session = $this->flowSession($account));
        $this->inbound($account, 'coffee');
        $this->resume($session);

        $session->refresh();
        $this->assertSame(['About tea', 'About coffee'], array_map(fn (AiRequest $r) => $r->prompt, $this->ai['requests']));
        $this->assertSame([WhatsAppFlowSession::STATUS_ACTIVE, 'q'], [$session->status, $session->current_node_id]);
        $this->assertSame(2, $session->context_data[JourneyAiNodeRunner::RUNS_KEY][JourneyAiNodeRunner::nodeKey('p')]);
        $keys = $this->aiOps($account)->pluck('operation_key')->all();
        $prefix = sprintf('journey:f%d:s%d:n%s:', $flow->id, $session->id, JourneyAiNodeRunner::nodeKey('p'));
        $this->assertSame([$prefix.'v0', $prefix.'v1'], $keys);
        $this->assertSame(96, $this->available($account), 'two executions, two charges');
    }

    public function test_the_internal_run_counter_is_not_a_template_variable(): void
    {
        $this->assertSame('{{@ai_runs}}', JourneyActionConfig::renderText('{{@ai_runs}}', [JourneyAiNodeRunner::RUNS_KEY => 'x']), 'left as written, never substituted');
        $this->assertSame(0, preg_match(JourneyActionConfig::VARIABLE_NAME_PATTERN, JourneyAiNodeRunner::RUNS_KEY), 'no node can write it as an output variable');
    }

    // ================================================================== configuration contract

    public static function configs(): array
    {
        return [
            'prompt ok' => ['prompt', ['prompt' => 'Hi {{name}}', 'outputVariable' => 'answer'], null, null],
            'prompt with model hint' => ['prompt', ['prompt' => 'Hi', 'outputVariable' => 'a', 'model' => 'x'], null, null],
            'prompt empty' => ['prompt', ['prompt' => ' ', 'outputVariable' => 'a'], 'non-empty prompt', null],
            'prompt no output' => ['prompt', ['prompt' => 'Hi'], 'output variable', null],
            'prompt bad output' => ['prompt', ['prompt' => 'Hi', 'outputVariable' => 'has space'], 'only letters', 'only letters'],
            'prompt reserved output' => ['prompt', ['prompt' => 'Hi', 'outputVariable' => '@ai_runs'], 'only letters', 'only letters'],
            'prompt model not text' => ['prompt', ['prompt' => 'Hi', 'outputVariable' => 'a', 'model' => 5], 'model must be text', 'model must be text'],
            'legacy draft prompt' => ['prompt', ['prompt' => ''], 'non-empty prompt', null],
            'agent ok' => ['agent', ['agentId' => 'S', 'instructions' => 'Be kind', 'inputVariable' => 'q', 'outputVariable' => 'r'], null, null],
            'agent no input' => ['agent', ['agentId' => 'S', 'instructions' => 'Be kind', 'inputVariable' => '', 'outputVariable' => 'r'], null, null],
            'agent no instructions' => ['agent', ['agentId' => 'S', 'outputVariable' => 'r'], 'non-empty instructions', null],
            'agent bad input' => ['agent', ['instructions' => 'x', 'inputVariable' => 'a b', 'outputVariable' => 'r'], 'input variable', 'input variable'],
            'agent input not text' => ['agent', ['instructions' => 'x', 'inputVariable' => ['a'], 'outputVariable' => 'r'], 'input variable', 'input variable'],
            'data not an object' => ['agent', 'x', 'must be an object', 'must be an object'],
        ];
    }

    #[DataProvider('configs')]
    public function test_the_ai_node_configuration_contract(string $type, mixed $data, ?string $runError, ?string $draftError): void
    {
        $run = JourneyActionConfig::error($type, $data);
        $draft = JourneyActionConfig::error($type, $data, draft: true);

        $runError === null ? $this->assertNull($run) : $this->assertStringContainsString($runError, (string) $run);
        $draftError === null ? $this->assertNull($draft) : $this->assertStringContainsString($draftError, (string) $draft);
    }

    // ================================================================== static guarantees

    public function test_journey_code_never_reaches_a_vendor_or_the_ledger_directly(): void
    {
        foreach (['app/Services/WhatsApp/JourneyAiNodeRunner.php', 'app/Services/WhatsApp/WhatsAppJourneyEngine.php'] as $file) {
            $source = (string) file_get_contents(base_path($file));

            foreach (['OpenAi', 'Anthropic', 'Gemini', 'api_key', 'Http::', 'CreditService', 'CreditConsumptionService', 'AiService::class', '->generateText(\$request'] as $forbidden) {
                $this->assertStringNotContainsStringIgnoringCase($forbidden, $source, "{$file} must not reference {$forbidden}");
            }
        }

        $runner = (string) file_get_contents(base_path('app/Services/WhatsApp/JourneyAiNodeRunner.php'));
        $this->assertStringContainsString('MeteredAiService $metered', $runner);
        $this->assertStringContainsString('->forAccount($account, self::MODULE, self::SOURCE)', $runner);
        $this->assertStringNotContainsString('Log::', $runner, 'prompts and replies are never logged here');
    }
}
