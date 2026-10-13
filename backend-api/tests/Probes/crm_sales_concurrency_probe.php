<?php
// CRM Sales Phases 1–3 — documents, payments and conversion under REAL concurrency (MySQL / MariaDB).
//
// DESTRUCTIVE TO ITS TARGET DATABASE (runs migrate:fresh). Refuses to run unless the database is named
// exactly `wa_throwaway_crm_sales`. Not PHPUnit (RefreshDatabase's transaction is invisible to other processes).
//
// Usage (from backend-api/):
//   DB_CONNECTION=mysql DB_DATABASE=wa_throwaway_crm_sales php tests/Probes/crm_sales_concurrency_probe.php
// Works without pcntl (Windows too): workers are separate `php` processes started with proc_open, each with
// its own DB connection, all released at the same moment. Exit 0 = every check passed.
//
// Checks: concurrent payments never pay an invoice beyond its total; an idempotency key and a gateway payment
// id record ONE payment however many processes race; concurrent issues never share a number; concurrent
// conversions of one lead make ONE deal.
use App\Models\{Account, Contact, CrmDeal, CrmDocument, CrmLead, CrmPayment};
use App\Services\Crm\CrmLeadService;
use App\Services\Crm\Sales\{CrmDocumentService, CrmPaymentService};
use Illuminate\Support\Facades\{Artisan, DB};
use Illuminate\Validation\ValidationException;

require dirname(__DIR__, 2).'/vendor/autoload.php';
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
if (DB::connection()->getDatabaseName() !== 'wa_throwaway_crm_sales') { fwrite(STDERR, "refusing: not the throwaway DB\n"); exit(2); }

// ------------------------------------------------------------------ worker mode
if (($argv[1] ?? null) === 'worker') {
    [, , $scenario, $i, $a, $b, $go] = $argv + [null, null, null, null, null, null, null];
    while (microtime(true) < (float) $go) {
        usleep(500);
    }
    try {
        $account = Account::query()->findOrFail((int) $a);
        $r = match ($scenario) {
            'pay_full' => app(CrmPaymentService::class)->record($account, ['amount' => '100.00', 'method' => 'cash', 'allocations' => [['crm_document_id' => (int) $b, 'amount' => '100.00']]], null) ? 'ok' : 'x',
            'pay_30' => app(CrmPaymentService::class)->record($account, ['amount' => '30.00', 'method' => 'cash', 'allocations' => [['crm_document_id' => (int) $b, 'amount' => '30.00']]], null) ? 'ok' : 'x',
            'same_key' => 'ok:'.app(CrmPaymentService::class)->record($account, ['amount' => '10.00', 'method' => 'cash', 'idempotency_key' => 'probe-key', 'allocations' => [['crm_document_id' => (int) $b, 'amount' => '10.00']]], null)->id,
            'same_gateway' => 'ok:'.app(CrmPaymentService::class)->record($account, ['amount' => '10.00', 'method' => 'gateway', 'source' => 'gateway', 'gateway' => 'razorpay', 'gateway_payment_id' => 'pay_probe', 'allocations' => []], null)->id,
            'issue' => app(CrmDocumentService::class)->issue(CrmDocument::query()->findOrFail((int) $b + (int) $i), null)->number,
            'convert' => 'ok:'.(app(CrmLeadService::class)->convert(CrmLead::query()->findOrFail((int) $b), ['create_deal' => true], null)['deal']?->id ?? 'none'),
        };
    } catch (ValidationException $e) {
        $r = 'invalid:'.implode(',', array_keys($e->errors()));
    } catch (Throwable $e) {
        $r = 'EXC:'.class_basename($e).':'.mb_substr($e->getMessage(), 0, 120);
    }
    echo $r;
    exit(0);
}

// ------------------------------------------------------------------ orchestrator
Artisan::call('migrate:fresh', ['--force' => true]);
Artisan::call('db:seed', ['--class' => Database\Seeders\RolePermissionSeeder::class, '--force' => true]);

/** @return list<string> each worker's printed result */
function race(string $scenario, int $n, int $a, int $b): array {
    $go = microtime(true) + 1.5;
    $procs = [];
    for ($i = 0; $i < $n; $i++) {
        $cmd = [PHP_BINARY, __FILE__, 'worker', $scenario, (string) $i, (string) $a, (string) $b, sprintf('%.4F', $go)];
        $procs[] = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, null);
        $pipesList[] = $pipes;
    }
    $out = [];
    foreach ($procs as $k => $p) {
        $out[] = trim(stream_get_contents($pipesList[$k][1]));
        proc_close($p);
    }

    return $out;
}

function invoice(Account $account, string $amount): CrmDocument {
    $contact = Contact::create(['account_id' => $account->id, 'phone_number' => '91'.random_int(7000000000, 7999999999), 'name' => 'Probe']);
    $svc = app(CrmDocumentService::class);
    $doc = $svc->create($account, 'invoice', ['contact_id' => $contact->id, 'items' => [['description' => 'Probe', 'quantity' => '1', 'unit_price' => $amount]]], null);

    return $svc->issue($doc, null);
}

$results = [];
$check = function (string $name, bool $ok, string $detail = '') use (&$results) { $results[] = $ok; echo ($ok ? 'PASS ' : 'FAIL ')."{$name}".($detail !== '' ? " — {$detail}" : '')."\n"; };
$count = fn (array $r, string $v) => count(array_filter($r, fn ($x) => $x === $v));

foreach ([1, 2] as $round) {
    $account = Account::factory()->create();

    // (a) 8 processes each pay the FULL 100.00: exactly one is applied, the invoice is paid once.
    $inv = invoice($account, '100');
    $r = race('pay_full', 8, $account->id, $inv->id);
    $paid = (string) DB::table('crm_payment_allocations')->where('crm_document_id', $inv->id)->whereNull('reversed_at')->sum('amount');
    $check("[{$round}] 8 full payments at once: exactly one applied", $count($r, 'ok') === 1 && $count($r, 'invalid:allocations') === 7 && (float) $paid === 100.0 && $inv->fresh()->status === 'paid' && (string) $inv->fresh()->balance_due === '0.00', json_encode(array_count_values($r)));

    // (b) 10 × 30.00 on 100.00: three fit, balance 10.00, never negative.
    $inv = invoice($account, '100');
    $r = race('pay_30', 10, $account->id, $inv->id);
    $fresh = $inv->fresh();
    $check("[{$round}] 10 × 30.00 on 100.00: three fit, balance 10.00", $count($r, 'ok') === 3 && (string) $fresh->balance_due === '10.00' && (string) $fresh->amount_paid === '90.00' && $fresh->status === 'partially_paid', json_encode(array_count_values($r)));

    // (c) the same idempotency key from 8 processes: one payment, everyone gets it.
    DB::table('crm_payments')->where('idempotency_key', 'probe-key')->update(['idempotency_key' => null]);
    $inv = invoice($account, '100');
    $r = race('same_key', 8, $account->id, $inv->id);
    $ids = array_unique(array_filter(array_map(fn ($x) => str_starts_with($x, 'ok:') ? substr($x, 3) : null, $r)));
    $check("[{$round}] same idempotency key ×8: one payment", count($ids) === 1 && $count($r, 'ok:'.reset($ids)) === 8 && CrmPayment::where('idempotency_key', 'probe-key')->count() === 1 && (string) $inv->fresh()->balance_due === '90.00', json_encode(array_count_values($r)));

    // (d) the same gateway payment id ×6 (callback + webhook racing): one payment.
    DB::table('crm_payments')->where('gateway_payment_id', 'pay_probe')->update(['gateway_payment_id' => null]);
    $r = race('same_gateway', 6, $account->id, 0);
    $ids = array_unique(array_filter(array_map(fn ($x) => str_starts_with($x, 'ok:') ? substr($x, 3) : null, $r)));
    $check("[{$round}] same gateway payment id ×6: one payment", count($ids) === 1 && CrmPayment::where('gateway_payment_id', 'pay_probe')->count() === 1, json_encode(array_count_values($r)));

    // (e) 8 drafts issued at once: 8 distinct, consecutive numbers.
    $contact = Contact::create(['account_id' => $account->id, 'phone_number' => '91'.random_int(7000000000, 7999999999), 'name' => 'Probe']);
    $first = null;
    for ($k = 0; $k < 8; $k++) {
        $d = app(CrmDocumentService::class)->create($account, 'quotation', ['contact_id' => $contact->id, 'items' => [['description' => 'Q', 'quantity' => '1', 'unit_price' => '1']]], null);
        $first ??= $d->id;
    }
    $r = race('issue', 8, $account->id, $first);
    $numbers = array_filter($r, fn ($x) => str_starts_with($x, 'QUO/'));
    $check("[{$round}] 8 issues at once: 8 distinct numbers", count($numbers) === 8 && count(array_unique($numbers)) === 8, json_encode($r));

    // (f) 6 processes convert the same lead with a deal: one deal.
    $lead = app(CrmLeadService::class)->create($account, ['phone_number' => '91'.random_int(7000000000, 7999999999)]);
    $r = race('convert', 6, $account->id, $lead->id);
    $deals = array_unique($r);
    $check("[{$round}] 6 conversions at once: one deal", CrmDeal::where('crm_lead_id', $lead->id)->count() === 1 && count($deals) === 1 && str_starts_with((string) reset($deals), 'ok:'), json_encode(array_count_values($r)));
}

$failed = count(array_filter($results, fn ($ok) => ! $ok));
echo $failed === 0 ? "\nALL ".count($results)." CHECKS PASSED\n" : "\n{$failed} of ".count($results)." CHECKS FAILED\n";
exit($failed === 0 ? 0 : 1);
