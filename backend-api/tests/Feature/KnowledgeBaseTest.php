<?php

namespace Tests\Feature;

use App\Jobs\ProcessKnowledgeDocumentJob;
use App\Models\Account;
use App\Models\AccountEntitlement;
use App\Models\AiOperation;
use App\Models\Capability;
use App\Models\CreditLedgerEntry;
use App\Models\CreditReservation;
use App\Models\Invoice;
use App\Models\KnowledgeBase;
use App\Models\KnowledgeChunk;
use App\Models\KnowledgeDocument;
use App\Models\User;
use App\Services\Ai\AiAuthorizer;
use App\Services\Ai\AiException;
use App\Services\Ai\AiManager;
use App\Services\Ai\AiService;
use App\Services\Ai\Billing\MeteredAiService;
use App\Services\Ai\Contracts\AiProvider;
use App\Services\Ai\Contracts\EmbeddingProvider;
use App\Services\Ai\Data\AiRequest;
use App\Services\Ai\Data\AiResponse;
use App\Services\Ai\Data\EmbeddingRequest;
use App\Services\Ai\Data\EmbeddingResponse;
use App\Services\Ai\Providers\GeminiProvider;
use App\Services\Ai\Providers\OpenAiProvider;
use App\Services\Ai\Retrieval\KnowledgeRetriever;
use App\Services\Ai\Retrieval\RetrievalQuery;
use App\Services\Billing\InvoiceCreditService;
use App\Services\Credits\CreditService;
use App\Services\Knowledge\CharacterWindowChunker;
use App\Services\Knowledge\DatabaseKnowledgeRetriever;
use App\Services\Knowledge\DatabaseVectorStore;
use App\Services\Knowledge\KnowledgeBaseService;
use App\Services\Knowledge\KnowledgeException;
use App\Services\Knowledge\KnowledgeIngestionService;
use App\Services\Knowledge\PlainTextExtractor;
use App\Support\JourneyNodeCatalog;
use App\Support\PlanCatalog;
use Database\Seeders\Phase1FoundationSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

/**
 * Phase 8 Task 9 — Knowledge Base / Retrieval foundation.
 *
 * Driven through the real services, job, API and metering. The embedding
 * vendor is an in-process fake registered on AiManager (deterministic
 * bag-of-words vectors, so retrieval ranking is meaningful); the OpenAI and
 * Gemini embedding implementations are tested against faked HTTP.
 *
 * Pricing: 1000 tokens per credit, minimum 1. The fake reports one token
 * per word unless told otherwise.
 */
class KnowledgeBaseTest extends TestCase
{
    use RefreshDatabase;

    private const API = '/api/knowledge-bases';

    /** @var array{calls: int, inputs: list<list<string>>, fail: ?AiException, failOnCall: ?int, usage: bool, dims: int, during: ?\Closure} */
    private array $emb;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        $this->seed(Phase1FoundationSeeder::class);
        Http::preventStrayRequests(); // nothing may reach a real vendor

        config([
            'ai.default' => 'fakeemb', 'ai.enabled' => ['fakeemb', 'anthropic', 'openai', 'gemini'], 'ai.retries' => 0,
            'ai.embeddings.provider' => 'fakeemb', 'ai.providers.fakeemb' => ['embedding_model' => 'bow-32'],
            'ai.providers.anthropic' => ['api_key' => 'sk-ant-test', 'model' => 'claude-test', 'base_url' => 'https://api.anthropic.com'],
            'ai.credits.tokens_per_credit' => 1000, 'ai.credits.minimum_per_operation' => 1, 'ai.credits.missing_usage' => 'minimum',
            'ai.credits.tokens_per_credit_overrides' => [],
            'ai.knowledge.chunk_chars' => 200, 'ai.knowledge.chunk_overlap_chars' => 40, 'ai.knowledge.embedding_batch_size' => 2,
            'ai.knowledge.max_document_chars' => 20000, 'ai.knowledge.max_results' => 10,
        ]);

        $this->emb = ['calls' => 0, 'inputs' => [], 'fail' => null, 'failOnCall' => null, 'usage' => true, 'dims' => 256, 'during' => null];
        $test = $this;
        app(AiManager::class)->extend('fakeemb', fn () => new class($test) implements AiProvider, EmbeddingProvider {
            public function __construct(private readonly KnowledgeBaseTest $test) {}
            public function name(): string { return 'fakeemb'; }
            public function generateText(AiRequest $r): AiResponse { throw new \LogicException('not used'); }
            public function generateStructured(AiRequest $r): AiResponse { throw new \LogicException('not used'); }
            public function embed(EmbeddingRequest $r): EmbeddingResponse { return $this->test->fakeEmbed($r); }
        });
        app(AiManager::class)->flush();
    }

    /** @internal the fake embedding vendor */
    public function fakeEmbed(EmbeddingRequest $request): EmbeddingResponse
    {
        $this->emb['calls']++;
        $this->emb['inputs'][] = $request->inputs;

        if ($this->emb['during']) {
            $during = $this->emb['during'];
            $this->emb['during'] = null;
            $during();
        }

        if ($this->emb['fail'] !== null && ($this->emb['failOnCall'] === null || $this->emb['failOnCall'] === $this->emb['calls'])) {
            throw $this->emb['fail'];
        }

        $vectors = array_map(fn (string $text) => self::bow($text, $this->emb['dims']), $request->inputs);
        $tokens = array_sum(array_map(fn ($t) => str_word_count($t), $request->inputs));

        return new EmbeddingResponse('fakeemb', $request->model ?? 'bow-32', $vectors, $this->emb['usage'] ? $tokens : null);
    }

    /** @return list<float> a deterministic bag-of-words vector */
    private static function bow(string $text, int $dims): array
    {
        $vector = array_fill(0, $dims, 0.0);
        foreach (preg_split('/[^a-z0-9]+/', strtolower($text), -1, PREG_SPLIT_NO_EMPTY) as $word) {
            $vector[crc32($word) % $dims] += 1.0;
        }
        $vector[$dims - 1] += 0.01; // never all-zero

        return $vector;
    }

    // ------------------------------------------------------------------ fixtures

    private function tenant(int $credits = 100, string $planKey = 'growth', array $attributes = []): Account
    {
        $account = Account::factory()->create($attributes);
        $plan = PlanCatalog::find($planKey);
        $invoice = Invoice::create([
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

    private function service(): KnowledgeBaseService
    {
        return app(KnowledgeBaseService::class);
    }

    private function base(Account $account, string $name = 'Help center'): KnowledgeBase
    {
        return $this->service()->createKnowledgeBase($account, $name, null, null);
    }

    private function submit(KnowledgeBase $base, string $content, ?string $key = null, string $title = 'Doc'): KnowledgeDocument
    {
        return $this->service()->submitDocument($base, $title, $content, $key)['document'];
    }

    private function process(KnowledgeDocument $document): string
    {
        $document->refresh();

        return app(KnowledgeIngestionService::class)->process($document->id, $document->version);
    }

    private function retrieve(Account $account, int|string $baseId, string $query, int $limit = 5, ?float $minScore = null, ?string $key = null)
    {
        return app(KnowledgeRetriever::class)->retrieve(new RetrievalQuery(
            $account, app(AiAuthorizer::class)->forAccount($account, 'chatbot', 'test'), $baseId, $query, $limit, $minScore, $key,
        ));
    }

    private function balance(Account $account): array
    {
        $b = app(CreditService::class)->balance($account->fresh());

        return [$b['balance'], $b['reserved']];
    }

    private function revoke(Account $account, string $slug): void
    {
        AccountEntitlement::updateOrCreate(
            ['account_id' => $account->id, 'capability_id' => Capability::where('slug', $slug)->value('id')],
            ['source' => 'manual_grant', 'granted_by_account_id' => null, 'revoked_at' => now()],
        );
    }

    private function assertAiError(string $code, callable $fn): void
    {
        try {
            $fn();
        } catch (AiException $e) {
            $this->assertSame($code, $e->errorCode, $e->getMessage());

            return;
        }
        $this->fail("Expected AiException {$code}.");
    }

    private function assertKnowledgeError(string $code, callable $fn): void
    {
        try {
            $fn();
        } catch (KnowledgeException $e) {
            $this->assertSame($code, $e->errorCode, $e->getMessage());

            return;
        }
        $this->fail("Expected KnowledgeException {$code}.");
    }

    private const REFUNDS = "Refund policy. Customers may request a refund within thirty days of purchase. Refunds are paid to the original payment method.\n\nShipping. Orders ship within two business days. Express shipping is available for an extra fee.\n\nWarranty. Every device carries a one year warranty covering manufacturing defects.";

    // ==================================================================
    // Embedding abstraction
    // ==================================================================

    public function test_the_embedding_provider_is_resolved_from_configuration_and_fails_safely(): void
    {
        $this->assertSame('fakeemb', app(AiManager::class)->embeddingProvider()->name());
        $this->assertAiError(AiException::EMBEDDINGS_UNSUPPORTED, fn () => app(AiManager::class)->embeddingProvider('anthropic'));
        $this->assertAiError(AiException::PROVIDER_UNAVAILABLE, fn () => app(AiManager::class)->embeddingProvider('nope'));

        config(['ai.embeddings.provider' => null]);
        $this->assertAiError(AiException::PROVIDER_NOT_CONFIGURED, fn () => app(AiManager::class)->embeddingProvider());
        $this->assertSame('fakeemb', app(AiManager::class)->provider()->name(), 'generation resolution unchanged');
    }

    public function test_embedding_request_and_response_are_validated_and_normalized(): void
    {
        $this->assertAiError(AiException::INVALID_REQUEST, fn () => new EmbeddingRequest([]));
        $this->assertAiError(AiException::INVALID_REQUEST, fn () => new EmbeddingRequest(['ok', '  ']));
        $this->assertAiError(AiException::INVALID_REQUEST, fn () => new EmbeddingRequest(array_fill(0, 101, 'x')));
        $this->assertAiError(AiException::INVALID_REQUEST, fn () => new EmbeddingRequest(['x'], 'other'));
        $this->assertAiError(AiException::MALFORMED_RESPONSE, fn () => new EmbeddingResponse('p', 'm', [[1.0, 2.0], [1.0]]));
        $this->assertAiError(AiException::MALFORMED_RESPONSE, fn () => new EmbeddingResponse('p', 'm', [['a']]));
        $this->assertSame(2, (new EmbeddingResponse('p', 'm', [[1, 0.5], [0.1, 0.2]]))->dimensions);
    }

    public function test_openai_embeddings_are_sent_and_normalized(): void
    {
        config(['ai.providers.openai' => ['api_key' => 'sk-test-SECRET', 'base_url' => 'https://api.openai.com/v1', 'model' => 'gpt-test', 'embedding_model' => 'text-embedding-test']]);
        app(AiManager::class)->flush();
        Http::fake(['api.openai.com/*' => Http::response([
            'model' => 'text-embedding-test', 'data' => [['index' => 1, 'embedding' => [0.3, 0.4]], ['index' => 0, 'embedding' => [0.1, 0.2]]],
            'usage' => ['prompt_tokens' => 9, 'total_tokens' => 9],
        ], 200)]);

        $response = app(AiManager::class)->embeddingProvider('openai')->embed(new EmbeddingRequest(['first', 'second']));

        $this->assertInstanceOf(OpenAiProvider::class, app(AiManager::class)->embeddingProvider('openai'));
        $this->assertSame([[0.1, 0.2], [0.3, 0.4]], $response->vectors, 'reordered by index');
        $this->assertSame(['openai', 'text-embedding-test', 9, 2], [$response->provider, $response->model, $response->inputTokens, $response->dimensions]);
        Http::assertSent(fn (HttpRequest $r) => $r->url() === 'https://api.openai.com/v1/embeddings'
            && $r->header('Authorization') === ['Bearer sk-test-SECRET']
            && $r->data() === ['model' => 'text-embedding-test', 'input' => ['first', 'second'], 'encoding_format' => 'float']);
    }

    public function test_gemini_embeddings_are_sent_and_normalized_without_inventing_usage(): void
    {
        config(['ai.providers.gemini' => ['api_key' => 'AIza-SECRET', 'base_url' => 'https://generativelanguage.googleapis.com', 'api_version' => 'v1beta', 'model' => 'gemini-test', 'embedding_model' => 'gemini-embedding-001']]);
        app(AiManager::class)->flush();
        $body = ['embeddings' => [['values' => [0.1, 0.2, 0.3]], ['values' => [0.4, 0.5, 0.6]]]];
        Http::fake(function () use (&$body) {
            return Http::response($body, 200);
        });

        $response = app(AiManager::class)->embeddingProvider('gemini')->embed(new EmbeddingRequest(['a doc', 'b doc'], EmbeddingRequest::PURPOSE_QUERY));

        $this->assertInstanceOf(GeminiProvider::class, app(AiManager::class)->embeddingProvider('gemini'));
        $this->assertSame([[0.1, 0.2, 0.3], [0.4, 0.5, 0.6]], $response->vectors);
        $this->assertNull($response->inputTokens, 'no usage reported → null → missing-usage rule');
        Http::assertSent(fn (HttpRequest $r) => $r->url() === 'https://generativelanguage.googleapis.com/v1beta/models/gemini-embedding-001:batchEmbedContents'
            && $r->header('x-goog-api-key') === ['AIza-SECRET'] && ! str_contains($r->url(), 'key=')
            && $r->data()['requests'][1] === ['model' => 'models/gemini-embedding-001', 'content' => ['parts' => [['text' => 'b doc']]], 'taskType' => 'RETRIEVAL_QUERY']);

        $body = ['embeddings' => [['values' => [0.1]]]]; // one vector for two inputs
        $this->assertAiError(AiException::MALFORMED_RESPONSE, fn () => app(AiManager::class)->embeddingProvider('gemini')->embed(new EmbeddingRequest(['a', 'b'])));
    }

    public function test_metered_embeddings_reuse_the_credit_flow(): void
    {
        $account = $this->tenant(100);
        $auth = app(AiAuthorizer::class)->forAccount($account, 'chatbot', 'test');
        $words = implode(' ', array_fill(0, 1500, 'word'));

        $result = app(MeteredAiService::class)->embed($auth, new EmbeddingRequest([$words]), 'emb:1');

        $this->assertSame([2, true], [$result->creditsCharged, $result->settled], '1500 tokens → 2 credits');
        $op = $result->operation;
        $this->assertSame(['embedding', 'fakeemb', 1500, 0, true, AiOperation::STATUS_SETTLED], [$op->operation, $op->provider, $op->input_tokens, $op->output_tokens, $op->usage_reported, $op->status]);
        $this->assertSame([98, 0], $this->balance($account));

        // idempotent: the same key is never charged twice
        $this->assertAiError(AiException::OPERATION_DUPLICATE, fn () => app(MeteredAiService::class)->embed($auth, new EmbeddingRequest([$words]), 'emb:1'));
        // missing usage → the existing rule
        $this->emb['usage'] = false;
        $this->assertSame(1, app(MeteredAiService::class)->embed($auth, new EmbeddingRequest(['x']), 'emb:2')->creditsCharged);
        // failure → hold released, nothing charged
        $this->emb['fail'] = AiException::providerFailed('fakeemb');
        $this->assertAiError(AiException::PROVIDER_FAILED, fn () => app(MeteredAiService::class)->embed($auth, new EmbeddingRequest(['y']), 'emb:3'));
        $this->assertSame([97, 0], $this->balance($account));
        $this->assertSame(0, CreditReservation::where('account_id', $account->id)->where('status', 'reserved')->count());
        // unsupported provider fails before anything is claimed
        $this->assertAiError(AiException::EMBEDDINGS_UNSUPPORTED, fn () => app(MeteredAiService::class)->embed($auth, new EmbeddingRequest(['z']), 'emb:4', 'anthropic'));
        $this->assertSame(0, AiOperation::where('operation_key', 'emb:4')->count());
    }

    public function test_embedding_logs_carry_metadata_only(): void
    {
        Log::spy();
        $account = $this->tenant(100);

        app(AiService::class)->embed(app(AiAuthorizer::class)->forAccount($account, 'chatbot', 'test'), new EmbeddingRequest(['PRIVATE customer text']));

        Log::shouldHaveReceived('info')->withArgs(fn ($message, $context = []) => $message === 'AI operation completed.'
            && $context['provider'] === 'fakeemb' && $context['operation'] === 'embedding'
            && ! str_contains(json_encode($context), 'PRIVATE'))->once();
    }

    // ==================================================================
    // Ingestion lifecycle
    // ==================================================================

    public function test_a_document_is_queued_then_chunked_embedded_billed_and_indexed(): void
    {
        $account = $this->tenant(100);
        $base = $this->base($account);

        $document = $this->submit($base, self::REFUNDS, null, 'Policies');

        $this->assertSame([KnowledgeDocument::STATUS_PENDING, 1, null, 'sha256:'.hash('sha256', PlainTextExtractor::normalize(self::REFUNDS))], [$document->status, $document->version, $document->indexed_version, $document->source_key]);
        $job = DB::table('jobs')->where('queue', 'knowledge')->first();
        $this->assertSame(ProcessKnowledgeDocumentJob::class, json_decode($job->payload, true)['displayName']);
        $this->assertSame(0, $this->emb['calls'], 'nothing embedded on submission');

        $this->assertSame('ready', $this->process($document));

        $document->refresh();
        $chunks = KnowledgeChunk::where('knowledge_document_id', $document->id)->orderBy('chunk_index')->get();
        $expected = CharacterWindowChunker::fromConfig()->chunk(PlainTextExtractor::normalize(self::REFUNDS));
        $this->assertSame([KnowledgeDocument::STATUS_READY, 1, count($expected)], [$document->status, $document->indexed_version, $document->chunk_count]);
        $this->assertSame(array_map(fn ($c) => $c->text, $expected), $chunks->pluck('content')->all());
        $this->assertTrue($chunks->every(fn ($c) => $c->embedding !== null && $c->embedding_dimensions === 256 && $c->account_id === $account->id && $c->knowledge_base_id === $base->id));
        $this->assertSame(['fakeemb', 'bow-32', 256], [$base->fresh()->embedding_provider, $base->fresh()->embedding_model, $base->fresh()->embedding_dimensions]);

        $batches = (int) ceil(count($expected) / 2);
        $this->assertSame($batches, $this->emb['calls']);
        $keys = AiOperation::where('account_id', $account->id)->orderBy('id')->pluck('operation_key')->all();
        $this->assertSame(array_map(fn ($b) => "kb:d{$document->id}:v1:b{$b}", range(0, $batches - 1)), $keys);
        $this->assertTrue(AiOperation::where('account_id', $account->id)->get()->every(fn ($op) => $op->status === 'settled' && $op->source === 'knowledge' && $op->operation === 'knowledge.embed'));
        $this->assertSame(100 - $batches, $this->balance($account)[0], 'one minimum credit per batch, charged to the owning account');
    }

    public function test_processing_is_deterministic_and_resubmission_is_idempotent(): void
    {
        $text = PlainTextExtractor::normalize(self::REFUNDS);
        $this->assertEquals(CharacterWindowChunker::fromConfig()->chunk($text), CharacterWindowChunker::fromConfig()->chunk($text));
        $this->assertSame($text, PlainTextExtractor::normalize("  ".str_replace("\n", "\r\n", self::REFUNDS)."\t\n\n\n"));

        $account = $this->tenant(100);
        $base = $this->base($account);
        $document = $this->submit($base, self::REFUNDS, 'faq/policies');
        $this->process($document);
        $calls = $this->emb['calls'];
        $balance = $this->balance($account);

        $again = $this->service()->submitDocument($base, 'Doc', "\r\n".self::REFUNDS."   ", 'faq/policies');
        $this->assertSame([false, false, $document->id, 1], [$again['created'], $again['changed'], $again['document']->id, $again['document']->version]);
        $this->assertSame(1, DB::table('jobs')->where('queue', 'knowledge')->count(), 'no second job');

        $this->assertSame('skipped', app(KnowledgeIngestionService::class)->process($document->id, 1), 'a duplicate/late job does nothing');
        $this->assertSame([$calls, $balance], [$this->emb['calls'], $this->balance($account)]);
    }

    public function test_a_changed_document_is_rebuilt_as_a_new_version_without_breaking_the_live_one(): void
    {
        $account = $this->tenant(100);
        $base = $this->base($account);
        $document = $this->submit($base, 'Opening hours are nine to five on weekdays.', 'hours');
        $this->process($document);

        $changed = $this->service()->submitDocument($base, 'Hours', 'Opening hours are ten to six including saturday.', 'hours');
        $this->assertSame([true, 2, KnowledgeDocument::STATUS_PENDING, 1], [$changed['changed'], $changed['document']->version, $changed['document']->status, $changed['document']->indexed_version]);

        // while v2 is pending, v1 is still what retrieval returns
        $hits = $this->retrieve($account, $base->id, 'opening hours weekdays')->chunks;
        $this->assertSame([1], array_map(fn ($c) => $c->documentVersion, $hits));

        $this->assertSame('skipped', app(KnowledgeIngestionService::class)->process($document->id, 1), 'the superseded version never runs');
        $this->assertSame('ready', $this->process($document));

        $this->assertSame([2], KnowledgeChunk::where('knowledge_document_id', $document->id)->distinct()->pluck('document_version')->all(), 'old version chunks removed after the switch');
        $this->assertStringContainsString('saturday', $this->retrieve($account, $base->id, 'opening hours saturday')->chunks[0]->text);
    }

    public function test_a_failed_ingestion_is_recorded_charges_nothing_and_resumes_without_recharging(): void
    {
        $account = $this->tenant(100);
        $base = $this->base($account);
        $document = $this->submit($base, self::REFUNDS);
        $batches = (int) ceil(count(CharacterWindowChunker::fromConfig()->chunk(PlainTextExtractor::normalize(self::REFUNDS))) / 2);
        $this->assertGreaterThan(1, $batches);

        $this->emb['fail'] = AiException::providerFailed('fakeemb');
        $this->emb['failOnCall'] = 2; // the second batch fails
        $this->assertSame('failed', $this->process($document));

        $document->refresh();
        $this->assertSame([KnowledgeDocument::STATUS_FAILED, 'AI_PROVIDER_FAILED', null], [$document->status, $document->error_code, $document->indexed_version]);
        $this->assertSame("The AI provider 'fakeemb' could not complete the request.", $document->error_message);
        $this->assertSame([99, 0], $this->balance($account), 'batch 1 charged, failed batch released');
        $this->assertSame(0, KnowledgeChunk::where('knowledge_document_id', $document->id)->whereNotNull('embedding')->count() === 2 ? 0 : 1, 'only the first batch is stored');
        $this->assertSame(0, DB::table('knowledge_documents')->whereNotNull('indexed_version')->count(), 'nothing live yet');

        // resume: same version, only the missing batches are embedded
        $this->emb['fail'] = null;
        $resumed = $this->service()->reprocess($document);
        $this->assertSame([1, KnowledgeDocument::STATUS_PENDING], [$resumed->version, $resumed->status]);
        $callsBefore = $this->emb['calls'];
        $this->assertSame('ready', $this->process($document));
        $this->assertSame($batches - 1, $this->emb['calls'] - $callsBefore, 'the embedded batch is not embedded again');
        $this->assertSame([100 - $batches, 0], $this->balance($account), 'every batch charged exactly once');
        $this->assertSame(2, (int) AiOperation::where('operation_key', "kb:d{$document->id}:v1:b1")->value('attempt'));
    }

    public function test_insufficient_credits_or_a_revoked_capability_fail_before_any_vendor_call(): void
    {
        $poor = $this->tenant(0);
        $document = $this->submit($this->base($poor), self::REFUNDS);
        $this->assertSame('failed', $this->process($document));
        $this->assertSame('INSUFFICIENT_CREDITS', $document->fresh()->error_code);

        $revoked = $this->tenant(100);
        $document = $this->submit($this->base($revoked), self::REFUNDS);
        $this->revoke($revoked, 'ai');
        $this->assertSame('failed', $this->process($document));
        $this->assertSame('AI_CAPABILITY_UNAVAILABLE', $document->fresh()->error_code);

        $this->assertSame(0, $this->emb['calls']);
        $this->assertSame(0, CreditLedgerEntry::whereIn('account_id', [$poor->id, $revoked->id])->where('type', 'consumption')->count());
    }

    public function test_embeddings_off_fail_the_document_safely(): void
    {
        config(['ai.embeddings.provider' => null]);
        $account = $this->tenant(100);
        $document = $this->submit($this->base($account), self::REFUNDS);

        $this->assertSame('failed', $this->process($document));
        $this->assertSame('AI_PROVIDER_NOT_CONFIGURED', $document->fresh()->error_code);
        $this->assertSame(0, KnowledgeChunk::whereNotNull('embedding')->count());
    }

    public function test_a_batch_charged_but_never_stored_is_not_charged_again(): void
    {
        $account = $this->tenant(100);
        $base = $this->base($account);
        $document = $this->submit($base, 'Short text about refunds.');
        $this->process($document);

        // simulate: the vectors of a charged batch were lost (crash between charge and store)
        $document->refresh();
        KnowledgeDocument::whereKey($document->id)->update(['status' => KnowledgeDocument::STATUS_PENDING, 'indexed_version' => null]);
        KnowledgeChunk::where('knowledge_document_id', $document->id)->update(['embedding' => null]);
        $calls = $this->emb['calls'];

        $this->assertSame('failed', $this->process($document));
        $this->assertSame(KnowledgeException::ALREADY_CHARGED, $document->fresh()->error_code);
        $this->assertSame($calls, $this->emb['calls']);
        $this->assertSame([99, 0], $this->balance($account), 'charged once');

        // an explicit re-process builds a NEW version (a new, deliberate charge)
        $this->assertSame(2, $this->service()->reprocess($document->fresh())->version);
        $this->assertSame('ready', $this->process($document));
        $this->assertSame([98, 0], $this->balance($account));
    }

    public function test_a_document_deleted_while_processing_is_abandoned_safely(): void
    {
        $account = $this->tenant(100);
        $document = $this->submit($this->base($account), self::REFUNDS);
        $this->emb['during'] = fn () => KnowledgeDocument::whereKey($document->id)->delete();

        $this->assertContains($this->process($document), ['superseded', 'failed']);
        $this->assertSame(0, KnowledgeDocument::count());
        $this->assertSame(0, KnowledgeChunk::count());
        $this->assertSame(0, CreditReservation::where('account_id', $account->id)->where('status', 'reserved')->count(), 'no hold left open');
    }

    public function test_invalid_documents_are_rejected_before_anything_is_stored(): void
    {
        $base = $this->base($this->tenant(100));

        $this->assertKnowledgeError(KnowledgeException::DOCUMENT_EMPTY, fn () => $this->submit($base, " \n\t \x00 "));
        $this->assertKnowledgeError(KnowledgeException::DOCUMENT_TOO_LARGE, fn () => $this->submit($base, str_repeat('a ', 10001)));
        $this->assertKnowledgeError(KnowledgeException::UNSUPPORTED_SOURCE, fn () => $this->service()->submitDocument($base, 'x', 'y', null, 'pdf'));
        $this->assertSame(0, KnowledgeDocument::count());
    }

    public function test_a_different_embedding_dimension_cannot_enter_an_indexed_knowledge_base(): void
    {
        $account = $this->tenant(100);
        $base = $this->base($account);
        $this->process($this->submit($base, 'First document about refunds.'));

        $this->emb['dims'] = 16;
        $second = $this->submit($base, 'Second document about shipping.');

        $this->assertSame('failed', $this->process($second));
        $this->assertSame(KnowledgeException::EMBEDDING_MISMATCH, $second->fresh()->error_code);
        $this->assertSame(0, KnowledgeChunk::where('knowledge_document_id', $second->id)->whereNotNull('embedding')->count());
    }

    public function test_the_recovery_command_redispatches_lost_and_stuck_documents_only(): void
    {
        $account = $this->tenant(100);
        $base = $this->base($account);
        $lost = $this->submit($base, 'lost job document', 'a');
        $stuck = $this->submit($base, 'stuck worker document', 'b');
        $fresh = $this->submit($base, 'fresh document', 'c');
        $failed = $this->submit($base, 'failed document', 'd');
        DB::table('jobs')->delete();
        KnowledgeDocument::whereKey($lost->id)->update(['updated_at' => now()->subMinutes(5)]);
        KnowledgeDocument::whereKey($stuck->id)->update(['status' => 'processing', 'processing_started_at' => now()->subHour()]);
        KnowledgeDocument::whereKey($failed->id)->update(['status' => 'failed', 'updated_at' => now()->subHour()]);

        $this->artisan('knowledge:recover-documents')->assertSuccessful();

        $ids = DB::table('jobs')->where('queue', 'knowledge')->pluck('payload')->map(fn ($p) => unserialize(json_decode($p, true)['data']['command'])->documentId)->sort()->values()->all();
        $this->assertSame([$lost->id, $stuck->id], $ids);

        $this->artisan('queue:work', ['connection' => 'database', '--queue' => 'knowledge', '--stop-when-empty' => true, '--memory' => 4096])->assertSuccessful();
        $this->assertSame([KnowledgeDocument::STATUS_READY, KnowledgeDocument::STATUS_READY, KnowledgeDocument::STATUS_PENDING], [$lost->fresh()->status, $stuck->fresh()->status, $fresh->fresh()->status]);
    }

    // ==================================================================
    // Retrieval
    // ==================================================================

    public function test_retrieval_ranks_relevant_chunks_limits_filters_and_bills_the_query(): void
    {
        $account = $this->tenant(100);
        $base = $this->base($account);
        $document = $this->submit($base, self::REFUNDS, 'policies', 'Store policies');
        $this->process($document);
        $before = $this->balance($account)[0];

        $result = $this->retrieve($account, $base->id, 'How long is the warranty on a device?', 2, null, 'q:1');

        $this->assertCount(2, $result->chunks);
        $top = $result->chunks[0];
        $this->assertStringContainsString('warranty', strtolower($top->text));
        $this->assertSame([$document->id, $base->id, 1, 'Store policies', 'policies'], [$top->documentId, $top->knowledgeBaseId, $top->documentVersion, $top->documentTitle, $top->sourceKey]);
        $this->assertGreaterThanOrEqual($result->chunks[1]->score, $top->score);
        $this->assertTrue($top->score <= 1.0 && $top->score > 0);
        $this->assertSame([1, $before - 1], [$result->creditsCharged, $this->balance($account)[0]], 'the query embedding is billed to the target account');
        $this->assertSame('knowledge.query', AiOperation::find($result->aiOperationId)->operation);

        $this->assertSame([], $this->retrieve($account, $base->id, 'warranty', 5, 0.9999)->chunks, 'min score filters');
        $this->assertKnowledgeError(KnowledgeException::INVALID_QUERY, fn () => $this->retrieve($account, $base->id, 'x', 11));
        $this->assertKnowledgeError(KnowledgeException::INVALID_QUERY, fn () => $this->retrieve($account, $base->id, '   '));
        $this->assertAiError(AiException::OPERATION_DUPLICATE, fn () => $this->retrieve($account, $base->id, 'warranty', 2, null, 'q:1'));
    }

    public function test_an_unindexed_knowledge_base_returns_nothing_without_calling_or_charging(): void
    {
        $account = $this->tenant(100);
        $base = $this->base($account);
        $this->submit($base, self::REFUNDS); // pending, never processed

        $result = $this->retrieve($account, $base->id, 'refund');

        $this->assertSame([[], null, 0, 0], [$result->chunks, $result->aiOperationId, $result->creditsCharged, $this->emb['calls']]);
        $this->assertSame([100, 0], $this->balance($account));
    }

    public function test_retrieval_never_crosses_accounts(): void
    {
        $a = $this->tenant(100);
        $b = $this->tenant(100);
        $baseA = $this->base($a, 'Shared name');
        $baseB = $this->base($b, 'Shared name');
        $this->process($this->submit($baseA, 'Account A secret refund rules for gold members.'));
        $this->process($this->submit($baseB, 'Account B refund rules for silver members.'));

        // B asking for A's knowledge base id: not found, nothing embedded or charged
        $calls = $this->emb['calls'];
        $this->assertKnowledgeError(KnowledgeException::KNOWLEDGE_BASE_NOT_FOUND, fn () => $this->retrieve($b, $baseA->id, 'refund rules'));
        $this->assertSame($calls, $this->emb['calls']);

        // the account named must be the authorized one
        $this->assertAiError(AiException::TARGET_ACCOUNT_FORBIDDEN, fn () => app(KnowledgeRetriever::class)->retrieve(
            new RetrievalQuery($a, app(AiAuthorizer::class)->forAccount($b, 'chatbot', 'test'), $baseA->id, 'refund'),
        ));

        // each account sees only its own chunks even for identical queries
        $hitsB = $this->retrieve($b, $baseB->id, 'refund rules gold members');
        $this->assertNotEmpty($hitsB->chunks);
        foreach ($hitsB->chunks as $chunk) {
            $this->assertStringContainsString('Account B', $chunk->text);
        }

        // the vector store itself filters by account even if handed another account's knowledge base id
        $query = app(AiManager::class)->embeddingProvider()->embed(new EmbeddingRequest(['refund rules']))->vectors[0];
        $this->assertSame([], app(DatabaseVectorStore::class)->search($b->id, $baseA->id, $query, 10));
    }

    public function test_the_schema_refuses_cross_account_rows(): void
    {
        $a = $this->tenant(100);
        $b = $this->tenant(100);
        $baseA = $this->base($a);

        $this->expectException(\Illuminate\Database\QueryException::class);
        DB::table('knowledge_documents')->insert([
            'account_id' => $b->id, 'knowledge_base_id' => $baseA->id, 'title' => 'x', 'source_key' => 'x', 'content' => 'x',
            'content_hash' => str_repeat('0', 64), 'status' => 'pending', 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    // ==================================================================
    // HTTP API + authorization
    // ==================================================================

    public function test_the_api_lifecycle_for_a_tenant(): void
    {
        $account = $this->tenant(100);
        $user = $this->user($account);

        $id = $this->actingAs($user)->postJson(self::API, ['name' => 'Help center', 'description' => 'FAQ'])->assertCreated()->json('data.id');
        $this->actingAs($user)->postJson(self::API, ['name' => 'Help center'])->assertStatus(422)->assertJsonPath('error_code', 'KNOWLEDGE_BASE_NAME_TAKEN');

        $doc = $this->actingAs($user)->postJson(self::API."/{$id}/documents", ['title' => 'Policies', 'content' => self::REFUNDS, 'source_key' => 'faq/policies'])
            ->assertStatus(202)->assertJsonPath('created', true)->assertJsonPath('data.status', 'pending');
        $this->assertArrayNotHasKey('content', $doc->json('data'), 'document text is not echoed back');
        $docId = $doc->json('data.id');
        $this->actingAs($user)->postJson(self::API."/{$id}/documents", ['title' => 'Policies', 'content' => self::REFUNDS, 'source_key' => 'faq/policies'])
            ->assertOk()->assertJsonPath('changed', false);

        $this->artisan('queue:work', ['connection' => 'database', '--queue' => 'knowledge', '--stop-when-empty' => true, '--memory' => 4096])->assertSuccessful();

        $this->actingAs($user)->getJson(self::API)->assertOk()->assertJsonPath('data.0.documents_count', 1);
        $this->actingAs($user)->getJson(self::API."/{$id}")->assertOk()->assertJsonPath('data.documents_by_status.ready', 1)->assertJsonPath('data.embedding_dimensions', 256);
        $this->actingAs($user)->getJson(self::API."/{$id}/documents/{$docId}")->assertOk()->assertJsonPath('data.status', 'ready')->assertJsonMissingPath('data.content');

        $search = $this->actingAs($user)->withHeaders(['Idempotency-Key' => 'search-1'])->postJson(self::API."/{$id}/search", ['query' => 'refund within thirty days', 'limit' => 3])->assertOk();
        $this->assertStringContainsString('refund', strtolower($search->json('data.results.0.text')));
        $this->assertSame(1, $search->json('data.credits_charged'));
        $this->assertStringNotContainsString('embedding', $search->getContent());
        $this->flushHeaders();

        $this->actingAs($user)->postJson(self::API."/{$id}/documents/{$docId}/reprocess")->assertStatus(202)->assertJsonPath('data.version', 2);
        $this->actingAs($user)->deleteJson(self::API."/{$id}/documents/{$docId}")->assertOk();
        $this->assertSame(0, KnowledgeChunk::count(), 'chunks deleted with the document');
        $this->actingAs($user)->postJson(self::API."/{$id}/search", ['query' => 'refund'])->assertOk()->assertJsonPath('data.results', []);

        $this->actingAs($user)->deleteJson(self::API."/{$id}")->assertOk();
        $this->assertSame(0, KnowledgeBase::count());
    }

    public function test_another_accounts_ids_are_not_found_through_every_endpoint(): void
    {
        $a = $this->tenant(100);
        $b = $this->tenant(100);
        $baseA = $this->base($a);
        $docA = $this->submit($baseA, self::REFUNDS);
        $this->process($docA);
        $userB = $this->user($b);
        $baseB = $this->base($b, 'Mine');

        $this->actingAs($userB)->getJson(self::API)->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $baseB->id);
        foreach ([
            ['getJson', "/{$baseA->id}"], ['deleteJson', "/{$baseA->id}"], ['getJson', "/{$baseA->id}/documents"],
            ['postJson', "/{$baseA->id}/documents", ['title' => 't', 'content' => 'c']], ['getJson', "/{$baseA->id}/documents/{$docA->id}"],
            ['postJson', "/{$baseA->id}/documents/{$docA->id}/reprocess"], ['deleteJson', "/{$baseA->id}/documents/{$docA->id}"],
            ['postJson', "/{$baseA->id}/search", ['query' => 'refund']],
            ['getJson', "/{$baseB->id}/documents/{$docA->id}"], ['deleteJson', "/{$baseB->id}/documents/{$docA->id}"],
        ] as $call) {
            $this->actingAs($userB)->{$call[0]}(self::API.$call[1], $call[2] ?? [])->assertNotFound();
        }

        $this->assertSame(1, KnowledgeBase::where('account_id', $a->id)->count());
        $this->assertSame(1, KnowledgeDocument::where('account_id', $a->id)->where('status', 'ready')->count());
        $this->assertSame(0, AiOperation::where('account_id', $b->id)->count(), 'nothing charged to anyone');
    }

    public function test_permission_module_capability_and_subscription_are_enforced(): void
    {
        $account = $this->tenant(100);
        $this->actingAs($this->user($account, 'user'))->getJson(self::API)->assertForbidden(); // no manage-chatbot

        $starter = $this->tenant(100, 'starter'); // no `ai` capability
        $this->actingAs($this->user($starter))->getJson(self::API)->assertForbidden()->assertJsonPath('error_code', 'AI_CAPABILITY_UNAVAILABLE');

        $revoked = $this->tenant(100);
        $admin = $this->user($revoked);
        $this->revoke($revoked, 'ai');
        $this->actingAs($admin)->postJson(self::API, ['name' => 'x'])->assertForbidden()->assertJsonPath('error_code', 'AI_CAPABILITY_UNAVAILABLE');

        $noModule = $this->tenant(100);
        $noModule->forceFill(['allowed_modules' => array_values(array_diff(Account::MODULES, ['chatbot']))])->save();
        $this->actingAs($this->user($noModule))->getJson(self::API)->assertForbidden()->assertJsonPath('error_code', 'MODULE_DISABLED');

        $expired = $this->tenant(100);
        $expiredUser = $this->user($expired);
        $expired->currentSubscription->forceFill(['expires_at' => now()->subDay(), 'status' => 'expired'])->save();
        $this->actingAs($expiredUser)->getJson(self::API)->assertForbidden();

        $this->assertSame(0, KnowledgeBase::count());
    }

    public function test_super_admin_and_agent_act_only_on_a_resolved_target_account(): void
    {
        $superAdmin = $this->user(null, 'super_admin');
        $client = $this->tenant(100);
        $starter = $this->tenant(100, 'starter');

        $this->actingAs($superAdmin)->postJson(self::API, ['name' => 'x'])->assertStatus(422)->assertJsonPath('error_code', 'AI_TARGET_ACCOUNT_REQUIRED');
        $this->actingAs($superAdmin)->postJson(self::API.'?account_id='.$starter->id, ['name' => 'x'])->assertForbidden()->assertJsonPath('error_code', 'AI_CAPABILITY_UNAVAILABLE');
        $id = $this->actingAs($superAdmin)->postJson(self::API.'?account_id='.$client->id, ['name' => 'For client'])->assertCreated()->json('data.id');
        $this->assertSame($client->id, KnowledgeBase::find($id)->account_id, 'owned by the selected client');

        $agent = $this->tenant(100, 'growth', ['account_type' => 'agent']);
        $sub = $this->tenant(100, 'growth', ['agent_id' => $agent->id]);
        $agentUser = $this->user($agent, 'agent');
        $agentUser->assignRole('admin');
        $subBase = $this->actingAs($agentUser)->postJson(self::API.'?account_id='.$sub->id, ['name' => 'Sub KB'])->assertCreated()->json('data.id');
        $this->assertSame($sub->id, KnowledgeBase::find($subBase)->account_id);
        $this->actingAs($agentUser)->getJson(self::API.'?account_id='.$client->id)->assertNotFound(); // refused by tenant isolation before the controller
    }

    // ==================================================================
    // Boundaries
    // ==================================================================

    public function test_knowledge_code_never_calls_a_vendor_or_the_ledger_directly(): void
    {
        $files = array_merge(glob(app_path('Services/Knowledge/{,*/}*.php'), GLOB_BRACE), [
            app_path('Http/Controllers/Api/KnowledgeBaseController.php'), app_path('Jobs/ProcessKnowledgeDocumentJob.php'),
        ]);

        foreach ($files as $file) {
            $source = file_get_contents($file);
            foreach (['Http::', 'OpenAiProvider', 'AnthropicProvider', 'GeminiProvider', 'generativelanguage', 'api.openai.com', 'CreditService', 'CreditConsumptionService', 'env(', '->generateText('] as $forbidden) {
                $this->assertStringNotContainsString($forbidden, $source, basename($file)." must not use {$forbidden}");
            }
            $this->assertDoesNotMatchRegularExpression('/(?<!Metered)AiService \$/', $source, basename($file).' must not call AiService directly (only through MeteredAiService)');
        }

        $this->assertStringContainsString('MeteredAiService $metered', file_get_contents(app_path('Services/Knowledge/KnowledgeIngestionService.php')));
        $this->assertStringContainsString('MeteredAiService $metered', file_get_contents(app_path('Services/Knowledge/DatabaseKnowledgeRetriever.php')));
    }

    public function test_the_retriever_is_bound_for_the_journey_rag_node(): void
    {
        // Phase 8 Task 10 — the rag node now executes through this binding (JourneyRagNodeTest).
        $this->assertTrue(JourneyNodeCatalog::isRuntimeExecutable('rag'));
        $this->assertInstanceOf(DatabaseKnowledgeRetriever::class, app(KnowledgeRetriever::class));
    }

    public function test_the_vector_codec_round_trips_unit_vectors(): void
    {
        $vector = DatabaseVectorStore::normalize([3, 4]);
        $this->assertEqualsWithDelta([0.6, 0.8], $vector, 1e-9);
        $this->assertEqualsWithDelta($vector, DatabaseVectorStore::unpack(DatabaseVectorStore::pack($vector)), 1e-6);
        $this->assertSame([], DatabaseVectorStore::unpack('abc'));
    }
}
