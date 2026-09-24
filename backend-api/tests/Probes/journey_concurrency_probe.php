<?php
// Phase 7 Task 9 — Journey cross-process concurrency probe against REAL MariaDB.
//
// DESTRUCTIVE TO ITS TARGET DATABASE (runs migrate:fresh). It refuses to run
// unless the connected database is named exactly `wa_throwaway_test`. Never
// point it at wa_saas_platform or any shared database. Not part of PHPUnit
// (not under tests/Unit or tests/Feature).
//
// Usage (from backend-api/):
//   DB_CONNECTION=mariadb DB_HOST=127.0.0.1 DB_PORT=3306 DB_DATABASE=wa_throwaway_test \
//   DB_USERNAME=... DB_PASSWORD=... APP_ENV=testing php tests/Probes/journey_concurrency_probe.php
// Exit code 0 = every check passed. Needs the pcntl extension.
// Phase 7 Task 9 — REAL MariaDB concurrency probe (throwaway DB only).
// Forks N OS processes, each with its own DB connection, that race on the
// same Journey rows through the production code paths. Not a PHPUnit test:
// PHPUnit's RefreshDatabase wraps each test in a transaction other
// processes cannot see.
use App\Models\{Account, Invoice, WhatsAppFlow, WhatsAppFlowSession, WhatsAppSession, MessageDispatchLog, JourneyExecutionEvent as Ev, Lead};
use App\Services\Billing\InvoiceCreditService;
use App\Services\Chatbot\ChatbotEngineService;
use App\Services\WhatsApp\WhatsAppJourneyEngine;
use App\Support\PlanCatalog;
use Illuminate\Support\Facades\{DB, Http, Artisan};

require dirname(__DIR__, 2).'/vendor/autoload.php';
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
if (DB::connection()->getDatabaseName() !== 'wa_throwaway_test') { fwrite(STDERR, "refusing: not the throwaway DB\n"); exit(2); }

Artisan::call('migrate:fresh', ['--force' => true]);
Artisan::call('db:seed', ['--class' => 'RolePermissionSeeder', '--force' => true]);
Artisan::call('db:seed', ['--class' => 'Phase1FoundationSeeder', '--force' => true]);
Http::fake(fn () => (usleep(random_int(1000, 20000)) ?: Http::response(['success' => true, 'message_id' => 'QR_'.uniqid()], 200)));

function tenant(): Account {
    $a = Account::factory()->create(); $p = PlanCatalog::find('growth');
    $i = Invoice::create(['account_id' => $a->id, 'invoice_number' => 'INV-'.uniqid(), 'plan_key' => 'growth', 'plan_label' => $p['label'], 'amount' => $p['price'], 'tax_amount' => 0, 'total_amount' => $p['price'], 'currency' => 'INR', 'payment_gateway' => 'razorpay', 'gateway_order_id' => 'o_'.uniqid(), 'status' => 'pending']);
    app(InvoiceCreditService::class)->markPaidAndCreditQuota($i->id, 'pay_'.uniqid());
    WhatsAppSession::create(['account_id' => $a->id, 'status' => 'connected']);
    return $a->fresh();
}
function flow(Account $a, array $nodes, string $kw = 'go'): WhatsAppFlow {
    $edges = [['id' => 'et', 'source' => 't', 'target' => $nodes[0]['id']]];
    for ($i = 1; $i < count($nodes); $i++) $edges[] = ['id' => "e$i", 'source' => $nodes[$i-1]['id'], 'target' => $nodes[$i]['id']];
    return WhatsAppFlow::create(['account_id' => $a->id, 'name' => 'C', 'trigger_type' => 'keyword', 'trigger_value' => $kw, 'is_active' => true, 'graph_data' => ['nodes' => [['id' => 't', 'type' => 'trigger', 'data' => []], ...$nodes], 'edges' => $edges]]);
}
function race(int $n, callable $work): void {
    DB::disconnect();
    $pids = [];
    for ($i = 0; $i < $n; $i++) {
        $pid = pcntl_fork();
        if ($pid === 0) { DB::reconnect(); try { $work($i); } catch (Throwable $e) { fwrite(STDERR, "child: ".$e->getMessage()."\n"); } exit(0); }
        $pids[] = $pid;
    }
    foreach ($pids as $p) pcntl_waitpid($p, $st);
    DB::reconnect();
}
function sent(Account $a, string $phone = '919800000001'): array {
    return MessageDispatchLog::where('account_id', $a->id)->where('source', 'journey')->where('status', 'sent')->where('recipient_phone', $phone)->orderBy('id')->pluck('message_preview')->all();
}
$results = [];
$check = function (string $name, bool $ok, string $detail = '') use (&$results) { $results[] = [$name, $ok, $detail]; };
$N = 8;

// (a) N workers resume the same due session.
$a = tenant(); flow($a, [['id' => 'd', 'type' => 'delay', 'data' => ['amount' => 1, 'unit' => 'seconds']], ['id' => 'x', 'type' => 'text', 'data' => ['text' => 'X']], ['id' => 'y', 'type' => 'text', 'data' => ['text' => 'Y']]]);
app(ChatbotEngineService::class)->handleInboundMessage($a->id, '919800000001', 'go', null, 'qr', 'a-1');
$s = WhatsAppFlowSession::where('account_id', $a->id)->sole();
WhatsAppFlowSession::whereKey($s->id)->update(['wait_until' => now()->subSecond()]);
race($N, fn () => app(WhatsAppJourneyEngine::class)->resumeDueSession($s->id));
$check('(a) concurrent resume runs once', sent($a) === ['X', 'Y'] && $s->fresh()->status === 'completed', json_encode([sent($a), $s->fresh()->status]));

// (b) N copies of the same inbound event (same WAMID).
$b = tenant(); flow($b, [['id' => 'q', 'type' => 'question', 'data' => ['prompt_text' => 'Q?', 'variable_name' => 'v', 'input_type' => 'text']]]);
race($N, fn () => app(ChatbotEngineService::class)->handleInboundMessage($b->id, '919800000001', 'go', null, 'qr', 'wamid.same'));
$check('(b) duplicate inbound executes once', sent($b) === ['Q?'] && WhatsAppFlowSession::where('account_id', $b->id)->count() === 1 && Ev::where('account_id', $b->id)->where('event', 'inbound_deduplicated')->count() === $N - 1, json_encode([sent($b), Ev::where('account_id', $b->id)->where('event', 'inbound_deduplicated')->count()]));

// (c) N DIFFERENT messages for one phone at once: serialized, one journey.
$c = tenant(); flow($c, [['id' => 'q', 'type' => 'question', 'data' => ['prompt_text' => 'Q?', 'variable_name' => 'v', 'input_type' => 'text']], ['id' => 'z', 'type' => 'text', 'data' => ['text' => 'Z {{v}}']]]);
race($N, fn ($i) => app(ChatbotEngineService::class)->handleInboundMessage($c->id, '919800000001', 'go', null, 'qr', "c-$i"));
$sessions = WhatsAppFlowSession::where('account_id', $c->id)->get();
$open = $sessions->whereIn('status', WhatsAppFlowSession::OPEN_STATUSES)->count();
$check('(c) different messages for one phone never start two journeys', $open <= 1 && count(array_filter(sent($c), fn ($p) => $p === 'Q?')) === $sessions->count() && $sessions->count() >= 1, json_encode([$sessions->pluck('status'), sent($c)]));

// (d) cancel racing a resumed run: never cancelled→anything else, never both.
$d = tenant(); flow($d, array_merge([['id' => 'd', 'type' => 'delay', 'data' => ['amount' => 1, 'unit' => 'seconds']]], array_map(fn ($i) => ['id' => "m$i", 'type' => 'text', 'data' => ['text' => "M$i"]], range(1, 10))));
app(ChatbotEngineService::class)->handleInboundMessage($d->id, '919800000001', 'go', null, 'qr', 'd-1');
$s = WhatsAppFlowSession::where('account_id', $d->id)->sole();
WhatsAppFlowSession::whereKey($s->id)->update(['wait_until' => now()->subSecond()]);
race(2, fn ($i) => $i === 0 ? app(WhatsAppJourneyEngine::class)->resumeDueSession($s->id) : (usleep(30000) ?: app(WhatsAppJourneyEngine::class)->cancelSession(WhatsAppFlowSession::find($s->id))));
$final = $s->fresh()->status; $n = count(sent($d));
$cancelEv = Ev::where('session_id', $s->id)->where('event', 'session_cancelled')->count();
$completeEv = Ev::where('session_id', $s->id)->where('event', 'session_completed')->count();
$check('(d) cancel vs resumed run: one terminal outcome', ($final === 'cancelled' && $completeEv === 0 && $n < 10) || ($final === 'completed' && $cancelEv === 0 && $n === 10), json_encode([$final, $n, $cancelEv, $completeEv]));

// (e) N schedulers recover the same interrupted runs.
$e = tenant(); flow($e, [['id' => 'x', 'type' => 'text', 'data' => ['text' => 'X']]]);
$ids = [];
foreach (range(1, 20) as $i) { $ids[] = WhatsAppFlowSession::create(['account_id' => $e->id, 'flow_id' => WhatsAppFlow::where('account_id', $e->id)->value('id'), 'flow_version_id' => WhatsAppFlow::where('account_id', $e->id)->value('published_version_id'), 'phone_number' => '9198000100'.str_pad((string) $i, 2, '0', STR_PAD_LEFT), 'status' => 'active', 'current_node_id' => 'x', 'context_data' => [], 'wait_until' => now()->subMinute()])->id; }
race($N, fn () => app(WhatsAppJourneyEngine::class)->recoverInterruptedRuns());
$recEv = Ev::whereIn('session_id', $ids)->where('event', 'node_retry_scheduled')->count();
$check('(e) concurrent recovery recovers each run once', $recEv === 20 && WhatsAppFlowSession::whereIn('id', $ids)->where('status', 'waiting')->count() === 20, "events=$recEv");
race($N, function () use ($ids) { foreach ($ids as $id) app(WhatsAppJourneyEngine::class)->resumeDueSession($id); });
$sentE = MessageDispatchLog::where('account_id', $e->id)->where('status', 'sent')->count();
$check('(e2) then concurrent resumes send each once', $sentE === 20 && WhatsAppFlowSession::whereIn('id', $ids)->where('status', 'completed')->count() === 20, "sent=$sentE");

// (f) N restorers + N revokers flapping entitlement never duplicate a restore or revive a terminal session.
$f = tenant(); flow($f, [['id' => 'q', 'type' => 'question', 'data' => ['prompt_text' => 'Q?', 'variable_name' => 'v', 'input_type' => 'text']]]);
app(ChatbotEngineService::class)->handleInboundMessage($f->id, '919800000001', 'go', null, 'qr', 'f-1');
$s = WhatsAppFlowSession::where('account_id', $f->id)->sole();
WhatsAppFlowSession::whereKey($s->id)->update(['status' => 'blocked', 'wait_until' => null]);
race($N, fn () => app(WhatsAppJourneyEngine::class)->restoreEntitledBlockedSessions());
$check('(f) concurrent restore restores once', $s->fresh()->status === 'active' && Ev::where('session_id', $s->id)->where('event', 'session_restored')->count() === 1, (string) Ev::where('session_id', $s->id)->where('event', 'session_restored')->count());

// (g) terminal immutability under a storm of every entry point.
$g = tenant(); flow($g, [['id' => 'q', 'type' => 'question', 'data' => ['prompt_text' => 'Q?', 'variable_name' => 'v', 'input_type' => 'text']], ['id' => 'x', 'type' => 'text', 'data' => ['text' => 'X']]]);
app(ChatbotEngineService::class)->handleInboundMessage($g->id, '919800000001', 'go', null, 'qr', 'g-1');
$s = WhatsAppFlowSession::where('account_id', $g->id)->sole();
app(WhatsAppJourneyEngine::class)->cancelSession($s);
race($N, function ($i) use ($s, $g) {
    $eng = app(WhatsAppJourneyEngine::class);
    match ($i % 4) { 0 => $eng->resumeDueSession($s->id), 1 => $eng->restoreEntitledBlockedSessions(), 2 => $eng->recoverInterruptedRuns(), 3 => $eng->cancelSession(WhatsAppFlowSession::find($s->id)) };
});
$check('(g) a cancelled session stays cancelled under every entry point', $s->fresh()->status === 'cancelled' && sent($g) === ['Q?'], $s->fresh()->status);

$ok = true;
foreach ($results as [$name, $pass, $detail]) { $ok = $ok && $pass; echo ($pass ? 'PASS ' : 'FAIL ').$name.($pass ? '' : "  $detail")."\n"; }
echo 'MariaDB '.DB::selectOne('select version() v')->v."\n";
exit($ok ? 0 : 1);
