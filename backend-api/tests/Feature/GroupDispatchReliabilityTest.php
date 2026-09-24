<?php

namespace Tests\Feature;

use App\Jobs\ProcessGroupDirectMessageJob;
use App\Jobs\ProcessGroupDispatchJob;
use App\Models\Account;
use App\Models\ContactGroup;
use App\Models\ContactGroupMember;
use App\Models\Invoice;
use App\Models\MessageDispatchLog;
use App\Models\MessageTemplate;
use App\Models\Subscription;
use App\Models\WhatsAppSession;
use App\Services\Billing\InvoiceCreditService;
use App\Services\Groups\GroupDirectMessageDispatcher;
use App\Services\Groups\GroupMessageDispatcher;
use App\Support\PlanCatalog;
use Database\Seeders\Phase1FoundationSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

// Shadows sleep() for App\Jobs in the test suite only (anti-ban pacing).
require_once __DIR__.'/../Support/disable_job_sleep.php';

/**
 * Phase 5 fix P5-3 — group batch reliability.
 *
 *   A. only one run can ever send for a batch (atomic claim);
 *   B. a run that throws settles: refund = reserved - delivered, once;
 *   C. failed() (timeout / queue failure) settles the same way, idempotently;
 *   D. a batch whose owner died is recovered by group-dispatch:recover-stale,
 *      a fresh one is not, and a recovered batch is never sent afterwards;
 *   E. a long batch runs in bounded slices, each continuing the next,
 *      without re-sending and without leaving the frozen P5-1 list;
 *   F. P5-1 freeze regressions under slicing and failure.
 *
 * Every behavioural scenario runs on BOTH group paths: template
 * (GroupMessageDispatcher / ProcessGroupDispatchJob) and direct
 * (GroupDirectMessageDispatcher / ProcessGroupDirectMessageJob).
 *
 * Row-lock (FOR UPDATE) serialisation cannot be observed on SQLite; the
 * claim itself is a conditional UPDATE and is exercised on both engines.
 * A multi-process race on MariaDB was additionally run outside PHPUnit —
 * see the task report.
 */
class GroupDispatchReliabilityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(Phase1FoundationSeeder::class);
        Queue::fake();
    }

    // ------------------------------------------------------------------ fixtures

    private function qrAccount(): Account
    {
        $account = Account::factory()->create();
        $planKey = collect(PlanCatalog::all())->search(fn ($p) => $p['engine_type'] === 'qr');
        $plan = PlanCatalog::find($planKey);
        $invoice = Invoice::create([
            'account_id' => $account->id, 'invoice_number' => 'INV-'.uniqid(), 'plan_key' => $planKey,
            'plan_label' => $plan['label'], 'amount' => $plan['price'], 'tax_amount' => 0,
            'total_amount' => $plan['price'], 'currency' => 'INR', 'payment_gateway' => 'razorpay',
            'gateway_order_id' => 'order_'.uniqid(), 'gateway_payment_id' => null, 'status' => 'pending',
            'paid_at' => null, 'gateway_raw_response' => null,
        ]);
        app(InvoiceCreditService::class)->markPaidAndCreditQuota($invoice->id, 'pay_'.uniqid());
        WhatsAppSession::create(['account_id' => $account->id, 'status' => 'connected']);

        return $account;
    }

    private function group(Account $account, int $size): ContactGroup
    {
        $group = ContactGroup::create(['account_id' => $account->id, 'name' => 'Segment', 'group_type' => ContactGroup::GROUP_TYPE_INTERNAL]);

        for ($i = 1; $i <= $size; $i++) {
            ContactGroupMember::create(['group_id' => $group->id, 'phone_number' => sprintf('9190000000%02d', $i), 'name' => "M{$i}"]);
        }

        return $group;
    }

    private function template(Account $account): MessageTemplate
    {
        return MessageTemplate::create([
            'account_id' => $account->id, 'template_code' => 'TPL_'.strtoupper(Str::random(6)),
            'title' => 'Blast', 'template_body' => 'Hello {{name}}.', 'status' => 'approved',
        ]);
    }

    private function used(Account $account): int
    {
        return (int) Subscription::where('account_id', $account->id)->value('used_messages');
    }

    private function allSendsSucceed(): void
    {
        Http::fake(['*' => Http::response(['success' => true, 'message_id' => 'QR_OK'], 200)]);
    }

    /** @return list<string> phone numbers actually handed to the WhatsApp engine, in order */
    private function sentTo(): array
    {
        return collect(Http::recorded())
            ->map(fn (array $pair) => $pair[0])
            ->filter(fn (HttpRequest $r) => str_ends_with($r->url(), '/api/message/send'))
            ->map(fn (HttpRequest $r) => Str::before((string) $r['to'], '@'))
            ->values()->all();
    }

    /**
     * Dispatch through one path. Returns [dispatch_id, makeJob] where makeJob()
     * builds a fresh job instance for that batch (a new "worker").
     *
     * @return array{0: int, 1: \Closure}
     */
    private function dispatchVia(string $path, Account $account, ContactGroup $group): array
    {
        if ($path === 'template') {
            $template = $this->template($account);
            $result = GroupMessageDispatcher::dispatch($account->id, $group->id, $template->id, []);
            $this->assertSame('queued', $result['status']);

            return [$result['dispatch_id'], fn () => new ProcessGroupDispatchJob($result['dispatch_id'], $template->id, [], null)];
        }

        $result = GroupDirectMessageDispatcher::dispatch($account->id, $group->id, 'text', ['body' => 'Hi']);
        $this->assertSame('queued', $result['status']);

        return [$result['dispatch_id'], fn () => new ProcessGroupDirectMessageJob($result['dispatch_id'], 'text', ['body' => 'Hi'], null)];
    }

    public static function paths(): array
    {
        return ['template path' => ['template'], 'direct path' => ['direct']];
    }

    /** Recipient rows of the batch as a worker would have written them. */
    private function recordDelivered(MessageDispatchLog $parent, ContactGroup $group, int $count): void
    {
        $members = ContactGroupMember::where('group_id', $group->id)->orderBy('id')->limit($count)->get();

        foreach ($members as $member) {
            MessageDispatchLog::recordGroupRecipient(
                $parent, (string) $member->phone_number, MessageDispatchLog::REFERENCE_TYPE_GROUP_MEMBER,
                (int) $member->id, success: true, engineType: 'qr', gatewayMessageId: 'QR_OK',
            );
        }
    }

    /**
     * Throw from inside the running job on the $n-th heartbeat (the check
     * made immediately before each recipient), i.e. after $n - 1 recipients
     * were fully processed and before recipient $n is sent.
     */
    private function throwOnHeartbeat(int $n): void
    {
        $seen = 0;

        DB::listen(function ($query) use (&$seen, $n) {
            if (preg_match('/^update [`"]?message_dispatch_logs[`"]? set [`"]?claimed_at[`"]?/i', $query->sql) && ++$seen === $n) {
                throw new RuntimeException('simulated crash mid-batch');
            }
        });
    }

    private function assertBalanced(MessageDispatchLog $log): void
    {
        $this->assertSame(
            (int) $log->recipient_count,
            (int) $log->success_count + (int) $log->failure_count,
            'delivered + failed = reserved',
        );
    }

    // ==================================================================
    // A. Duplicate execution
    // ==================================================================

    public function test_the_claim_is_a_conditional_update_only_one_caller_wins(): void
    {
        $account = $this->qrAccount();
        [$dispatchId] = $this->dispatchVia('template', $account, $this->group($account, 2));
        $log = MessageDispatchLog::findOrFail($dispatchId);

        $this->assertTrue($log->claimGroupDispatch('worker-a'));
        $this->assertFalse(MessageDispatchLog::findOrFail($dispatchId)->claimGroupDispatch('worker-b'));
        $this->assertFalse($log->claimGroupDispatch('worker-a'), 'not re-entrant either');

        $this->assertTrue($log->heartbeatGroupDispatch('worker-a'));
        $this->assertFalse($log->heartbeatGroupDispatch('worker-b'), 'a non-owner never passes the send guard');
    }

    public function test_a_heartbeat_in_the_same_second_still_reports_ownership(): void
    {
        // MySQL/MariaDB report 0 affected rows for an UPDATE that writes an
        // unchanged value; ownership must not depend on that count.
        $account = $this->qrAccount();
        [$dispatchId] = $this->dispatchVia('template', $account, $this->group($account, 1));
        $log = MessageDispatchLog::findOrFail($dispatchId);
        $this->freezeTime();

        $this->assertTrue($log->claimGroupDispatch('worker-a'));
        $this->assertTrue($log->heartbeatGroupDispatch('worker-a'));
        $this->assertTrue($log->heartbeatGroupDispatch('worker-a'));
    }

    #[DataProvider('paths')]
    public function test_a_second_execution_while_the_first_is_running_sends_nothing(string $path): void
    {
        $account = $this->qrAccount();
        $group = $this->group($account, 3);
        [$dispatchId, $makeJob] = $this->dispatchVia($path, $account, $group);

        // The second "worker" starts while the first is inside its first send.
        $secondRan = false;
        Http::fake(function () use (&$secondRan, $makeJob) {
            if (! $secondRan) {
                $secondRan = true;
                $makeJob()->handle();
            }

            return Http::response(['success' => true, 'message_id' => 'QR_OK'], 200);
        });

        $makeJob()->handle();

        $this->assertTrue($secondRan);
        $this->assertSame(['919000000001', '919000000002', '919000000003'], $this->sentTo(), 'each recipient exactly once');
        $this->assertSame(3, MessageDispatchLog::where('parent_dispatch_id', $dispatchId)->count(), 'no duplicate recipient rows');

        $log = MessageDispatchLog::findOrFail($dispatchId);
        $this->assertSame('sent', $log->status);
        $this->assertSame([3, 0], [(int) $log->success_count, (int) $log->failure_count]);
        $this->assertSame(3, $this->used($account), 'no extra consumption, no refund');
    }

    #[DataProvider('paths')]
    public function test_a_duplicate_job_for_a_batch_claimed_by_a_dead_worker_sends_nothing(string $path): void
    {
        $account = $this->qrAccount();
        [$dispatchId, $makeJob] = $this->dispatchVia($path, $account, $this->group($account, 2));
        MessageDispatchLog::findOrFail($dispatchId)->claimGroupDispatch('dead-worker');

        $this->allSendsSucceed();
        $makeJob()->handle();

        $this->assertSame([], $this->sentTo());
        $this->assertSame('queued', MessageDispatchLog::findOrFail($dispatchId)->status, 'left for failed()/recovery, not settled by a non-owner');
        $this->assertSame(2, $this->used($account));
    }

    #[DataProvider('paths')]
    public function test_a_run_that_loses_ownership_mid_batch_stops_without_sending_more(string $path): void
    {
        $account = $this->qrAccount();
        $group = $this->group($account, 3);
        [$dispatchId, $makeJob] = $this->dispatchVia($path, $account, $group);
        $this->allSendsSucceed();

        // Right before recipient 2 is sent, the batch is settled from outside
        // (as failed() or recovery would): the running job must stop.
        $heartbeats = 0;
        $settling = false;
        DB::listen(function ($query) use (&$heartbeats, &$settling, $dispatchId) {
            if (! $settling && preg_match('/set [`"]?claimed_at[`"]?/i', $query->sql) && str_starts_with(strtolower($query->sql), 'update') && ++$heartbeats === 2) {
                $settling = true;
                MessageDispatchLog::findOrFail($dispatchId)->settleGroupDispatchFromRecipients('settled externally');
            }
        });

        $makeJob()->handle();

        $this->assertSame(['919000000001'], $this->sentTo(), 'nothing after ownership was lost');
        $log = MessageDispatchLog::findOrFail($dispatchId);
        $this->assertSame([1, 2], [(int) $log->success_count, (int) $log->failure_count]);
        $this->assertBalanced($log);
        $this->assertSame(1, $this->used($account));
    }

    // ==================================================================
    // B. An exception inside the run settles, once
    // ==================================================================

    #[DataProvider('paths')]
    public function test_a_throw_before_any_send_refunds_the_whole_reservation(string $path): void
    {
        $account = $this->qrAccount();
        [$dispatchId, $makeJob] = $this->dispatchVia($path, $account, $this->group($account, 3));
        $this->assertSame(3, $this->used($account));
        $this->allSendsSucceed();
        $this->throwOnHeartbeat(1);

        $makeJob()->handle();

        $this->assertSame([], $this->sentTo());
        $log = MessageDispatchLog::findOrFail($dispatchId);
        $this->assertSame('failed', $log->status);
        $this->assertSame([0, 3], [(int) $log->success_count, (int) $log->failure_count]);
        $this->assertStringContainsString('simulated crash', $log->error_reason);
        $this->assertBalanced($log);
        $this->assertSame(0, $this->used($account), 'refund = reserved');
    }

    #[DataProvider('paths')]
    public function test_a_throw_after_partial_delivery_refunds_only_the_undelivered(string $path): void
    {
        $account = $this->qrAccount();
        [$dispatchId, $makeJob] = $this->dispatchVia($path, $account, $this->group($account, 5));
        $this->allSendsSucceed();
        $this->throwOnHeartbeat(3);

        $makeJob()->handle();

        $this->assertSame(['919000000001', '919000000002'], $this->sentTo());
        $log = MessageDispatchLog::findOrFail($dispatchId);
        $this->assertSame('sent', $log->status, 'at least one recipient was delivered');
        $this->assertSame([2, 3], [(int) $log->success_count, (int) $log->failure_count], 'no false success count');
        $this->assertBalanced($log);
        $this->assertSame(2, $this->used($account), 'refund = reserved - delivered');
    }

    #[DataProvider('paths')]
    public function test_after_a_throw_neither_failed_nor_a_rerun_nor_recovery_changes_anything(string $path): void
    {
        $account = $this->qrAccount();
        [$dispatchId, $makeJob] = $this->dispatchVia($path, $account, $this->group($account, 4));
        $this->allSendsSucceed();
        $this->throwOnHeartbeat(2);
        $makeJob()->handle();

        $before = MessageDispatchLog::findOrFail($dispatchId)->only(['status', 'success_count', 'failure_count', 'error_reason']);
        $usedBefore = $this->used($account);
        $sentBefore = $this->sentTo();

        $makeJob()->failed(new RuntimeException('late failure'));
        $makeJob()->handle();
        $this->travel(2)->hours();
        Artisan::call('group-dispatch:recover-stale');

        $this->assertSame($before, MessageDispatchLog::findOrFail($dispatchId)->only(['status', 'success_count', 'failure_count', 'error_reason']));
        $this->assertSame($usedBefore, $this->used($account));
        $this->assertSame($sentBefore, $this->sentTo());
        $this->assertSame(1, $usedBefore);
    }

    // ==================================================================
    // C. failed() — timeout / queue-level failure
    // ==================================================================

    #[DataProvider('paths')]
    public function test_failed_before_processing_refunds_the_whole_reservation(string $path): void
    {
        $account = $this->qrAccount();
        [$dispatchId, $makeJob] = $this->dispatchVia($path, $account, $this->group($account, 3));

        $makeJob()->failed(new RuntimeException('Job has timed out.'));

        $log = MessageDispatchLog::findOrFail($dispatchId);
        $this->assertSame('failed', $log->status);
        $this->assertSame([0, 3], [(int) $log->success_count, (int) $log->failure_count]);
        $this->assertStringContainsString('Job has timed out.', $log->error_reason);
        $this->assertSame(0, $this->used($account));
    }

    #[DataProvider('paths')]
    public function test_failed_after_partial_delivery_refunds_exactly_the_remainder(string $path): void
    {
        $account = $this->qrAccount();
        $group = $this->group($account, 5);
        [$dispatchId, $makeJob] = $this->dispatchVia($path, $account, $group);

        // A worker claimed the batch, delivered 2 recipients, then was killed.
        $log = MessageDispatchLog::findOrFail($dispatchId);
        $log->claimGroupDispatch('killed-worker');
        $this->recordDelivered($log, $group, 2);

        $makeJob()->failed(new RuntimeException('Job has timed out.'));

        $log = MessageDispatchLog::findOrFail($dispatchId);
        $this->assertSame('sent', $log->status);
        $this->assertSame([2, 3], [(int) $log->success_count, (int) $log->failure_count]);
        $this->assertBalanced($log);
        $this->assertSame(2, $this->used($account));
    }

    #[DataProvider('paths')]
    public function test_failed_twice_refunds_once_and_corrupts_nothing(string $path): void
    {
        $account = $this->qrAccount();
        $group = $this->group($account, 4);
        [$dispatchId, $makeJob] = $this->dispatchVia($path, $account, $group);
        $log = MessageDispatchLog::findOrFail($dispatchId);
        $log->claimGroupDispatch('killed-worker');
        $this->recordDelivered($log, $group, 1);

        $state = fn () => array_merge(
            MessageDispatchLog::findOrFail($dispatchId)->only(['status', 'success_count', 'failure_count', 'error_reason']),
            ['sent_at' => (string) MessageDispatchLog::findOrFail($dispatchId)->sent_at],
        );

        $makeJob()->failed(new RuntimeException('first'));
        $afterFirst = $state();
        $this->travel(5)->seconds();
        $makeJob()->failed(new RuntimeException('second'));
        $makeJob()->failed(null);

        $this->assertSame($afterFirst, $state());
        $this->assertStringContainsString('first', $afterFirst['error_reason']);
        $this->assertSame(1, $this->used($account), '4 reserved, 3 refunded exactly once');
    }

    #[DataProvider('paths')]
    public function test_failed_on_an_already_completed_batch_changes_nothing(string $path): void
    {
        $account = $this->qrAccount();
        [$dispatchId, $makeJob] = $this->dispatchVia($path, $account, $this->group($account, 2));
        $this->allSendsSucceed();
        $makeJob()->handle();

        $makeJob()->failed(new RuntimeException('too late'));

        $log = MessageDispatchLog::findOrFail($dispatchId);
        $this->assertSame('sent', $log->status);
        $this->assertSame([2, 0], [(int) $log->success_count, (int) $log->failure_count]);
        $this->assertNull($log->error_reason);
        $this->assertSame(2, $this->used($account));
    }

    #[DataProvider('paths')]
    public function test_a_batch_settled_by_failed_is_never_sent_afterwards(string $path): void
    {
        $account = $this->qrAccount();
        [$dispatchId, $makeJob] = $this->dispatchVia($path, $account, $this->group($account, 2));
        $makeJob()->failed(new RuntimeException('Job has timed out.'));

        $this->allSendsSucceed();
        $makeJob()->handle();

        $this->assertSame([], $this->sentTo());
        $this->assertSame(0, $this->used($account));
    }

    public function test_both_jobs_declare_a_bounded_timeout_and_fail_on_timeout(): void
    {
        foreach ([new ProcessGroupDispatchJob(1, 1, []), new ProcessGroupDirectMessageJob(1, 'text', ['body' => 'x'])] as $job) {
            $this->assertSame(1, $job->tries);
            $this->assertSame(MessageDispatchLog::GROUP_JOB_TIMEOUT_SECONDS, $job->timeout);
            $this->assertTrue($job->failOnTimeout);
            $this->assertTrue(method_exists($job, 'failed'));
        }

        $retryAfter = (int) config('queue.connections.database.retry_after');
        $this->assertLessThan($retryAfter, MessageDispatchLog::GROUP_JOB_TIMEOUT_SECONDS, 'a running slice must never be handed to a second worker');
        // Slice budget + one worst-case recipient (8 s pacing + 15 s provider timeout + margin).
        $this->assertLessThan(MessageDispatchLog::GROUP_JOB_TIMEOUT_SECONDS, MessageDispatchLog::GROUP_JOB_SLICE_BUDGET_SECONDS + 8 + 15 + 5);
        $this->assertGreaterThan(MessageDispatchLog::GROUP_JOB_TIMEOUT_SECONDS * 5, MessageDispatchLog::GROUP_CLAIM_STALE_AFTER_SECONDS);
    }

    // ==================================================================
    // D. Stale recovery
    // ==================================================================

    #[DataProvider('paths')]
    public function test_a_stale_claimed_batch_is_recovered_refunded_and_only_once(string $path): void
    {
        $account = $this->qrAccount();
        $group = $this->group($account, 4);
        [$dispatchId, $makeJob] = $this->dispatchVia($path, $account, $group);
        $log = MessageDispatchLog::findOrFail($dispatchId);
        $log->claimGroupDispatch('dead-worker');
        $this->recordDelivered($log, $group, 1);

        $this->travel(MessageDispatchLog::GROUP_CLAIM_STALE_AFTER_SECONDS + 1)->seconds();
        Artisan::call('group-dispatch:recover-stale');

        $log = MessageDispatchLog::findOrFail($dispatchId);
        $this->assertSame('sent', $log->status);
        $this->assertSame([1, 3], [(int) $log->success_count, (int) $log->failure_count]);
        $this->assertStringContainsString('settled by recovery', $log->error_reason);
        $this->assertSame(1, $this->used($account));

        $snapshot = $log->only(['status', 'success_count', 'failure_count', 'error_reason']);
        Artisan::call('group-dispatch:recover-stale');
        $this->assertStringContainsString('Settled 0 of 0', Artisan::output());
        $this->assertSame($snapshot, MessageDispatchLog::findOrFail($dispatchId)->only(['status', 'success_count', 'failure_count', 'error_reason']));
        $this->assertSame(1, $this->used($account));

        // The late job for this batch cannot send it any more.
        $this->allSendsSucceed();
        $makeJob()->handle();
        $this->assertSame([], $this->sentTo());
    }

    #[DataProvider('paths')]
    public function test_a_never_started_batch_is_recovered_after_the_unclaimed_threshold(string $path): void
    {
        $account = $this->qrAccount();
        [$dispatchId] = $this->dispatchVia($path, $account, $this->group($account, 3));

        $this->travel(MessageDispatchLog::GROUP_UNCLAIMED_STALE_AFTER_SECONDS + 1)->seconds();
        Artisan::call('group-dispatch:recover-stale');

        $log = MessageDispatchLog::findOrFail($dispatchId);
        $this->assertSame('failed', $log->status);
        $this->assertSame([0, 3], [(int) $log->success_count, (int) $log->failure_count]);
        $this->assertSame(0, $this->used($account));
    }

    #[DataProvider('paths')]
    public function test_a_fresh_queued_batch_is_not_reclaimed(string $path): void
    {
        $account = $this->qrAccount();
        [$unclaimedId] = $this->dispatchVia($path, $account, $this->group($account, 2));
        [$claimedId] = $this->dispatchVia($path, $account, $this->group($account, 2));
        MessageDispatchLog::findOrFail($claimedId)->claimGroupDispatch('live-worker');

        // Past the claimed threshold's start but inside both thresholds.
        $this->travel(MessageDispatchLog::GROUP_CLAIM_STALE_AFTER_SECONDS - 60)->seconds();
        Artisan::call('group-dispatch:recover-stale');

        $this->assertSame('queued', MessageDispatchLog::findOrFail($unclaimedId)->status);
        $this->assertSame('queued', MessageDispatchLog::findOrFail($claimedId)->status);
        $this->assertSame(4, $this->used($account));
    }

    #[DataProvider('paths')]
    public function test_a_claimed_batch_with_a_recent_heartbeat_is_not_reclaimed(string $path): void
    {
        $account = $this->qrAccount();
        [$dispatchId] = $this->dispatchVia($path, $account, $this->group($account, 2));
        $log = MessageDispatchLog::findOrFail($dispatchId);
        $log->claimGroupDispatch('live-worker');

        // Old claim, but the owner heartbeated a moment ago.
        $this->travel(MessageDispatchLog::GROUP_CLAIM_STALE_AFTER_SECONDS * 2)->seconds();
        $this->assertTrue($log->heartbeatGroupDispatch('live-worker'));
        Artisan::call('group-dispatch:recover-stale');

        $this->assertSame('queued', MessageDispatchLog::findOrFail($dispatchId)->status);
        $this->assertFalse(MessageDispatchLog::findOrFail($dispatchId)->settleGroupDispatchFromRecipients('x', onlyIfStale: true), 'the locked re-check refuses it too');
    }

    public function test_recovery_never_touches_non_group_or_already_terminal_rows(): void
    {
        $account = $this->qrAccount();
        $individual = MessageDispatchLog::record($account->id, 'api', '919000000099', success: true);
        [$dispatchId, $makeJob] = $this->dispatchVia('template', $account, $this->group($account, 1));
        $this->allSendsSucceed();
        $makeJob()->handle();

        $this->travel(3)->hours();
        Artisan::call('group-dispatch:recover-stale');

        $this->assertSame('sent', $individual->fresh()->status);
        $this->assertSame('sent', MessageDispatchLog::findOrFail($dispatchId)->status);
        $this->assertStringContainsString('Settled 0 of 0', Artisan::output());
    }

    public function test_the_recovery_command_is_scheduled(): void
    {
        $events = collect(app(\Illuminate\Console\Scheduling\Schedule::class)->events());

        $this->assertTrue($events->contains(fn ($e) => str_contains((string) $e->command, 'group-dispatch:recover-stale')));
    }

    // ==================================================================
    // E. Slicing a long batch
    // ==================================================================

    #[DataProvider('paths')]
    public function test_a_long_batch_runs_in_slices_and_sends_each_recipient_once(string $path): void
    {
        $account = $this->qrAccount();
        $group = $this->group($account, 5);
        [$dispatchId, $makeJob] = $this->dispatchVia($path, $account, $group);

        // Every send "takes" 30 s, so a slice (50 s budget) sends 2 recipients.
        Http::fake(function () {
            $this->travel(30)->seconds();

            return Http::response(['success' => true, 'message_id' => 'QR_OK'], 200);
        });

        $jobClass = $path === 'template' ? ProcessGroupDispatchJob::class : ProcessGroupDirectMessageJob::class;

        // The dispatcher's own push is #1; every continuation adds one.
        Queue::assertPushed($jobClass, 1);

        $makeJob()->handle();
        $this->assertSame(['919000000001', '919000000002'], $this->sentTo());
        $this->assertSame('queued', MessageDispatchLog::findOrFail($dispatchId)->status);
        $this->assertNull(MessageDispatchLog::findOrFail($dispatchId)->claim_token, 'the slice handed the batch back');
        Queue::assertPushed($jobClass, 2);

        // Run every continuation the way a worker would, in order.
        $handled = 1;
        while (Queue::pushed($jobClass)->count() > $handled && $handled < 10) {
            Queue::pushed($jobClass)->values()->get($handled)->handle();
            $handled++;
        }

        $this->assertSame(3, $handled, '5 recipients at 2 per slice = 3 slices');

        $this->assertSame(['919000000001', '919000000002', '919000000003', '919000000004', '919000000005'], $this->sentTo());
        $log = MessageDispatchLog::findOrFail($dispatchId);
        $this->assertSame('sent', $log->status);
        $this->assertSame([5, 0], [(int) $log->success_count, (int) $log->failure_count]);
        $this->assertSame(5, MessageDispatchLog::where('parent_dispatch_id', $dispatchId)->count());
        $this->assertSame(5, $this->used($account), 'no refund, no extra charge across slices');
    }

    #[DataProvider('paths')]
    public function test_a_continuation_that_arrives_after_settlement_sends_nothing(string $path): void
    {
        $account = $this->qrAccount();
        [$dispatchId, $makeJob] = $this->dispatchVia($path, $account, $this->group($account, 4));
        Http::fake(function () {
            $this->travel(30)->seconds();

            return Http::response(['success' => true, 'message_id' => 'QR_OK'], 200);
        });
        $jobClass = $path === 'template' ? ProcessGroupDispatchJob::class : ProcessGroupDirectMessageJob::class;

        $makeJob()->handle();
        $this->assertCount(2, $this->sentTo());

        // The continuation waits in the queue past the unclaimed threshold.
        $this->travel(MessageDispatchLog::GROUP_UNCLAIMED_STALE_AFTER_SECONDS + 1)->seconds();
        Artisan::call('group-dispatch:recover-stale');
        Queue::pushed($jobClass)->last()->handle();

        $this->assertCount(2, $this->sentTo());
        $log = MessageDispatchLog::findOrFail($dispatchId);
        $this->assertSame([2, 2], [(int) $log->success_count, (int) $log->failure_count]);
        $this->assertSame(2, $this->used($account));
    }

    // ==================================================================
    // F. P5-1 regressions under slicing / failure
    // ==================================================================

    #[DataProvider('paths')]
    public function test_a_member_added_after_reservation_is_never_sent_even_across_slices(string $path): void
    {
        $account = $this->qrAccount();
        $group = $this->group($account, 3);
        [$dispatchId, $makeJob] = $this->dispatchVia($path, $account, $group);
        ContactGroupMember::create(['group_id' => $group->id, 'phone_number' => '919000000077', 'name' => 'Late']);
        Http::fake(function () {
            $this->travel(30)->seconds();

            return Http::response(['success' => true, 'message_id' => 'QR_OK'], 200);
        });
        $jobClass = $path === 'template' ? ProcessGroupDispatchJob::class : ProcessGroupDirectMessageJob::class;

        $makeJob()->handle();
        Queue::pushed($jobClass)->last()->handle();

        $this->assertSame(['919000000001', '919000000002', '919000000003'], $this->sentTo());
        $log = MessageDispatchLog::findOrFail($dispatchId);
        $this->assertSame([3, 0, 3], [(int) $log->success_count, (int) $log->failure_count, (int) $log->recipient_count]);
        $this->assertSame(3, $this->used($account));
    }

    #[DataProvider('paths')]
    public function test_a_member_removed_after_reservation_is_still_a_refunded_failure_across_slices(string $path): void
    {
        $account = $this->qrAccount();
        $group = $this->group($account, 4);
        [$dispatchId, $makeJob] = $this->dispatchVia($path, $account, $group);
        $removed = ContactGroupMember::where('group_id', $group->id)->orderBy('id')->skip(2)->firstOrFail();
        $removed->delete();
        Http::fake(function () {
            $this->travel(30)->seconds();

            return Http::response(['success' => true, 'message_id' => 'QR_OK'], 200);
        });
        $jobClass = $path === 'template' ? ProcessGroupDispatchJob::class : ProcessGroupDirectMessageJob::class;

        $makeJob()->handle();
        Queue::pushed($jobClass)->last()->handle();

        $this->assertSame(['919000000001', '919000000002', '919000000004'], $this->sentTo());
        $row = MessageDispatchLog::where('parent_dispatch_id', $dispatchId)->where('reference_id', $removed->id)->sole();
        $this->assertSame('failed', $row->status);
        $this->assertStringContainsString('Removed from the group', $row->error_reason);

        $log = MessageDispatchLog::findOrFail($dispatchId);
        $this->assertSame([3, 1], [(int) $log->success_count, (int) $log->failure_count]);
        $this->assertBalanced($log);
        $this->assertSame(3, $this->used($account));
    }

    #[DataProvider('paths')]
    public function test_failure_settlement_uses_the_frozen_reservation_not_the_live_group(string $path): void
    {
        $account = $this->qrAccount();
        $group = $this->group($account, 3);
        [$dispatchId, $makeJob] = $this->dispatchVia($path, $account, $group);

        // The live group changes heavily; settlement must still be about the 3 reserved.
        ContactGroupMember::where('group_id', $group->id)->delete();
        for ($i = 0; $i < 5; $i++) {
            ContactGroupMember::create(['group_id' => $group->id, 'phone_number' => '91988888880'.$i, 'name' => "N{$i}"]);
        }

        $makeJob()->failed(new RuntimeException('Job has timed out.'));

        $log = MessageDispatchLog::findOrFail($dispatchId);
        $this->assertSame([0, 3, 3], [(int) $log->success_count, (int) $log->failure_count, (int) $log->recipient_count]);
        $this->assertSame(0, $this->used($account), 'never refunds more than was reserved');
    }

    // ==================================================================
    // Native WhatsApp group (1 credit)
    // ==================================================================

    #[DataProvider('paths')]
    public function test_a_native_batch_is_never_sent_twice_and_settles_from_its_row(string $path): void
    {
        $account = $this->qrAccount();
        $group = ContactGroup::create([
            'account_id' => $account->id, 'name' => 'Native', 'group_type' => ContactGroup::GROUP_TYPE_NATIVE,
            'wa_group_jid' => '120363000000000000@g.us', 'sync_status' => ContactGroup::SYNC_STATUS_SYNCED,
        ]);
        ContactGroupMember::create(['group_id' => $group->id, 'phone_number' => '919000000001', 'name' => 'A']);
        [$dispatchId, $makeJob] = $this->dispatchVia($path, $account, $group);
        $this->assertSame(1, $this->used($account));

        // A worker sent into the group, recorded it, then died before settling.
        $log = MessageDispatchLog::findOrFail($dispatchId);
        $log->claimGroupDispatch('killed-worker');
        MessageDispatchLog::recordGroupRecipient($log, (string) $group->wa_group_jid, MessageDispatchLog::REFERENCE_TYPE_NATIVE_GROUP, (int) $group->id, success: true, engineType: 'qr', gatewayMessageId: 'QR_OK');

        $this->allSendsSucceed();
        $makeJob()->handle();
        $this->assertSame([], $this->sentTo(), 'a duplicate run cannot claim, so it cannot resend');

        $makeJob()->failed(new RuntimeException('Job has timed out.'));
        $log = MessageDispatchLog::findOrFail($dispatchId);
        $this->assertSame('sent', $log->status);
        $this->assertSame([1, 0], [(int) $log->success_count, (int) $log->failure_count]);
        $this->assertSame(1, $this->used($account), 'the delivered native send is not refunded');
    }

    // ==================================================================
    // Structural
    // ==================================================================

    public function test_the_claim_token_is_never_serialised(): void
    {
        $account = $this->qrAccount();
        [$dispatchId] = $this->dispatchVia('template', $account, $this->group($account, 1));
        $log = MessageDispatchLog::findOrFail($dispatchId);
        $log->claimGroupDispatch('secret-ish-token');

        $this->assertArrayNotHasKey('claim_token', MessageDispatchLog::findOrFail($dispatchId)->toArray());
    }

    public function test_the_jobs_no_longer_use_the_no_refund_failure_or_a_plain_read_guard_alone(): void
    {
        foreach (['Jobs/ProcessGroupDispatchJob.php', 'Jobs/ProcessGroupDirectMessageJob.php'] as $path) {
            $code = file_get_contents(app_path($path));

            $this->assertStringContainsString('->claimGroupDispatch(', $code);
            $this->assertStringContainsString('public function failed(', $code);
            $this->assertStringContainsString('->settleGroupDispatchFromRecipients(', $code);
            $this->assertStringNotContainsString('->failGroupDispatchWithoutRefund(', $code);
            $this->assertStringNotContainsString('->release(', $code, 'refunds stay in the single resolution path');
        }
    }
}
