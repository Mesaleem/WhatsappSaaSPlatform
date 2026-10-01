<?php
// Phase 8 Task 7 — Journey AI nodes under REAL concurrency (MariaDB).
//
// DESTRUCTIVE TO ITS TARGET DATABASE (runs migrate:fresh). Refuses to run
// unless the database is named exactly `wa_throwaway_test`. Not PHPUnit
// (RefreshDatabase's transaction is invisible to other processes).
//
// Usage (from backend-api/):
//   DB_CONNECTION=mariadb DB_HOST=127.0.0.1 DB_PORT=3306 DB_DATABASE=wa_throwaway_test \
//   DB_USERNAME=... DB_PASSWORD=... APP_ENV=testing php tests/Probes/journey_ai_concurrency_probe.php
// Needs pcntl. Exit 0 = every check passed.
//
// Real OS processes (each with its own DB connection) race to resume the
// SAME due Journey session parked at an AI node — duplicate/overlapping
// journeys workers — through the production path (resumeDueSession claim →
// JourneyAiNodeRunner → MeteredAiService → CreditService). The AI provider
// is an in-process fake that sleeps to overlap and records every call in a
// shared file; WhatsApp sends are faked at the HTTP boundary.
use App\Models\{Account, AiOperation, CreditLedgerEntry, CreditReservation, Invoice, MessageDispatchLog, WhatsAppFlow, WhatsAppFlowSession, WhatsAppSession};
use App\Services\Ai\AiManager;
use App\Services\Ai\Contracts\AiProvider;
use App\Services\Ai\Data\{AiRequest, AiResponse};
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
config(['ai.default' => 'fake', 'ai.enabled' => ['fake'], 'ai.providers.fake' => ['model' => 'fake'],
    'ai.credits.tokens_per_credit' => 1000, 'ai.credits.minimum_per_operation' => 1, 'ai.credits.missing_usage' => 'minimum',
    'ai.journey.max_tokens' => 2000]);
app(AiManager::class)->extend('fake', fn () => new class($calls) implements AiProvider {
    public function __construct(private readonly string $calls) {}
    public function name(): string { return 'fake'; }
    public function generateText(AiRequest $r): AiResponse
    {
        file_put_contents($this->calls, getmypid()."\n", FILE_APPEND | LOCK_EX);
        usleep(random_int(50000, 200000));

        return new AiResponse('fake', 'fake', 'reply', null, 1500, 500); // 2 credits
    }
    public function generateStructured(AiRequest $r): AiResponse { return $this->generateText($r); }
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
    file_put_contents($calls, '');
    $a = tenant(100);
    WhatsAppFlow::create(['account_id' => $a->id, 'name' => 'AI', 'trigger_type' => 'keyword', 'trigger_value' => 'go', 'is_active' => true, 'graph_data' => [
        'nodes' => [['id' => 't', 'type' => 'trigger', 'data' => []], ['id' => 'p', 'type' => 'prompt', 'data' => ['prompt' => 'Hi', 'outputVariable' => 'out']], ['id' => 's', 'type' => 'text', 'data' => ['text' => 'AI: {{out}}']]],
        'edges' => [['id' => 'e1', 'source' => 't', 'target' => 'p'], ['id' => 'e2', 'source' => 'p', 'target' => 's']],
    ]]);

    app(ChatbotEngineService::class)->handleInboundMessage($a->id, $phone, 'go', null, 'qr', "wamsg:r{$round}");
    $session = WhatsAppFlowSession::where('account_id', $a->id)->latest('id')->firstOrFail();
    $check("[{$round}] the inbound path hands the AI node to the worker without calling the provider",
        $session->status === 'waiting' && $session->current_node_id === 'p' && trim((string) file_get_contents($calls)) === '');

    // 8 workers resume the same due session at once.
    race(8, fn () => app(WhatsAppJourneyEngine::class)->resumeDueSession($session->id));

    $session->refresh();
    $providerCalls = count(array_filter(explode("\n", trim((string) file_get_contents($calls)))));
    $bal = app(CreditService::class)->balance($a);
    $check("[{$round}] exactly one provider call and one operation", $providerCalls === 1 && AiOperation::where('account_id', $a->id)->count() === 1 && AiOperation::where('account_id', $a->id)->value('status') === 'settled', "calls={$providerCalls}");
    $check("[{$round}] charged exactly once, no hold left", $bal['balance'] === 98 && $bal['reserved'] === 0
        && CreditLedgerEntry::where('account_id', $a->id)->where('type', 'consumption')->count() === 1
        && CreditReservation::where('account_id', $a->id)->where('status', 'reserved')->count() === 0, json_encode($bal));
    $sent = MessageDispatchLog::where('account_id', $a->id)->where('source', 'journey')->where('status', 'sent')->pluck('message_preview')->all();
    $check("[{$round}] the reply was stored and sent once; session completed", $session->status === 'completed' && ($session->context_data['out'] ?? null) === 'reply' && $sent === ['AI: reply'], json_encode([$session->status, $sent]));
}

@unlink($calls);
$passed = count(array_filter($results));
echo "\n{$passed}/".count($results)." checks passed\n".DB::select('select version() v')[0]->v."\n";
exit($passed === count($results) ? 0 : 1);
