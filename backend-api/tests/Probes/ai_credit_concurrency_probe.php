<?php
// Phase 8 Task 5 — AI credit metering under REAL concurrency (MariaDB).
//
// DESTRUCTIVE TO ITS TARGET DATABASE (runs migrate:fresh). Refuses to run
// unless the database is named exactly `wa_throwaway_test`. Not PHPUnit
// (RefreshDatabase's transaction is invisible to other processes).
//
// Usage (from backend-api/):
//   DB_CONNECTION=mariadb DB_HOST=127.0.0.1 DB_PORT=3306 DB_DATABASE=wa_throwaway_test \
//   DB_USERNAME=... DB_PASSWORD=... APP_ENV=testing php tests/Probes/ai_credit_concurrency_probe.php
// Needs pcntl. Exit 0 = every check passed.
//
// Real OS processes, each with its own DB connection, run MeteredAiService
// against ONE account through the production code (existing CreditService
// row lock); the AI provider is an in-process fake that sleeps to overlap.
use App\Models\{Account, AiOperation, CreditAccount, CreditLedgerEntry, CreditReservation, Invoice};
use App\Services\Ai\{AiAuthorizer, AiException, AiManager};
use App\Services\Ai\Billing\MeteredAiService;
use App\Services\Ai\Contracts\AiProvider;
use App\Services\Ai\Data\{AiRequest, AiResponse};
use App\Services\Billing\InvoiceCreditService;
use App\Services\Credits\CreditService;
use App\Support\PlanCatalog;
use Illuminate\Support\Facades\{Artisan, DB};

require dirname(__DIR__, 2).'/vendor/autoload.php';
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
if (DB::connection()->getDatabaseName() !== 'wa_throwaway_test') { fwrite(STDERR, "refusing: not the throwaway DB\n"); exit(2); }

Artisan::call('migrate:fresh', ['--force' => true]);
Artisan::call('db:seed', ['--class' => 'RolePermissionSeeder', '--force' => true]);
Artisan::call('db:seed', ['--class' => 'Phase1FoundationSeeder', '--force' => true]);

config(['ai.default' => 'fake', 'ai.enabled' => ['fake'], 'ai.providers.fake' => ['model' => 'fake'],
    'ai.credits.tokens_per_credit' => 1000, 'ai.credits.minimum_per_operation' => 1, 'ai.credits.missing_usage' => 'minimum']);
app(AiManager::class)->extend('fake', fn () => new class implements AiProvider {
    public function name(): string { return 'fake'; }
    public function generateText(AiRequest $r): AiResponse { usleep(random_int(20000, 120000)); return new AiResponse('fake', 'fake', 'ok', null, 1500, 500); }
    public function generateStructured(AiRequest $r): AiResponse { return $this->generateText($r); }
});

function subscriber(int $credits): Account {
    $a = Account::factory()->create(); $p = PlanCatalog::find('growth');
    $i = Invoice::create(['account_id' => $a->id, 'invoice_number' => 'INV-'.uniqid(), 'plan_key' => 'growth', 'plan_label' => $p['label'], 'amount' => $p['price'], 'tax_amount' => 0, 'total_amount' => $p['price'], 'currency' => 'INR', 'payment_gateway' => 'razorpay', 'gateway_order_id' => 'o_'.uniqid(), 'status' => 'pending']);
    app(InvoiceCreditService::class)->markPaidAndCreditQuota($i->id, 'pay_'.uniqid());
    app(CreditService::class)->grant($a, $credits, 'probe:grant:'.uniqid());
    return $a->fresh();
}
function race(int $n, callable $work, string $out): void {
    DB::disconnect();
    $pids = [];
    for ($i = 0; $i < $n; $i++) {
        $pid = pcntl_fork();
        if ($pid === 0) {
            DB::reconnect();
            try { $work($i); $r = 'ok'; } catch (AiException $e) { $r = $e->errorCode; } catch (Throwable $e) { $r = 'EXC:'.class_basename($e).':'.$e->getMessage(); }
            file_put_contents($out, $r."\n", FILE_APPEND | LOCK_EX);
            exit(0);
        }
        $pids[] = $pid;
    }
    foreach ($pids as $p) pcntl_waitpid($p, $st);
    DB::reconnect();
}
function consistent(Account $a): bool {
    $c = CreditAccount::where('account_id', $a->id)->first(); $b = $r = 0;
    foreach (CreditLedgerEntry::where('account_id', $a->id)->orderBy('id')->get() as $e) {
        $b += $e->balance_delta; $r += $e->reserved_delta;
        if ($b !== (int) $e->balance_after || $r !== (int) $e->reserved_after || $r < 0 || $r > $b) return false;
    }
    return $b === (int) $c->balance && $r === (int) $c->reserved && $b >= 0;
}
$results = [];
$check = function (string $name, bool $ok, string $detail = '') use (&$results) { $results[] = $ok; echo ($ok ? 'PASS ' : 'FAIL ')."{$name}".($detail !== '' ? " — {$detail}" : '')."\n"; };
$req = new AiRequest('Summarize this lead.', maxTokens: 4000); // hold 5, charge 2

foreach ([1, 2, 3] as $round) {
    // (a) 16 distinct operations race on 20 credits: holds of 5 → at most 4 in flight.
    $a = subscriber(20);
    $out = tempnam(sys_get_temp_dir(), 'aiprobe');
    race(16, fn ($i) => app(MeteredAiService::class)->generateText(app(AiAuthorizer::class)->forAccount(Account::find($a->id), 'chatbot', 'journey'), $req, "r{$round}:op:{$i}"), $out);
    $outcomes = array_count_values(array_filter(explode("\n", trim(file_get_contents($out)))));
    $ok = $outcomes['ok'] ?? 0;
    $bal = app(CreditService::class)->balance($a);
    $consumed = (int) CreditLedgerEntry::where('account_id', $a->id)->where('type', 'consumption')->sum('amount');
    $check("[{$round}] distinct operations never overspend", $ok >= 4 && $ok + ($outcomes['INSUFFICIENT_CREDITS'] ?? 0) === 16 && $bal['balance'] === 20 - 2 * $ok && $bal['reserved'] === 0 && $bal['available'] >= 0, json_encode($outcomes + ['balance' => $bal['balance']]));
    $check("[{$round}] ledger consistent, one consumption per success", consistent($a) && $consumed === 2 * $ok && CreditLedgerEntry::where('account_id', $a->id)->where('type', 'consumption')->count() === $ok && AiOperation::where('account_id', $a->id)->where('status', 'settled')->count() === $ok);
    $check("[{$round}] no hold left open", CreditReservation::where('account_id', $a->id)->where('status', 'reserved')->count() === 0);
    @unlink($out);

    // (b) 8 processes run the SAME operation key at once: one runs, one charge.
    $b = subscriber(100);
    $out = tempnam(sys_get_temp_dir(), 'aiprobe');
    race(8, fn () => app(MeteredAiService::class)->generateText(app(AiAuthorizer::class)->forAccount(Account::find($b->id), 'chatbot', 'api'), $req, "r{$round}:same"), $out);
    $outcomes = array_count_values(array_filter(explode("\n", trim(file_get_contents($out)))));
    $check("[{$round}] the same operation key is charged once", ($outcomes['ok'] ?? 0) === 1 && ($outcomes['AI_OPERATION_DUPLICATE'] ?? 0) === 7 && app(CreditService::class)->balance($b)['balance'] === 98 && consistent($b) && AiOperation::where('account_id', $b->id)->count() === 1, json_encode($outcomes));
    @unlink($out);
}

$passed = count(array_filter($results));
echo "\n{$passed}/".count($results)." checks passed\n".DB::select('select version() v')[0]->v."\n";
exit($passed === count($results) ? 0 : 1);
