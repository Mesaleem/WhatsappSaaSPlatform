<?php
// Phase 8 Task 9 — knowledge-base processing + retrieval under REAL concurrency (MariaDB).
//
// DESTRUCTIVE TO ITS TARGET DATABASE (runs migrate:fresh). Refuses to run
// unless the database is named exactly `wa_throwaway_test`. Not PHPUnit.
//
// Usage (from backend-api/):
//   DB_CONNECTION=mariadb DB_HOST=127.0.0.1 DB_PORT=3306 DB_DATABASE=wa_throwaway_test \
//   DB_USERNAME=... DB_PASSWORD=... APP_ENV=testing php tests/Probes/knowledge_concurrency_probe.php
// Needs pcntl. Exit 0 = every check passed.
//
// (a) 8 workers process the SAME document version at once → exactly one
//     embedding per batch, one charge per batch, one live version;
// (b) two documents of an EMPTY knowledge base race to lock its embedding
//     space → both end ready in one consistent space;
// (c) 8 concurrent searches with the same Idempotency key → one charge.
use App\Models\{Account, AiOperation, CreditReservation, Invoice, KnowledgeBase, KnowledgeChunk, KnowledgeDocument};
use App\Services\Ai\{AiAuthorizer, AiException, AiManager};
use App\Services\Ai\Contracts\{AiProvider, EmbeddingProvider};
use App\Services\Ai\Data\{AiRequest, AiResponse, EmbeddingRequest, EmbeddingResponse};
use App\Services\Ai\Retrieval\{KnowledgeRetriever, RetrievalQuery};
use App\Services\Billing\InvoiceCreditService;
use App\Services\Credits\CreditService;
use App\Services\Knowledge\{KnowledgeBaseService, KnowledgeIngestionService};
use App\Support\PlanCatalog;
use Illuminate\Support\Facades\{Artisan, DB};

require dirname(__DIR__, 2).'/vendor/autoload.php';
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
if (DB::connection()->getDatabaseName() !== 'wa_throwaway_test') { fwrite(STDERR, "refusing: not the throwaway DB\n"); exit(2); }

Artisan::call('migrate:fresh', ['--force' => true]);
Artisan::call('db:seed', ['--class' => 'RolePermissionSeeder', '--force' => true]);
Artisan::call('db:seed', ['--class' => 'Phase1FoundationSeeder', '--force' => true]);

$calls = tempnam(sys_get_temp_dir(), 'kbcalls');
config(['ai.default' => 'fakeemb', 'ai.enabled' => ['fakeemb'], 'ai.embeddings.provider' => 'fakeemb', 'ai.providers.fakeemb' => ['embedding_model' => 'bow'],
    'ai.credits.tokens_per_credit' => 1000, 'ai.credits.minimum_per_operation' => 1, 'ai.credits.missing_usage' => 'minimum',
    'ai.knowledge.chunk_chars' => 200, 'ai.knowledge.chunk_overlap_chars' => 40, 'ai.knowledge.embedding_batch_size' => 2]);
app(AiManager::class)->extend('fakeemb', fn () => new class($calls) implements AiProvider, EmbeddingProvider {
    public function __construct(private readonly string $calls) {}
    public function name(): string { return 'fakeemb'; }
    public function generateText(AiRequest $r): AiResponse { throw new LogicException('unused'); }
    public function generateStructured(AiRequest $r): AiResponse { throw new LogicException('unused'); }
    public function embed(EmbeddingRequest $r): EmbeddingResponse
    {
        file_put_contents($this->calls, $r->operation."\n", FILE_APPEND | LOCK_EX);
        usleep(random_int(20000, 120000));
        $vectors = array_map(function ($t) { $v = array_fill(0, 64, 0.0); foreach (preg_split('/\W+/', strtolower($t), -1, PREG_SPLIT_NO_EMPTY) as $w) { $v[crc32($w) % 64] += 1; } $v[63] += 0.01; return $v; }, $r->inputs);

        return new EmbeddingResponse('fakeemb', 'bow', $vectors, 10);
    }
});

function tenant(int $credits): Account {
    $a = Account::factory()->create(); $p = PlanCatalog::find('growth');
    $i = Invoice::create(['account_id' => $a->id, 'invoice_number' => 'INV-'.uniqid(), 'plan_key' => 'growth', 'plan_label' => $p['label'], 'amount' => $p['price'], 'tax_amount' => 0, 'total_amount' => $p['price'], 'currency' => 'INR', 'payment_gateway' => 'razorpay', 'gateway_order_id' => 'o_'.uniqid(), 'status' => 'pending']);
    app(InvoiceCreditService::class)->markPaidAndCreditQuota($i->id, 'pay_'.uniqid());
    app(CreditService::class)->grant($a->fresh(), $credits, 'probe:grant:'.uniqid());
    return $a->fresh();
}
function race(int $n, callable $work, string $out): void {
    DB::disconnect();
    $pids = [];
    for ($i = 0; $i < $n; $i++) {
        $pid = pcntl_fork();
        if ($pid === 0) {
            DB::reconnect();
            try { $r = $work($i); $r = is_object($r) ? 'ok' : (string) $r; } catch (AiException $e) { $r = $e->errorCode; } catch (Throwable $e) { $r = 'EXC:'.class_basename($e).':'.$e->getMessage(); }
            file_put_contents($out, $r."\n", FILE_APPEND | LOCK_EX);
            exit(0);
        }
        $pids[] = $pid;
    }
    foreach ($pids as $p) pcntl_waitpid($p, $st);
    DB::reconnect();
}
function lines(string $file): array { return array_values(array_filter(explode("\n", trim((string) file_get_contents($file))))); }
$results = [];
$check = function (string $name, bool $ok, string $detail = '') use (&$results) { $results[] = $ok; echo ($ok ? 'PASS ' : 'FAIL ')."{$name}".($detail !== '' ? " — {$detail}" : '')."\n"; };
$text = str_repeat('Refunds are accepted within thirty days of purchase. ', 12)."\n\n".str_repeat('Shipping takes two business days. ', 10);

foreach ([1, 2, 3] as $round) {
    // (a) same version, 8 workers
    file_put_contents($calls, '');
    $a = tenant(100);
    $base = app(KnowledgeBaseService::class)->createKnowledgeBase($a, "KB {$round}", null, null);
    $doc = app(KnowledgeBaseService::class)->submitDocument($base, 'Doc', $text)['document'];
    $out = tempnam(sys_get_temp_dir(), 'kbout');
    race(8, fn () => app(KnowledgeIngestionService::class)->process($doc->id, 1), $out);
    $outcomes = array_count_values(lines($out));
    $doc->refresh();
    $chunks = KnowledgeChunk::where('knowledge_document_id', $doc->id)->count();
    $batches = (int) ceil($chunks / 2);
    $embedCalls = count(lines($calls));
    $bal = app(CreditService::class)->balance($a);
    $check("[{$round}] one worker processes the version, the others skip", ($outcomes['ready'] ?? 0) === 1 && ($outcomes['skipped'] ?? 0) === 7, json_encode($outcomes));
    $check("[{$round}] one embedding and one charge per batch", $embedCalls === $batches && AiOperation::where('account_id', $a->id)->where('status', 'settled')->count() === $batches && $bal['balance'] === 100 - $batches && $bal['reserved'] === 0, "calls={$embedCalls} batches={$batches}");
    $check("[{$round}] one live version, every chunk embedded", $doc->status === 'ready' && $doc->indexed_version === 1 && KnowledgeChunk::where('knowledge_document_id', $doc->id)->whereNull('embedding')->count() === 0);
    @unlink($out);

    // (b) two documents lock an empty knowledge base's embedding space concurrently
    $b = tenant(100);
    $base2 = app(KnowledgeBaseService::class)->createKnowledgeBase($b, "KB2 {$round}", null, null);
    $d1 = app(KnowledgeBaseService::class)->submitDocument($base2, 'One', 'Alpha document about refunds and returns.', 'one')['document'];
    $d2 = app(KnowledgeBaseService::class)->submitDocument($base2, 'Two', 'Beta document about shipping and delivery.', 'two')['document'];
    $out = tempnam(sys_get_temp_dir(), 'kbout');
    race(2, fn ($i) => app(KnowledgeIngestionService::class)->process($i === 0 ? $d1->id : $d2->id, 1), $out);
    $base2->refresh();
    $check("[{$round}] concurrent first documents share one embedding space", lines($out) !== [] && array_count_values(lines($out)) === ['ready' => 2]
        && $base2->embedding_provider === 'fakeemb' && $base2->embedding_dimensions === 64, json_encode(lines($out)));
    @unlink($out);

    // (c) 8 concurrent searches with the same idempotency key
    $out = tempnam(sys_get_temp_dir(), 'kbout');
    $before = app(CreditService::class)->balance($a)['balance'];
    race(8, fn () => app(KnowledgeRetriever::class)->retrieve(new RetrievalQuery(Account::find($a->id), app(AiAuthorizer::class)->forAccount(Account::find($a->id), 'chatbot', 'probe'), $base->id, 'refund days', 3, null, "search:r{$round}")), $out);
    $outcomes = array_count_values(lines($out));
    $check("[{$round}] the same search key is charged once", ($outcomes['ok'] ?? 0) === 1 && ($outcomes['AI_OPERATION_DUPLICATE'] ?? 0) === 7
        && app(CreditService::class)->balance($a)['balance'] === $before - 1 && CreditReservation::where('account_id', $a->id)->where('status', 'reserved')->count() === 0, json_encode($outcomes));
    @unlink($out);
}

@unlink($calls);
$passed = count(array_filter($results));
echo "\n{$passed}/".count($results)." checks passed\n".DB::select('select version() v')[0]->v."\n";
exit($passed === count($results) ? 0 : 1);
