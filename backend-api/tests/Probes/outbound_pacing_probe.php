<?php
// Phase 5 fix P5-9 — anti-ban pacing through a REAL database queue worker.
//
// DESTRUCTIVE TO ITS TARGET DATABASE (runs migrate:fresh). It refuses to run
// unless the connected database is named exactly `wa_throwaway_test`. Not
// part of PHPUnit.
//
// Usage (from backend-api/):
//   DB_CONNECTION=mariadb DB_HOST=127.0.0.1 DB_PORT=3306 DB_DATABASE=wa_throwaway_test \
//   DB_USERNAME=... DB_PASSWORD=... APP_ENV=testing QUEUE_CONNECTION=database \
//   php tests/Probes/outbound_pacing_probe.php
//
// Proves on real infrastructure (database queue, `queue:work`) that the
// spacing the removed sleep(random_int(3, 8)) gave is now honoured as a
// queue delay: consecutive group sends and consecutive CSV-style payment
// alerts are never closer than the pacing, while the worker is never
// blocked (its busy time stays far below the paced wall-clock time).
use App\Jobs\ProcessPaymentAlertJob;
use App\Models\{Account, ContactGroup, ContactGroupMember, Invoice, MessageDispatchLog, MessageTemplate, PaymentAlert, Subscription, WhatsAppSession};
use App\Services\Billing\InvoiceCreditService;
use App\Services\Groups\GroupMessageDispatcher;
use App\Support\PlanCatalog;
use Illuminate\Support\Facades\{Artisan, DB, Http};

require dirname(__DIR__, 2).'/vendor/autoload.php';
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
if (DB::connection()->getDatabaseName() !== 'wa_throwaway_test') { fwrite(STDERR, "refusing: not the throwaway DB\n"); exit(2); }
if (config('queue.default') !== 'database') { fwrite(STDERR, "refusing: QUEUE_CONNECTION must be database\n"); exit(2); }

const PACE = 2; // fixed pacing for a deterministic lower bound

config(['messaging.outbound_pacing.min_seconds' => PACE, 'messaging.outbound_pacing.max_seconds' => PACE]);
Artisan::call('migrate:fresh', ['--force' => true]);
Artisan::call('db:seed', ['--class' => 'RolePermissionSeeder', '--force' => true]);
Artisan::call('db:seed', ['--class' => 'Phase1FoundationSeeder', '--force' => true]);

$sends = [];
Http::fake(function ($request) use (&$sends) {
    $sends[] = [microtime(true), (string) $request['to']];

    return Http::response(['success' => true, 'message_id' => 'QR_'.uniqid()], 200);
});

$account = Account::factory()->create();
$plan = PlanCatalog::find('growth');
$invoice = Invoice::create(['account_id' => $account->id, 'invoice_number' => 'INV-'.uniqid(), 'plan_key' => 'growth', 'plan_label' => $plan['label'], 'amount' => $plan['price'], 'tax_amount' => 0, 'total_amount' => $plan['price'], 'currency' => 'INR', 'payment_gateway' => 'razorpay', 'gateway_order_id' => 'o_'.uniqid(), 'status' => 'pending']);
app(InvoiceCreditService::class)->markPaidAndCreditQuota($invoice->id, 'pay_'.uniqid());
WhatsAppSession::create(['account_id' => $account->id, 'status' => 'connected']);

$results = [];
$check = function (string $name, bool $ok, string $detail = '') use (&$results) {
    $results[] = $ok;
    echo ($ok ? 'PASS ' : 'FAIL ')."{$name}".($detail !== '' ? " — {$detail}" : '')."\n";
};

/** Runs one worker until nothing is left (delayed jobs included); returns seconds spent inside queue:work. */
$drain = function (int $timeout = 60): float {
    $busy = 0.0;
    $deadline = microtime(true) + $timeout;
    while (microtime(true) < $deadline && DB::table('jobs')->exists()) {
        $t = microtime(true);
        Artisan::call('queue:work', ['connection' => 'database', '--stop-when-empty' => true, '--sleep' => 0, '--tries' => 1]);
        $busy += microtime(true) - $t;
        usleep(100_000);
    }

    return $busy;
};

$gaps = fn (array $s) => array_map(fn ($i) => $s[$i][0] - $s[$i - 1][0], range(1, count($s) - 1));

// ---------------------------------------------------------------- group batch
$group = ContactGroup::create(['account_id' => $account->id, 'name' => 'Segment', 'group_type' => ContactGroup::GROUP_TYPE_INTERNAL]);
foreach (range(1, 4) as $i) {
    ContactGroupMember::create(['group_id' => $group->id, 'phone_number' => sprintf('9190000000%02d', $i), 'name' => "M{$i}"]);
}
$template = MessageTemplate::create(['account_id' => $account->id, 'template_code' => 'TPL_P59', 'title' => 'Blast', 'template_body' => 'Hello {{name}}.', 'status' => 'approved']);
$usedBefore = (int) Subscription::where('account_id', $account->id)->value('used_messages');
$result = GroupMessageDispatcher::dispatch($account->id, $group->id, $template->id, []);

$start = microtime(true);
$busy = $drain();
$wall = microtime(true) - $start;
$groupSends = $sends;
$g = $gaps($groupSends);
$log = MessageDispatchLog::find($result['dispatch_id']);
$check('group: every member sent once, in order', array_map(fn ($s) => explode('@', $s[1])[0], $groupSends) === ['919000000001', '919000000002', '919000000003', '919000000004']);
$check('group: consecutive sends never closer than the pacing', min($g) >= PACE - 0.05, 'gaps '.implode(', ', array_map(fn ($x) => sprintf('%.2f', $x), $g)).' s');
$check('group: batch settled sent 4/0, quota 4', $log->status === 'sent' && (int) $log->success_count === 4 && (int) Subscription::where('account_id', $account->id)->value('used_messages') - $usedBefore === 4);
$check('group: the worker was not blocked by the pacing', $wall >= 3 * PACE && $busy < $wall, sprintf('wall %.2f s, worker busy %.2f s (with sleep() the worker would be busy for the whole wall time)', $wall, $busy));

// ---------------------------------------------------------------- payment alerts, CSV-style spacing
$sends = [];
$offset = 0;
foreach (range(1, 3) as $i) {
    $alert = PaymentAlert::create(['account_id' => $account->id, 'recipient_phone' => '98765432'.sprintf('%02d', $i), 'customer_name' => "C{$i}", 'amount' => 10, 'payment_ref' => "P59-{$i}", 'status' => 'queued']);
    $offset += \App\Support\OutboundPacing::delaySeconds();
    ProcessPaymentAlertJob::dispatchPaced($alert->id, afterSeconds: $offset);
}
$availableAt = DB::table('jobs')->orderBy('id')->pluck('available_at')->map(fn ($t) => (int) $t)->all();
$drain();
$a = $gaps($sends);
$check('alerts: all three sent', PaymentAlert::where('account_id', $account->id)->where('status', 'sent')->count() === 3 && count($sends) === 3);
$check('alerts: queued exactly one pacing apart', array_map(fn ($i) => $availableAt[$i] - $availableAt[$i - 1], [1, 2]) === [PACE, PACE], 'available_at '.implode(', ', $availableAt));
// Actual send gaps also include the worker's own pick-up latency (its 100 ms poll here), in both directions.
$check('alerts: consecutive sends one pacing apart (± worker poll latency)', min($a) >= PACE - 0.15, 'gaps '.implode(', ', array_map(fn ($x) => sprintf('%.2f', $x), $a)).' s');
$check('no failed job', DB::table('failed_jobs')->count() === 0);

$passed = count(array_filter($results));
echo "\n{$passed}/".count($results)." checks passed\n";
exit($passed === count($results) ? 0 : 1);
