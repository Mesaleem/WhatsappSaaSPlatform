<?php
// Phase 11 Task 4 — Billing & Collections under REAL concurrency (MariaDB).
//
// DESTRUCTIVE TO ITS TARGET DATABASE (runs migrate:fresh). Refuses to run unless the database is named
// exactly `wa_throwaway_collections`. Not PHPUnit (RefreshDatabase's transaction is invisible to other processes).
//
// Usage (from backend-api/):
//   DB_CONNECTION=mariadb DB_HOST=127.0.0.1 DB_PORT=3306 DB_DATABASE=wa_throwaway_collections \
//   DB_USERNAME=... DB_PASSWORD=... APP_ENV=testing php tests/Probes/collections_payment_concurrency_probe.php
// Needs pcntl. Exit 0 = every check passed.
//
// Real OS processes, each with its own DB connection, drive BillingCollectionService (the production code and
// its row lock) against ONE assignment: the paid total must never exceed the amount due, a key is honoured
// once, and an assignment is not created twice.
use App\Models\{Account, Contact};
use App\Models\Collections\{CollectionChargeAssignment, CollectionPayment};
use App\Services\Collections\BillingCollectionService;
use Illuminate\Support\Facades\{Artisan, DB};
use Illuminate\Validation\ValidationException;

require dirname(__DIR__, 2).'/vendor/autoload.php';
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
if (DB::connection()->getDatabaseName() !== 'wa_throwaway_collections') { fwrite(STDERR, "refusing: not the throwaway DB\n"); exit(2); }

Artisan::call('migrate:fresh', ['--force' => true]);
$core = app(BillingCollectionService::class);

function fixture(BillingCollectionService $core, string $due = '100.00'): array {
    $account = Account::factory()->create();
    $contact = Contact::create(['account_id' => $account->id, 'phone_number' => '91'.random_int(7000000000, 7999999999), 'name' => 'Probe']);
    $item = $core->createItem($account, 'probe', ['name' => 'Fee '.uniqid(), 'amount' => $due]);
    $assignment = $core->assign($account, 'probe', $contact->id, ['charge_item_id' => $item->id]);
    return [$account, $contact, $item, $assignment];
}
function race(int $n, callable $work, string $out): void {
    DB::disconnect();
    $pids = [];
    for ($i = 0; $i < $n; $i++) {
        $pid = pcntl_fork();
        if ($pid === 0) {
            DB::reconnect();
            try { $r = $work($i); $r = is_string($r) ? $r : 'ok'; }
            catch (ValidationException $e) { $r = 'invalid:'.implode(',', array_keys($e->errors())); }
            catch (Throwable $e) { $r = 'EXC:'.class_basename($e).':'.$e->getMessage(); }
            file_put_contents($out, $r."\n", FILE_APPEND | LOCK_EX);
            exit(0);
        }
        $pids[] = $pid;
    }
    foreach ($pids as $p) pcntl_waitpid($p, $st);
    DB::reconnect();
}
function tally(string $out): array { return array_count_values(array_filter(explode("\n", trim(file_get_contents($out))))); }
function paid(int $assignmentId): string { return (string) DB::table('collection_payments')->where('charge_assignment_id', $assignmentId)->sum('amount'); }

$results = [];
$check = function (string $name, bool $ok, string $detail = '') use (&$results) { $results[] = $ok; echo ($ok ? 'PASS ' : 'FAIL ')."{$name}".($detail !== '' ? " — {$detail}" : '')."\n"; };

foreach ([1, 2, 3] as $round) {
    // (a) 8 processes each try to pay the FULL amount: exactly one wins, the rest are refused as overpayment.
    [$account, , , $a] = fixture($core);
    $out = tempnam(sys_get_temp_dir(), 'colprobe');
    race(8, fn ($i) => $core->recordPayment($account, 'probe', $a, ['amount' => '100.00', 'idempotency_key' => "r{$round}-full-{$i}-".uniqid()]) && 'ok', $out);
    $t = tally($out);
    $check("[{$round}] 8 full payments at once: exactly one succeeds", ($t['ok'] ?? 0) === 1 && ($t['invalid:amount'] ?? 0) === 7 && (float) paid($a->id) === 100.0 && CollectionPayment::where('charge_assignment_id', $a->id)->count() === 1, json_encode($t));
    @unlink($out);

    // (b) 10 processes each pay 30.00 on 100.00: at most 3 fit, the paid total stays ≤ amount due.
    [$account, , , $a] = fixture($core);
    $out = tempnam(sys_get_temp_dir(), 'colprobe');
    race(10, fn ($i) => $core->recordPayment($account, 'probe', $a, ['amount' => '30.00', 'idempotency_key' => "r{$round}-part-{$i}-".uniqid()]) && 'ok', $out);
    $t = tally($out);
    $present = $core->present($account, CollectionChargeAssignment::with('item')->find($a->id));
    $check("[{$round}] 10 × 30.00 on 100.00: exactly three fit, balance never negative", ($t['ok'] ?? 0) === 3 && ($t['invalid:amount'] ?? 0) === 7 && (float) paid($a->id) === 90.0 && $present['outstanding'] === '10.00' && $present['status'] === 'partially_paid', json_encode($t));
    @unlink($out);

    // (c) the SAME key from 8 processes: one payment row, everyone gets a success.
    [$account, , , $a] = fixture($core);
    $out = tempnam(sys_get_temp_dir(), 'colprobe');
    race(8, fn () => $core->recordPayment($account, 'probe', $a, ['amount' => '25.00', 'payment_date' => '2026-09-01', 'idempotency_key' => "r{$round}-same-key"])['idempotent'] ? 'idem' : 'ok', $out);
    $t = tally($out);
    $check("[{$round}] one idempotency key from 8 processes: charged once", ($t['ok'] ?? 0) === 1 && ($t['idem'] ?? 0) === 7 && CollectionPayment::where('charge_assignment_id', $a->id)->count() === 1 && (float) paid($a->id) === 25.0, json_encode($t));
    @unlink($out);

    // (d) the same charge + due date assigned from 8 processes: one assignment.
    [$account, $contact, $item] = fixture($core);
    $out = tempnam(sys_get_temp_dir(), 'colprobe');
    race(8, fn () => $core->assign($account, 'probe', $contact->id, ['charge_item_id' => $item->id, 'due_date' => '2027-03-01']) && 'ok', $out);
    $t = tally($out);
    $check("[{$round}] 8 identical assignments at once: one row", ($t['ok'] ?? 0) === 1 && ($t['invalid:charge_item_id'] ?? 0) === 7 && CollectionChargeAssignment::where('contact_id', $contact->id)->where('due_date', '2027-03-01')->count() === 1, json_encode($t));
    @unlink($out);

    // (e) two different assignments of one contact are independent under load.
    [$account, $contact, $item, $a1] = fixture($core);
    $item2 = $core->createItem($account, 'probe', ['name' => 'Second '.uniqid(), 'amount' => '60.00']);
    $a2 = $core->assign($account, 'probe', $contact->id, ['charge_item_id' => $item2->id]);
    $out = tempnam(sys_get_temp_dir(), 'colprobe');
    race(8, fn ($i) => $core->recordPayment($account, 'probe', $i % 2 ? $a1 : $a2, ['amount' => '30.00', 'idempotency_key' => "r{$round}-two-{$i}-".uniqid()]) && 'ok', $out);
    $check("[{$round}] independent assignments do not interfere", (float) paid($a1->id) === 90.0 && (float) paid($a2->id) === 60.0, 'a1='.paid($a1->id).' a2='.paid($a2->id));
    @unlink($out);

    // (f) payments racing amount_due reductions on ONE assignment: whichever order they serialize in, the paid
    //     total never exceeds the final amount due, and every refusal is a clean validation error.
    foreach ([0, 1, 2, 3] as $trial) {
        [$account, , , $a] = fixture($core); // 100.00
        $out = tempnam(sys_get_temp_dir(), 'colprobe');
        race(12, function ($i) use ($core, $account, $a, $round, $trial) {
            if ($i % 2 === 0) {
                return $core->recordPayment($account, 'probe', $a, ['amount' => '30.00', 'idempotency_key' => "r{$round}-t{$trial}-mix-{$i}-".uniqid()]) && 'ok';
            }
            $core->updateAssignment($account, 'probe', $a, ['amount_due' => '50.00']);
            return 'upd';
        }, $out);
        $t = tally($out);
        $final = (float) DB::table('collection_charge_assignments')->where('id', $a->id)->value('amount_due');
        $sumPaid = (float) paid($a->id);
        $clean = count(array_filter(array_keys($t), fn ($k) => str_starts_with($k, 'EXC'))) === 0;
        $check("[{$round}.{$trial}] payments racing amount_due reductions: paid <= amount due", $sumPaid <= $final + 0.0001 && $clean && $final >= 50.0, json_encode($t + ['final_due' => $final, 'paid' => $sumPaid]));
        @unlink($out);
    }

    // (g) payments racing a cancel on ONE assignment: the row lock serializes them, so a charge is never left
    //     both cancelled AND carrying payments, and every refusal is a clean validation error.
    foreach ([0, 1, 2, 3] as $trial) {
        [$account, , , $a] = fixture($core); // 100.00
        $out = tempnam(sys_get_temp_dir(), 'colprobe');
        race(10, function ($i) use ($core, $account, $a, $round, $trial) {
            if ($i % 2 === 0) {
                return $core->recordPayment($account, 'probe', $a, ['amount' => '30.00', 'idempotency_key' => "r{$round}-t{$trial}-cx-{$i}-".uniqid()]) && 'paid';
            }
            $core->cancelAssignment($account, 'probe', $a, 'race');
            return 'cancelled';
        }, $out);
        $t = tally($out);
        $row = DB::table('collection_charge_assignments')->where('id', $a->id)->first();
        $sumPaid = (float) paid($a->id);
        $clean = count(array_filter(array_keys($t), fn ($k) => str_starts_with($k, 'EXC'))) === 0;
        $check("[{$round}.{$trial}] payments racing cancel: never cancelled with payments", $clean && ! ($row->cancelled_at !== null && $sumPaid > 0) && $sumPaid <= (float) $row->amount_due + 0.0001 && ($t['cancelled'] ?? 0) <= 1, json_encode($t + ['cancelled_at' => $row->cancelled_at !== null, 'paid' => $sumPaid]));
        @unlink($out);
    }

    // (h) cancel / reinstate / pay all at once: whatever the interleaving, the end state satisfies the invariants.
    foreach ([0, 1, 2, 3] as $trial) {
        [$account, , , $a] = fixture($core); // 100.00
        $core->cancelAssignment($account, 'probe', $a);
        $out = tempnam(sys_get_temp_dir(), 'colprobe');
        race(12, function ($i) use ($core, $account, $a, $round, $trial) {
            return match ($i % 3) {
                0 => $core->reinstateAssignment($account, 'probe', $a) && 'reinstated',
                1 => $core->recordPayment($account, 'probe', $a, ['amount' => '60.00', 'idempotency_key' => "r{$round}-t{$trial}-rc-{$i}-".uniqid()]) && 'paid',
                default => $core->cancelAssignment($account, 'probe', $a, 'race') && 'cancelled',
            };
        }, $out);
        $t = tally($out);
        $row = DB::table('collection_charge_assignments')->where('id', $a->id)->first();
        $sumPaid = (float) paid($a->id);
        $clean = count(array_filter(array_keys($t), fn ($k) => str_starts_with($k, 'EXC'))) === 0;
        $check("[{$round}.{$trial}] cancel/reinstate/pay interleaved: invariants hold", $clean && ! ($row->cancelled_at !== null && $sumPaid > 0) && $sumPaid <= (float) $row->amount_due + 0.0001, json_encode($t + ['cancelled_at' => $row->cancelled_at !== null, 'paid' => $sumPaid]));
        @unlink($out);
    }
}

$passed = count(array_filter($results));
echo "\n{$passed}/".count($results)." checks passed\n".DB::select('select version() v')[0]->v."\n";
exit($passed === count($results) ? 0 : 1);
