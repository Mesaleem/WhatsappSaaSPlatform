<?php

// Phase 5 fix P5-5 — payment fulfillment concurrency probe against REAL MariaDB.
//
// DESTRUCTIVE TO ITS TARGET DATABASE (runs migrate:fresh). It refuses to run
// unless the connected database's name starts with `wa_throwaway_`. Never
// point it at wa_saas_platform or any shared database. Not part of PHPUnit.
//
// Usage (from backend-api/):
//   DB_CONNECTION=mariadb DB_HOST=127.0.0.1 DB_PORT=3306 DB_DATABASE=wa_throwaway_test \
//   DB_USERNAME=... DB_PASSWORD=... APP_ENV=testing [PROBE_ROUNDS=3] php tests/Probes/payment_fulfillment_concurrency_probe.php
// PaymentFulfillmentExactlyOnceTest runs it (1 round) against `wa_throwaway_probe` when the suite
// itself runs on MariaDB.
// Exit code 0 = every check passed. Needs the pcntl extension.
//
// Each check forks N OS processes, each with its OWN database connection,
// released together, that call the REAL routes through the HTTP kernel
// (POST /api/webhooks/razorpay with a valid signature, POST
// /api/billing/verify-payment with a Sanctum token and a valid Razorpay
// signature). PHPUnit cannot do this: RefreshDatabase wraps a test in one
// transaction no other process can see.
use App\Models\Account;
use App\Models\AccountEntitlement;
use App\Models\AgentCommission;
use App\Models\AgentCommissionRule;
use App\Models\Invoice;
use App\Models\PaymentGatewaySetting;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use App\Services\Messaging\MessageQuotaService;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

require dirname(__DIR__, 2).'/vendor/autoload.php';
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$kernel = $app->make(Kernel::class);
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
if (! str_starts_with((string) DB::connection()->getDatabaseName(), 'wa_throwaway_') || ! in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
    fwrite(STDERR, "refusing: not a wa_throwaway_* MariaDB database\n");
    exit(2);
}
$ROUNDS = max(1, (int) (getenv('PROBE_ROUNDS') ?: 3));

const KEY_SECRET = 'rzp_probe_secret';
const WEBHOOK_SECRET = 'rzp_probe_webhook';

Artisan::call('migrate:fresh', ['--force' => true]);
Artisan::call('db:seed', ['--class' => 'RolePermissionSeeder', '--force' => true]);
Artisan::call('db:seed', ['--class' => 'Phase1FoundationSeeder', '--force' => true]);
PaymentGatewaySetting::create(['gateway' => 'razorpay', 'mode' => 'test', 'is_enabled' => true, 'test_key_id' => 'rzp_probe_key', 'test_key_secret' => KEY_SECRET, 'test_webhook_secret' => WEBHOOK_SECRET]);

function customer(?Account $agent = null): array
{
    $account = $agent ? Account::factory()->client($agent)->create() : Account::factory()->create();
    $user = User::factory()->create(['account_id' => $account->id, 'is_active' => true]);
    $user->assignRole('admin');

    return [$account, $user, $user->createToken('probe')->plainTextToken];
}
function invoiceFor(Account $account, string $plan = 'growth'): Invoice
{
    $p = Plan::where('slug', $plan)->firstOrFail();
    // As createOrder() builds it: the P5-4 terms captured from the plan row.
    $invoice = new Invoice([
        'account_id' => $account->id, 'invoice_number' => 'INV-P55-'.uniqid(), 'plan_key' => $plan, 'plan_label' => $p->label,
        'amount' => $p->price, 'tax_amount' => 0, 'total_amount' => $p->price, 'currency' => 'INR', 'payment_gateway' => 'razorpay',
        'gateway_order_id' => 'order_'.uniqid(), 'status' => 'pending',
    ]);
    $invoice->capturePlanTerms($p);
    $invoice->save();

    return $invoice;
}
function webhook(Invoice $invoice, string $paymentId): int
{
    global $kernel;
    $body = json_encode(['event' => 'payment.captured', 'payload' => ['payment' => ['entity' => ['id' => $paymentId, 'order_id' => $invoice->gateway_order_id, 'status' => 'captured']]]]);
    $r = Request::create('/api/webhooks/razorpay', 'POST', [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json', 'HTTP_X_RAZORPAY_SIGNATURE' => hash_hmac('sha256', $body, WEBHOOK_SECRET)], $body);

    return $kernel->handle($r)->getStatusCode();
}
function verify(Invoice $invoice, string $token, string $paymentId): int
{
    global $kernel;
    $body = json_encode(['invoice_id' => $invoice->id, 'razorpay_order_id' => $invoice->gateway_order_id, 'razorpay_payment_id' => $paymentId, 'razorpay_signature' => hash_hmac('sha256', "{$invoice->gateway_order_id}|{$paymentId}", KEY_SECRET)]);
    $r = Request::create('/api/billing/verify-payment', 'POST', [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json', 'HTTP_AUTHORIZATION' => "Bearer {$token}"], $body);

    return $kernel->handle($r)->getStatusCode();
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
            } catch (Throwable $e) {
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
function state(Account $account, Invoice $invoice): array
{
    $subs = Subscription::where('account_id', $account->id)->get();

    return [
        'invoice' => $invoice->fresh()->status,
        'subscriptions' => $subs->count(),
        'allocated' => (int) $subs->sum('total_allocated_messages'),
        'expires_at' => (string) $subs->max('expires_at'),
        'price_paid' => (float) $subs->sum('price_paid'),
        'commissions' => AgentCommission::where('invoice_id', $invoice->id)->count(),
        'entitlements' => AccountEntitlement::where('account_id', $account->id)->count(),
    ];
}

$results = [];
$check = function (string $name, bool $ok, $detail) use (&$results) {
    $results[] = [$name, $ok, json_encode($detail)];
};
$growth = Plan::where('slug', 'growth')->firstOrFail();
$expect = fn (int $invoices = 1) => ['subscriptions' => 1, 'allocated' => $invoices * (int) $growth->total_allocated_messages];

$agent = Account::factory()->agent()->create();
AgentCommissionRule::create(['agent_account_id' => $agent->id, 'type' => 'percentage', 'value' => 10]);

$cases = [
    'webhook + webhook' => fn ($inv, $tok) => [fn () => webhook($inv, 'pay_a'), fn () => webhook($inv, 'pay_a')],
    'webhook + verify-payment' => fn ($inv, $tok) => [fn () => webhook($inv, 'pay_b'), fn () => verify($inv, $tok, 'pay_b')],
    'verify-payment + verify-payment' => fn ($inv, $tok) => [fn () => verify($inv, $tok, 'pay_c'), fn () => verify($inv, $tok, 'pay_c')],
    '8 mixed callbacks' => fn ($inv, $tok) => array_map(fn ($i) => $i % 2 ? fn () => webhook($inv, 'pay_d') : fn () => verify($inv, $tok, 'pay_d'), range(0, 7)),
];

foreach ($cases as $name => $make) {
    foreach (range(1, $ROUNDS) as $round) {
        [$account, $user, $token] = customer($agent);
        $invoice = invoiceFor($account);
        race($make($invoice, $token));
        $s = state($account, $invoice);
        $ok = $s['invoice'] === 'paid' && $s['subscriptions'] === 1 && $s['allocated'] === $expect()['allocated']
            && $s['commissions'] === 1 && abs($s['price_paid'] - (float) $invoice->total_amount) < 0.001;
        $check("same invoice, {$name} (round {$round})", $ok, $s);
    }
}

// Two DIFFERENT invoices of one account paid at the same moment (no subscription yet).
foreach (range(1, $ROUNDS) as $round) {
    [$account, $user, $token] = customer();
    $a = invoiceFor($account);
    $b = invoiceFor($account);
    race([fn () => webhook($a, 'pay_x'), fn () => webhook($b, 'pay_y')]);
    $subs = Subscription::where('account_id', $account->id)->get();
    $current = $account->fresh()->currentSubscription;
    $ok = $a->fresh()->isPaid() && $b->fresh()->isPaid() && $subs->count() === 1 && (int) $current->total_allocated_messages === 2 * (int) $growth->total_allocated_messages;
    $check("two invoices of one account at once (round {$round})", $ok, ['invoices' => [$a->fresh()->status, $b->fresh()->status], 'subscriptions' => $subs->map->only(['id', 'total_allocated_messages', 'price_paid', 'expires_at'])->all()]);
}

// A renewal paid while the tenant is sending: fulfilment (account → subscription
// locks) racing MessageQuotaService::consume() (subscription lock) — no
// deadlock, no lost credit, no lost consumption.
foreach (range(1, $ROUNDS) as $round) {
    [$account, $user, $token] = customer();
    $first = invoiceFor($account);
    webhook($first, 'pay_first');
    $renewal = invoiceFor($account);
    $work = [fn () => webhook($renewal, 'pay_renew')];
    foreach (range(1, 6) as $i) {
        $work[] = fn () => app(MessageQuotaService::class)->consume(Subscription::where('account_id', $account->id)->first(), 1);
    }
    race($work);
    $sub = Subscription::where('account_id', $account->id)->sole();
    $ok = $renewal->fresh()->isPaid() && (int) $sub->total_allocated_messages === 2 * (int) $growth->total_allocated_messages && (int) $sub->used_messages === 6;
    $check("renewal fulfilment racing quota consumption (round {$round})", $ok, ['renewal' => $renewal->fresh()->status, 'allocated' => $sub->total_allocated_messages, 'used' => $sub->used_messages]);
}

$ok = true;
foreach ($results as [$name, $pass, $detail]) {
    $ok = $ok && $pass;
    echo ($pass ? 'PASS ' : 'FAIL ').$name.($pass ? '' : "  $detail")."\n";
}
echo 'MariaDB '.DB::selectOne('select version() v')->v."\n";
exit($ok ? 0 : 1);
