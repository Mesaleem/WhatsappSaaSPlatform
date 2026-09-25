<?php

// Phase 8 Task 1 — Credit System Foundation concurrency probe against REAL MariaDB/MySQL.
//
// DESTRUCTIVE TO ITS TARGET DATABASE (runs migrate:fresh). It refuses to run
// unless the connected database's name starts with `wa_throwaway_`. Never
// point it at wa_saas_platform or any shared database. Not part of PHPUnit.
//
// Usage (from backend-api/):
//   DB_CONNECTION=mariadb DB_HOST=127.0.0.1 DB_PORT=3306 DB_DATABASE=wa_throwaway_test \
//   DB_USERNAME=... DB_PASSWORD=... APP_ENV=testing [PROBE_ROUNDS=3] php tests/Probes/credit_concurrency_probe.php
// CreditSystemFoundationTest runs it (1 round) against `wa_throwaway_credit_probe` when the
// suite itself runs on MariaDB/MySQL. Exit code 0 = every check passed. Needs pcntl.
//
// Each check forks N OS processes, each with its OWN database connection,
// released together, calling the REAL CreditService (and, for the HTTP
// check, the real route through the HTTP kernel). PHPUnit alone cannot do
// this: RefreshDatabase wraps a test in one transaction no other process sees.
use App\Models\Account;
use App\Models\CreditAccount;
use App\Models\CreditLedgerEntry;
use App\Models\CreditReservation;
use App\Models\Invoice;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\UsageQuota;
use App\Models\User;
use App\Services\Billing\InvoiceCreditService;
use App\Services\Credits\PlanCreditAllocator;
use App\Services\Credits\CreditConsumptionService;
use App\Services\Credits\CreditException;
use App\Services\Credits\CreditService;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

require dirname(__DIR__, 2).'/vendor/autoload.php';
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$kernel = $app->make(Kernel::class);
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
if (! str_starts_with((string) DB::connection()->getDatabaseName(), 'wa_throwaway_') || ! in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
    fwrite(STDERR, "refusing: not a wa_throwaway_* MariaDB/MySQL database\n");
    exit(2);
}
$ROUNDS = max(1, (int) (getenv('PROBE_ROUNDS') ?: 3));

Artisan::call('migrate:fresh', ['--force' => true]);
Artisan::call('db:seed', ['--class' => 'RolePermissionSeeder', '--force' => true]);
// Phase 8 Task 2 — plans for the plan-allocation checks (growth: 300 credits per period).
Artisan::call('db:seed', ['--class' => 'Phase1FoundationSeeder', '--force' => true]);
Plan::where('slug', 'growth')->update(['included_credits' => 300]);

$OUT = tempnam(sys_get_temp_dir(), 'credit_probe_');

function svc(): CreditService
{
    return app(CreditService::class);
}
function spending(): CreditConsumptionService
{
    return app(CreditConsumptionService::class);
}
function note(string $line): void
{
    global $OUT;
    file_put_contents($OUT, $line."\n", FILE_APPEND | LOCK_EX);
}
/** An order exactly as checkout builds it (captured plan terms). */
function planOrder(Account $account, string $slug = 'growth'): Invoice
{
    $plan = Plan::where('slug', $slug)->firstOrFail();
    $invoice = new Invoice(['account_id' => $account->id, 'invoice_number' => 'INV-T2P-'.uniqid(), 'plan_key' => $slug, 'plan_label' => $plan->label,
        'amount' => $plan->price, 'tax_amount' => 0, 'total_amount' => $plan->price, 'currency' => 'INR', 'payment_gateway' => 'razorpay',
        'gateway_order_id' => 'order_'.uniqid(), 'status' => 'pending']);
    $invoice->capturePlanTerms($plan)->save();

    return $invoice;
}
function outcomes(): array
{
    global $OUT;
    $lines = array_filter(explode("\n", (string) file_get_contents($OUT)));
    file_put_contents($OUT, '');

    return array_count_values($lines);
}
/** Fork one process per closure; all start together (barrier on a timestamp). */
function race(array $work): void
{
    DB::disconnect();
    $go = microtime(true) + 0.5;
    $pids = [];
    foreach ($work as $i => $w) {
        $pid = pcntl_fork();
        if ($pid === 0) {
            DB::reconnect();
            app('auth')->forgetGuards();
            while (microtime(true) < $go) {
                usleep(200);
            }
            try {
                $w($i);
            } catch (CreditException $e) {
                note('refused:'.$e->reason);
            } catch (Throwable $e) {
                note('error:'.get_class($e));
                fwrite(STDERR, "child $i: ".$e->getMessage()."\n");
            }
            exit(0);
        }
        $pids[] = $pid;
    }
    foreach ($pids as $p) {
        pcntl_waitpid($p, $st);
    }
    DB::reconnect();
}
/** The ledger alone must reproduce the account's state, row by row. */
function ledgerConsistent(Account $account): bool
{
    $credit = CreditAccount::where('account_id', $account->id)->first();
    $balance = 0;
    $reserved = 0;
    foreach (CreditLedgerEntry::where('account_id', $account->id)->orderBy('id')->get() as $e) {
        $balance += $e->balance_delta;
        $reserved += $e->reserved_delta;
        if ($balance !== $e->balance_after || $reserved !== $e->reserved_after || $reserved > $balance || $balance < 0) {
            return false;
        }
    }
    // Phase 8 Task 3 — an open reservation holds amount − consumed_amount.
    $open = (int) CreditReservation::where('account_id', $account->id)->where('status', 'reserved')->get()->sum(fn ($r) => $r->remaining());
    foreach (CreditReservation::where('account_id', $account->id)->get() as $r) {
        $rows = CreditLedgerEntry::where('reservation_id', $r->id)->get();
        if ((int) $rows->where('type', 'consumption')->sum('amount') !== (int) ($r->consumed_amount ?? 0) || (int) $rows->sum('reserved_delta') !== $r->remaining()
            || $rows->where('type', 'reservation_release')->count() > 1 || ($r->status === 'reserved' && $rows->where('type', 'reservation_release')->count() > 0)) {
            return false;
        }
    }

    return $credit && $balance === (int) $credit->balance && $reserved === (int) $credit->reserved && $open === $reserved;
}

$results = [];
$check = function (string $name, bool $ok, $detail) use (&$results) {
    $results[] = [$name, $ok, json_encode($detail)];
};

for ($round = 1; $round <= $ROUNDS; $round++) {
    // 1. Simultaneous grants (distinct keys): every one lands, none lost.
    $a = Account::factory()->create();
    race(array_fill(0, 20, function ($i) use ($a) {
        svc()->grant($a, 10, "probe:grant:{$i}");
        note('ok');
    }));
    $o = outcomes();
    $check("[$round] 20 simultaneous grants", ($o['ok'] ?? 0) === 20 && svc()->balance($a)['balance'] === 200 && CreditLedgerEntry::where('account_id', $a->id)->count() === 20 && ledgerConsistent($a), $o + svc()->balance($a));

    // 2. Simultaneous reservations: exactly the available credits get reserved.
    $b = Account::factory()->create();
    svc()->grant($b, 100, 'probe:seed');
    race(array_fill(0, 20, function ($i) use ($b) {
        svc()->reserve($b, 10, "probe:reserve:{$i}");
        note('ok');
    }));
    $o = outcomes();
    $bal = svc()->balance($b);
    $check("[$round] 20 simultaneous reservations of 10 against 100", ($o['ok'] ?? 0) === 10 && ($o['refused:insufficient_credits'] ?? 0) === 10 && $bal === ['balance' => 100, 'reserved' => 100, 'available' => 0] && CreditReservation::where('account_id', $b->id)->count() === 10 && ledgerConsistent($b), $o + $bal);

    // 3. Simultaneous consumption: each reservation consumed by 2 racing processes → once.
    $ids = CreditReservation::where('account_id', $b->id)->pluck('id')->all();
    $work = [];
    foreach ($ids as $id) {
        foreach ([0, 1] as $n) {
            $work[] = function () use ($id) {
                $r = svc()->consume(CreditReservation::findOrFail($id));
                note($r->replayed ? 'replayed' : 'consumed');
            };
        }
    }
    race($work);
    $o = outcomes();
    $bal = svc()->balance($b);
    $check("[$round] 20 simultaneous consumes of 10 reservations", ($o['consumed'] ?? 0) === 10 && ($o['replayed'] ?? 0) === 10 && $bal === ['balance' => 0, 'reserved' => 0, 'available' => 0]
        && CreditLedgerEntry::where('account_id', $b->id)->where('type', 'consumption')->count() === 10 && ledgerConsistent($b), $o + $bal);

    // 4. Consume vs release racing on the same reservation: exactly one wins.
    $c = Account::factory()->create();
    svc()->grant($c, 50, 'probe:seed');
    $res = [];
    for ($i = 0; $i < 5; $i++) {
        $res[] = svc()->reserve($c, 10, "probe:cr:{$i}")->reservation->id;
    }
    $work = [];
    foreach ($res as $id) {
        $work[] = function () use ($id) { svc()->consume(CreditReservation::findOrFail($id)); note('consumed'); };
        $work[] = function () use ($id) { svc()->release(CreditReservation::findOrFail($id)); note('released'); };
    }
    race($work);
    $o = outcomes();
    $consumed = CreditReservation::where('account_id', $c->id)->where('status', 'consumed')->count();
    $released = CreditReservation::where('account_id', $c->id)->where('status', 'released')->count();
    $bal = svc()->balance($c);
    $check("[$round] consume vs release on 5 reservations", $consumed + $released === 5 && ($o['refused:reservation_already_terminal'] ?? 0) === 5
        && $bal['reserved'] === 0 && $bal['balance'] === 50 - 10 * $consumed && ledgerConsistent($c), $o + $bal);

    // 5. Duplicate idempotency key: 12 processes, one financial effect.
    $d = Account::factory()->create();
    race(array_fill(0, 12, function () use ($d) {
        $r = svc()->grant($d, 50, 'probe:dup:grant');
        note($r->replayed ? 'replayed' : 'applied');
    }));
    $o = outcomes();
    $check("[$round] 12 simultaneous duplicate grants (one key)", ($o['applied'] ?? 0) === 1 && ($o['replayed'] ?? 0) === 11 && svc()->balance($d)['balance'] === 50 && CreditLedgerEntry::where('account_id', $d->id)->count() === 1 && ledgerConsistent($d), $o);

    race(array_fill(0, 12, function () use ($d) {
        $r = svc()->reserve($d, 20, 'probe:dup:reserve');
        note($r->replayed ? 'replayed' : 'applied');
    }));
    $o = outcomes();
    $check("[$round] 12 simultaneous duplicate reservations (one key)", ($o['applied'] ?? 0) === 1 && ($o['replayed'] ?? 0) === 11 && CreditReservation::where('account_id', $d->id)->count() === 1 && svc()->balance($d)['reserved'] === 20 && ledgerConsistent($d), $o);

    // 6. The HTTP path: 8 simultaneous Super Admin grants with one Idempotency-Key.
    $e = Account::factory()->create();
    $admin = User::factory()->create(['account_id' => null, 'is_active' => true]);
    $admin->assignRole('super_admin');
    $token = $admin->createToken('probe')->plainTextToken;
    race(array_fill(0, 8, function () use ($e, $token) {
        global $kernel;
        $body = json_encode(['amount' => 75, 'reason' => 'probe']);
        $r = Request::create("/api/admin/accounts/{$e->id}/credits/grant", 'POST', [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json', 'HTTP_AUTHORIZATION' => "Bearer {$token}", 'HTTP_IDEMPOTENCY_KEY' => 'probe-http-grant-1'], $body);
        note('http:'.$kernel->handle($r)->getStatusCode());
    }));
    $o = outcomes();
    $check("[$round] 8 simultaneous HTTP grants (one Idempotency-Key)", ($o['http:201'] ?? 0) === 1 && ($o['http:200'] ?? 0) === 7 && svc()->balance($e)['balance'] === 75 && CreditLedgerEntry::where('account_id', $e->id)->count() === 1, $o);

    // 7. Mixed load on one account: grants, reservations, consumes, releases, adjustments.
    $f = Account::factory()->create();
    svc()->grant($f, 500, 'probe:seed');
    $work = [];
    for ($i = 0; $i < 24; $i++) {
        $work[] = match ($i % 4) {
            0 => function ($n) use ($f) { svc()->grant($f, 7, "probe:mix:g{$n}"); note('ok'); },
            1 => function ($n) use ($f) { $r = svc()->reserve($f, 40, "probe:mix:r{$n}"); svc()->consume($r->reservation, 25); note('ok'); },
            2 => function ($n) use ($f) { $r = svc()->reserve($f, 30, "probe:mix:x{$n}"); svc()->release($r->reservation); note('ok'); },
            3 => function ($n) use ($f) { svc()->adjust($f, -11, "probe:mix:a{$n}"); note('ok'); },
        };
    }
    race($work);
    $o = outcomes();
    $bal = svc()->balance($f);
    $check("[$round] 24 mixed operations on one account", ! array_filter(array_keys($o), fn ($k) => str_starts_with($k, 'error:')) && $bal['reserved'] === 0 && $bal['balance'] >= 0 && ledgerConsistent($f), $o + $bal);

    // 8. Phase 8 Task 2 — 8 simultaneous fulfilments of ONE plan invoice → one allocation.
    $g = Account::factory()->create();
    $inv = planOrder($g);
    race(array_fill(0, 8, function ($i) use ($inv) {
        note(app(InvoiceCreditService::class)->markPaidAndCreditQuota($inv->id, "pay_probe_{$i}") ? 'fulfilled' : 'noop');
    }));
    $o = outcomes();
    $check("[$round] 8 simultaneous fulfilments of one plan invoice", ($o['fulfilled'] ?? 0) === 1 && ($o['noop'] ?? 0) === 7 && svc()->balance($g)['balance'] === 300
        && CreditLedgerEntry::where('account_id', $g->id)->where('type', 'plan_allocation')->count() === 1 && UsageQuota::where('account_id', $g->id)->count() === 1 && ledgerConsistent($g), $o + svc()->balance($g));

    // 9. 8 simultaneous allocations (live retry + backfill style) of ONE period → one.
    $sub = Subscription::where('account_id', $g->id)->firstOrFail();
    race(array_fill(0, 8, function () use ($g, $sub, $inv) {
        $r = app(PlanCreditAllocator::class)->allocate(Account::findOrFail($g->id), Subscription::findOrFail($sub->id), Invoice::findOrFail($inv->id), 300, now(), now()->addDays(30), 'probe');
        note($r['replayed'] ? 'replayed' : 'applied');
    }));
    $o = outcomes();
    $check("[$round] 8 simultaneous re-allocations of one period", ($o['replayed'] ?? 0) === 8 && svc()->balance($g)['balance'] === 300 && UsageQuota::where('account_id', $g->id)->count() === 1, $o);

    // 9b. 8 simultaneous FIRST allocations of one not-yet-allocated period → exactly one applied.
    $m = Account::factory()->create();
    Plan::where('slug', 'growth')->update(['included_credits' => 0]);
    $min = planOrder($m); // bought no credits (captured 0) — the period exists, unallocated
    Plan::where('slug', 'growth')->update(['included_credits' => 300]);
    app(InvoiceCreditService::class)->markPaidAndCreditQuota($min->id, 'pay_m');
    $msub = Subscription::where('account_id', $m->id)->firstOrFail();
    race(array_fill(0, 8, function () use ($m, $msub, $min) {
        $r = app(PlanCreditAllocator::class)->allocate(Account::findOrFail($m->id), Subscription::findOrFail($msub->id), Invoice::findOrFail($min->id), 300, now(), now()->addDays(30), 'probe');
        note($r['replayed'] ? 'replayed' : 'applied');
    }));
    $o = outcomes();
    $check("[$round] 8 simultaneous first allocations of one period", ($o['applied'] ?? 0) === 1 && ($o['replayed'] ?? 0) === 7 && svc()->balance($m)['balance'] === 300 && UsageQuota::where('account_id', $m->id)->count() === 1 && ledgerConsistent($m), $o);

    // 10. Two different invoices (renewal racing an upgrade) of one account → two periods, both allocated.
    $h = Account::factory()->create();
    [$i1, $i2] = [planOrder($h), planOrder($h)];
    race([fn () => note(app(InvoiceCreditService::class)->markPaidAndCreditQuota($i1->id, 'pay_h1') ? 'fulfilled' : 'noop'), fn () => note(app(InvoiceCreditService::class)->markPaidAndCreditQuota($i2->id, 'pay_h2') ? 'fulfilled' : 'noop')]);
    $o = outcomes();
    $periods = UsageQuota::where('account_id', $h->id)->orderBy('period_starts_at')->get();
    $check("[$round] 2 simultaneous plan invoices of one account", ($o['fulfilled'] ?? 0) === 2 && svc()->balance($h)['balance'] === 600 && $periods->count() === 2
        && $periods[1]->period_starts_at->equalTo($periods[0]->period_ends_at) && ledgerConsistent($h), $o + svc()->balance($h));

    // 11. The backfill racing the live payment of the same invoice → one allocation.
    $k = Account::factory()->create();
    $kin = planOrder($k);
    app(InvoiceCreditService::class)->markPaidAndCreditQuota($kin->id, 'pay_k'); // live path already allocated
    race(array_fill(0, 4, function () use ($k) {
        Artisan::call('credits:backfill-plan-allocation', ['--account' => $k->id]);
        note('backfill');
    }));
    outcomes();
    $check("[$round] 4 simultaneous backfills after the live allocation", svc()->balance($k)['balance'] === 300 && CreditLedgerEntry::where('account_id', $k->id)->count() === 1, svc()->balance($k));

    // ---------------------------------------------------------------- Phase 8 Task 3 — spending

    // 12. 20 simultaneous DIRECT spends of 10 against 100 → exactly 10.
    $n = Account::factory()->create();
    svc()->grant($n, 100, 'probe:seed');
    race(array_fill(0, 20, function ($i) use ($n) {
        spending()->spend(Account::findOrFail($n->id), 10, "probe:spend:{$i}");
        note('ok');
    }));
    $o = outcomes();
    $bal = svc()->balance($n);
    $check("[$round] 20 simultaneous direct spends of 10 against 100", ($o['ok'] ?? 0) === 10 && ($o['refused:insufficient_credits'] ?? 0) === 10 && $bal === ['balance' => 0, 'reserved' => 0, 'available' => 0]
        && CreditLedgerEntry::where('account_id', $n->id)->where('type', 'consumption')->count() === 10 && ledgerConsistent($n), $o + $bal);

    // 13. ONE reservation of 100, 20 simultaneous partial consumes of 10 → exactly 10.
    $p = Account::factory()->create();
    svc()->grant($p, 150, 'probe:seed');
    $pr = spending()->reserve($p, 100, 'probe:partial:hold')->reservation;
    race(array_fill(0, 20, function ($i) use ($p, $pr) {
        spending()->consume(Account::findOrFail($p->id), $pr->id, 10, "probe:partial:c{$i}");
        note('ok');
    }));
    $o = outcomes();
    $bal = svc()->balance($p);
    $check("[$round] 20 simultaneous partial consumes of 10 on one reservation of 100", ($o['ok'] ?? 0) === 10 && ($o['refused:reservation_already_terminal'] ?? 0) === 10
        && $bal === ['balance' => 50, 'reserved' => 0, 'available' => 50] && $pr->fresh()->status === 'consumed' && $pr->fresh()->consumed_amount === 100 && ledgerConsistent($p), $o + $bal);

    // 14. Partial consumes racing a release of the same reservation → one terminal transition.
    $q = Account::factory()->create();
    svc()->grant($q, 100, 'probe:seed');
    $qr = spending()->reserve($q, 100, 'probe:cvr:hold')->reservation;
    $work = [];
    for ($i = 0; $i < 12; $i++) {
        $work[] = function () use ($q, $qr, $i) { spending()->consume(Account::findOrFail($q->id), $qr->id, 5, "probe:cvr:c{$i}"); note('consumed'); };
    }
    $work[] = function () use ($q, $qr) { spending()->release(Account::findOrFail($q->id), $qr->id); note('released'); };
    $work[] = function () use ($q, $qr) { spending()->release(Account::findOrFail($q->id), $qr->id); note('released'); };
    race($work);
    $o = outcomes();
    $bal = svc()->balance($q);
    $fresh = $qr->fresh();
    $taken = (int) ($fresh->consumed_amount ?? 0); // NULL when the release won before any consume
    $releases = CreditLedgerEntry::where('reservation_id', $qr->id)->where('type', 'reservation_release')->get();
    $check("[$round] partial consumes racing releases of one reservation", $fresh->status === 'released' && $releases->count() === 1
        && ($o['consumed'] ?? 0) * 5 === $taken && (int) $releases->first()->amount === 100 - $taken
        && ($o['consumed'] ?? 0) + ($o['refused:reservation_already_terminal'] ?? 0) === 12 && $bal === ['balance' => 100 - $taken, 'reserved' => 0, 'available' => 100 - $taken] && ledgerConsistent($q), $o + $bal);

    // 15. Duplicate requests (one key) → one financial effect: spend, partial consume, settle, release.
    $s = Account::factory()->create();
    svc()->grant($s, 300, 'probe:seed');
    race(array_fill(0, 12, function () use ($s) { $r = spending()->spend(Account::findOrFail($s->id), 20, 'probe:dup:spend'); note($r->replayed ? 'replayed' : 'applied'); }));
    $o1 = outcomes();
    $sr = spending()->reserve($s, 100, 'probe:dup:hold')->reservation;
    race(array_fill(0, 12, function () use ($s, $sr) { $r = spending()->consume(Account::findOrFail($s->id), $sr->id, 30, 'probe:dup:consume'); note($r->replayed ? 'replayed' : 'applied'); }));
    $o2 = outcomes();
    $st = spending()->reserve($s, 50, 'probe:dup:hold2')->reservation;
    race(array_merge(
        array_fill(0, 6, function () use ($s, $st) { $r = spending()->settle(Account::findOrFail($s->id), $st->id, 20); note($r->replayed ? 'replayed' : 'applied'); }),
    ));
    $o3 = outcomes();
    race(array_fill(0, 6, function () use ($s, $sr) { $r = spending()->release(Account::findOrFail($s->id), $sr->id); note($r->replayed ? 'replayed' : 'applied'); }));
    $o4 = outcomes();
    $bal = svc()->balance($s);
    $one = fn ($o, $n) => ($o['applied'] ?? 0) === 1 && ($o['replayed'] ?? 0) === $n - 1;
    $check("[$round] duplicate spend / consume / settle / release requests (one key each)", $one($o1, 12) && $one($o2, 12) && $one($o3, 6) && $one($o4, 6)
        && $bal === ['balance' => 230, 'reserved' => 0, 'available' => 230] && CreditLedgerEntry::where('account_id', $s->id)->where('type', 'consumption')->count() === 3 && ledgerConsistent($s), compact('o1', 'o2', 'o3', 'o4') + $bal);

    // 16. Mixed load incl. refunds and partial consumption on one account.
    $t = Account::factory()->create();
    svc()->grant($t, 1000, 'probe:seed');
    $refundable = [];
    for ($i = 0; $i < 6; $i++) {
        $refundable[] = spending()->spend($t, 10, "probe:mix3:pre{$i}")->entry->id;
    }
    $work = [];
    for ($i = 0; $i < 30; $i++) {
        $work[] = match ($i % 6) {
            0 => function ($k) use ($t) { svc()->grant($t, 9, "probe:mix3:g{$k}"); note('ok'); },
            1 => function ($k) use ($t) { $acc = Account::findOrFail($t->id); $r = spending()->reserve($acc, 60, "probe:mix3:r{$k}")->reservation; spending()->consume($acc, $r->id, 15, "probe:mix3:c{$k}a"); spending()->consume($acc, $r->id, 5, "probe:mix3:c{$k}b"); spending()->release($acc, $r->id, 40); note('ok'); },
            2 => function ($k) use ($t) { $acc = Account::findOrFail($t->id); $r = spending()->reserve($acc, 30, "probe:mix3:s{$k}")->reservation; spending()->settle($acc, $r->id, 12); note('ok'); },
            3 => function ($k) use ($t) { spending()->spend(Account::findOrFail($t->id), 7, "probe:mix3:d{$k}"); note('ok'); },
            4 => function ($k) use ($t, $refundable) { svc()->refund(Account::findOrFail($t->id), 2, "probe:mix3:f{$k}", $refundable[intdiv($k, 6)]); note('ok'); },
            5 => function ($k) use ($t) { svc()->adjust(Account::findOrFail($t->id), -3, "probe:mix3:a{$k}"); note('ok'); },
        };
    }
    race($work);
    $o = outcomes();
    $bal = svc()->balance($t);
    // 1000 − 60 + 5×(9 − 20 − 12 − 7 + 2 − 3)
    $check("[$round] 30 mixed grant/reserve/consume/release/settle/spend/refund/adjust", ($o['ok'] ?? 0) === 30 && $bal === ['balance' => 785, 'reserved' => 0, 'available' => 785] && ledgerConsistent($t), $o + $bal);

    // 17. Expiry cleanup (4 runs) racing the owners' settles → one terminal transition each.
    $u = Account::factory()->create();
    svc()->grant($u, 100, 'probe:seed');
    $expiring = [];
    for ($i = 0; $i < 6; $i++) {
        $expiring[] = spending()->reserve($u, 10, "probe:exp:{$i}", ['expires_at' => now()->addSeconds(1)])->reservation->id;
    }
    sleep(2);
    $work = array_fill(0, 4, function () { spending()->releaseExpired(); note('cleanup'); });
    foreach ($expiring as $id) {
        // Past expires_at the owner may still RELEASE (never consume).
        $work[] = function () use ($u, $id) { spending()->release(Account::findOrFail($u->id), $id); note('owner'); };
    }
    race($work);
    $o = outcomes();
    $bal = svc()->balance($u);
    $check("[$round] expiry cleanup racing owner releases", CreditReservation::whereIn('id', $expiring)->where('status', 'released')->count() === 6
        && CreditLedgerEntry::whereIn('reservation_id', $expiring)->where('type', 'reservation_release')->count() === 6 && ! array_filter(array_keys($o), fn ($k) => str_starts_with($k, 'error:') || str_starts_with($k, 'refused:'))
        && $bal === ['balance' => 100, 'reserved' => 0, 'available' => 100] && ledgerConsistent($u), $o + $bal);

    // 18. Spending racing a plan payment on the SAME account (payment tx locks invoice/subscription,
    //     then the credit account) and spends nested in callers' own transactions → no deadlock.
    $v = Account::factory()->create();
    $vin = planOrder($v);
    svc()->grant($v, 50, 'probe:seed');
    $work = [fn () => note(app(InvoiceCreditService::class)->markPaidAndCreditQuota($vin->id, 'pay_v') ? 'fulfilled' : 'noop')];
    for ($i = 0; $i < 10; $i++) {
        $work[] = function () use ($v, $i) {
            DB::transaction(function () use ($v, $i) {
                $acc = Account::query()->lockForUpdate()->findOrFail($v->id); // a caller lock taken BEFORE the credit lock
                spending()->spend($acc, 5, "probe:nest:{$i}");
            });
            note('spent');
        };
    }
    race($work);
    $o = outcomes();
    $bal = svc()->balance($v);
    $check("[$round] spends in caller transactions racing a plan payment", ($o['fulfilled'] ?? 0) === 1 && ($o['spent'] ?? 0) === 10
        && $bal['balance'] === 300 && ! array_filter(array_keys($o), fn ($k) => str_starts_with($k, 'error:')) && ledgerConsistent($v), $o + $bal);}

@unlink($OUT);
$failed = 0;
foreach ($results as [$name, $ok, $detail]) {
    echo ($ok ? 'PASS ' : 'FAIL ')."{$name} {$detail}\n";
    $failed += $ok ? 0 : 1;
}
echo DB::selectOne('SELECT VERSION() AS v')->v."\n";
exit($failed === 0 ? 0 : 1);
