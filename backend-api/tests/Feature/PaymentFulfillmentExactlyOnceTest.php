<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\AccountEntitlement;
use App\Models\AgentCommission;
use App\Models\AgentCommissionRule;
use App\Models\Capability;
use App\Models\Invoice;
use App\Models\PaymentGatewaySetting;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use App\Services\Access\PlanEntitlementReconciliationService;
use App\Services\Access\ProviderCapabilityService;
use Database\Seeders\Phase1FoundationSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Symfony\Component\Process\Process;
use Tests\TestCase;

/**
 * Phase 5 fix P5-5 — one successful payment → exactly one fulfilment:
 * one paid invoice, one subscription effect, one quota credit, one
 * entitlement reconciliation, one Agent commission — through the REAL
 * routes (POST /api/webhooks/razorpay with a valid signature, POST
 * /api/billing/verify-payment with a valid Razorpay signature) and the
 * real InvoiceCreditService.
 *
 * Sequential duplicates are tested here. Real concurrency cannot be
 * tested inside PHPUnit (RefreshDatabase wraps each test in one
 * transaction no other process can see), so
 * test_concurrent_callbacks_fulfil_exactly_once_on_real_mariadb runs
 * tests/Probes/payment_fulfillment_concurrency_probe.php — forked OS
 * processes, own connections, real routes — against the throwaway
 * database `wa_throwaway_probe` whenever this suite runs on MariaDB.
 */
class PaymentFulfillmentExactlyOnceTest extends TestCase
{
    use RefreshDatabase;

    private const KEY_SECRET = 'rzp_test_secret_p55';

    private const WEBHOOK_SECRET = 'rzp_webhook_secret_p55';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        $this->seed(Phase1FoundationSeeder::class);

        PaymentGatewaySetting::create([
            'gateway' => 'razorpay', 'mode' => 'test', 'is_enabled' => true,
            'test_key_id' => 'rzp_test_key_p55', 'test_key_secret' => self::KEY_SECRET, 'test_webhook_secret' => self::WEBHOOK_SECRET,
        ]);

        Http::fake(['api.razorpay.com/v1/orders' => fn () => Http::response(['id' => 'order_'.uniqid(), 'status' => 'created'], 200)]);
    }

    // ------------------------------------------------------------------ fixtures

    /** @return array{account: Account, user: User, agent: Account} an Agent's customer with a 10% commission rule */
    private function agentCustomer(): array
    {
        $agent = Account::factory()->agent()->create();
        AgentCommissionRule::create(['agent_account_id' => $agent->id, 'type' => 'percentage', 'value' => 10]);
        $account = Account::factory()->client($agent)->create();

        return ['account' => $account, 'user' => $this->admin($account), 'agent' => $agent];
    }

    private function admin(Account $account): User
    {
        $user = User::factory()->create(['account_id' => $account->id, 'is_active' => true]);
        $user->assignRole('admin');

        return $user;
    }

    private function order(User $user, string $plan = 'growth', array $extra = []): Invoice
    {
        $response = $this->actingAs($user)->postJson('/api/billing/create-order', ['plan_key' => $plan, 'gateway' => 'razorpay'] + $extra)->assertCreated();

        return Invoice::findOrFail($response->json('invoice_id'));
    }

    private function webhook(Invoice $invoice, string $paymentId = 'pay_p55', string $event = 'payment.captured', array $extraEntity = [], array $extraPayload = [])
    {
        $body = json_encode([
            'event' => $event,
            'payload' => ['payment' => ['entity' => ['id' => $paymentId, 'order_id' => $invoice->gateway_order_id, 'status' => $event === 'payment.failed' ? 'failed' : 'captured'] + $extraEntity]],
        ] + $extraPayload);

        return $this->call('POST', '/api/webhooks/razorpay', [], [], [], [
            'CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json',
            'HTTP_X_RAZORPAY_SIGNATURE' => hash_hmac('sha256', $body, self::WEBHOOK_SECRET),
        ], $body);
    }

    private function verify(User $user, Invoice $invoice, string $paymentId = 'pay_p55', array $extra = [], ?string $signature = null)
    {
        return $this->actingAs($user)->postJson('/api/billing/verify-payment', array_replace([
            'invoice_id' => $invoice->id,
            'razorpay_order_id' => $invoice->gateway_order_id,
            'razorpay_payment_id' => $paymentId,
            'razorpay_signature' => $signature ?? hash_hmac('sha256', "{$invoice->gateway_order_id}|{$paymentId}", self::KEY_SECRET),
        ], $extra));
    }

    /** Count real reconciliation runs, running the real reconciler each time. */
    private function countReconciliations(): \stdClass
    {
        $counter = new \stdClass;
        $counter->runs = 0;
        $real = new PlanEntitlementReconciliationService(app(ProviderCapabilityService::class));
        $spy = \Mockery::mock(PlanEntitlementReconciliationService::class);
        $spy->shouldReceive('reconcile')->andReturnUsing(function (...$args) use ($counter, $real) {
            $counter->runs++;

            return $real->reconcile(...$args);
        });
        $this->app->instance(PlanEntitlementReconciliationService::class, $spy);

        return $counter;
    }

    /** @return array<string, mixed> the whole fulfilment footprint of one account */
    private function footprint(Account $account): array
    {
        $subs = Subscription::where('account_id', $account->id)->orderBy('id')->get();

        return [
            'subscriptions' => $subs->count(),
            'allocated' => $subs->sum('total_allocated_messages'),
            'price_paid' => (string) $subs->sum('price_paid'),
            'expires_at' => (string) $subs->max('expires_at'),
            'invoices' => Invoice::where('account_id', $account->id)->orderBy('id')->get(['status', 'gateway_payment_id', 'paid_at'])->map(fn ($i) => [$i->status, $i->gateway_payment_id, (string) $i->paid_at])->all(),
            'commissions' => AgentCommission::whereIn('invoice_id', Invoice::where('account_id', $account->id)->pluck('id'))->get(['invoice_id', 'amount', 'status'])->toArray(),
            'entitlements' => AccountEntitlement::where('account_id', $account->id)->orderBy('capability_id')->get(['capability_id', 'source', 'revoked_at', 'revoked_reason'])->map(fn ($e) => $e->only(['capability_id', 'source', 'revoked_reason']) + ['revoked' => $e->revoked_at !== null])->all(),
        ];
    }

    // ==================================================================
    // 1–4, 8–12. Duplicate callbacks, every order of the two paths
    // ==================================================================

    public static function duplicateSequences(): array
    {
        return [
            '1 webhook twice' => [['webhook', 'webhook']],
            '2 verify-payment twice' => [['verify', 'verify']],
            '3 webhook then verify-payment' => [['webhook', 'verify']],
            '4 verify-payment then webhook' => [['verify', 'webhook']],
            'webhook, webhook, verify-payment' => [['webhook', 'webhook', 'verify']],
        ];
    }

    #[DataProvider('duplicateSequences')]
    public function test_duplicate_callbacks_fulfil_exactly_once(array $sequence): void
    {
        ['account' => $account, 'user' => $user, 'agent' => $agent] = $this->agentCustomer();
        $invoice = $this->order($user);
        $plan = Plan::where('slug', 'growth')->firstOrFail();
        $reconciliations = $this->countReconciliations();

        $call = fn (string $path) => $path === 'webhook' ? $this->webhook($invoice)->assertOk() : $this->verify($user, $invoice)->assertOk();
        $first = $call(array_shift($sequence));
        $this->assertSame('Payment verified — quota credited.', $first->json('message') ?? 'Payment verified — quota credited.');
        $after = $this->footprint($account);

        $this->travel(3)->days(); // a duplicate much later must still not extend anything
        foreach ($sequence as $path) {
            $response = $call($path);
            if ($path === 'verify') {
                // Existing contract: the duplicate returns the paid invoice and the current subscription.
                $this->assertContains($response->json('message'), ['This invoice was already confirmed.', 'Payment already processed.']);
                $this->assertSame('paid', $response->json('invoice.status'));
                $this->assertSame(Subscription::where('account_id', $account->id)->value('id'), $response->json('subscription.id'));
            } else {
                $response->assertExactJson(['received' => true]);
            }
        }

        $this->assertSame($after, $this->footprint($account), 'nothing changed after the first fulfilment');
        $this->assertSame(1, $after['subscriptions'], '9: one subscription');
        $this->assertSame((int) $plan->total_allocated_messages, (int) $after['allocated'], '8: one quota credit');
        $this->assertSame(now()->subDays(3)->addDays((int) $plan->duration_days)->toDateString(), substr($after['expires_at'], 0, 10), '12: extended once');
        $this->assertSame([['paid', 'pay_p55']], array_map(fn ($i) => array_slice($i, 0, 2), $after['invoices']));
        $this->assertCount(1, $after['commissions'], '11: one commission');
        $this->assertEquals(round((float) $invoice->total_amount * 0.10, 2), (float) $after['commissions'][0]['amount']);
        $this->assertSame(1, $reconciliations->runs, '10: reconciled once, not again on a duplicate');
        $this->assertNotEmpty($after['entitlements']);
        $this->assertSame(0, AgentCommission::whereIn('invoice_id', Invoice::where('account_id', $agent->id)->pluck('id'))->count());
    }

    public function test_duplicates_leave_manual_grants_and_manual_revocations_alone(): void
    {
        ['account' => $account, 'user' => $user] = $this->agentCustomer();
        $invoice = $this->order($user);
        $this->webhook($invoice)->assertOk();

        $planGranted = AccountEntitlement::where('account_id', $account->id)->where('source', 'plan')->firstOrFail();
        $planGranted->forceFill(['revoked_at' => now(), 'revoked_reason' => AccountEntitlement::REVOKED_MANUAL])->save();
        $extra = Capability::whereNotIn('id', AccountEntitlement::where('account_id', $account->id)->pluck('capability_id'))->firstOrFail();
        AccountEntitlement::create(['account_id' => $account->id, 'capability_id' => $extra->id, 'source' => 'manual_grant']);
        $before = $this->footprint($account);

        $this->webhook($invoice)->assertOk();
        $this->verify($user, $invoice)->assertOk();

        $this->assertSame($before, $this->footprint($account));
        $this->assertNotNull($planGranted->fresh()->revoked_at, 'a manual revocation stays revoked');
    }

    public function test_two_separate_purchases_of_one_account_stack_on_one_subscription(): void
    {
        ['account' => $account, 'user' => $user] = $this->agentCustomer();
        $first = $this->order($user);
        $second = $this->order($user);
        $plan = Plan::where('slug', 'growth')->firstOrFail();

        $this->webhook($first, 'pay_1')->assertOk();
        $this->verify($user, $second, 'pay_2')->assertOk();
        $this->webhook($second, 'pay_2')->assertOk();

        $f = $this->footprint($account);
        $this->assertSame(1, $f['subscriptions']);
        $this->assertSame(2 * (int) $plan->total_allocated_messages, (int) $f['allocated']);
        $this->assertSame(now()->addDays(2 * (int) $plan->duration_days)->toDateString(), substr($f['expires_at'], 0, 10));
        $this->assertCount(2, $f['commissions'], 'one per paid invoice');
    }

    // ==================================================================
    // 5–7. Real concurrency (MariaDB, separate OS processes)
    // ==================================================================

    public function test_concurrent_callbacks_fulfil_exactly_once_on_real_mariadb(): void
    {
        if (! in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
            $this->markTestSkipped('Real row-lock concurrency needs MariaDB; SQLite serializes all writers. Run the suite on MariaDB (authoritative) or the probe directly.');
        }
        if (! function_exists('pcntl_fork')) {
            $this->markTestSkipped('The concurrency probe needs the pcntl extension.');
        }

        $current = (string) DB::connection()->getDatabaseName();
        $this->assertStringStartsWith('wa_throwaway_', $current, 'refusing to create a probe database next to a non-throwaway one');
        DB::statement('CREATE DATABASE IF NOT EXISTS `wa_throwaway_probe`');

        $config = config('database.connections.'.config('database.default'));
        $process = new Process([PHP_BINARY, base_path('tests/Probes/payment_fulfillment_concurrency_probe.php')], base_path(), [
            'APP_ENV' => 'testing', 'DB_CONNECTION' => config('database.default'), 'DB_HOST' => $config['host'], 'DB_PORT' => (string) $config['port'],
            'DB_DATABASE' => 'wa_throwaway_probe', 'DB_USERNAME' => $config['username'], 'DB_PASSWORD' => (string) $config['password'], 'PROBE_ROUNDS' => '1',
        ]);
        $process->setTimeout(600)->run();
        $output = $process->getOutput().$process->getErrorOutput();

        $this->assertSame(0, $process->getExitCode(), $output);
        $this->assertStringNotContainsString('FAIL', $output, $output);
        foreach (['webhook + webhook', 'webhook + verify-payment', 'verify-payment + verify-payment', '8 mixed callbacks', 'two invoices of one account at once', 'renewal fulfilment racing quota consumption'] as $case) {
            $this->assertMatchesRegularExpression('/^PASS .*'.preg_quote($case, '/').'/m', $output, $case);
        }
    }

    // ==================================================================
    // 13–16. Security / P5-4
    // ==================================================================

    public function test_13_another_tenant_cannot_confirm_or_redirect_someone_elses_invoice(): void
    {
        ['account' => $victim, 'user' => $victimUser] = $this->agentCustomer();
        $invoice = $this->order($victimUser);
        $attacker = Account::factory()->create();
        $attackerUser = $this->admin($attacker);

        $this->verify($attackerUser, $invoice)->assertNotFound();
        $this->verify($attackerUser, $invoice, 'pay_p55', ['account_id' => $victim->id])->assertNotFound();

        $this->assertSame('pending', $invoice->fresh()->status);
        $this->assertSame(0, Subscription::whereIn('account_id', [$victim->id, $attacker->id])->count());
    }

    public function test_14_request_account_ids_never_redirect_fulfilment(): void
    {
        ['account' => $owner, 'user' => $user] = $this->agentCustomer();
        $other = Account::factory()->create();
        $invoice = $this->order($user);

        $this->verify($user, $invoice, 'pay_p55', ['account_id' => $other->id, 'tenant_id' => $other->id, 'subscription_id' => 999])->assertOk();
        $this->webhook($invoice, 'pay_p55', 'payment.captured', ['notes' => ['account_id' => (string) $other->id]], ['account_id' => $other->id])->assertOk();

        $this->assertSame(1, Subscription::where('account_id', $owner->id)->count());
        $this->assertSame(0, Subscription::where('account_id', $other->id)->count());
        $this->assertSame(0, AccountEntitlement::where('account_id', $other->id)->count());
    }

    public function test_15_request_commercial_values_never_alter_fulfilment(): void
    {
        ['account' => $account, 'user' => $user] = $this->agentCustomer();
        $invoice = $this->order($user, 'growth', ['total_allocated_messages' => 999999, 'duration_days' => 3650, 'price' => 1, 'engine_type' => 'meta']);
        $plan = Plan::where('slug', 'growth')->firstOrFail();

        $this->verify($user, $invoice, 'pay_p55', ['plan_key' => 'business', 'total_allocated_messages' => 999999, 'amount' => 1, 'total_amount' => 1, 'engine_type' => 'meta'])->assertOk();

        $sub = Subscription::where('account_id', $account->id)->sole();
        $this->assertSame((float) $plan->price, (float) $invoice->fresh()->amount, 'the order was priced from the plan row, not the request');
        $this->assertSame([(int) $plan->total_allocated_messages, $plan->engine_type, (float) $invoice->fresh()->total_amount], [(int) $sub->total_allocated_messages, $sub->engine_type, (float) $sub->price_paid]);
        $this->assertSame(now()->addDays((int) $plan->duration_days)->toDateString(), $sub->expires_at->toDateString());
    }

    public static function paths(): array
    {
        return ['webhook' => ['webhook'], 'verify-payment' => ['verify']];
    }

    #[DataProvider('paths')]
    public function test_16_the_p54_invoice_snapshot_stays_authoritative_through_duplicates(string $path): void
    {
        ['account' => $account, 'user' => $user] = $this->agentCustomer();
        $invoice = $this->order($user);
        $bought = Plan::where('slug', 'growth')->firstOrFail()->only(['total_allocated_messages', 'duration_days', 'engine_type']);
        Plan::where('slug', 'growth')->update(['total_allocated_messages' => 1, 'duration_days' => 1]);

        $path === 'webhook' ? $this->webhook($invoice)->assertOk() : $this->verify($user, $invoice)->assertOk();
        $path === 'webhook' ? $this->verify($user, $invoice)->assertOk() : $this->webhook($invoice)->assertOk();

        $sub = Subscription::where('account_id', $account->id)->sole();
        $this->assertSame((int) $bought['total_allocated_messages'], (int) $sub->total_allocated_messages);
        $this->assertSame(now()->addDays((int) $bought['duration_days'])->toDateString(), $sub->expires_at->toDateString());
    }

    // ==================================================================
    // 17–19. Failure, rollback, retry
    // ==================================================================

    public function test_17_a_failed_payment_credits_nothing(): void
    {
        ['account' => $account, 'user' => $user] = $this->agentCustomer();
        $invoice = $this->order($user);

        $this->webhook($invoice, 'pay_bad', 'payment.failed')->assertOk();
        $this->verify($user, $invoice, 'pay_bad', [], 'not-a-valid-signature')->assertStatus(422);
        $this->verify($user, $invoice, 'pay_bad', ['razorpay_order_id' => 'order_someone_else'])->assertStatus(422);
        $body = json_encode(['event' => 'payment.captured', 'payload' => ['payment' => ['entity' => ['id' => 'x', 'order_id' => $invoice->gateway_order_id, 'status' => 'captured']]]]);
        $this->call('POST', '/api/webhooks/razorpay', [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_X_RAZORPAY_SIGNATURE' => 'forged'], $body)->assertStatus(400);

        $this->assertSame('pending', $invoice->fresh()->status);
        $f = $this->footprint($account);
        $this->assertSame([0, [], []], [$f['subscriptions'], $f['commissions'], $f['entitlements']]);
    }

    public function test_18_19_a_fulfilment_that_throws_rolls_back_completely_and_a_retry_fulfils_once(): void
    {
        ['account' => $account, 'user' => $user] = $this->agentCustomer();
        $invoice = $this->order($user);
        $broken = \Mockery::mock(PlanEntitlementReconciliationService::class);
        $broken->shouldReceive('reconcile')->andThrow(new RuntimeException('entitlement store unavailable'));
        $this->app->instance(PlanEntitlementReconciliationService::class, $broken);

        $this->webhook($invoice)->assertStatus(500);
        $this->verify($user, $invoice)->assertStatus(500);

        // 18: nothing of the attempt survives — the gateway will retry.
        $this->assertSame(['pending', null, null], [$invoice->fresh()->status, $invoice->fresh()->gateway_payment_id, $invoice->fresh()->paid_at]);
        $f = $this->footprint($account);
        $this->assertSame([0, [], []], [$f['subscriptions'], $f['commissions'], $f['entitlements']]);

        // 19: the existing contract — the gateway's webhook retry (or the client's verify) fulfils, once.
        $this->app->forgetInstance(PlanEntitlementReconciliationService::class);
        $this->webhook($invoice)->assertOk();
        $this->webhook($invoice)->assertOk();
        $this->verify($user, $invoice)->assertOk()->assertJsonPath('message', 'This invoice was already confirmed.');

        $f = $this->footprint($account);
        $this->assertSame(1, $f['subscriptions']);
        $this->assertSame((int) Plan::where('slug', 'growth')->value('total_allocated_messages'), (int) $f['allocated']);
        $this->assertCount(1, $f['commissions']);
        $this->assertNotEmpty($f['entitlements']);
    }

    public function test_the_fulfilment_serializes_per_account_before_writing_anything(): void
    {
        $source = file_get_contents(app_path('Services/Billing/InvoiceCreditService.php'));
        $fulfil = substr($source, strpos($source, 'public function markPaidAndCreditQuota'));
        $fulfil = substr($fulfil, 0, strpos($fulfil, 'private function reconcilePlanEntitlements'));

        $invoiceLock = strpos($fulfil, 'Invoice::query()->lockForUpdate()');
        $paidCheck = strpos($fulfil, '$invoice->isPaid()');
        $accountLock = strpos($fulfil, 'Account::query()->lockForUpdate()->find($invoice->account_id)');
        $invoiceWrite = strpos($fulfil, "'status' => 'paid'");
        $subscriptionLock = strpos($fulfil, "Subscription::query()\n                ->where('account_id', \$account->id)");

        $this->assertNotFalse($accountLock, 'the invoice owner, never a request value, is locked');
        $this->assertTrue($invoiceLock < $paidCheck && $paidCheck < $accountLock && $accountLock < $invoiceWrite && $invoiceWrite < $subscriptionLock,
            'lock order invoice → (already paid?) → account → write → subscription');
        $this->assertStringNotContainsString('$request', $fulfil);
    }
}
