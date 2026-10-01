<?php

namespace Tests\Feature;

use App\Jobs\CompleteInstagramVideoPostJob;
use App\Jobs\ProcessDeferredInboundMessageJob;
use App\Jobs\ProcessGroupDirectMessageJob;
use App\Jobs\ProcessGroupDispatchJob;
use App\Jobs\ProcessPaymentAlertJob;
use App\Models\Account;
use App\Models\ContactGroup;
use App\Models\ContactGroupMember;
use App\Models\InboundMessageEvent;
use App\Models\Invoice;
use App\Models\JourneyExecutionEvent as Ev;
use App\Models\MessageDispatchLog;
use App\Models\MessageTemplate;
use App\Models\OrganicPost;
use App\Models\PaymentAlert;
use App\Models\SocialAccount;
use App\Models\SocialProviderConfig;
use App\Models\Subscription;
use App\Models\User;
use App\Models\WhatsAppFlow;
use App\Models\WhatsAppFlowSession;
use App\Models\WhatsAppSession;
use App\Services\Billing\InvoiceCreditService;
use App\Services\Chatbot\ChatbotEngineService;
use App\Services\Groups\GroupDirectMessageDispatcher;
use App\Services\Groups\GroupMessageDispatcher;
use App\Services\Messaging\InboundEventGate;
use App\Services\PaymentAlerts\PaymentAlertDispatcher;
use App\Services\Social\OrganicPublishService;
use App\Support\OutboundPacing;
use App\Support\PlanCatalog;
use Database\Seeders\Phase1FoundationSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Phase 5 fix P5-9 — blocking sleep() / request-path waits removed.
 *
 *   A. static: no production file calls sleep()/usleep()/time_nanosleep()/
 *      time_sleep_until() or the Sleep facade (comments excluded — tokens,
 *      not text, are inspected);
 *   B. group jobs: anti-ban pacing is a delayed continuation slice, not a
 *      sleep — each recipient still sent once, in the frozen order, with the
 *      same settlement/refund, claim and recovery behaviour;
 *   C. payment alerts: the jitter is the job's queue delay; a CSV upload is
 *      spaced 3-8 s per alert; the job itself never waits;
 *   D. organic publishing: the Instagram video poll is a chain of queued
 *      attempts (same 10 × 3 s bounds), not a blocking loop in the request;
 *   E. InboundEventGate: a busy conversation returns at once (no 10 s lease
 *      wait); the message is deferred, not lost, and processed exactly once.
 *
 * The real multi-process race on MariaDB is tests/Probes/inbound_gate_nonblocking_probe.php.
 */
class BlockingWaitRemovalTest extends TestCase
{
    use RefreshDatabase;

    private const PHONE = '919812345678';

    private const INTERNAL = 'internal-secret-p5-9';

    /** The former gate waited this long before giving up; a request must finish far below it. */
    private const OLD_LOCK_WAIT_SECONDS = 10;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(Phase1FoundationSeeder::class);
        config(['services.qr_engine.internal_secret' => self::INTERNAL]);
    }

    private function pacing(int $min, int $max): void
    {
        config(['messaging.outbound_pacing.min_seconds' => $min, 'messaging.outbound_pacing.max_seconds' => $max]);
    }

    // ------------------------------------------------------------------ fixtures

    private function tenant(string $planKey = 'growth'): Account
    {
        $account = Account::factory()->create();
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

        return $account->fresh();
    }

    private function used(Account $account): int
    {
        return (int) Subscription::where('account_id', $account->id)->value('used_messages');
    }

    private function sendsSucceed(): void
    {
        Http::fake(['*' => Http::response(['success' => true, 'message_id' => 'QR_OK'], 200)]);
    }

    /** @return list<string> phone numbers handed to the QR engine, in order */
    private function sentTo(): array
    {
        return collect(Http::recorded())
            ->map(fn (array $pair) => $pair[0])
            ->filter(fn (HttpRequest $r) => str_ends_with($r->url(), '/api/message/send'))
            ->map(fn (HttpRequest $r) => Str::before((string) $r['to'], '@'))
            ->values()->all();
    }

    private function seconds(callable $work): float
    {
        $start = microtime(true);
        $work();

        return microtime(true) - $start;
    }

    private function delayOf(object $job): int
    {
        if ($job->delay === null) {
            return 0;
        }

        return $job->delay instanceof \DateTimeInterface
            ? (int) round(Carbon::now()->diffInSeconds($job->delay, false))
            : (int) $job->delay;
    }

    // ==================================================================
    // A. Static — no blocking sleep in production code
    // ==================================================================

    /** @return list<string> "file:line call" for every blocking-wait CALL (tokens, so comments/strings never count) */
    private function blockingCallsIn(string $path): array
    {
        $found = [];
        $tokens = token_get_all(file_get_contents($path));

        foreach ($tokens as $i => $token) {
            if (! is_array($token) || ! in_array($token[0], [T_STRING, T_NAME_FULLY_QUALIFIED], true)) {
                continue;
            }

            $name = strtolower(ltrim($token[1], '\\'));
            $next = $tokens[$i + 1] ?? null;
            $prev = $tokens[$i - 1] ?? null;
            $isMethodOrDeclaration = is_array($prev) && in_array($prev[0], [T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR, T_DOUBLE_COLON, T_FUNCTION], true);

            if (in_array($name, ['sleep', 'usleep', 'time_nanosleep', 'time_sleep_until'], true) && $next === '(' && ! $isMethodOrDeclaration) {
                $found[] = basename($path).':'.$token[2].' '.$name.'()';
            }

            if (str_ends_with($name, 'sleep') && str_contains($name, 'support\\sleep')) {
                $found[] = basename($path).':'.$token[2].' Sleep facade';
            }
        }

        return $found;
    }

    public function test_no_production_code_calls_a_blocking_sleep(): void
    {
        $offenders = [];

        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(app_path())) as $file) {
            if ($file->isFile() && $file->getExtension() === 'php') {
                array_push($offenders, ...$this->blockingCallsIn($file->getPathname()));
            }
        }

        $this->assertSame([], $offenders);
    }

    public function test_the_four_known_sleeps_and_the_gate_wait_are_gone(): void
    {
        foreach ([
            'Jobs/ProcessGroupDispatchJob.php', 'Jobs/ProcessGroupDirectMessageJob.php', 'Jobs/ProcessPaymentAlertJob.php',
            'Services/Social/OrganicPublishService.php', 'Services/Messaging/InboundEventGate.php',
        ] as $relative) {
            $this->assertSame([], $this->blockingCallsIn(app_path($relative)), $relative);
        }

        $gate = file_get_contents(app_path('Services/Messaging/InboundEventGate.php'));
        $this->assertStringNotContainsString('inbound_lock_wait_ms', $gate, 'no configurable lease wait left');
        $this->assertStringNotContainsString('microtime', $gate, 'no deadline loop left');
    }

    public function test_the_scanner_itself_detects_a_real_call_but_not_a_comment(): void
    {
        $probe = tempnam(sys_get_temp_dir(), 'p59').'.php';
        file_put_contents($probe, "<?php\n// sleep(3) in a comment\n/* usleep(10) */\n\$s = 'sleep(1)';\n\$o->sleep(1);\nsleep(2);\n\\usleep(5);\n");

        try {
            $this->assertSame([basename($probe).':6 sleep()', basename($probe).':7 usleep()'], $this->blockingCallsIn($probe));
        } finally {
            @unlink($probe);
        }
    }

    public function test_outbound_pacing_is_the_former_range_and_can_be_turned_off(): void
    {
        $this->pacing(3, 8);
        foreach (range(1, 50) as $_) {
            $this->assertThat(OutboundPacing::delaySeconds(), $this->logicalAnd($this->greaterThanOrEqual(3), $this->lessThanOrEqual(8)));
        }

        $this->pacing(3, 0);
        $this->assertSame(0, OutboundPacing::delaySeconds());

        $source = file_get_contents(config_path('messaging.php'));
        $this->assertStringContainsString("env('MESSAGING_PACING_MIN_SECONDS', 3)", $source, 'production default min 3 s');
        $this->assertStringContainsString("env('MESSAGING_PACING_MAX_SECONDS', 8)", $source, 'production default max 8 s (phpunit.xml sets 0)');
    }

    // ==================================================================
    // B. Group dispatch jobs
    // ==================================================================

    private function group(Account $account, int $size): ContactGroup
    {
        $group = ContactGroup::create(['account_id' => $account->id, 'name' => 'Segment', 'group_type' => ContactGroup::GROUP_TYPE_INTERNAL]);

        for ($i = 1; $i <= $size; $i++) {
            ContactGroupMember::create(['group_id' => $group->id, 'phone_number' => sprintf('9190000000%02d', $i), 'name' => "M{$i}"]);
        }

        return $group;
    }

    /** @return array{0: int, 1: class-string, 2: \Closure} [dispatch id, job class, fresh job factory] */
    private function dispatchVia(string $path, Account $account, ContactGroup $group): array
    {
        if ($path === 'template') {
            $template = MessageTemplate::create([
                'account_id' => $account->id, 'template_code' => 'TPL_'.strtoupper(Str::random(6)),
                'title' => 'Blast', 'template_body' => 'Hello {{name}}.', 'status' => 'approved',
            ]);
            $result = GroupMessageDispatcher::dispatch($account->id, $group->id, $template->id, []);
            $this->assertSame('queued', $result['status']);

            return [$result['dispatch_id'], ProcessGroupDispatchJob::class, fn () => new ProcessGroupDispatchJob($result['dispatch_id'], $template->id, [], null)];
        }

        $result = GroupDirectMessageDispatcher::dispatch($account->id, $group->id, 'text', ['body' => 'Hi']);
        $this->assertSame('queued', $result['status']);

        return [$result['dispatch_id'], ProcessGroupDirectMessageJob::class, fn () => new ProcessGroupDirectMessageJob($result['dispatch_id'], 'text', ['body' => 'Hi'], null)];
    }

    public static function paths(): array
    {
        return ['template path' => ['template'], 'direct path' => ['direct']];
    }

    #[DataProvider('paths')]
    public function test_group_pacing_is_a_delayed_continuation_not_a_sleep(string $path): void
    {
        Queue::fake();
        $this->freezeSecond();
        $this->pacing(5, 5);
        $this->sendsSucceed();
        $account = $this->tenant();
        [$dispatchId, $jobClass, $makeJob] = $this->dispatchVia($path, $account, $this->group($account, 3));
        Queue::assertPushed($jobClass, 1);

        // The first run sends the first recipient only and hands the rest to
        // a continuation delayed by the pacing — it never waits in-process.
        $elapsed = $this->seconds(fn () => $makeJob()->handle());
        $this->assertLessThan(2, $elapsed, 'no in-process pacing wait');
        $this->assertSame(['919000000001'], $this->sentTo());
        $log = MessageDispatchLog::findOrFail($dispatchId);
        $this->assertSame('queued', $log->status);
        $this->assertNull($log->claim_token, 'the batch is handed back while the continuation waits');
        Queue::assertPushed($jobClass, 2);
        $this->assertSame(5, $this->delayOf(Queue::pushed($jobClass)->last()), 'the anti-ban gap is the queue delay');

        // Every continuation, in order, the way a worker would run them.
        $handled = 1;
        while (Queue::pushed($jobClass)->count() > $handled && $handled < 10) {
            $job = Queue::pushed($jobClass)->values()->get($handled);
            $this->assertSame(5, $this->delayOf($job));
            $this->assertLessThan(2, $this->seconds(fn () => $job->handle()));
            $handled++;
        }

        $this->assertSame(3, $handled, 'one send per slice: the first run + 2 paced continuations');
        $this->assertSame(['919000000001', '919000000002', '919000000003'], $this->sentTo(), 'frozen order, each once');
        $log = MessageDispatchLog::findOrFail($dispatchId);
        $this->assertSame('sent', $log->status);
        $this->assertSame([3, 0], [(int) $log->success_count, (int) $log->failure_count]);
        $this->assertSame(3, MessageDispatchLog::where('parent_dispatch_id', $dispatchId)->count());
        $this->assertSame(3, $this->used($account), 'reserved once, nothing refunded, nothing charged twice');
    }

    #[DataProvider('paths')]
    public function test_a_redelivered_or_duplicate_continuation_never_sends_twice(string $path): void
    {
        Queue::fake();
        $this->pacing(3, 8);
        $this->sendsSucceed();
        $account = $this->tenant();
        [$dispatchId, $jobClass, $makeJob] = $this->dispatchVia($path, $account, $this->group($account, 3));

        $makeJob()->handle();
        $continuation = Queue::pushed($jobClass)->last();

        // A stray copy (duplicate dispatch) claims the handed-back batch first.
        $makeJob()->handle();
        // The real continuation then arrives, and is also delivered twice.
        $continuation->handle();
        $continuation->handle();

        while (($pending = Queue::pushed($jobClass)->filter(fn ($j) => ! isset($j->ran)))->count() > 0 && MessageDispatchLog::findOrFail($dispatchId)->status === 'queued') {
            $next = $pending->first();
            $next->ran = true;
            $next->handle();
        }

        $sent = $this->sentTo();
        $this->assertSame(array_values(array_unique($sent)), $sent, 'no recipient twice');
        $this->assertSame(['919000000001', '919000000002', '919000000003'], $sent);
        $log = MessageDispatchLog::findOrFail($dispatchId);
        $this->assertSame('sent', $log->status);
        $this->assertSame(3, $this->used($account));
    }

    #[DataProvider('paths')]
    public function test_a_batch_abandoned_during_a_pacing_gap_is_recovered_and_refunded_once(string $path): void
    {
        Queue::fake();
        $this->pacing(3, 8);
        $this->sendsSucceed();
        $account = $this->tenant();
        [$dispatchId, $jobClass, $makeJob] = $this->dispatchVia($path, $account, $this->group($account, 4));

        $makeJob()->handle();
        $this->assertCount(1, $this->sentTo());

        // The paced continuation is lost / stuck in a backlog past the threshold.
        $this->travel(MessageDispatchLog::GROUP_UNCLAIMED_STALE_AFTER_SECONDS + 1)->seconds();
        Artisan::call('group-dispatch:recover-stale');
        Queue::pushed($jobClass)->last()->handle();

        $this->assertCount(1, $this->sentTo(), 'a settled batch is never sent afterwards');
        $log = MessageDispatchLog::findOrFail($dispatchId);
        $this->assertSame([1, 3], [(int) $log->success_count, (int) $log->failure_count]);
        $this->assertSame(1, $this->used($account), 'refund = reserved - delivered, once');
    }

    #[DataProvider('paths')]
    public function test_on_the_sync_queue_the_batch_completes_inline_without_any_wait(string $path): void
    {
        // Real sync connection (phpunit.xml): the job runs as a SyncJob. A
        // queue delay does not exist there, so the batch continues in-process
        // — and must not fall back to sleeping (3 recipients × 3-8 s before).
        $this->pacing(3, 8);
        $this->sendsSucceed();
        $account = $this->tenant();
        $group = $this->group($account, 3);

        $elapsed = $this->seconds(function () use (&$dispatchId, $path, $account, $group) {
            [$dispatchId] = $this->dispatchVia($path, $account, $group);
        });

        $this->assertLessThan(3, $elapsed, 'the old code would sleep at least 6 s here');
        $this->assertSame(['919000000001', '919000000002', '919000000003'], $this->sentTo());
        $this->assertSame('sent', MessageDispatchLog::findOrFail($dispatchId)->status);
        $this->assertSame(3, $this->used($account));
    }

    #[DataProvider('paths')]
    public function test_a_budget_slice_that_already_sent_is_continued_with_the_pacing_delay(string $path): void
    {
        Queue::fake();
        $this->freezeSecond();
        $this->pacing(4, 4);
        $account = $this->tenant();
        [$dispatchId, $jobClass, $makeJob] = $this->dispatchVia($path, $account, $this->group($account, 3));

        // The first send "takes" the whole slice budget: the next recipient is
        // reached by the budget check, which must still pace it.
        Http::fake(function () {
            $this->travel(MessageDispatchLog::GROUP_JOB_SLICE_BUDGET_SECONDS)->seconds();

            return Http::response(['success' => true, 'message_id' => 'QR_OK'], 200);
        });

        $makeJob()->handle();
        $this->assertSame(['919000000001'], $this->sentTo());
        $this->assertSame(4, $this->delayOf(Queue::pushed($jobClass)->last()));
    }

    // ==================================================================
    // C. Payment alerts
    // ==================================================================

    private function alert(Account $account, ?string $ref = null): array
    {
        return PaymentAlertDispatcher::dispatch($account->id, [
            'recipient_phone' => '9876543210', 'customer_name' => 'Bob', 'amount' => 250.00,
            'payment_ref' => $ref ?? 'PAY-'.Str::random(8),
        ]);
    }

    public function test_a_payment_alert_is_queued_behind_the_jitter_instead_of_sleeping(): void
    {
        Queue::fake();
        $this->freezeSecond();
        $this->pacing(3, 8);
        $account = $this->tenant();

        $result = $this->alert($account);

        $this->assertSame('queued', $result['status']);
        $job = Queue::pushed(ProcessPaymentAlertJob::class)->sole();
        $this->assertThat($this->delayOf($job), $this->logicalAnd($this->greaterThanOrEqual(3), $this->lessThanOrEqual(8)));
    }

    public function test_the_payment_alert_job_itself_never_waits_and_sends_once(): void
    {
        Queue::fake();
        $this->pacing(3, 8);
        $this->sendsSucceed();
        $account = $this->tenant();
        $result = $this->alert($account);
        $job = Queue::pushed(ProcessPaymentAlertJob::class)->sole();

        $this->assertLessThan(2, $this->seconds(fn () => $job->handle()), 'the old job slept 3-8 s here');
        $job->handle(); // redelivery / duplicate dispatch

        $this->assertSame('sent', PaymentAlert::findOrFail($result['alert']->id)->status);
        $this->assertCount(1, $this->sentTo());
        $this->assertSame(1, $this->used($account), 'one quota unit');
        $this->assertSame(1, MessageDispatchLog::where('account_id', $account->id)->where('status', 'sent')->count());
    }

    public function test_a_csv_upload_spaces_consecutive_alerts_by_the_jitter(): void
    {
        Queue::fake();
        $this->freezeSecond();
        $this->pacing(3, 8);
        $this->seed(RolePermissionSeeder::class);
        $account = $this->tenant();
        $account->forceFill(['allowed_modules' => ['dashboard', 'send_alert']])->save();
        $user = User::factory()->create(['account_id' => $account->id, 'is_active' => true]);
        $user->assignRole('user');

        $csv = "phone,customer_name,amount,payment_ref\n9876543210,A,10,R1\n9876543211,B,20,R2\n9876543212,C,30,R3\n";
        $this->actingAs($user)->post('/api/alerts/bulk-upload', ['file' => UploadedFile::fake()->createWithContent('alerts.csv', $csv)], ['Accept' => 'application/json'])
            ->assertOk()->assertJsonPath('queued_count', 3);

        $delays = Queue::pushed(ProcessPaymentAlertJob::class)->map(fn ($j) => $this->delayOf($j))->values()->all();
        $this->assertCount(3, $delays);
        $this->assertThat($delays[0], $this->logicalAnd($this->greaterThanOrEqual(3), $this->lessThanOrEqual(8)));
        foreach ([1, 2] as $i) {
            $gap = $delays[$i] - $delays[$i - 1];
            $this->assertThat($gap, $this->logicalAnd($this->greaterThanOrEqual(3), $this->lessThanOrEqual(8)), "gap {$i}");
        }
    }

    // ==================================================================
    // D. Organic publishing — Instagram video
    // ==================================================================

    private function instagram(Account $account): SocialAccount
    {
        return SocialAccount::create([
            'account_id' => $account->id, 'provider' => 'meta', 'asset_type' => 'instagram',
            'provider_id' => 'IG1', 'name' => 'Insta', 'access_token' => 'tok-ig',
        ]);
    }

    /** @param list<string> $statuses status_code answers, in order (the last repeats) */
    private function fakeMeta(array $statuses): void
    {
        $i = 0;
        Http::fake(function (HttpRequest $r) use (&$i, $statuses) {
            if (str_ends_with($r->url(), '/IG1/media')) {
                return Http::response(['id' => 'C1'], 200);
            }
            if (str_ends_with($r->url(), '/IG1/media_publish')) {
                return Http::response(['id' => 'POST_1'], 200);
            }
            if (str_contains($r->url(), '/C1')) {
                return Http::response(['status_code' => $statuses[min($i++, count($statuses) - 1)]], 200);
            }

            return Http::response([], 404);
        });
    }

    private function publishCalls(): int
    {
        return collect(Http::recorded())->filter(fn ($pair) => str_ends_with($pair[0]->url(), '/media_publish'))->count();
    }

    private function runChain(): int
    {
        $ran = 0;
        while (($job = Queue::pushed(CompleteInstagramVideoPostJob::class)->values()->get($ran)) !== null && $ran < 20) {
            app()->call([$job, 'handle']);
            $ran++;
        }

        return $ran;
    }

    public function test_an_instagram_video_publish_returns_at_once_and_completes_in_the_queue(): void
    {
        Queue::fake();
        $this->freezeSecond();
        $account = $this->tenant();
        $this->instagram($account);
        $this->fakeMeta(['IN_PROGRESS', 'IN_PROGRESS', 'FINISHED']);

        $elapsed = $this->seconds(function () use (&$post, $account) {
            $post = app(OrganicPublishService::class)->publish($account, ['platform' => 'instagram', 'caption' => 'Hi', 'media_url' => 'https://x/v.mp4', 'media_type' => 'video']);
        });

        $this->assertLessThan(2, $elapsed, 'the request no longer polls Meta');
        $this->assertSame(OrganicPost::STATUS_PENDING, $post->status);
        $first = Queue::pushed(CompleteInstagramVideoPostJob::class)->sole();
        $this->assertSame([1, 'C1', 0], [$first->attempt, $first->creationId, $this->delayOf($first)], 'first check at once, as before');

        $this->assertSame(3, $this->runChain());
        $delays = Queue::pushed(CompleteInstagramVideoPostJob::class)->map(fn ($j) => $this->delayOf($j))->values()->all();
        $this->assertSame([0, 3, 3], $delays, 'the former sleep(3) is the queue delay between checks');

        $post->refresh();
        $this->assertSame([OrganicPost::STATUS_PUBLISHED, 'POST_1'], [$post->status, $post->external_post_id]);
        $this->assertSame(1, $this->publishCalls(), 'media_publish exactly once');
    }

    public function test_an_instagram_video_that_never_finishes_fails_after_the_same_ten_checks(): void
    {
        Queue::fake();
        $account = $this->tenant();
        $this->instagram($account);
        $this->fakeMeta(['IN_PROGRESS']);

        $post = app(OrganicPublishService::class)->publish($account, ['platform' => 'instagram', 'caption' => 'Hi', 'media_url' => 'https://x/v.mp4', 'media_type' => 'video']);

        $this->assertSame(CompleteInstagramVideoPostJob::MAX_ATTEMPTS, $this->runChain(), 'no 11th check');
        $post->refresh();
        $this->assertSame([OrganicPost::STATUS_FAILED, CompleteInstagramVideoPostJob::TIMEOUT_MESSAGE], [$post->status, $post->error_message]);
        $this->assertSame(0, $this->publishCalls());
    }

    public function test_an_instagram_video_rejected_by_meta_fails_and_is_never_published(): void
    {
        Queue::fake();
        $account = $this->tenant();
        $this->instagram($account);
        $this->fakeMeta(['IN_PROGRESS', 'ERROR']);

        $post = app(OrganicPublishService::class)->publish($account, ['platform' => 'instagram', 'caption' => 'Hi', 'media_url' => 'https://x/v.mp4', 'media_type' => 'video']);
        $this->assertSame(2, $this->runChain());

        $post->refresh();
        $this->assertSame(OrganicPost::STATUS_FAILED, $post->status);
        $this->assertStringContainsString('status: ERROR', $post->error_message);
        $this->assertSame(0, $this->publishCalls());

        // A late / duplicate check for a settled post calls nothing.
        $before = count(Http::recorded());
        app()->call([new CompleteInstagramVideoPostJob($post->id, 'C1', 2), 'handle']);
        $this->assertCount($before, Http::recorded());
    }

    public function test_an_instagram_image_and_a_facebook_post_are_still_published_in_the_request(): void
    {
        Queue::fake();
        $account = $this->tenant();
        $this->instagram($account);
        SocialAccount::create(['account_id' => $account->id, 'provider' => 'meta', 'asset_type' => 'facebook_page', 'provider_id' => 'PG1', 'name' => 'Page', 'access_token' => 'tok-pg']);
        Http::fake([
            '*/IG1/media' => Http::response(['id' => 'C2'], 200),
            '*/IG1/media_publish' => Http::response(['id' => 'IGPOST'], 200),
            '*/PG1/feed' => Http::response(['id' => 'FBPOST'], 200),
        ]);

        $ig = app(OrganicPublishService::class)->publish($account, ['platform' => 'instagram', 'caption' => 'Hi', 'media_url' => 'https://x/i.jpg', 'media_type' => 'image']);
        $fb = app(OrganicPublishService::class)->publish($account, ['platform' => 'facebook', 'caption' => 'Hi']);

        $this->assertSame([OrganicPost::STATUS_PUBLISHED, 'IGPOST'], [$ig->status, $ig->external_post_id]);
        $this->assertSame([OrganicPost::STATUS_PUBLISHED, 'FBPOST'], [$fb->status, $fb->external_post_id]);
        Queue::assertNotPushed(CompleteInstagramVideoPostJob::class);
    }

    public function test_the_instagram_check_never_uses_another_tenants_social_account(): void
    {
        Queue::fake();
        $owner = $this->tenant();
        $other = $this->tenant();
        $this->fakeMeta(['FINISHED']);
        $foreign = $this->instagram($other);
        $post = OrganicPost::create([
            'account_id' => $owner->id, 'social_account_id' => $foreign->id, 'provider' => 'meta', 'platform' => 'instagram',
            'caption' => 'x', 'media_url' => 'https://x/v.mp4', 'media_type' => 'video', 'status' => OrganicPost::STATUS_PENDING,
        ]);

        app()->call([new CompleteInstagramVideoPostJob($post->id, 'C1'), 'handle']);

        $this->assertSame(OrganicPost::STATUS_FAILED, $post->fresh()->status);
        $this->assertCount(0, Http::recorded(), 'no call made with the other tenant\'s token');
    }

    // ==================================================================
    // E. InboundEventGate — busy conversation, non-blocking
    // ==================================================================

    /** trigger(keyword join) → question(name) → save_lead("Thanks") */
    private function questionFlow(Account $account): WhatsAppFlow
    {
        return WhatsAppFlow::create([
            'account_id' => $account->id, 'name' => 'Ask', 'trigger_type' => 'keyword', 'trigger_value' => 'join', 'is_active' => true,
            'graph_data' => [
                'nodes' => [
                    ['id' => 't', 'type' => 'trigger', 'data' => []],
                    ['id' => 'q', 'type' => 'question', 'data' => ['prompt_text' => 'Your name?', 'variable_name' => 'name', 'input_type' => 'text']],
                    ['id' => 'save', 'type' => 'save_lead', 'data' => ['name_variable' => 'name', 'completion_message' => 'Thanks']],
                ],
                'edges' => [['id' => 'a', 'source' => 't', 'target' => 'q'], ['id' => 'b', 'source' => 'q', 'target' => 'save']],
            ],
        ]);
    }

    private function holdConversation(Account $account, string $phone = self::PHONE): void
    {
        DB::table('journey_conversation_locks')->insertOrIgnore([
            'account_id' => $account->id, 'phone_number' => $phone, 'owner' => null, 'locked_until' => null, 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('journey_conversation_locks')->where('account_id', $account->id)->where('phone_number', $phone)
            ->update(['owner' => 'other-worker', 'locked_until' => now()->addMinute()]);
    }

    private function freeConversation(Account $account, string $phone = self::PHONE): void
    {
        DB::table('journey_conversation_locks')->where('account_id', $account->id)->where('phone_number', $phone)
            ->update(['owner' => null, 'locked_until' => null]);
    }

    private function qrInbound(Account $account, string $text, ?string $messageId, string $phone = self::PHONE)
    {
        return $this->withHeader('X-Internal-Secret', self::INTERNAL)->postJson('/api/internal/whatsapp-inbound', array_filter([
            'account_id' => $account->id, 'sender_phone' => $phone, 'message' => $text, 'message_id' => $messageId,
        ], fn ($v) => $v !== null));
    }

    /** @return list<string> */
    private function journeySent(Account $account, string $phone = self::PHONE): array
    {
        return MessageDispatchLog::where('account_id', $account->id)->where('source', 'journey')->where('recipient_phone', $phone)
            ->orderBy('id')->pluck('message_preview')->all();
    }

    private function runDeferred(): int
    {
        $ran = 0;
        while (($job = Queue::pushed(ProcessDeferredInboundMessageJob::class)->values()->get($ran)) !== null && $ran < 50) {
            app()->call([$job, 'handle']);
            $ran++;
        }

        return $ran;
    }

    public function test_the_gate_returns_busy_at_once_and_touches_nothing(): void
    {
        $account = $this->tenant();
        $this->holdConversation($account);
        config(['journeys.inbound_lock_wait_ms' => 10000]); // the old knob no longer means anything
        $ran = false;

        $elapsed = $this->seconds(function () use (&$gate, &$ran, $account) {
            $gate = app(InboundEventGate::class)->run($account->id, self::PHONE, 'qr', 'wamsg:B1', function () use (&$ran) {
                $ran = true;
            });
        });

        $this->assertLessThan(1, $elapsed, 'no lease wait (was up to '.self::OLD_LOCK_WAIT_SECONDS.' s)');
        $this->assertSame(['handled' => false, 'result' => null, 'reason' => 'busy'], $gate);
        $this->assertFalse($ran);
        $this->assertSame(0, InboundMessageEvent::count(), 'nothing claimed, so it can be processed later');
        $this->assertSame('other-worker', DB::table('journey_conversation_locks')->where('account_id', $account->id)->value('owner'), 'the holder keeps its lease');
    }

    public function test_a_webhook_for_a_busy_conversation_answers_at_once_and_is_processed_once_later(): void
    {
        Queue::fake();
        $this->freezeSecond();
        Http::fake(['*' => Http::response(['success' => true, 'message_id' => 'OK'], 200)]);
        $account = $this->tenant();
        $this->questionFlow($account);
        $this->holdConversation($account);
        $usedBefore = $this->used($account);

        $elapsed = $this->seconds(fn () => $this->qrInbound($account, 'join', 'K1')->assertOk());

        $this->assertLessThan(2, $elapsed, 'the webhook no longer waits for the lease (was up to '.self::OLD_LOCK_WAIT_SECONDS.' s)');
        $this->assertSame([], $this->journeySent($account), 'not processed beside the other worker');
        $this->assertSame(0, InboundMessageEvent::count());
        $job = Queue::pushed(ProcessDeferredInboundMessageJob::class)->sole();
        $this->assertSame([$account->id, self::PHONE, 'join', 'qr', 'wamsg:K1', 1, 1], [$job->accountId, $job->senderPhone, $job->incomingMessage, $job->provider, $job->eventKey, $job->attempt, $this->delayOf($job)]);
        $this->assertSame(['database', 'journeys'], [$job->connection, $job->queue], 'the durable queue Journey delays use');

        // The other worker finishes; the deferred message runs exactly once.
        $this->freeConversation($account);
        $this->assertSame(1, $this->runDeferred());

        $this->assertSame(['Your name?'], $this->journeySent($account));
        $this->assertSame(1, WhatsAppFlowSession::count());
        $this->assertNotNull(InboundMessageEvent::sole()->processed_at);
        $this->assertSame($usedBefore + 1, $this->used($account), 'one quota unit');
        $this->assertNull(DB::table('journey_conversation_locks')->where('account_id', $account->id)->value('owner'), 'lease released');
    }

    public function test_redeliveries_of_a_busy_message_are_all_deferred_but_processed_once(): void
    {
        Queue::fake();
        Http::fake(['*' => Http::response(['success' => true, 'message_id' => 'OK'], 200)]);
        $account = $this->tenant();
        $this->questionFlow($account);
        $this->holdConversation($account);
        $usedBefore = $this->used($account);

        $this->qrInbound($account, 'join', 'K1')->assertOk();
        $this->qrInbound($account, 'join', 'K1')->assertOk();
        $this->qrInbound($account, 'join', 'K1')->assertOk();
        Queue::assertPushed(ProcessDeferredInboundMessageJob::class, 3);

        $this->freeConversation($account);
        $this->runDeferred();
        // A fourth redelivery after it was handled.
        $this->qrInbound($account, 'join', 'K1')->assertOk();

        $this->assertSame(['Your name?'], $this->journeySent($account), 'one journey start, one send');
        $this->assertSame(1, WhatsAppFlowSession::count());
        $this->assertSame(1, InboundMessageEvent::count());
        $this->assertSame($usedBefore + 1, $this->used($account), 'no duplicate quota charge');
        $this->assertSame(3, Ev::where('account_id', $account->id)->where('event', Ev::INBOUND_DEDUPLICATED)->count());
    }

    public function test_distinct_messages_deferred_behind_a_busy_conversation_are_none_lost(): void
    {
        Queue::fake();
        Http::fake(['*' => Http::response(['success' => true, 'message_id' => 'OK'], 200)]);
        $account = $this->tenant();
        $this->questionFlow($account);
        $this->qrInbound($account, 'join', 'K1')->assertOk();
        $this->holdConversation($account);

        $this->qrInbound($account, 'Meera', 'K2')->assertOk();
        $this->assertSame(['Your name?'], $this->journeySent($account));

        $this->freeConversation($account);
        $this->runDeferred();

        $this->assertSame(['Your name?', 'Thanks'], $this->journeySent($account), 'the answer was deferred, not lost');
        $this->assertSame(2, InboundMessageEvent::whereNotNull('processed_at')->count());
    }

    public function test_a_still_busy_conversation_is_retried_with_backoff_then_given_up_and_logged(): void
    {
        Queue::fake();
        $this->freezeSecond();
        $account = $this->tenant();
        $this->questionFlow($account);
        $this->holdConversation($account);

        $this->qrInbound($account, 'join', 'K1')->assertOk();
        $this->runDeferred(); // attempt 1 finds it busy again → attempt 2 queued

        $jobs = Queue::pushed(ProcessDeferredInboundMessageJob::class)->values();
        $this->assertSame([[1, 1], [2, 2]], $jobs->map(fn ($j) => [$j->attempt, $this->delayOf($j)])->take(2)->all());
        $this->assertSame([1, 2, 3, 4, 5, 5], array_map(fn ($n) => ProcessDeferredInboundMessageJob::delayBeforeAttempt($n), [1, 2, 3, 4, 5, 60]));

        $total = array_sum(array_map(fn ($n) => ProcessDeferredInboundMessageJob::delayBeforeAttempt($n), range(1, ProcessDeferredInboundMessageJob::MAX_ATTEMPTS)));
        $this->assertGreaterThan(InboundEventGate::LEASE_SECONDS * 2, $total, 'an abandoned lease always expires long before the last attempt');

        // The last allowed attempt, still busy: logged, nothing further queued.
        Log::spy();
        $pushedBefore = Queue::pushed(ProcessDeferredInboundMessageJob::class)->count();
        app()->call([new ProcessDeferredInboundMessageJob($account->id, self::PHONE, 'join', null, 'qr', 'wamsg:K1', ProcessDeferredInboundMessageJob::MAX_ATTEMPTS), 'handle']);
        $this->assertSame($pushedBefore, Queue::pushed(ProcessDeferredInboundMessageJob::class)->count());
        Log::shouldHaveReceived('error')->withArgs(fn ($m) => str_contains($m, 'stayed busy'))->once();
        $this->assertSame(0, InboundMessageEvent::count());
    }

    public function test_an_expired_lease_left_by_a_crashed_worker_is_taken_by_the_deferred_retry(): void
    {
        Queue::fake();
        Http::fake(['*' => Http::response(['success' => true, 'message_id' => 'OK'], 200)]);
        $account = $this->tenant();
        $this->questionFlow($account);
        $this->holdConversation($account); // locked_until = now + 1 min, owner never releases

        $this->qrInbound($account, 'join', 'K1')->assertOk();
        $this->travel(InboundEventGate::LEASE_SECONDS + 1)->seconds();
        $this->runDeferred();

        $this->assertSame(['Your name?'], $this->journeySent($account));
    }

    public function test_without_a_queue_fake_the_deferred_message_is_kept_on_the_journeys_queue_and_run_by_its_worker(): void
    {
        // No Queue::fake and the default connection is `sync` (phpunit.xml):
        // the deferred message must still be DURABLE — explicitly on the
        // database `journeys` queue the scheduled worker drains — not run
        // inline (busy again) or lost.
        Http::fake(['*' => Http::response(['success' => true, 'message_id' => 'OK'], 200)]);
        $account = $this->tenant();
        $this->questionFlow($account);
        $this->holdConversation($account);

        $elapsed = $this->seconds(fn () => $this->qrInbound($account, 'join', 'K1')->assertOk());

        $this->assertLessThan(2, $elapsed);
        $this->assertSame([], $this->journeySent($account));
        $this->assertSame(['journeys'], DB::table('jobs')->pluck('queue')->all(), 'one durable deferred job');

        $this->freeConversation($account);
        $this->travel(ProcessDeferredInboundMessageJob::delayBeforeAttempt(1) + 1)->seconds();
        Artisan::call('queue:work', ['connection' => 'database', '--queue' => 'journeys', '--stop-when-empty' => true, '--memory' => 4096, '--sleep' => 0]);

        $this->assertSame(['Your name?'], $this->journeySent($account), 'run by the journeys worker, once');
        $this->assertFalse(DB::table('jobs')->exists());
        $this->assertSame(0, DB::table('failed_jobs')->count());
    }

    public function test_a_deferred_attempt_that_runs_synchronously_is_not_redispatched_in_a_loop(): void
    {
        Queue::fake();
        $account = $this->tenant();
        $this->questionFlow($account);
        $this->holdConversation($account);
        Log::spy();

        app(ChatbotEngineService::class)->handleDeferredInboundMessage($account->id, self::PHONE, 'join', null, 'qr', 'wamsg:K1', 1, runningSynchronously: true);

        Queue::assertNotPushed(ProcessDeferredInboundMessageJob::class);
        Log::shouldHaveReceived('error')->withArgs(fn ($m, $ctx = []) => str_contains($m, 'stayed busy') && ($ctx['sync_queue'] ?? false) === true)->once();
    }

    public function test_a_deferred_message_keeps_the_intake_tenant_and_never_crosses_accounts(): void
    {
        Queue::fake();
        Http::fake(['*' => Http::response(['success' => true, 'message_id' => 'OK'], 200)]);
        $a = $this->tenant();
        $b = $this->tenant();
        $this->questionFlow($a);
        $this->questionFlow($b);
        $this->holdConversation($a);

        $this->qrInbound($a, 'join', 'K1')->assertOk();
        $this->qrInbound($b, 'join', 'K1')->assertOk(); // same phone + key, other tenant: not busy, processed now

        $this->assertSame(['Your name?'], $this->journeySent($b));
        $this->assertSame([], $this->journeySent($a));

        $this->freeConversation($a);
        $this->runDeferred();

        $this->assertSame(['Your name?'], $this->journeySent($a));
        $this->assertSame(['Your name?'], $this->journeySent($b), 'tenant B untouched by A\'s deferred message');
        $this->assertSame(1, WhatsAppFlowSession::where('account_id', $a->id)->count());
        $this->assertSame(1, WhatsAppFlowSession::where('account_id', $b->id)->count());
    }

    public function test_a_manual_journey_test_on_a_busy_phone_is_refused_at_once(): void
    {
        $account = $this->tenant();
        $flow = $this->questionFlow($account);
        $this->holdConversation($account);

        $elapsed = $this->seconds(function () use ($account, $flow) {
            try {
                app(\App\Services\WhatsApp\WhatsAppJourneyEngine::class)->testFlow($account, $flow->fresh(), self::PHONE);
                $this->fail('expected a refusal');
            } catch (\RuntimeException $e) {
                $this->assertStringContainsString('in the middle of another conversation', $e->getMessage());
            }
        });

        $this->assertLessThan(1, $elapsed, 'refused without the old lease wait');
    }
}
