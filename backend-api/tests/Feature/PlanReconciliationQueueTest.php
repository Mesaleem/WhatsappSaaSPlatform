<?php

namespace Tests\Feature;

use App\Jobs\ReconcilePlanAccountsJob;
use App\Models\Account;
use App\Models\AccountEntitlement;
use App\Models\Capability;
use App\Models\Invoice;
use App\Models\Plan;
use App\Models\User;
use App\Services\Access\AccessControlService;
use App\Support\PlanCatalog;
use Database\Seeders\Phase1FoundationSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use RuntimeException;
use Tests\TestCase;

/**
 * F-5.1 — plan-bundle reconciliation must have a worker on the queue setup
 * production actually uses (named queues, one worker line each in
 * routes/console.php).
 *
 * Flow under test:  plan bundle changed → job dispatched → NAMED queue →
 * that queue's worker processes it → existing customers reconciled.
 */
class PlanReconciliationQueueTest extends TestCase
{
    use RefreshDatabase;

    private const PLANS = '/api/admin/plans-management';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        $this->seed(Phase1FoundationSeeder::class);
    }

    private function superAdmin(): User
    {
        $user = User::factory()->create(['account_id' => null, 'is_active' => true]);
        $user->assignRole('super_admin');

        return $user;
    }

    private function paidTenant(string $planKey = 'business'): Account
    {
        $account = Account::factory()->create();
        $plan = PlanCatalog::find($planKey);
        $invoice = Invoice::create([
            'account_id' => $account->id, 'invoice_number' => 'INV-'.uniqid(), 'plan_key' => $planKey, 'plan_label' => $plan['label'],
            'amount' => $plan['price'], 'tax_amount' => 0, 'total_amount' => $plan['price'], 'currency' => 'INR',
            'payment_gateway' => 'razorpay', 'gateway_order_id' => 'order_'.uniqid(), 'gateway_payment_id' => null,
            'status' => 'pending', 'paid_at' => null, 'gateway_raw_response' => null,
        ]);
        app(\App\Services\Billing\InvoiceCreditService::class)->markPaidAndCreditQuota($invoice->id, 'pay_'.uniqid());

        return $account->fresh();
    }

    private function holds(Account $account, string $slug): bool
    {
        return app(AccessControlService::class)->canTenant($account->fresh(), $slug);
    }

    /** @return array<int, string> the business bundle without 'ads' */
    private function bundleWithoutAds(): array
    {
        return Plan::where('slug', 'business')->firstOrFail()->capabilities->pluck('slug')->reject(fn ($s) => $s === 'ads')->values()->all();
    }

    private function pending(?string $queue = null): int
    {
        return DB::table('jobs')->when($queue, fn ($q) => $q->where('queue', $queue))->count();
    }

    // ---------------------------------------------------------------- 1. dispatched to the named queue

    public function test_a_bundle_change_dispatches_the_job_to_the_named_queue(): void
    {
        Queue::fake();
        $admin = $this->superAdmin();

        $this->actingAs($admin)->putJson(self::PLANS.'/business', ['capabilities' => $this->bundleWithoutAds(), 'capability_limits' => ['api_installations' => 5]])
            ->assertOk()->assertJsonPath('data.bundle_changed', true);

        Queue::assertPushedOn('plan-reconciliation', ReconcilePlanAccountsJob::class);
        Queue::assertPushed(ReconcilePlanAccountsJob::class, 1);
        $this->assertSame('plan-reconciliation', ReconcilePlanAccountsJob::QUEUE);
    }

    public function test_the_job_can_execute_when_run_directly(): void
    {
        $account = $this->paidTenant();
        Plan::where('slug', 'business')->firstOrFail()->capabilities()->detach(Capability::where('slug', 'ads')->value('id'));
        $this->assertTrue($this->holds($account, 'ads'));

        ReconcilePlanAccountsJob::dispatchSync('business', null);

        $this->assertFalse($this->holds($account, 'ads'));
    }

    // ---------------------------------------------------------------- 2/3. a worker drains it; never left pending

    public function test_on_the_database_queue_the_named_worker_processes_it_and_nothing_stays_pending(): void
    {
        config(['queue.default' => 'database']);
        $account = $this->paidTenant();
        $admin = $this->superAdmin();

        $this->actingAs($admin)->putJson(self::PLANS.'/business', ['capabilities' => $this->bundleWithoutAds(), 'capability_limits' => ['api_installations' => 5]])->assertOk();

        // 5. The HTTP request did NOT run the fleet reconciliation itself.
        $this->assertTrue($this->holds($account, 'ads'), 'the request returned before any customer was reconciled');
        $this->assertSame(1, $this->pending('plan-reconciliation'));
        $this->assertSame(0, $this->pending('default'), 'nothing was pushed to the unnamed default queue');

        Auth::forgetGuards();

        // A worker for the old unnamed queue (the pre-fix setup) never sees it.
        Artisan::call('queue:work', ['--queue' => 'default', '--stop-when-empty' => true, '--memory' => 1024]);
        $this->assertSame(1, $this->pending('plan-reconciliation'));
        $this->assertTrue($this->holds($account, 'ads'));

        // The worker line routes/console.php schedules (no connection argument → default connection).
        Artisan::call('queue:work', ['--queue' => 'plan-reconciliation', '--stop-when-empty' => true, '--memory' => 1024]);

        $this->assertFalse($this->holds($account, 'ads'), 'the existing customer was reconciled');
        $this->assertSame(0, $this->pending(), 'not left permanently pending');
        $this->assertSame(0, DB::table('failed_jobs')->count());
    }

    public function test_the_scheduler_has_a_worker_line_for_the_named_queue(): void
    {
        $this->app->make(Kernel::class)->bootstrap();

        $events = collect($this->app->make(Schedule::class)->events())
            ->filter(fn ($e) => str_contains((string) $e->command, 'queue:work') && str_contains((string) $e->command, '--queue=plan-reconciliation'))
            ->values();

        $this->assertCount(1, $events);
        $event = $events[0];
        $this->assertSame('* * * * *', $event->expression);
        $this->assertTrue($event->withoutOverlapping);
        $this->assertStringContainsString('--stop-when-empty', $event->command);
        $this->assertStringNotContainsString('queue:work database', $event->command, 'no hard-coded connection: it follows QUEUE_CONNECTION');

        config(['queue.default' => 'database']);
        $this->assertTrue($event->filtersPass($this->app), 'runs on a real queue connection');

        config(['queue.default' => 'redis']);
        $this->assertTrue($event->filtersPass($this->app), 'not tied to the database driver');

        config(['queue.default' => 'sync']);
        $this->assertFalse($event->filtersPass($this->app), 'nothing to drain under sync');
    }

    // ---------------------------------------------------------------- 4. sync / local behaviour

    public function test_under_the_sync_driver_a_bundle_change_still_reconciles_immediately(): void
    {
        $this->assertSame('sync', config('queue.default'));
        $account = $this->paidTenant();

        $this->actingAs($this->superAdmin())->putJson(self::PLANS.'/business', ['capabilities' => $this->bundleWithoutAds(), 'capability_limits' => ['api_installations' => 5]])->assertOk();

        $this->assertFalse($this->holds($account, 'ads'));
        $this->assertSame(AccountEntitlement::REVOKED_PLAN_DOWNGRADE, AccountEntitlement::where('account_id', $account->id)->where('capability_id', Capability::where('slug', 'ads')->value('id'))->value('revoked_reason'));
    }

    // ---------------------------------------------------------------- no duplicates / safe retries

    public function test_a_second_pending_reconciliation_for_the_same_plan_is_not_queued(): void
    {
        config(['queue.default' => 'database']);

        ReconcilePlanAccountsJob::dispatch('business', 1);
        ReconcilePlanAccountsJob::dispatch('business', 2);
        $this->assertSame(1, $this->pending('plan-reconciliation'), 'no duplicate pending run for one plan');

        ReconcilePlanAccountsJob::dispatch('growth', 1);
        $this->assertSame(2, $this->pending('plan-reconciliation'), 'a different plan is independent');

        // Once a worker has started the first, a later edit queues a fresh run (the lock is "until processing").
        Artisan::call('queue:work', ['--queue' => 'plan-reconciliation', '--stop-when-empty' => true, '--memory' => 1024]);
        $this->assertSame(0, $this->pending());
        ReconcilePlanAccountsJob::dispatch('business', 1);
        $this->assertSame(1, $this->pending('plan-reconciliation'));
    }

    public function test_retry_and_failure_behaviour_is_bounded_and_logs_no_tenant_data(): void
    {
        $job = new ReconcilePlanAccountsJob('business', 7);

        $this->assertSame(3, $job->tries);
        $this->assertSame([60, 300], $job->backoff);
        $this->assertGreaterThan(60, $job->timeout, 'a fleet run must outlive the worker default of 60s');
        $this->assertSame('plan-reconciliation', $job->queue);

        Log::spy();
        $job->failed(new RuntimeException('boom with tenant detail'));
        Log::shouldHaveReceived('error')->once()->withArgs(fn ($message, $context = []) => $message === 'ReconcilePlanAccountsJob failed.'
            && $context === ['plan' => 'business', 'exception' => 'RuntimeException']);
    }

    public function test_reconciliation_logic_is_unchanged_one_bad_account_does_not_stop_the_rest(): void
    {
        $good = $this->paidTenant();
        $bad = $this->paidTenant();
        Plan::where('slug', 'business')->firstOrFail()->capabilities()->detach(Capability::where('slug', 'ads')->value('id'));

        $real = app(\App\Services\Access\PlanEntitlementReconciliationService::class);
        $mock = \Mockery::mock(\App\Services\Access\PlanEntitlementReconciliationService::class)->makePartial();
        $mock->shouldReceive('reconcile')->andReturnUsing(function (Account $account, ...$rest) use ($bad, $real) {
            if ($account->id === $bad->id) {
                throw new RuntimeException('one bad row');
            }

            return $real->reconcile($account, ...$rest);
        });
        $mock->__construct(app(\App\Services\Access\ProviderCapabilityService::class));

        (new ReconcilePlanAccountsJob('business'))->handle($mock);

        $this->assertFalse($this->holds($good, 'ads'));
        $this->assertTrue($this->holds($bad, 'ads'), 'the failing account is stepped over, not reconciled halfway');
    }
}
