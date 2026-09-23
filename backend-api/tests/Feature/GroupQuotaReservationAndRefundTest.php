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
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\TestCase;

// See this file's own docblock: shadows sleep() for App\Jobs so the
// jobs' anti-ban pacing (unchanged in production) does not put this
// suite into the minutes.
require_once __DIR__.'/../Support/disable_job_sleep.php';

/**
 * Phase 5 Task 4 -- group reservation migration + idempotent refund.
 *
 * Two things are proved here.
 *
 * RESERVATION: both group dispatchers now reserve through
 * MessageQuotaService::reserve() instead of their own copy of
 * lock -> re-check -> increment(N), and the reservation still commits
 * atomically with the 'queued' audit row.
 *
 * REFUND: a batch used to reserve N and never give any of it back, so a
 * 10-recipient batch with 4 failures still billed 10. It now releases
 * exactly the recipients that received nothing -- ONCE. The idempotence
 * is a persisted 'queued' -> terminal state transition taken under
 * SELECT ... FOR UPDATE, NOT release()'s zero-floor and NOT a cache
 * flag: a second resolution observes a non-'queued' row and does
 * nothing at all.
 *
 * `sleep(random_int(3,8))` between recipients is preserved, so batches
 * here are kept small on purpose.
 */
class GroupQuotaReservationAndRefundTest extends TestCase
{
    use RefreshDatabase;


    protected function setUp(): void
    {
        parent::setUp();
        /*
         * Phase 5 Task 11 — the `plans` table is now the runtime source of
         * truth for checkout AND fulfilment, so markPaidAndCreditQuota()
         * can no longer credit a payment on an unseeded database. Real
         * environments always have this seeded; seeding it here makes the
         * fixture match production rather than relying on the static
         * PlanCatalog the cutover removed from every runtime path.
         */
        $this->seed(Phase1FoundationSeeder::class);
    }

    private const META_TOKEN = 'EAAG_tenant_token_never_leak_me_0123456789';

    // ------------------------------------------------------------------
    // Fixtures
    // ------------------------------------------------------------------
    private function planKeyFor(string $engine): string
    {
        foreach (PlanCatalog::all() as $key => $plan) {
            if ($plan['engine_type'] === $engine) {
                return $key;
            }
        }

        $this->fail("No PlanCatalog plan maps to the {$engine} provider.");
    }

    private function giveActiveSubscription(Account $account, string $engine): void
    {
        $planKey = $this->planKeyFor($engine);
        $plan = PlanCatalog::find($planKey);

        $invoice = Invoice::create([
            'account_id' => $account->id,
            'invoice_number' => 'INV-'.uniqid(),
            'plan_key' => $planKey,
            'plan_label' => $plan['label'],
            'amount' => $plan['price'],
            'tax_amount' => 0,
            'total_amount' => $plan['price'],
            'currency' => 'INR',
            'payment_gateway' => 'razorpay',
            'gateway_order_id' => 'order_'.uniqid(),
            'gateway_payment_id' => null,
            'status' => 'pending',
            'paid_at' => null,
            'gateway_raw_response' => null,
        ]);

        app(InvoiceCreditService::class)->markPaidAndCreditQuota($invoice->id, 'pay_'.uniqid());
    }

    private function qrAccount(): Account
    {
        $account = Account::factory()->create();
        $this->giveActiveSubscription($account, 'qr');
        WhatsAppSession::create(['account_id' => $account->id, 'status' => 'connected']);

        return $account;
    }

    private function metaAccount(): Account
    {
        $account = Account::factory()->create();
        $this->giveActiveSubscription($account, 'meta');
        WhatsAppSession::create([
            'account_id' => $account->id,
            'status' => 'connected',
            'meta_phone_number_id' => '1098'.random_int(100000000, 999999999),
            'meta_waba_id' => '123456789012345',
            'meta_access_token' => self::META_TOKEN,
        ]);

        return $account;
    }

    private function subscriptionOf(Account $account): Subscription
    {
        return Subscription::where('account_id', $account->id)->firstOrFail();
    }

    private function used(Account $account): int
    {
        return (int) $this->subscriptionOf($account)->used_messages;
    }

    private function segmentGroup(Account $account, int $memberCount): ContactGroup
    {
        $group = ContactGroup::create([
            'account_id' => $account->id,
            'name' => 'Segment',
            'group_type' => ContactGroup::GROUP_TYPE_INTERNAL,
        ]);

        for ($i = 0; $i < $memberCount; $i++) {
            ContactGroupMember::create([
                'group_id' => $group->id,
                'phone_number' => '9190000000'.str_pad((string) $i, 2, '0', STR_PAD_LEFT),
                'name' => "Member {$i}",
            ]);
        }

        return $group;
    }

    private function nativeGroup(Account $account): ContactGroup
    {
        $group = ContactGroup::create([
            'account_id' => $account->id,
            'name' => 'Native Crew',
            'group_type' => ContactGroup::GROUP_TYPE_NATIVE,
            'wa_group_jid' => '120363000000000000@g.us',
            'sync_status' => ContactGroup::SYNC_STATUS_SYNCED,
        ]);

        ContactGroupMember::create([
            'group_id' => $group->id,
            'phone_number' => '919000000001',
            'name' => 'Bob',
        ]);

        return $group;
    }

    private function template(Account $account): MessageTemplate
    {
        return MessageTemplate::create([
            'account_id' => $account->id,
            'template_code' => 'TPL_'.strtoupper(Str::random(6)),
            'title' => 'Blast',
            'template_body' => 'Hello {{name}}.',
            'status' => 'approved',
        ]);
    }

    private function allSendsSucceed(): void
    {
        Http::fake([
            'graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.OK']]], 200),
            '*' => Http::response(['success' => true, 'message_id' => 'QR_OK'], 200),
        ]);
    }

    private function allSendsFail(): void
    {
        Http::fake(['*' => Http::response(['success' => false, 'error' => 'Engine said no'], 200)]);
    }

    /**
     * First $successes sends succeed, the rest fail.
     *
     * A sequence is consumed by EVERY matching request, so it must be
     * registered after the fixtures are built -- markPaidAndCreditQuota()
     * fires an outbound webhook of its own and would otherwise eat the
     * first entry. The single-response fakes above are reusable and do
     * not have this problem.
     */
    private function sendsSucceedThenFail(int $successes, int $failures): void
    {
        // Http::fakeSequence() ALREADY registers itself as the '*' stub.
        // Passing the same sequence to Http::fake(['*' => ...]) as well
        // registers it twice, and Laravel then pops TWO entries per
        // request -- verified, and the cause of this file's first red run.
        $sequence = Http::fakeSequence();

        for ($i = 0; $i < $successes; $i++) {
            $sequence->push(['success' => true, 'message_id' => 'QR_OK'], 200);
        }

        for ($i = 0; $i < $failures; $i++) {
            $sequence->push(['success' => false, 'error' => 'Engine said no'], 200);
        }

        // An unexpected extra request should fail the assertion it breaks,
        // not throw an OutOfBoundsException from deep inside the client.
        $sequence->whenEmpty(Http::response(['success' => false, 'error' => 'sequence exhausted'], 200));
    }

    private function dispatchLogFor(Account $account): MessageDispatchLog
    {
        return MessageDispatchLog::where('account_id', $account->id)
            ->where('recipient_type', 'group')
            ->latest('id')
            ->firstOrFail();
    }

    // ==================================================================
    // 1. Reservation
    // ==================================================================
    public function test_reservation_debits_exactly_the_recipient_count(): void
    {
        Queue::fake();
        $account = $this->qrAccount();
        $group = $this->segmentGroup($account, 4);
        $template = $this->template($account);

        $result = GroupMessageDispatcher::dispatch($account->id, $group->id, $template->id, []);

        $this->assertSame('queued', $result['status']);
        $this->assertSame(4, $result['queued_recipients_count']);
        $this->assertSame(4, $this->used($account));
    }

    public function test_the_direct_group_path_reserves_the_same_way(): void
    {
        Queue::fake();
        $account = $this->qrAccount();
        $group = $this->segmentGroup($account, 3);

        $result = GroupDirectMessageDispatcher::dispatch($account->id, $group->id, 'text', ['body' => 'Hi']);

        $this->assertSame('queued', $result['status']);
        $this->assertSame(3, $this->used($account));
    }

    public function test_insufficient_quota_reserves_nothing_and_queues_nothing(): void
    {
        Queue::fake();
        $account = $this->qrAccount();
        $subscription = $this->subscriptionOf($account);
        Subscription::whereKey($subscription->id)->update([
            'used_messages' => $subscription->total_allocated_messages - 2,
        ]);
        $before = $this->used($account);

        $group = $this->segmentGroup($account, 5);
        $template = $this->template($account);

        $result = GroupMessageDispatcher::dispatch($account->id, $group->id, $template->id, []);

        $this->assertSame('insufficient_quota', $result['status']);
        $this->assertSame(5, $result['required']);
        $this->assertSame(2, $result['remaining']);
        $this->assertSame($before, $this->used($account), 'a refused reservation must write nothing');
        $this->assertSame(0, MessageDispatchLog::count(), 'and must not create a queued audit row');
        Queue::assertNothingPushed();
    }

    public function test_exactly_the_remaining_quota_can_be_reserved(): void
    {
        Queue::fake();
        $account = $this->qrAccount();
        $subscription = $this->subscriptionOf($account);
        Subscription::whereKey($subscription->id)->update([
            'used_messages' => $subscription->total_allocated_messages - 3,
        ]);

        $group = $this->segmentGroup($account, 3);
        $template = $this->template($account);

        $result = GroupMessageDispatcher::dispatch($account->id, $group->id, $template->id, []);

        $this->assertSame('queued', $result['status']);
        $this->assertSame((int) $subscription->total_allocated_messages, $this->used($account));
    }

    public function test_the_reservation_and_the_queued_row_commit_together(): void
    {
        Queue::fake();
        $account = $this->qrAccount();
        $group = $this->segmentGroup($account, 4);
        $template = $this->template($account);

        $result = GroupMessageDispatcher::dispatch($account->id, $group->id, $template->id, []);

        $log = MessageDispatchLog::findOrFail($result['dispatch_id']);
        $this->assertSame('queued', $log->status);
        $this->assertSame(4, (int) $log->recipient_count);
        $this->assertSame(4, $this->used($account), 'the debit and the audit row must agree');
    }

    public function test_the_job_is_queued_only_after_the_reservation_commits(): void
    {
        Queue::fake();
        $account = $this->qrAccount();
        $group = $this->segmentGroup($account, 2);
        $template = $this->template($account);

        GroupMessageDispatcher::dispatch($account->id, $group->id, $template->id, []);

        Queue::assertPushed(ProcessGroupDispatchJob::class, 1);
    }

    public function test_an_uncapped_plan_reserves_without_refusing(): void
    {
        Queue::fake();
        $account = $this->qrAccount();
        Subscription::where('account_id', $account->id)->update(['billing_model' => 'unlimited']);

        $group = $this->segmentGroup($account, 6);
        $template = $this->template($account);

        $result = GroupMessageDispatcher::dispatch($account->id, $group->id, $template->id, []);

        $this->assertSame('queued', $result['status']);
        $this->assertSame(6, $this->used($account), 'usage is still tracked on an uncapped plan');
    }

    // ==================================================================
    // 2. Capability behaviour preserved (Task 2's gate)
    // ==================================================================
    public function test_a_qr_native_group_send_still_reserves_one_credit(): void
    {
        Queue::fake();
        $account = $this->qrAccount();
        $group = $this->nativeGroup($account);
        $template = $this->template($account);

        $result = GroupMessageDispatcher::dispatch($account->id, $group->id, $template->id, []);

        $this->assertSame('queued', $result['status']);
        $this->assertSame(1, $result['queued_recipients_count'], 'a native group is one message, not one per member');
        $this->assertSame(1, $this->used($account));
    }

    public function test_a_meta_native_group_send_is_still_rejected_and_reserves_nothing(): void
    {
        Queue::fake();
        $account = $this->metaAccount();
        $group = $this->nativeGroup($account);
        $template = $this->template($account);

        $result = GroupMessageDispatcher::dispatch($account->id, $group->id, $template->id, []);

        $this->assertSame('unsupported_engine', $result['status']);
        $this->assertSame(0, $this->used($account));
        Queue::assertNothingPushed();
    }

    public function test_an_empty_group_is_still_rejected_before_reserving(): void
    {
        Queue::fake();
        $account = $this->qrAccount();
        $group = $this->segmentGroup($account, 0);
        $template = $this->template($account);

        $result = GroupMessageDispatcher::dispatch($account->id, $group->id, $template->id, []);

        $this->assertSame('empty_group', $result['status']);
        $this->assertSame(0, $this->used($account));
    }

    // ==================================================================
    // 3. Refund -- the spec's three shapes
    // ==================================================================
    public function test_a_fully_successful_batch_refunds_nothing(): void
    {
        $this->allSendsSucceed();
        $account = $this->qrAccount();
        $group = $this->segmentGroup($account, 3);
        $template = $this->template($account);

        $result = GroupMessageDispatcher::dispatch($account->id, $group->id, $template->id, []);

        $this->assertSame(3, $this->used($account), 'all three stay billed');

        $log = MessageDispatchLog::findOrFail($result['dispatch_id']);
        $this->assertSame('sent', $log->status);
        $this->assertSame(3, (int) $log->success_count);
        $this->assertSame(0, (int) $log->failure_count);
    }

    public function test_a_partially_failed_batch_refunds_only_the_failures(): void
    {
        // 4 reserved, 3 delivered, 1 rejected -> 1 credit back.
        $account = $this->qrAccount();
        $group = $this->segmentGroup($account, 4);
        $template = $this->template($account);
        $this->sendsSucceedThenFail(3, 1);

        $result = GroupMessageDispatcher::dispatch($account->id, $group->id, $template->id, []);

        $this->assertSame(3, $this->used($account), 'reserved 4, delivered 3, so 1 is refunded');

        $log = MessageDispatchLog::findOrFail($result['dispatch_id']);
        $this->assertSame('sent', $log->status, 'a partial batch is still "sent"');
        $this->assertSame(3, (int) $log->success_count);
        $this->assertSame(1, (int) $log->failure_count);
        $this->assertSame(
            (int) $log->recipient_count,
            (int) $log->success_count + (int) $log->failure_count,
            'S + F must equal the reservation N',
        );
    }

    public function test_a_fully_failed_batch_refunds_the_whole_reservation(): void
    {
        $this->allSendsFail();
        $account = $this->qrAccount();
        $group = $this->segmentGroup($account, 3);
        $template = $this->template($account);

        $result = GroupMessageDispatcher::dispatch($account->id, $group->id, $template->id, []);

        $this->assertSame(0, $this->used($account), 'nothing was delivered, so nothing stays billed');

        $log = MessageDispatchLog::findOrFail($result['dispatch_id']);
        $this->assertSame('failed', $log->status);
        $this->assertSame(0, (int) $log->success_count);
        $this->assertSame(3, (int) $log->failure_count);
    }

    public function test_a_fully_failed_batch_reopens_an_exhausted_subscription(): void
    {
        $this->allSendsFail();
        $account = $this->qrAccount();
        $subscription = $this->subscriptionOf($account);
        Subscription::whereKey($subscription->id)->update([
            'used_messages' => $subscription->total_allocated_messages - 2,
        ]);

        $group = $this->segmentGroup($account, 2);
        $template = $this->template($account);

        GroupMessageDispatcher::dispatch($account->id, $group->id, $template->id, []);

        $fresh = $this->subscriptionOf($account);
        $this->assertSame((int) $subscription->total_allocated_messages - 2, (int) $fresh->used_messages);
        $this->assertSame('active', $fresh->status, 'the refund must recompute the status');
    }

    public function test_the_direct_group_path_refunds_the_same_way(): void
    {
        $account = $this->qrAccount();
        $group = $this->segmentGroup($account, 3);
        $this->sendsSucceedThenFail(2, 1);

        $result = GroupDirectMessageDispatcher::dispatch($account->id, $group->id, 'text', ['body' => 'Hi']);

        $this->assertSame(2, $this->used($account));

        $log = MessageDispatchLog::findOrFail($result['dispatch_id']);
        $this->assertSame(2, (int) $log->success_count);
        $this->assertSame(1, (int) $log->failure_count);
    }

    public function test_a_failed_native_group_send_refunds_its_single_credit(): void
    {
        $this->allSendsFail();
        $account = $this->qrAccount();
        $group = $this->nativeGroup($account);
        $template = $this->template($account);

        GroupMessageDispatcher::dispatch($account->id, $group->id, $template->id, []);

        $this->assertSame(0, $this->used($account));
    }

    /**
     * The branch that used to be a raw forceFill leaving the counts NULL
     * and refunding nothing: every member removed between enqueue and
     * run. Those recipients provably received nothing.
     */
    public function test_a_group_emptied_after_reservation_refunds_everything(): void
    {
        Queue::fake();
        $account = $this->qrAccount();
        $group = $this->segmentGroup($account, 5);
        $template = $this->template($account);

        $result = GroupMessageDispatcher::dispatch($account->id, $group->id, $template->id, []);
        $this->assertSame(5, $this->used($account));

        ContactGroupMember::where('group_id', $group->id)->delete();

        $this->allSendsSucceed();
        (new ProcessGroupDispatchJob($result['dispatch_id'], $template->id, [], null))->handle();

        $this->assertSame(0, $this->used($account));

        $log = MessageDispatchLog::findOrFail($result['dispatch_id']);
        $this->assertSame('failed', $log->status);
        $this->assertSame(0, (int) $log->success_count);
        $this->assertSame(5, (int) $log->failure_count);
    }

    /**
     * Membership shrank but did not vanish: 5 reserved, 2 members left,
     * both delivered. The 3 phantom recipients received nothing, so their
     * credits come back -- the case a plain attempted-failure tally would
     * silently keep.
     */
    public function test_a_shrunken_group_refunds_the_recipients_that_no_longer_exist(): void
    {
        Queue::fake();
        $account = $this->qrAccount();
        $group = $this->segmentGroup($account, 5);
        $template = $this->template($account);

        $result = GroupMessageDispatcher::dispatch($account->id, $group->id, $template->id, []);
        $this->assertSame(5, $this->used($account));

        ContactGroupMember::where('group_id', $group->id)->orderBy('id')->limit(3)->delete();

        $this->allSendsSucceed();
        (new ProcessGroupDispatchJob($result['dispatch_id'], $template->id, [], null))->handle();

        $this->assertSame(2, $this->used($account), 'only the two real deliveries stay billed');

        $log = MessageDispatchLog::findOrFail($result['dispatch_id']);
        $this->assertSame(2, (int) $log->success_count);
        $this->assertSame(3, (int) $log->failure_count);
        $this->assertSame(5, (int) $log->success_count + (int) $log->failure_count);
    }

    // ==================================================================
    // 4. Idempotence -- the heart of this task
    // ==================================================================
    public function test_a_second_resolution_refunds_nothing_more(): void
    {
        $account = $this->qrAccount();
        $group = $this->segmentGroup($account, 10);
        $template = $this->template($account);
        $this->sendsSucceedThenFail(6, 4);

        $result = GroupMessageDispatcher::dispatch($account->id, $group->id, $template->id, []);

        // The spec's own example: reserve 10, success 6, failure 4.
        $this->assertSame(6, $this->used($account));

        $log = MessageDispatchLog::findOrFail($result['dispatch_id']);

        $this->assertFalse($log->resolveGroupDispatch(6, 4), 'a resolved dispatch must refuse to resolve again');
        $this->assertSame(6, $this->used($account), 'the second resolve must release 0, not 4');

        $this->assertFalse($log->resolveGroupDispatch(0, 10));
        $this->assertSame(6, $this->used($account));
    }

    public function test_a_second_resolution_does_not_mutate_the_final_state(): void
    {
        $account = $this->qrAccount();
        $group = $this->segmentGroup($account, 3);
        $template = $this->template($account);
        $this->sendsSucceedThenFail(2, 1);

        $result = GroupMessageDispatcher::dispatch($account->id, $group->id, $template->id, []);
        $log = MessageDispatchLog::findOrFail($result['dispatch_id']);
        $before = $log->only(['status', 'success_count', 'failure_count', 'error_reason', 'sent_at']);

        $log->resolveGroupDispatch(0, 3, 'a different story entirely');

        $after = MessageDispatchLog::findOrFail($result['dispatch_id'])
            ->only(['status', 'success_count', 'failure_count', 'error_reason', 'sent_at']);

        $this->assertEquals($before, $after);
    }

    /**
     * The concurrency case, made deterministic. Two workers each load the
     * dispatch row while it is still 'queued'; the database serialises
     * their SELECT ... FOR UPDATE, so whichever commits second is acting
     * on a stale in-memory copy -- exactly what this asserts. Only one
     * refund may land.
     */
    public function test_two_workers_holding_stale_copies_produce_exactly_one_refund(): void
    {
        Queue::fake();
        $account = $this->qrAccount();
        $group = $this->segmentGroup($account, 8);
        $template = $this->template($account);

        $result = GroupMessageDispatcher::dispatch($account->id, $group->id, $template->id, []);
        $this->assertSame(8, $this->used($account));

        // Both workers read the row before either resolves.
        $workerA = MessageDispatchLog::findOrFail($result['dispatch_id']);
        $workerB = MessageDispatchLog::findOrFail($result['dispatch_id']);

        $this->assertSame('queued', $workerA->status);
        $this->assertSame('queued', $workerB->status, 'B genuinely believes it is still unresolved');

        $this->assertTrue($workerA->resolveGroupDispatch(5, 3));
        $this->assertSame(5, $this->used($account));

        $this->assertFalse($workerB->resolveGroupDispatch(5, 3), 'the loser must observe the resolved state');
        $this->assertSame(5, $this->used($account), 'exactly one refund, not two');
    }

    public function test_running_the_same_job_twice_refunds_once(): void
    {
        Queue::fake();
        $account = $this->qrAccount();
        $group = $this->segmentGroup($account, 3);
        $template = $this->template($account);

        $result = GroupMessageDispatcher::dispatch($account->id, $group->id, $template->id, []);

        $this->allSendsFail();
        (new ProcessGroupDispatchJob($result['dispatch_id'], $template->id, [], null))->handle();
        $this->assertSame(0, $this->used($account));

        // A duplicate delivery of the same job.
        (new ProcessGroupDispatchJob($result['dispatch_id'], $template->id, [], null))->handle();
        $this->assertSame(0, $this->used($account), 'the second run must not refund again');
    }

    public function test_running_the_same_direct_group_job_twice_refunds_once(): void
    {
        Queue::fake();
        $account = $this->qrAccount();
        $group = $this->segmentGroup($account, 3);

        $result = GroupDirectMessageDispatcher::dispatch($account->id, $group->id, 'text', ['body' => 'Hi']);

        $this->allSendsFail();
        (new ProcessGroupDirectMessageJob($result['dispatch_id'], 'text', ['body' => 'Hi'], null))->handle();
        $this->assertSame(0, $this->used($account));

        (new ProcessGroupDirectMessageJob($result['dispatch_id'], 'text', ['body' => 'Hi'], null))->handle();
        $this->assertSame(0, $this->used($account));
    }

    public function test_the_guard_is_a_locked_read_inside_a_transaction(): void
    {
        Queue::fake();
        $account = $this->qrAccount();
        $group = $this->segmentGroup($account, 2);
        $template = $this->template($account);

        $result = GroupMessageDispatcher::dispatch($account->id, $group->id, $template->id, []);
        $log = MessageDispatchLog::findOrFail($result['dispatch_id']);

        $sawLockedSelect = false;
        $sawWriteInTransaction = false;

        DB::listen(function ($query) use (&$sawLockedSelect, &$sawWriteInTransaction) {
            $sql = strtolower($query->sql);

            if (str_contains($sql, 'for update')) {
                $sawLockedSelect = true;
            }

            if (str_starts_with($sql, 'update "message_dispatch_logs"') || str_starts_with($sql, 'update `message_dispatch_logs`')) {
                $sawWriteInTransaction = DB::transactionLevel() > 0;
            }
        });

        $log->resolveGroupDispatch(1, 1);

        $this->assertTrue($sawWriteInTransaction, 'the resolution write must be inside a transaction');

        // Laravel's SQLite grammar compiles lockForUpdate() to nothing;
        // this half genuinely runs on the MySQL/MariaDB parity run.
        if (DB::connection()->getDriverName() !== 'sqlite') {
            $this->assertTrue($sawLockedSelect, 'the guard must re-read the row FOR UPDATE');
        }
    }

    /**
     * The one resolution that must NOT refund: when the job throws, which
     * recipients actually received their message is unknown, so handing
     * back the whole reservation would credit messages that went out.
     */
    public function test_an_internal_error_marks_the_row_failed_without_refunding(): void
    {
        Queue::fake();
        $account = $this->qrAccount();
        $group = $this->segmentGroup($account, 4);
        $template = $this->template($account);

        $result = GroupMessageDispatcher::dispatch($account->id, $group->id, $template->id, []);
        $this->assertSame(4, $this->used($account));

        $log = MessageDispatchLog::findOrFail($result['dispatch_id']);
        $this->assertTrue($log->failGroupDispatchWithoutRefund('Internal error: boom'));

        $this->assertSame(4, $this->used($account), 'an unknown outcome must not be refunded');

        $fresh = MessageDispatchLog::findOrFail($result['dispatch_id']);
        $this->assertSame('failed', $fresh->status);
        $this->assertSame('Internal error: boom', $fresh->error_reason);
    }

    public function test_an_internal_error_cannot_overwrite_an_already_resolved_row(): void
    {
        $account = $this->qrAccount();
        $group = $this->segmentGroup($account, 3);
        $template = $this->template($account);
        $this->sendsSucceedThenFail(2, 1);

        $result = GroupMessageDispatcher::dispatch($account->id, $group->id, $template->id, []);
        $log = MessageDispatchLog::findOrFail($result['dispatch_id']);

        $this->assertFalse($log->failGroupDispatchWithoutRefund('Internal error: too late'));

        $fresh = MessageDispatchLog::findOrFail($result['dispatch_id']);
        $this->assertSame('sent', $fresh->status);
        $this->assertSame(2, (int) $fresh->success_count);
        $this->assertSame(2, $this->used($account));
    }

    // ==================================================================
    // 5. Structural guarantees
    // ==================================================================
    private function codeWithoutComments(string $relativePath): string
    {
        $tokens = token_get_all(file_get_contents(app_path($relativePath)));
        $code = '';

        foreach ($tokens as $token) {
            if (is_array($token) && in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }

            $code .= is_array($token) ? $token[1] : $token;
        }

        return $code;
    }

    public function test_the_group_dispatchers_reserve_and_never_consume(): void
    {
        foreach (['Services/Groups/GroupMessageDispatcher.php',
                  'Services/Groups/GroupDirectMessageDispatcher.php'] as $path) {
            $code = $this->codeWithoutComments($path);

            $this->assertStringContainsString('->reserve(', $code);
            $this->assertStringNotContainsString('->consume(', $code, 'a group path must never consume');
            $this->assertStringNotContainsString("increment('used_messages'", $code);
            $this->assertStringNotContainsString('lockForUpdate', $code);
        }
    }

    public function test_the_group_jobs_hold_no_quota_logic_of_their_own(): void
    {
        foreach (['Jobs/ProcessGroupDispatchJob.php',
                  'Jobs/ProcessGroupDirectMessageJob.php'] as $path) {
            $code = $this->codeWithoutComments($path);

            $this->assertStringNotContainsString("increment('used_messages'", $code);
            $this->assertStringNotContainsString('->release(', $code, 'the refund belongs to the single resolution path');
            $this->assertStringNotContainsString('total_allocated_messages', $code);
        }
    }

    public function test_provider_selection_is_still_the_factory_only(): void
    {
        foreach (['Jobs/ProcessGroupDispatchJob.php',
                  'Jobs/ProcessGroupDirectMessageJob.php',
                  'Services/Groups/GroupMessageDispatcher.php',
                  'Services/Groups/GroupDirectMessageDispatcher.php'] as $path) {
            $code = $this->codeWithoutComments($path);

            $this->assertStringNotContainsString('new BaileysDriver', $code);
            $this->assertStringNotContainsString('new MetaCloudApiDriver', $code);
        }
    }
}
