<?php
// Phase 5 fix P5-9 — InboundEventGate non-blocking probe against REAL MariaDB.
//
// DESTRUCTIVE TO ITS TARGET DATABASE (runs migrate:fresh). It refuses to run
// unless the connected database is named exactly `wa_throwaway_test`. Never
// point it at wa_saas_platform or any shared database. Not part of PHPUnit
// (not under tests/Unit or tests/Feature): PHPUnit's RefreshDatabase wraps a
// test in a transaction other processes cannot see.
//
// Usage (from backend-api/):
//   DB_CONNECTION=mariadb DB_HOST=127.0.0.1 DB_PORT=3306 DB_DATABASE=wa_throwaway_test \
//   DB_USERNAME=... DB_PASSWORD=... APP_ENV=testing \
//   php tests/Probes/inbound_gate_nonblocking_probe.php
// (Any QUEUE_CONNECTION: deferred messages always go to the database
// `journeys` queue, which the probe drains like the scheduled worker.)
// Exit code 0 = every check passed. Needs the pcntl extension.
//
// Scenario (real OS processes, each with its own DB connection):
//   1. process H holds one customer's conversation lease for HOLD_SECONDS
//      (a slow message being processed through the real gate);
//   2. meanwhile N processes deliver inbound messages for that same
//      conversation concurrently — DISTINCT event keys, each delivered
//      twice (provider redelivery) — through ChatbotEngineService, the
//      webhook's real entry point;
//   3. two real `queue:work database --queue=journeys` workers then drain
//      the deferred jobs concurrently.
// Checks: no delivery waited for the lease (old gate: up to 10 s); deferral
// was used; every distinct event was processed exactly once (none lost,
// none twice); exactly one reply and one quota unit per distinct event; no
// failed job; the lease is free at the end; another customer was never
// blocked.
use App\Models\{Account, ChatbotLog, ChatbotRule, InboundMessageEvent, Invoice, Subscription, WhatsAppSession};
use App\Services\Billing\InvoiceCreditService;
use App\Services\Chatbot\ChatbotEngineService;
use App\Services\Messaging\InboundEventGate;
use App\Support\PlanCatalog;
use Illuminate\Support\Facades\{Artisan, DB, Http};

require dirname(__DIR__, 2).'/vendor/autoload.php';
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
if (DB::connection()->getDatabaseName() !== 'wa_throwaway_test') { fwrite(STDERR, "refusing: not the throwaway DB\n"); exit(2); }

const PHONE = '919800000001';
const OTHER_PHONE = '919800000002';
const HOLD_SECONDS = 4;
const DISTINCT = 6;
const COPIES = 2;

Artisan::call('migrate:fresh', ['--force' => true]);
Artisan::call('db:seed', ['--class' => 'RolePermissionSeeder', '--force' => true]);
Artisan::call('db:seed', ['--class' => 'Phase1FoundationSeeder', '--force' => true]);
Http::fake(fn () => (usleep(random_int(1000, 20000)) ?: Http::response(['success' => true, 'message_id' => 'QR_'.uniqid()], 200)));

$account = Account::factory()->create();
$plan = PlanCatalog::find('growth');
$invoice = Invoice::create(['account_id' => $account->id, 'invoice_number' => 'INV-'.uniqid(), 'plan_key' => 'growth', 'plan_label' => $plan['label'], 'amount' => $plan['price'], 'tax_amount' => 0, 'total_amount' => $plan['price'], 'currency' => 'INR', 'payment_gateway' => 'razorpay', 'gateway_order_id' => 'o_'.uniqid(), 'status' => 'pending']);
app(InvoiceCreditService::class)->markPaidAndCreditQuota($invoice->id, 'pay_'.uniqid());
WhatsAppSession::create(['account_id' => $account->id, 'status' => 'connected']);
ChatbotRule::create(['account_id' => $account->id, 'name' => 'Any', 'match_type' => 'fallback', 'keywords' => [], 'response_type' => 'text', 'response_payload' => ['text' => 'ok'], 'priority' => 1, 'is_active' => true]);
$usedBefore = (int) Subscription::where('account_id', $account->id)->value('used_messages');

$timings = tempnam(sys_get_temp_dir(), 'p59probe');
$results = [];
$check = function (string $name, bool $ok, string $detail = '') use (&$results) {
    $results[] = $ok;
    echo ($ok ? 'PASS ' : 'FAIL ')."{$name}".($detail !== '' ? " — {$detail}" : '')."\n";
};

// ---------------------------------------------------------------- 1. holder
DB::disconnect();
$holder = pcntl_fork();
if ($holder === 0) {
    DB::reconnect();
    app(InboundEventGate::class)->run($account->id, PHONE, 'probe', null, fn () => sleep(HOLD_SECONDS)); // test-only wait: simulates a slow message
    exit(0);
}
usleep(500_000); // let the holder take the lease first

// ---------------------------------------------------------------- 2. concurrent deliveries
$pids = [];
for ($k = 1; $k <= DISTINCT; $k++) {
    for ($c = 1; $c <= COPIES; $c++) {
        $pid = pcntl_fork();
        if ($pid === 0) {
            DB::reconnect();
            $t = microtime(true);
            app(ChatbotEngineService::class)->handleInboundMessage($account->id, PHONE, "msg {$k}", null, 'qr', "wamsg:P59-{$k}");
            file_put_contents($timings, sprintf("%s %.4f\n", PHONE, microtime(true) - $t), FILE_APPEND | LOCK_EX);
            exit(0);
        }
        $pids[] = $pid;
    }
}
// Another customer of the same tenant, while the first conversation is held.
$pid = pcntl_fork();
if ($pid === 0) {
    DB::reconnect();
    $t = microtime(true);
    app(ChatbotEngineService::class)->handleInboundMessage($account->id, OTHER_PHONE, 'hello', null, 'qr', 'wamsg:P59-OTHER');
    file_put_contents($timings, sprintf("%s %.4f\n", OTHER_PHONE, microtime(true) - $t), FILE_APPEND | LOCK_EX);
    exit(0);
}
$pids[] = $pid;
foreach ($pids as $p) pcntl_waitpid($p, $st);
DB::reconnect();

$lines = array_filter(explode("\n", trim((string) file_get_contents($timings))));
$durations = array_map(fn ($l) => (float) explode(' ', $l)[1], $lines);
$holderStillHolding = DB::table('journey_conversation_locks')->where('account_id', $account->id)->where('phone_number', PHONE)->whereNotNull('owner')->exists();
$deferred = DB::table('jobs')->where('queue', 'journeys')->where('payload', 'like', '%ProcessDeferredInboundMessageJob%')->count();
$check('every delivery returned while the conversation was still held', $holderStillHolding && count($durations) === DISTINCT * COPIES + 1);
$check('no delivery waited for the lease', max($durations) < 1.0, sprintf('max %.3f s over %d deliveries (old gate: up to 10 s)', max($durations), count($durations)));
$check('busy deliveries were deferred, not dropped', $deferred === DISTINCT * COPIES, "{$deferred} deferred jobs");
$check('nothing of the held conversation was claimed or processed meanwhile', InboundMessageEvent::where('phone_number', PHONE)->count() === 0);
$check('another customer was processed immediately', ChatbotLog::where('account_id', $account->id)->where('sender_phone', OTHER_PHONE)->where('status', 'replied')->count() === 1);

pcntl_waitpid($holder, $st);

// ---------------------------------------------------------------- 3. two real workers drain the queue
DB::disconnect();
$workers = [];
for ($w = 0; $w < 2; $w++) {
    $pid = pcntl_fork();
    if ($pid === 0) {
        DB::reconnect();
        $deadline = microtime(true) + 90;
        while (microtime(true) < $deadline && DB::table('jobs')->exists()) {
            Artisan::call('queue:work', ['connection' => 'database', '--queue' => 'journeys', '--stop-when-empty' => true, '--sleep' => 0, '--tries' => 1]);
            usleep(200_000);
        }
        exit(0);
    }
    $workers[] = $pid;
}
foreach ($workers as $p) pcntl_waitpid($p, $st);
DB::reconnect();

$claims = InboundMessageEvent::where('account_id', $account->id)->where('phone_number', PHONE)->get();
$replies = ChatbotLog::where('account_id', $account->id)->where('sender_phone', PHONE)->where('status', 'replied')->count();
$used = (int) Subscription::where('account_id', $account->id)->value('used_messages') - $usedBefore;
$check('queue drained', ! DB::table('jobs')->exists());
$check('no failed job', DB::table('failed_jobs')->count() === 0, DB::table('failed_jobs')->count().' failed');
$check('every distinct event claimed exactly once — none lost, none twice', $claims->count() === DISTINCT && $claims->pluck('event_key')->unique()->count() === DISTINCT, $claims->count().' claims');
$check('every claim completed', $claims->whereNull('processed_at')->count() === 0);
$check('exactly one reply per distinct event', $replies === DISTINCT, "{$replies} replies");
$check('exactly one quota unit per reply (+1 for the other customer)', $used === DISTINCT + 1, "{$used} used");
$check('lease free at the end', ! DB::table('journey_conversation_locks')->whereNotNull('owner')->where('locked_until', '>', now())->exists());

@unlink($timings);
$passed = count(array_filter($results));
echo "\n{$passed}/".count($results)." checks passed\n";
exit($passed === count($results) ? 0 : 1);
