<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\AccountEntitlement;
use App\Models\AiOperation;
use App\Models\Capability;
use App\Models\CreditReservation;
use App\Models\Invoice;
use App\Models\JourneyExecutionEvent;
use App\Models\KnowledgeBase;
use App\Models\KnowledgeDocument;
use App\Models\MessageDispatchLog;
use App\Models\User;
use App\Models\WhatsAppFlow;
use App\Models\WhatsAppFlowSession;
use App\Models\WhatsAppSession;
use App\Services\Ai\AiException;
use App\Services\Ai\AiManager;
use App\Services\Ai\Contracts\AiProvider;
use App\Services\Ai\Contracts\EmbeddingProvider;
use App\Services\Ai\Data\AiRequest;
use App\Services\Ai\Data\AiResponse;
use App\Services\Ai\Data\EmbeddingRequest;
use App\Services\Ai\Data\EmbeddingResponse;
use App\Services\Billing\InvoiceCreditService;
use App\Services\Chatbot\ChatbotEngineService;
use App\Services\Credits\CreditService;
use App\Services\Knowledge\KnowledgeBaseService;
use App\Services\Knowledge\KnowledgeIngestionService;
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
 * Phase 8 Task 10 — the Journey `rag` node, executed end to end:
 * inbound → hand-off to the journeys worker → KnowledgeRetriever (query
 * embedding billed, key …:q) → hits cached in the session → one metered
 * generation over the passages (key …:g) → answer in the output variable.
 *
 * The AI vendor is ONE in-process fake registered on AiManager that both
 * embeds (deterministic bag-of-words vectors) and generates; WhatsApp sends
 * are faked at the HTTP boundary.
 *
 * Pricing: 1000 tokens per credit, minimum 1. Every embedding reports one
 * token per word (a short query → 1 credit); every generation reports
 * 1500 + 500 tokens → 2 credits (hold ≥ 3 with ai.journey.max_tokens 2000).
 */
class JourneyRagNodeTest extends TestCase
{
    use RefreshDatabase;

    private const PHONE = '919812345678';

    private const POLICIES = "Refund policy. Customers may request a refund within thirty days of purchase.\n\n"
        ."Shipping. Orders ship within two business days. Express shipping costs extra.\n\n"
        ."Warranty. Every device carries a one year warranty covering manufacturing defects.";

    /** @var array{genCalls: int, embedCalls: int, requests: list<AiRequest>, genFail: ?AiException, text: string, during: ?\Closure} */
    private array $ai;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        $this->seed(Phase1FoundationSeeder::class);

        Http::fake(fn (HttpRequest $r) => Http::response(['success' => true, 'message_id' => 'QR_'.uniqid()], 200));

        config([
            'ai.default' => 'fake', 'ai.enabled' => ['fake'], 'ai.providers.fake' => ['model' => 'fake-model', 'embedding_model' => 'bow'],
            'ai.embeddings.provider' => 'fake',
            'ai.credits.tokens_per_credit' => 1000, 'ai.credits.minimum_per_operation' => 1,
            'ai.credits.tokens_per_credit_overrides' => [], 'ai.credits.missing_usage' => 'minimum', 'ai.credits.stale_after_seconds' => 900,
            'ai.journey.max_tokens' => 2000, 'ai.journey.allowed_models' => [],
            'ai.knowledge.chunk_chars' => 200, 'ai.knowledge.chunk_overlap_chars' => 0, 'ai.knowledge.embedding_batch_size' => 10,
            'ai.knowledge.max_results' => 20,
        ]);

        $this->ai = ['genCalls' => 0, 'embedCalls' => 0, 'requests' => [], 'genFail' => null, 'text' => 'The warranty lasts one year.', 'during' => null];
        $test = $this;
        app(AiManager::class)->extend('fake', fn () => new class($test) implements AiProvider, EmbeddingProvider {
            public function __construct(private readonly JourneyRagNodeTest $test) {}
            public function name(): string { return 'fake'; }
            public function generateText(AiRequest $r): AiResponse { return $this->test->fakeGenerate($r); }
            public function generateStructured(AiRequest $r): AiResponse { return $this->test->fakeGenerate($r)->withData([]); }
            public function embed(EmbeddingRequest $r): EmbeddingResponse { return $this->test->fakeEmbed($r); }
        });
        app(AiManager::class)->flush();
    }

    /** @internal */
    public function fakeGenerate(AiRequest $request): AiResponse
    {
        $this->ai['genCalls']++;
        $this->ai['requests'][] = $request;

        if ($this->ai['during']) {
            $during = $this->ai['during'];
            $this->ai['during'] = null;
            $during();
        }

        if ($this->ai['genFail'] !== null) {
            throw $this->ai['genFail'];
        }

        return new AiResponse('fake', 'fake-model', $this->ai['text'], null, 1500, 500, 'stop');
    }

    /** @internal */
    public function fakeEmbed(EmbeddingRequest $request): EmbeddingResponse
    {
        $this->ai['embedCalls']++;
        $vectors = array_map(function (string $text) {
            $v = array_fill(0, 256, 0.0);
            foreach (preg_split('/[^a-z0-9]+/', strtolower($text), -1, PREG_SPLIT_NO_EMPTY) as $w) {
                $v[crc32($w) % 256] += 1.0;
            }
            $v[255] += 0.01;

            return $v;
        }, $request->inputs);

        return new EmbeddingResponse('fake', 'bow', $vectors, array_sum(array_map('str_word_count', $request->inputs)));
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
        WhatsAppSession::create(['account_id' => $account->id, 'status' => 'connected']);
        if ($credits > 0) {
            app(CreditService::class)->grant($account->fresh(), $credits, 'test:grant:'.uniqid());
        }

        return $account->fresh();
    }

    /** A knowledge base of $account with $content indexed (its embedding charges are then refunded so tests start from a round balance). */
    private function indexedBase(Account $account, string $content = self::POLICIES, string $name = 'Policies'): KnowledgeBase
    {
        $base = app(KnowledgeBaseService::class)->createKnowledgeBase($account, $name, null, null);
        $document = app(KnowledgeBaseService::class)->submitDocument($base, 'Store policies', $content)['document'];
        $this->assertSame('ready', app(KnowledgeIngestionService::class)->process($document->id, 1));
        $this->ai['embedCalls'] = 0;
        $this->topUpTo($account, 100);

        return $base->fresh();
    }

    private function topUpTo(Account $account, int $credits): void
    {
        $available = $this->available($account);
        if ($available < $credits) {
            app(CreditService::class)->grant($account->fresh(), $credits - $available, 'test:topup:'.uniqid());
        }
    }

    private function rag(string $id, int|string $baseId, string $query = 'question', string $output = 'answer', mixed $topK = 3): array
    {
        return ['id' => $id, 'type' => 'rag', 'data' => ['knowledgeBaseId' => (string) $baseId, 'queryVariable' => $query, 'topK' => $topK, 'outputVariable' => $output]];
    }

    private function q(string $id, string $variable = 'question'): array
    {
        return ['id' => $id, 'type' => 'question', 'data' => ['prompt_text' => 'Ask us', 'variable_name' => $variable, 'input_type' => 'text']];
    }

    private function text(string $id, string $text): array
    {
        return ['id' => $id, 'type' => 'text', 'data' => ['text' => $text]];
    }

    private function flow(Account $account, array $nodes, ?array $edges = null): WhatsAppFlow
    {
        if ($edges === null) {
            $edges = [];
            for ($i = 1; $i < count($nodes); $i++) {
                $edges[] = [$nodes[$i - 1]['id'], $nodes[$i]['id']];
            }
        }
        $all = [['id' => 'e_t', 'source' => 't', 'target' => $nodes[0]['id']]];
        foreach ($edges as $i => $edge) {
            $all[] = ['id' => "e{$i}", 'source' => $edge[0], 'target' => $edge[1]];
        }

        return WhatsAppFlow::create([
            'account_id' => $account->id, 'name' => 'RAG', 'trigger_type' => 'keyword', 'trigger_value' => 'go', 'is_active' => true,
            'graph_data' => ['nodes' => [['id' => 't', 'type' => 'trigger', 'data' => []], ...$nodes], 'edges' => $all],
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

    private function resumeDue(WhatsAppFlowSession $session): string
    {
        WhatsAppFlowSession::whereKey($session->id)->where('status', 'waiting')->update(['wait_until' => now()->subSecond()]);

        return $this->resume($session);
    }

    private function available(Account $account): int
    {
        return (int) app(CreditService::class)->balance($account->fresh())['available'];
    }

    /** @return list<string> */
    private function sent(Account $account): array
    {
        return MessageDispatchLog::where('account_id', $account->id)->where('source', 'journey')->where('status', 'sent')->orderBy('id')->pluck('message_preview')->all();
    }

    private function key(WhatsAppFlow $flow, WhatsAppFlowSession $session, string $node, int $visit, string $suffix): string
    {
        return sprintf('journey:f%d:s%d:n%s:v%d:%s', $flow->id, $session->id, JourneyAiNodeRunner::nodeKey($node), $visit, $suffix);
    }

    private function ask(Account $account, string $question = 'How long is the warranty on a device?'): WhatsAppFlowSession
    {
        $this->inbound($account, 'go');
        $this->inbound($account, $question);

        return $this->flowSession($account);
    }

    private function user(Account $account, string $role = 'admin'): User
    {
        $user = User::factory()->create(['account_id' => $account->id, 'is_active' => true]);
        $user->assignRole($role);

        return $user->fresh();
    }

    // ================================================================== execution

    public function test_a_rag_node_retrieves_generates_and_stores_the_answer_through_the_worker(): void
    {
        $account = $this->account();
        $base = $this->indexedBase($account);
        $flow = $this->flow($account, [$this->q('q'), $this->rag('r', $base->id), $this->text('s', 'A: {{answer}}')]);

        $session = $this->ask($account);

        // webhook path: parked for the worker, no retrieval, no generation
        $this->assertSame([WhatsAppFlowSession::STATUS_WAITING, 'r', 0, 0], [$session->status, $session->current_node_id, $this->ai['embedCalls'], $this->ai['genCalls']]);
        $this->assertSame('ai_queued', JourneyExecutionEvent::where('session_id', $session->id)->where('event', 'session_waiting')->latest('id')->value('result'));

        $this->assertSame(WhatsAppFlowSession::STATUS_COMPLETED, $this->resume($session));

        $session->refresh();
        $this->assertSame('The warranty lasts one year.', $session->context_data['answer']);
        $this->assertArrayNotHasKey(JourneyAiNodeRunner::RAG_KEY, $session->context_data, 'the retrieval cache is cleared once the node completes');
        $this->assertSame(['Ask us', 'A: The warranty lasts one year.'], $this->sent($account));
        $this->assertSame([1, 1], [$this->ai['embedCalls'], $this->ai['genCalls']]);

        // the retrieved passage reaches the AI layer inside a controlled prompt
        $request = $this->ai['requests'][0];
        $this->assertSame(JourneyAiNodeRunner::RAG_SYSTEM, $request->system);
        $this->assertStringContainsString('Reference passages:', $request->prompt);
        $this->assertStringContainsString('one year warranty', $request->prompt);
        $this->assertStringContainsString("Customer question:\nHow long is the warranty on a device?", $request->prompt);
        $this->assertStringNotContainsString(self::PHONE, $request->prompt.$request->system);
        $this->assertSame(['journey.rag', 2000], [$request->operation, $request->maxTokens]);

        // two metered operations, both billed to the journey's account
        $retrieval = AiOperation::where('operation_key', $this->key($flow, $session, 'r', 0, 'q'))->sole();
        $generation = AiOperation::where('operation_key', $this->key($flow, $session, 'r', 0, 'g'))->sole();
        $this->assertSame(['knowledge.query', 'journey', 'settled', 1], [$retrieval->operation, $retrieval->source, $retrieval->status, (int) $retrieval->credits_charged]);
        $this->assertSame(['journey.rag', 'journey', 'settled', 2], [$generation->operation, $generation->source, $generation->status, (int) $generation->credits_charged]);
        $this->assertSame([$account->id, $account->id], [(int) $retrieval->account_id, (int) $generation->account_id]);
        $this->assertSame(97, $this->available($account));

        $event = JourneyExecutionEvent::where('session_id', $session->id)->where('event', 'node_succeeded')->where('node_id', 'r')->sole();
        $this->assertSame(['ai_response', $generation->id, $retrieval->id], [$event->result, $event->details['ai_operation_id'], $event->details['retrieval_operation_id']]);
    }

    public function test_top_k_bounds_the_passages_sent(): void
    {
        $account = $this->account();
        $base = $this->indexedBase($account);
        $this->flow($account, [$this->q('q'), $this->rag('r', $base->id, topK: 1)]);

        $this->resume($this->ask($account));

        $this->assertStringContainsString('[1] Store policies', $this->ai['requests'][0]->prompt);
        $this->assertStringNotContainsString('[2]', $this->ai['requests'][0]->prompt);
    }

    public function test_no_retrieved_context_generates_nothing_and_stores_an_empty_answer(): void
    {
        $account = $this->account();
        $base = $this->indexedBase($account);
        KnowledgeDocument::where('knowledge_base_id', $base->id)->delete(); // indexed space, no live chunks
        $this->flow($account, [$this->q('q'), $this->rag('r', $base->id)]);

        $this->assertSame(WhatsAppFlowSession::STATUS_COMPLETED, $this->resume($session = $this->ask($account)));

        $this->assertSame('', $session->fresh()->context_data['answer']);
        $this->assertSame([1, 0], [$this->ai['embedCalls'], $this->ai['genCalls']], 'no generation, nothing invented');
        $this->assertSame(99, $this->available($account), 'only the query embedding is charged');
        $this->assertSame('rag_no_context', JourneyExecutionEvent::where('session_id', $session->id)->where('event', 'node_succeeded')->where('node_id', 'r')->value('result'));
    }

    public function test_an_empty_query_fails_without_retrieval_or_generation(): void
    {
        $account = $this->account();
        $base = $this->indexedBase($account);
        $this->flow($account, [$this->rag('r', $base->id, 'never_collected')]);
        $this->inbound($account, 'go');

        $this->resume($session = $this->flowSession($account));

        $session->refresh();
        $this->assertSame(WhatsAppFlowSession::STATUS_FAILED, $session->status);
        $this->assertStringContainsString("query variable 'never_collected' is empty", $session->last_error);
        $this->assertSame([0, 0, 100], [$this->ai['embedCalls'], $this->ai['genCalls'], $this->available($account)]);
    }

    public function test_an_unindexed_knowledge_base_fails_permanently_without_charging(): void
    {
        $account = $this->account();
        $base = app(KnowledgeBaseService::class)->createKnowledgeBase($account, 'Empty', null, null);
        $this->flow($account, [$this->q('q'), $this->rag('r', $base->id)]);

        $this->resume($session = $this->ask($account));

        $session->refresh();
        $this->assertSame(WhatsAppFlowSession::STATUS_FAILED, $session->status);
        $this->assertStringContainsString('no indexed documents', $session->last_error);
        $this->assertSame('invalid_configuration', JourneyExecutionEvent::where('session_id', $session->id)->where('event', 'session_failed')->value('error_category'));
        $this->assertSame([0, 0, 100], [$this->ai['embedCalls'], $this->ai['genCalls'], $this->available($account)]);
    }

    // ================================================================== isolation

    public function test_a_journey_cannot_use_another_accounts_knowledge_base(): void
    {
        $owner = $this->account();
        $foreignBase = $this->indexedBase($owner);
        $attacker = $this->account();
        // a graph written straight to the database (bypassing the save check) with the other account's id
        $this->flow($attacker, [$this->q('q'), $this->rag('r', $foreignBase->id), $this->text('s', '{{answer}}')]);

        $this->resume($session = $this->ask($attacker));

        $session->refresh();
        $this->assertSame(WhatsAppFlowSession::STATUS_FAILED, $session->status);
        $this->assertStringContainsString('KNOWLEDGE_BASE_NOT_FOUND', $session->last_error);
        $this->assertStringNotContainsString('Policies', $session->last_error, 'nothing about the other account leaks');
        $this->assertSame([0, 0], [$this->ai['embedCalls'], $this->ai['genCalls']]);
        $this->assertSame([100, 100], [$this->available($attacker), $this->available($owner)]);
        $this->assertSame(['Ask us'], $this->sent($attacker));
    }

    public function test_saving_a_journey_only_accepts_the_accounts_own_knowledge_bases(): void
    {
        $account = $this->account();
        $mine = $this->indexedBase($account);
        $other = $this->indexedBase($this->account(), name: 'Other');
        $user = $this->user($account);
        $payload = fn (int|string $kb) => [
            'name' => 'RAG', 'trigger_type' => 'keyword', 'trigger_value' => 'go',
            'graph_data' => ['nodes' => [['id' => 't', 'type' => 'trigger', 'data' => []], $this->rag('r', $kb)], 'edges' => [['id' => 'e', 'source' => 't', 'target' => 'r']]],
        ];

        $this->actingAs($user)->postJson('/api/whatsapp/flows', $payload($other->id))
            ->assertStatus(422)->assertJsonValidationErrors(['graph_data.nodes.1.data.knowledgeBaseId']);
        $missing = $this->actingAs($user)->postJson('/api/whatsapp/flows', $payload(999999))->assertStatus(422);
        $this->assertSame(['Knowledge base not found.'], $missing->json('errors')['graph_data.nodes.1.data.knowledgeBaseId']);
        $foreign = $this->actingAs($user)->postJson('/api/whatsapp/flows', $payload($other->id))->assertStatus(422);
        $this->assertSame($missing->json('errors'), $foreign->json('errors'), 'a foreign id is indistinguishable from a missing one');
        $this->actingAs($user)->postJson('/api/whatsapp/flows', $payload('kb-1'))->assertStatus(422);
        $id = $this->actingAs($user)->postJson('/api/whatsapp/flows', $payload($mine->id))->assertCreated()->json('data.id');

        $this->actingAs($user)->putJson("/api/whatsapp/flows/{$id}", $payload($other->id))->assertStatus(422);
        $this->assertSame((string) $mine->id, WhatsAppFlow::find($id)->graph_data['nodes'][1]['data']['knowledgeBaseId'], 'the refused update changed nothing');
    }

    public function test_an_agent_and_a_super_admin_are_bound_to_the_selected_target_account(): void
    {
        $agent = $this->account(100, 'growth', ['account_type' => 'agent']);
        $sub = $this->account(100, 'growth', ['agent_id' => $agent->id]);
        $agentBase = $this->indexedBase($agent, name: 'Agent KB');
        $subBase = $this->indexedBase($sub, name: 'Sub KB');
        $agentUser = $this->user($agent, 'agent');
        $agentUser->assignRole('admin');
        $payload = fn (int $kb) => [
            'name' => 'RAG', 'trigger_type' => 'keyword', 'trigger_value' => 'go',
            'graph_data' => ['nodes' => [['id' => 't', 'type' => 'trigger', 'data' => []], $this->rag('r', $kb)], 'edges' => [['id' => 'e', 'source' => 't', 'target' => 'r']]],
        ];

        // the agent's OWN knowledge base cannot be used in its sub-client's journey
        $this->actingAs($agentUser)->postJson('/api/whatsapp/flows?account_id='.$sub->id, $payload($agentBase->id))->assertStatus(422);
        $this->actingAs($agentUser)->postJson('/api/whatsapp/flows?account_id='.$sub->id, $payload($subBase->id))->assertCreated();

        $superAdmin = User::factory()->create(['account_id' => null, 'is_active' => true]);
        $superAdmin->assignRole('super_admin');
        $client = $this->account();
        $clientBase = $this->indexedBase($client, name: 'Client KB');
        $this->actingAs($superAdmin)->postJson('/api/whatsapp/flows?account_id='.$client->id, $payload($subBase->id))->assertStatus(422);
        $this->actingAs($superAdmin)->postJson('/api/whatsapp/flows?account_id='.$client->id, $payload($clientBase->id))->assertCreated();

        // The selected client's own entitlements decide at RUN time, where AI is
        // used and billed: a Super Admin may save a journey for a client (the
        // pre-existing P5-7 save-time bypass is unchanged), but on a client
        // without `ai` the rag node is refused before any retrieval or charge.
        $starter = $this->account(100, 'starter');
        $starterBase = KnowledgeBase::create(['account_id' => $starter->id, 'name' => 'Starter KB', 'embedding_provider' => 'fake', 'embedding_model' => 'bow', 'embedding_dimensions' => 256]);
        $this->actingAs($superAdmin->fresh())->postJson('/api/whatsapp/flows?account_id='.$starter->id, $payload($clientBase->id))->assertStatus(422);
        $this->actingAs($superAdmin->fresh())->postJson('/api/whatsapp/flows?account_id='.$starter->id, [
            'name' => 'RAG', 'trigger_type' => 'keyword', 'trigger_value' => 'go', 'publish' => false,
            'graph_data' => ['nodes' => [['id' => 't', 'type' => 'trigger', 'data' => []], $this->q('q'), $this->rag('r', $starterBase->id)], 'edges' => [['id' => 'e', 'source' => 't', 'target' => 'q'], ['id' => 'e2', 'source' => 'q', 'target' => 'r']]],
        ])->assertCreated();
        $this->flow($starter, [$this->q('q'), $this->rag('r', $starterBase->id)]);
        $this->inbound($starter, 'go');
        $this->inbound($starter, 'warranty?');
        $starterSession = WhatsAppFlowSession::where('account_id', $starter->id)->latest('id')->first();
        if ($starterSession && $starterSession->status === 'waiting') {
            $this->resume($starterSession);
        }
        $this->assertNotSame(WhatsAppFlowSession::STATUS_COMPLETED, $starterSession?->fresh()->status);
        $this->assertSame(0, AiOperation::where('account_id', $starter->id)->count(), 'nothing retrieved, generated or charged for the unentitled client');
    }

    public function test_a_revoked_ai_capability_or_disabled_module_stops_the_node_before_any_call(): void
    {
        $account = $this->account();
        $base = $this->indexedBase($account);
        $this->flow($account, [$this->q('q'), $this->rag('r', $base->id)]);
        $session = $this->ask($account);
        AccountEntitlement::updateOrCreate(
            ['account_id' => $account->id, 'capability_id' => Capability::where('slug', 'ai')->value('id')],
            ['source' => 'manual_grant', 'granted_by_account_id' => null, 'revoked_at' => now()],
        );

        $this->resume($session);
        $this->assertSame(['failed', 'entitlement_blocked'], [$session->fresh()->status, JourneyExecutionEvent::where('session_id', $session->id)->where('event', 'session_failed')->value('error_category')]);

        $other = $this->account();
        $otherBase = $this->indexedBase($other);
        $this->flow($other, [$this->q('q'), $this->rag('r', $otherBase->id)]);
        $this->inbound($other, 'go');
        $this->inbound($other, 'warranty?');
        $other->forceFill(['allowed_modules' => array_values(array_diff(Account::MODULES, ['chatbot']))])->save();
        $this->assertSame(WhatsAppFlowSession::STATUS_BLOCKED, $this->resume($this->flowSession($other)));

        $this->assertSame([0, 0], [$this->ai['embedCalls'], $this->ai['genCalls']]);
    }

    // ================================================================== billing + idempotency

    public function test_insufficient_credits_for_generation_retry_without_repeating_retrieval(): void
    {
        $account = $this->account();
        $base = $this->indexedBase($account);
        $flow = $this->flow($account, [$this->q('q'), $this->rag('r', $base->id), $this->text('s', '{{answer}}')]);
        app(CreditService::class)->adjust($account->fresh(), -98, 'test:drain:'.uniqid(), ['reason' => 'test']);
        $this->assertSame(2, $this->available($account)); // enough for the query embedding (1), not the generation hold (3)

        $session = $this->ask($account);
        $this->assertSame('retrying', $this->resume($session));

        $session->refresh();
        $this->assertStringContainsString('INSUFFICIENT_CREDITS', $session->last_error);
        $this->assertSame('quota_failure', JourneyExecutionEvent::where('session_id', $session->id)->where('event', 'node_retry_scheduled')->latest('id')->value('error_category'));
        $this->assertSame([1, 0], [$this->ai['embedCalls'], $this->ai['genCalls']], 'no generation without credits');
        $this->assertIsArray($session->context_data[JourneyAiNodeRunner::RAG_KEY][JourneyAiNodeRunner::nodeKey('r')] ?? null, 'hits cached for the retry');
        $this->assertSame(0, CreditReservation::where('account_id', $account->id)->where('status', 'reserved')->count());

        // still short: retried again — the retrieval is NOT re-run or re-charged
        $this->resumeDue($session);
        $this->assertSame([1, 0, 1], [$this->ai['embedCalls'], $this->ai['genCalls'], $this->available($account)]);

        $this->topUpTo($account, 20);
        $this->assertSame(WhatsAppFlowSession::STATUS_COMPLETED, $this->resumeDue($session));
        $this->assertSame([1, 1], [$this->ai['embedCalls'], $this->ai['genCalls']]);
        $this->assertSame(1, AiOperation::where('operation_key', $this->key($flow, $session, 'r', 0, 'q'))->count());
        $this->assertSame(18, $this->available($account), 'generation charged once (2); retrieval once (1, before the top-up)');
    }

    public function test_a_failed_generation_releases_its_hold_and_the_retry_charges_once(): void
    {
        $account = $this->account();
        $base = $this->indexedBase($account);
        $flow = $this->flow($account, [$this->q('q'), $this->rag('r', $base->id), $this->text('s', '{{answer}}')]);
        $this->ai['genFail'] = AiException::timeout('fake');

        $session = $this->ask($account);
        $this->assertSame('retrying', $this->resume($session));

        $session->refresh();
        $this->assertStringContainsString('AI_PROVIDER_TIMEOUT', $session->last_error);
        $this->assertSame(99, $this->available($account), 'generation hold released, only the query embedding charged');
        $this->assertSame('failed', AiOperation::where('operation_key', $this->key($flow, $session, 'r', 0, 'g'))->value('status'));

        $this->ai['genFail'] = null;
        $this->assertSame(WhatsAppFlowSession::STATUS_COMPLETED, $this->resumeDue($session));

        $generation = AiOperation::where('operation_key', $this->key($flow, $session, 'r', 0, 'g'))->sole();
        $this->assertSame(['settled', 2], [$generation->status, (int) $generation->attempt], 'same key, second attempt');
        $this->assertSame([1, 2], [$this->ai['embedCalls'], $this->ai['genCalls']], 'one retrieval, two generation attempts');
        $this->assertSame(97, $this->available($account));
    }

    public function test_exhausted_provider_retries_fail_safely_with_no_generation_charge(): void
    {
        $account = $this->account();
        $base = $this->indexedBase($account);
        $this->flow($account, [$this->q('q'), $this->rag('r', $base->id), $this->text('s', 'never')]);
        $this->ai['genFail'] = AiException::providerFailed('fake');

        $session = $this->ask($account);
        $this->resume($session);
        for ($i = 2; $i <= WhatsAppJourneyEngine::MAX_RESUME_ATTEMPTS; $i++) {
            $this->resumeDue($session);
        }

        $session->refresh();
        $this->assertSame(['failed', 'r'], [$session->status, $session->current_node_id]);
        $this->assertSame("Node 'r' (rag): AI_PROVIDER_FAILED — The AI provider 'fake' could not complete the request.", $session->last_error);
        $this->assertSame(['Ask us'], $this->sent($account));
        $this->assertSame(99, $this->available($account));
        $this->assertSame(1, $this->ai['embedCalls']);
    }

    public function test_a_charged_answer_lost_before_saving_is_never_regenerated(): void
    {
        $account = $this->account();
        $base = $this->indexedBase($account);
        $this->flow($account, [$this->q('q'), $this->rag('r', $base->id), $this->text('s', '{{answer}}')]);
        $session = $this->ask($account);
        $this->resume($session);
        $this->assertSame(97, $this->available($account));

        // the worker "died" after the generation settled, before the checkpoint write
        $context = $session->fresh()->context_data;
        unset($context['answer'], $context[JourneyAiNodeRunner::RUNS_KEY]);
        WhatsAppFlowSession::whereKey($session->id)->update(['status' => 'waiting', 'current_node_id' => 'r', 'context_data' => json_encode($context), 'wait_until' => now()->subSecond(), 'attempts' => 0, 'last_error' => null]);

        $this->resume($session);

        $session->refresh();
        $this->assertSame(WhatsAppFlowSession::STATUS_FAILED, $session->status);
        $this->assertStringContainsString('not run again, to avoid a second charge', $session->last_error);
        $this->assertSame([1, 1, 97], [$this->ai['genCalls'], $this->ai['embedCalls'], $this->available($account)], 'no second provider call or charge');
    }

    public function test_a_generation_left_running_by_a_dead_worker_is_retried_only_once_it_can_be_abandoned(): void
    {
        $account = $this->account();
        $base = $this->indexedBase($account);
        $flow = $this->flow($account, [$this->q('q'), $this->rag('r', $base->id), $this->text('s', '{{answer}}')]);
        $session = $this->ask($account);
        DB::table('ai_operations')->insert([
            'account_id' => $account->id, 'operation_key' => $this->key($flow, $session, 'r', 0, 'g'), 'attempt' => 1, 'source' => 'journey',
            'operation' => 'journey.rag', 'provider' => 'fake', 'status' => 'running', 'credits_reserved' => 0, 'credits_uncharged' => 0,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->assertSame('retrying', $this->resume($session));
        $this->assertSame(0, $this->ai['genCalls']);
        $this->assertTrue($session->fresh()->wait_until->greaterThanOrEqualTo(now()->addSeconds(900)));

        $this->travel(906)->seconds();
        $this->assertSame(WhatsAppFlowSession::STATUS_COMPLETED, $this->resume($session));
        $this->assertSame([1, 1], [$this->ai['embedCalls'], $this->ai['genCalls']], 'the cached retrieval was reused');
        $this->assertSame(2, (int) AiOperation::where('operation_key', $this->key($flow, $session, 'r', 0, 'g'))->value('attempt'));
    }

    public function test_a_new_visit_gets_new_operations_and_is_charged_again(): void
    {
        $account = $this->account();
        $base = $this->indexedBase($account);
        $flow = $this->flow($account, [$this->q('q'), $this->rag('r', $base->id)], [['q', 'r'], ['r', 'q']]);

        $session = $this->ask($account, 'warranty length?');
        $this->resume($session);
        $this->inbound($account, 'refund window?');
        $this->resume($session);

        $keys = AiOperation::where('account_id', $account->id)->where('operation_key', 'like', 'journey:%')->orderBy('id')->pluck('operation_key')->all();
        $this->assertSame([
            $this->key($flow, $session, 'r', 0, 'q'), $this->key($flow, $session, 'r', 0, 'g'),
            $this->key($flow, $session, 'r', 1, 'q'), $this->key($flow, $session, 'r', 1, 'g'),
        ], $keys);
        $this->assertSame(94, $this->available($account));
        $this->assertStringContainsString('refund window?', $this->ai['requests'][1]->prompt);
    }

    public function test_a_rag_node_after_a_delay_runs_in_the_resumed_worker_run(): void
    {
        $account = $this->account();
        $base = $this->indexedBase($account);
        $this->flow($account, [$this->q('q'), ['id' => 'd', 'type' => 'delay', 'data' => ['amount' => 5, 'unit' => 'minutes']], $this->rag('r', $base->id), $this->text('s', 'Later: {{answer}}')]);

        $session = $this->ask($account);
        $this->assertSame(['waiting', 'd'], [$session->status, $session->current_node_id]);

        $this->travel(6)->minutes();
        $this->assertSame(WhatsAppFlowSession::STATUS_COMPLETED, $this->resume($session));
        $this->assertSame(['Ask us', 'Later: The warranty lasts one year.'], $this->sent($account));
    }

    public function test_the_scheduler_and_worker_pick_up_a_queued_rag_node_once(): void
    {
        $account = $this->account();
        $base = $this->indexedBase($account);
        $this->flow($account, [$this->q('q'), $this->rag('r', $base->id), $this->text('s', '{{answer}}')]);
        $this->ask($account);

        $this->artisan('journeys:resume-due')->assertSuccessful();
        $this->artisan('queue:work', ['connection' => 'database', '--queue' => 'journeys', '--stop-when-empty' => true, '--memory' => 4096])->assertSuccessful();

        $this->assertSame(WhatsAppFlowSession::STATUS_COMPLETED, $this->flowSession($account)->status);
        $this->assertSame([1, 1, 97], [$this->ai['embedCalls'], $this->ai['genCalls'], $this->available($account)]);
    }

    public function test_a_cancellation_during_generation_keeps_the_session_cancelled(): void
    {
        $account = $this->account();
        $base = $this->indexedBase($account);
        $this->flow($account, [$this->q('q'), $this->rag('r', $base->id), $this->text('s', 'never')]);
        $session = $this->ask($account);
        $this->ai['during'] = fn () => WhatsAppFlowSession::whereKey($session->id)->update(['status' => WhatsAppFlowSession::STATUS_CANCELLED]);

        $this->resume($session);

        $this->assertSame(WhatsAppFlowSession::STATUS_CANCELLED, $session->fresh()->status);
        $this->assertArrayNotHasKey('answer', $session->fresh()->context_data ?? []);
        $this->assertSame(['Ask us'], $this->sent($account));
    }

    // ================================================================== contract

    public static function configs(): array
    {
        return [
            'ok' => [['knowledgeBaseId' => '12', 'queryVariable' => 'q', 'topK' => 5, 'outputVariable' => 'a'], null, null],
            'ok integer id, default topK' => [['knowledgeBaseId' => 12, 'queryVariable' => 'q', 'outputVariable' => 'a'], null, null],
            'missing knowledge base' => [['queryVariable' => 'q', 'outputVariable' => 'a'], 'needs a knowledge base', null],
            'non-numeric id' => [['knowledgeBaseId' => 'kb1', 'queryVariable' => 'q', 'outputVariable' => 'a'], 'needs a knowledge base', 'needs a knowledge base'],
            'zero id' => [['knowledgeBaseId' => '0', 'queryVariable' => 'q', 'outputVariable' => 'a'], 'needs a knowledge base', 'needs a knowledge base'],
            'missing query variable' => [['knowledgeBaseId' => '1', 'outputVariable' => 'a'], 'query variable', null],
            'bad query variable' => [['knowledgeBaseId' => '1', 'queryVariable' => 'a b', 'outputVariable' => 'a'], 'query variable', 'query variable'],
            'missing output variable' => [['knowledgeBaseId' => '1', 'queryVariable' => 'q'], 'output variable', null],
            'reserved output variable' => [['knowledgeBaseId' => '1', 'queryVariable' => 'q', 'outputVariable' => '@rag'], 'output variable', 'output variable'],
            'topK too large' => [['knowledgeBaseId' => '1', 'queryVariable' => 'q', 'outputVariable' => 'a', 'topK' => 21], 'top K', 'top K'],
            'topK zero' => [['knowledgeBaseId' => '1', 'queryVariable' => 'q', 'outputVariable' => 'a', 'topK' => 0], 'top K', 'top K'],
            'topK fraction' => [['knowledgeBaseId' => '1', 'queryVariable' => 'q', 'outputVariable' => 'a', 'topK' => 2.5], 'top K', 'top K'],
            'empty draft' => [['knowledgeBaseId' => '', 'queryVariable' => '', 'outputVariable' => ''], 'needs a knowledge base', null],
        ];
    }

    #[DataProvider('configs')]
    public function test_the_rag_configuration_contract(array $data, ?string $runError, ?string $draftError): void
    {
        $run = JourneyActionConfig::error('rag', $data);
        $draft = JourneyActionConfig::error('rag', $data, draft: true);

        $runError === null ? $this->assertNull($run) : $this->assertStringContainsString($runError, (string) $run);
        $draftError === null ? $this->assertNull($draft) : $this->assertStringContainsString($draftError, (string) $draft);
    }

    public function test_rag_is_executable_and_publishable_when_configured(): void
    {
        $this->assertTrue(JourneyNodeCatalog::isRuntimeExecutable('rag'));
        $this->assertContains('rag', JourneyActionConfig::AI_TYPES);

        $nodes = [['id' => 't', 'type' => 'trigger', 'data' => []], $this->rag('r', 5)];
        $this->assertSame([], JourneyPublishValidator::errors($nodes, [['id' => 'e', 'source' => 't', 'target' => 'r']]));
    }

    public function test_journey_code_reaches_retrieval_and_generation_only_through_the_contracts(): void
    {
        $runner = (string) file_get_contents(app_path('Services/WhatsApp/JourneyAiNodeRunner.php'));

        foreach (['Http::', 'OpenAiProvider', 'GeminiProvider', 'AnthropicProvider', 'DatabaseVectorStore', 'KnowledgeChunk', 'CreditService', 'Log::', 'env('] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $runner, "JourneyAiNodeRunner must not use {$forbidden}");
        }

        $this->assertStringContainsString('private readonly KnowledgeRetriever $retriever', $runner);
        $this->assertStringContainsString('private readonly MeteredAiService $metered', $runner);
    }
}
