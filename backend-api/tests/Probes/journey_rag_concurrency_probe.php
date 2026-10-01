<?php
// Phase 8 Task 10 — the Journey `rag` node under REAL concurrency (MariaDB).
//
// DESTRUCTIVE TO ITS TARGET DATABASE (runs migrate:fresh). Refuses to run
// unless the database is named exactly `wa_throwaway_test`. Not PHPUnit
// (RefreshDatabase's transaction is invisible to other processes).
//
// Usage (from backend-api/):
//   DB_CONNECTION=mariadb DB_HOST=127.0.0.1 DB_PORT=3306 DB_DATABASE=wa_throwaway_test \
//   DB_USERNAME=... DB_PASSWORD=... APP_ENV=testing php tests/Probes/journey_rag_concurrency_probe.php
// Needs pcntl. Exit 0 = every check passed.
//
// 8 OS processes (each with its own DB connection) race to resume the SAME
// due session parked at a rag node — overlapping journeys workers — through
// the production path (resumeDueSession claim → JourneyAiNodeRunner →
// KnowledgeRetriever → MeteredAiService). The provider is an in-process fake
// (embeddings + generation) that sleeps to overlap and records every call.
use App\Models\{Account, AiOperation, CreditLedgerEntry, CreditReservation, Invoice, MessageDispatchLog, WhatsAppFlow, WhatsAppFlowSession, WhatsAppSession};
use App\Services\Ai\AiManager;
use App\Services\Ai\Contracts\{AiProvider, EmbeddingProvider};
use App\Services\Ai\Data\{AiRequest, AiResponse, EmbeddingRequest, EmbeddingResponse};
use App\Services\Knowledge\{KnowledgeBaseService, KnowledgeIngestionService};
use App\Services\Billing\InvoiceCreditService;
use App\Services\Chatbot\ChatbotEngineService;
use App\Services\Credits\CreditService;
use App\Services\WhatsApp\WhatsAppJourneyEngine;
use App\Support\PlanCatalog;
use Illuminate\Support\Facades\{Artisan, DB, Http};

require dirname(__DIR__, 2).'/vendor/autoload.php';
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
if (DB::connection()->getDatabaseName() !== 'wa_throwaway_test') { fwrite(STDERR, "refusing: not the throwaway DB\n"); exit(2); }

Artisan::call('migrate:fresh', ['--force' => true]);
Artisan::call('db:seed', ['--class' => 'RolePermissionSeeder', '--force' => true]);
Artisan::call('db:seed', ['--class' => 'Phase1FoundationSeeder', '--force' => true]);
Http::fake(fn () => Http::response(['success' => true, 'message_id' => 'QR_'.uniqid()], 200));

$calls = tempnam(sys_get_temp_dir(), 'jaicalls');
config(['ai.default' => 'fake', 'ai.enabled' => ['fake'], 'ai.providers.fake' => ['model' => 'fake', 'embedding_model' => 'bow'],
    'ai.embeddings.provider' => 'fake', 'ai.knowledge.chunk_chars' => 200, 'ai.knowledge.chunk_overlap_chars' => 0,
    'ai.credits.tokens_per_credit' => 1000, 'ai.credits.minimum_per_operation' => 1, 'ai.credits.missing_usage' => 'minimum',
    'ai.journey.max_tokens' => 2000]);
app(AiManager::class)->extend('fake', fn () => new class($calls) implements AiProvider, EmbeddingProvider {
    public function __construct(private readonly string $calls) {}
    public function name(): string { return 'fake'; }
    public function generateText(AiRequest $r): AiResponse
    {
        file_put_contents($this->calls, "gen\n", FILE_APPEND | LOCK_EX);
        usleep(random_int(50000, 200000));

        return new AiResponse('fake', 'fake', 'reply', null, 1500, 500); // 2 credits
    }
    public function generateStructured(AiRequest $r): AiResponse { return $this->generateText($r); }
    public function embed(EmbeddingRequest $r): EmbeddingResponse
    {
        if ($r->operation === 'knowledge.query') {
            file_put_contents($this->calls, "query\n", FILE_APPEND | LOCK_EX);
            usleep(random_int(20000, 100000));
        }
        $vectors = array_map(function ($t) { $v = array_fill(0, 64, 0.0); foreach (preg_split('/\W+/', strtolower($t), -1, PREG_SPLIT_NO_EMPTY) as $w) { $v[crc32($w) % 64] += 1; } $v[63] += 0.01; return $v; }, $r->inputs);

        return new EmbeddingResponse('fake', 'bow', $vectors, 10); // 1 credit
    }
});

function tenant(int $credits): Account {
    $a = Account::factory()->create(); $p = PlanCatalog::find('growth');
    $i = Invoice::create(['account_id' => $a->id, 'invoice_number' => 'INV-'.uniqid(), 'plan_key' => 'growth', 'plan_label' => $p['label'], 'amount' => $p['price'], 'tax_amount' => 0, 'total_amount' => $p['price'], 'currency' => 'INR', 'payment_gateway' => 'razorpay', 'gateway_order_id' => 'o_'.uniqid(), 'status' => 'pending']);
    app(InvoiceCreditService::class)->markPaidAndCreditQuota($i->id, 'pay_'.uniqid());
    WhatsAppSession::create(['account_id' => $a->id, 'status' => 'connected']);
    app(CreditService::class)->grant($a->fresh(), $credits, 'probe:grant:'.uniqid());
    return $a->fresh();
}
function race(int $n, callable $work): void {
    DB::disconnect();
    $pids = [];
    for ($i = 0; $i < $n; $i++) {
        $pid = pcntl_fork();
        if ($pid === 0) { DB::reconnect(); try { $work($i); } catch (Throwable $e) { fwrite(STDERR, 'child: '.class_basename($e).': '.$e->getMessage()."\n"); } exit(0); }
        $pids[] = $pid;
    }
    foreach ($pids as $p) pcntl_waitpid($p, $st);
    DB::reconnect();
}
$results = [];
$check = function (string $name, bool $ok, string $detail = '') use (&$results) { $results[] = $ok; echo ($ok ? 'PASS ' : 'FAIL ')."{$name}".($detail !== '' ? " — {$detail}" : '')."\n"; };
$phone = '919800000077';

foreach ([1, 2, 3] as $round) {
    $a = tenant(100);
    $base = app(KnowledgeBaseService::class)->createKnowledgeBase($a, 'KB', null, null);
    $doc = app(KnowledgeBaseService::class)->submitDocument($base, 'Policies', "Refunds within thirty days.\n\nWarranty lasts one year on every device.")['document'];
    app(KnowledgeIngestionService::class)->process($doc->id, 1);
    $before = app(CreditService::class)->balance($a)['balance'];
    file_put_contents($calls, '');
    WhatsAppFlow::create(['account_id' => $a->id, 'name' => 'RAG', 'trigger_type' => 'keyword', 'trigger_value' => 'go', 'is_active' => true, 'graph_data' => [
        'nodes' => [['id' => 't', 'type' => 'trigger', 'data' => []], ['id' => 'q', 'type' => 'question', 'data' => ['prompt_text' => 'Ask', 'variable_name' => 'question', 'input_type' => 'text']],
            ['id' => 'r', 'type' => 'rag', 'data' => ['knowledgeBaseId' => (string) $base->id, 'queryVariable' => 'question', 'topK' => 2, 'outputVariable' => 'answer']],
            ['id' => 's', 'type' => 'text', 'data' => ['text' => 'A: {{answer}}']]],
        'edges' => [['id' => 'e1', 'source' => 't', 'target' => 'q'], ['id' => 'e2', 'source' => 'q', 'target' => 'r'], ['id' => 'e3', 'source' => 'r', 'target' => 's']],
    ]]);

    app(ChatbotEngineService::class)->handleInboundMessage($a->id, $phone, 'go', null, 'qr', "wamsg:r{$round}a");
    app(ChatbotEngineService::class)->handleInboundMessage($a->id, $phone, 'how long is the warranty', null, 'qr', "wamsg:r{$round}b");
    $session = WhatsAppFlowSession::where('account_id', $a->id)->latest('id')->firstOrFail();
    $check("[{$round}] the inbound path hands the rag node to the worker without any provider call",
        $session->status === 'waiting' && $session->current_node_id === 'r' && trim((string) file_get_contents($calls)) === '');

    race(8, fn () => app(WhatsAppJourneyEngine::class)->resumeDueSession($session->id));

    $session->refresh();
    $lines = array_count_values(array_filter(explode("\n", trim((string) file_get_contents($calls)))));
    $ops = AiOperation::where('account_id', $a->id)->where('operation_key', 'like', 'journey:%')->pluck('status', 'operation')->all();
    ksort($ops);
    $bal = app(CreditService::class)->balance($a);
    $check("[{$round}] exactly one query embedding and one generation", ($lines['query'] ?? 0) === 1 && ($lines['gen'] ?? 0) === 1, json_encode($lines));
    $check("[{$round}] one retrieval + one generation operation, both settled, charged once", $ops === ['journey.rag' => 'settled', 'knowledge.query' => 'settled'] && $bal['balance'] === $before - 3 && $bal['reserved'] === 0, json_encode($ops + $bal));
    $sent = MessageDispatchLog::where('account_id', $a->id)->where('source', 'journey')->where('status', 'sent')->pluck('message_preview')->all();
    $check("[{$round}] the answer was stored and sent once; session completed", $session->status === 'completed' && ($session->context_data['answer'] ?? null) === 'reply' && $sent === ['Ask', 'A: reply'], json_encode([$session->status, $sent]));
}

@unlink($calls);
$passed = count(array_filter($results));
echo "\n{$passed}/".count($results)." checks passed\n".DB::select('select version() v')[0]->v."\n";
exit($passed === count($results) ? 0 : 1);
