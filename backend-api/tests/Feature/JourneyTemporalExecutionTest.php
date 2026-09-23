<?php

namespace Tests\Feature;

use App\Jobs\ResumeJourneySessionJob;
use App\Models\Account;
use App\Models\Invoice;
use App\Models\MessageDispatchLog;
use App\Models\User;
use App\Models\WhatsAppFlow;
use App\Models\WhatsAppFlowSession;
use App\Models\WhatsAppSession;
use App\Services\Billing\InvoiceCreditService;
use App\Services\Chatbot\ChatbotEngineService;
use App\Services\WhatsApp\WhatsAppJourneyEngine;
use App\Support\PlanCatalog;
use Database\Seeders\Phase1FoundationSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Phase 7 Task 1 — Journey temporal execution backbone.
 *
 * A `delay` node parks the session ('waiting', wait_until); the scheduler
 * (journeys:resume-due → ResumeJourneySessionJob on database:journeys →
 * WhatsAppJourneyEngine::resumeDueSession()) continues it once due. The
 * session row is the only state; every resume claims it atomically.
 *
 * Covered: delayed state, due/not-due, the real scheduler + database queue
 * worker, duplicate jobs and duplicate inbound events, worker crash
 * (lease) and restart, retry with checkpointing on provider failure,
 * exhausted retries → failed + last_error, cancellation (API, before and
 * during a resumed run), flow deactivation holding timers, graph drift,
 * tenant isolation of both the background run and the cancel endpoint.
 * The immediate path is pinned separately by JourneyImmediateExecutionTest.
 */
class JourneyTemporalExecutionTest extends TestCase
{
    use RefreshDatabase;

    private const PHONE = '919812345678';

    /** When true, the faked QR engine refuses any message whose text contains $failOn. */
    private bool $failing = false;

    private string $failOn = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        $this->seed(Phase1FoundationSeeder::class);

        Http::fake(function (HttpRequest $request) {
            if ($this->failing && str_contains($request->body(), $this->failOn)) {
                return Http::response(['success' => false, 'error' => 'Engine offline'], 200);
            }

            return Http::response(['success' => true, 'message_id' => 'QR_OK'], 200);
        });
    }

    // ------------------------------------------------------------------ fixtures

    private function qrAccount(?callable $factory = null): Account
    {
        $account = ($factory ? $factory(Account::factory()) : Account::factory())->create();
        // Growth: the QR plan that includes journey_automation, which the Journey API requires (Phase 7 Task 1.5).
        $planKey = 'growth';
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

    private function user(Account $account, ?string $role = 'admin', array $permissions = []): User
    {
        $user = User::factory()->create(['account_id' => $account->id, 'is_active' => true]);
        if ($role) {
            $user->assignRole($role);
        }
        foreach ($permissions as $p) {
            $user->givePermissionTo($p);
        }

        return $user;
    }

    /** trigger → "Before" → delay(5 min) → "First" → "Second" */
    private function delayedFlow(Account $account, array $afterDelay = ['First', 'Second']): WhatsAppFlow
    {
        $nodes = [
            ['id' => 't', 'type' => 'trigger', 'data' => []],
            ['id' => 'before', 'type' => 'message', 'data' => ['text' => 'Before']],
            ['id' => 'd', 'type' => 'delay', 'data' => ['amount' => 5, 'unit' => 'minutes']],
        ];
        $edges = [
            ['id' => 'e1', 'source' => 't', 'target' => 'before'],
            ['id' => 'e2', 'source' => 'before', 'target' => 'd'],
        ];
        $previous = 'd';
        foreach ($afterDelay as $i => $text) {
            $nodes[] = ['id' => "m{$i}", 'type' => 'message', 'data' => ['text' => $text]];
            $edges[] = ['id' => "x{$i}", 'source' => $previous, 'target' => "m{$i}"];
            $previous = "m{$i}";
        }

        return WhatsAppFlow::create([
            'account_id' => $account->id, 'name' => 'Nurture', 'trigger_type' => 'keyword',
            'trigger_value' => 'join', 'is_active' => true, 'graph_data' => ['nodes' => $nodes, 'edges' => $edges],
        ]);
    }

    private function start(Account $account, string $text = 'join', string $phone = self::PHONE): void
    {
        app(ChatbotEngineService::class)->handleInboundMessage($account->id, $phone, $text);
    }

    private function flowSession(): WhatsAppFlowSession
    {
        return WhatsAppFlowSession::sole();
    }

    private function resume(?WhatsAppFlowSession $session = null): string
    {
        return app(WhatsAppJourneyEngine::class)->resumeDueSession(($session ?? $this->flowSession())->id);
    }

    /** @return list<string> */
    private function sent(Account $account, bool $onlySuccessful = true): array
    {
        return MessageDispatchLog::where('account_id', $account->id)->where('source', 'journey')
            ->when($onlySuccessful, fn ($q) => $q->where('status', 'sent'))
            ->orderBy('id')->pluck('message_preview')->all();
    }

    private function cancelUrl(WhatsAppFlowSession $s): string
    {
        return "/api/whatsapp/flows/{$s->flow_id}/sessions/{$s->id}/cancel";
    }

    // ------------------------------------------------------------------ delayed state

    public function test_a_delay_parks_the_session_as_waiting_with_a_due_time(): void
    {
        $account = $this->qrAccount();
        $this->delayedFlow($account);
        $this->freezeSecond();

        $this->start($account);

        $s = $this->flowSession();
        $this->assertSame(WhatsAppFlowSession::STATUS_WAITING, $s->status);
        $this->assertSame('d', $s->current_node_id);
        $this->assertTrue($s->wait_until->equalTo(now()->addMinutes(5)));
        $this->assertSame(0, $s->attempts);
        $this->assertNull($s->last_error);
        $this->assertSame(['Before'], $this->sent($account), 'nothing after the delay runs in the webhook request');
    }

    public function test_every_delay_unit_is_honoured(): void
    {
        $account = $this->qrAccount();
        $this->freezeSecond();
        foreach (['seconds' => 30, 'minutes' => 1800, 'hours' => 108000, 'days' => 2592000] as $unit => $seconds) {
            $amount = ['seconds' => 30, 'minutes' => 30, 'hours' => 30, 'days' => 30][$unit];
            $flow = WhatsAppFlow::create([
                'account_id' => $account->id, 'name' => $unit, 'trigger_type' => 'keyword', 'trigger_value' => "go{$unit}", 'is_active' => true,
                'graph_data' => ['nodes' => [['id' => 't', 'type' => 'trigger', 'data' => []], ['id' => 'd', 'type' => 'delay', 'data' => ['amount' => $amount, 'unit' => $unit]]],
                    'edges' => [['id' => 'e', 'source' => 't', 'target' => 'd']]],
            ]);
            $phone = '91980000000'.array_search($unit, ['seconds', 'minutes', 'hours', 'days'], true);
            $this->start($account, "go{$unit}", $phone);

            $s = WhatsAppFlowSession::where('flow_id', $flow->id)->sole();
            $this->assertTrue($s->wait_until->equalTo(now()->addSeconds($seconds)), $unit);
        }
    }

    public function test_a_session_is_not_resumed_before_it_is_due(): void
    {
        $account = $this->qrAccount();
        $this->delayedFlow($account);
        $this->start($account);
        $this->travel(4)->minutes();

        $this->assertSame('skipped', $this->resume());
        $this->assertSame(['Before'], $this->sent($account));
        $this->assertSame(0, $this->flowSession()->attempts);
    }

    public function test_a_due_session_runs_the_rest_of_the_flow_and_completes(): void
    {
        $account = $this->qrAccount();
        $this->delayedFlow($account);
        $this->start($account);
        $this->travel(5)->minutes();

        $this->assertSame(WhatsAppFlowSession::STATUS_COMPLETED, $this->resume());

        $s = $this->flowSession();
        $this->assertSame(WhatsAppFlowSession::STATUS_COMPLETED, $s->status);
        $this->assertNull($s->wait_until);
        $this->assertSame(0, $s->attempts);
        $this->assertSame(['Before', 'First', 'Second'], $this->sent($account));
    }

    public function test_a_resumed_run_can_pause_at_a_question_and_continue_on_the_reply(): void
    {
        $account = $this->qrAccount();
        WhatsAppFlow::create([
            'account_id' => $account->id, 'name' => 'Ask later', 'trigger_type' => 'keyword', 'trigger_value' => 'join', 'is_active' => true,
            'graph_data' => [
                'nodes' => [
                    ['id' => 't', 'type' => 'trigger', 'data' => []],
                    ['id' => 'd', 'type' => 'delay', 'data' => ['amount' => 1, 'unit' => 'hours']],
                    ['id' => 'q', 'type' => 'question', 'data' => ['prompt_text' => 'Still interested?', 'variable_name' => 'answer', 'input_type' => 'text']],
                    ['id' => 'bye', 'type' => 'message', 'data' => ['text' => 'Noted.']],
                ],
                'edges' => [['id' => 'e1', 'source' => 't', 'target' => 'd'], ['id' => 'e2', 'source' => 'd', 'target' => 'q'], ['id' => 'e3', 'source' => 'q', 'target' => 'bye']],
            ],
        ]);
        $this->start($account);
        $this->travel(61)->minutes();

        $this->assertSame(WhatsAppFlowSession::STATUS_ACTIVE, $this->resume());
        $s = $this->flowSession();
        $this->assertSame('q', $s->current_node_id);
        $this->assertNull($s->wait_until);

        $this->start($account, 'yes');

        $this->assertSame(WhatsAppFlowSession::STATUS_COMPLETED, $this->flowSession()->status);
        $this->assertSame(['yes'], array_values($this->flowSession()->context_data));
        $this->assertSame(['Still interested?', 'Noted.'], $this->sent($account));
    }

    public function test_a_second_delay_parks_the_session_again(): void
    {
        $account = $this->qrAccount();
        WhatsAppFlow::create([
            'account_id' => $account->id, 'name' => 'Drip', 'trigger_type' => 'keyword', 'trigger_value' => 'join', 'is_active' => true,
            'graph_data' => [
                'nodes' => [
                    ['id' => 't', 'type' => 'trigger', 'data' => []],
                    ['id' => 'd1', 'type' => 'delay', 'data' => ['amount' => 1, 'unit' => 'days']],
                    ['id' => 'm1', 'type' => 'message', 'data' => ['text' => 'Day 1']],
                    ['id' => 'd2', 'type' => 'delay', 'data' => ['amount' => 2, 'unit' => 'days']],
                    ['id' => 'm2', 'type' => 'message', 'data' => ['text' => 'Day 3']],
                ],
                'edges' => [['id' => 'a', 'source' => 't', 'target' => 'd1'], ['id' => 'b', 'source' => 'd1', 'target' => 'm1'], ['id' => 'c', 'source' => 'm1', 'target' => 'd2'], ['id' => 'e', 'source' => 'd2', 'target' => 'm2']],
            ],
        ]);
        $this->start($account);
        $this->travel(1)->days();

        $this->assertSame(WhatsAppFlowSession::STATUS_WAITING, $this->resume());
        $s = $this->flowSession();
        $this->assertSame('d2', $s->current_node_id);
        $this->assertSame(0, $s->attempts);
        $this->assertSame(['Day 1'], $this->sent($account));

        $this->travel(2)->days();
        $this->assertSame(WhatsAppFlowSession::STATUS_COMPLETED, $this->resume());
        $this->assertSame(['Day 1', 'Day 3'], $this->sent($account));
    }

    // ------------------------------------------------------------------ scheduler + queue

    public function test_the_scheduler_and_database_queue_worker_resume_due_sessions_end_to_end(): void
    {
        $account = $this->qrAccount();
        $this->delayedFlow($account);
        $this->start($account);

        Artisan::call('journeys:resume-due');
        $this->assertSame(0, DB::table('jobs')->count(), 'nothing is due yet');

        $this->travel(6)->minutes();
        Artisan::call('journeys:resume-due');
        $this->assertSame(1, DB::table('jobs')->where('queue', 'journeys')->count());

        Artisan::call('queue:work', ['connection' => 'database', '--queue' => 'journeys', '--stop-when-empty' => true]);

        $this->assertSame(0, DB::table('jobs')->count());
        $this->assertSame(WhatsAppFlowSession::STATUS_COMPLETED, $this->flowSession()->status);
        $this->assertSame(['Before', 'First', 'Second'], $this->sent($account));
    }

    public function test_the_scan_dispatches_only_due_waiting_sessions_on_active_flows(): void
    {
        Queue::fake();
        $account = $this->qrAccount();
        $this->delayedFlow($account);
        $this->start($account, 'join', '919800000001');
        $this->start($account, 'join', '919800000002');
        $this->start($account, 'join', '919800000003');
        [$due, $notDue, $cancelled] = WhatsAppFlowSession::orderBy('id')->get()->all();
        $this->travel(6)->minutes();
        $notDue->forceFill(['wait_until' => now()->addHour()])->save();
        app(WhatsAppJourneyEngine::class)->cancelSession($cancelled);

        Artisan::call('journeys:resume-due');

        Queue::assertPushed(ResumeJourneySessionJob::class, 1);
        Queue::assertPushed(ResumeJourneySessionJob::class, fn ($job) => $job->sessionId === $due->id && $job->connection === 'database' && $job->queue === 'journeys');
    }

    // ------------------------------------------------------------------ idempotency / duplicates

    public function test_a_duplicate_resume_sends_nothing_twice(): void
    {
        $account = $this->qrAccount();
        $this->delayedFlow($account);
        $this->start($account);
        $this->travel(5)->minutes();

        $this->assertSame(WhatsAppFlowSession::STATUS_COMPLETED, $this->resume());
        $this->assertSame('skipped', $this->resume());
        (new ResumeJourneySessionJob($this->flowSession()->id))->handle(app(WhatsAppJourneyEngine::class));

        $this->assertSame(['Before', 'First', 'Second'], $this->sent($account));
    }

    public function test_duplicate_jobs_in_the_queue_run_the_step_once(): void
    {
        $account = $this->qrAccount();
        $this->delayedFlow($account);
        $this->start($account);
        $this->travel(5)->minutes();
        $id = $this->flowSession()->id;

        // Two copies already in the queue (e.g. two scans before a worker ran).
        foreach ([1, 2] as $_) {
            DB::table('jobs')->insert([
                'queue' => 'journeys',
                'payload' => json_encode(['uuid' => (string) \Illuminate\Support\Str::uuid(), 'displayName' => ResumeJourneySessionJob::class, 'job' => 'Illuminate\\Queue\\CallQueuedHandler@call', 'maxTries' => 1, 'maxExceptions' => null, 'failOnTimeout' => false, 'backoff' => null, 'timeout' => null, 'retryUntil' => null, 'data' => ['commandName' => ResumeJourneySessionJob::class, 'command' => serialize(new ResumeJourneySessionJob($id))]]),
                'attempts' => 0, 'reserved_at' => null, 'available_at' => now()->timestamp, 'created_at' => now()->timestamp,
            ]);
        }

        Artisan::call('queue:work', ['connection' => 'database', '--queue' => 'journeys', '--stop-when-empty' => true]);

        $this->assertSame(['Before', 'First', 'Second'], $this->sent($account));
        $this->assertSame(0, DB::table('failed_jobs')->count());
    }

    public function test_a_repeated_inbound_message_while_waiting_starts_no_second_journey(): void
    {
        $account = $this->qrAccount();
        $this->delayedFlow($account);
        $this->start($account);

        // Same keyword again (a redelivery or the customer re-sending): not consumed by the journey.
        $this->assertFalse(app(\App\Services\WhatsApp\WhatsAppJourneyEngine::class)->handleInboundMessage($account->id, self::PHONE, 'join'));
        $this->start($account, 'join');

        $this->assertSame(1, WhatsAppFlowSession::count());
        $this->assertSame(WhatsAppFlowSession::STATUS_WAITING, $this->flowSession()->status);
        $this->assertSame(['Before'], $this->sent($account));
    }

    // ------------------------------------------------------------------ restart / resume

    public function test_a_worker_that_died_after_claiming_is_recovered_after_the_lease(): void
    {
        $account = $this->qrAccount();
        $this->delayedFlow($account);
        $this->start($account);
        $this->travel(5)->minutes();

        // What a worker leaves behind if it is killed right after its claim: lease set, attempt counted, nothing sent.
        WhatsAppFlowSession::whereKey($this->flowSession()->id)->update([
            'wait_until' => now()->addSeconds(WhatsAppJourneyEngine::RESUME_LEASE_SECONDS),
            'attempts' => 1,
        ]);

        $this->assertSame('skipped', $this->resume(), 'another worker must not take a leased row');

        $this->travel(WhatsAppJourneyEngine::RESUME_LEASE_SECONDS + 1)->seconds();
        $this->assertSame(WhatsAppFlowSession::STATUS_COMPLETED, $this->resume());
        $this->assertSame(['Before', 'First', 'Second'], $this->sent($account));
    }

    public function test_resuming_needs_nothing_but_the_database_row(): void
    {
        $account = $this->qrAccount();
        $this->delayedFlow($account);
        $this->start($account);

        // What an application/worker restart loses: every resolved instance, the cache, any queued job.
        $this->app->forgetInstance(WhatsAppJourneyEngine::class);
        \Illuminate\Support\Facades\Cache::flush();
        DB::table('jobs')->delete();
        $this->travel(5)->minutes();

        // A brand-new engine and a fresh scan pick the run up from the row alone.
        Artisan::call('journeys:resume-due');
        $this->assertSame(1, DB::table('jobs')->count());
        $this->assertSame(WhatsAppFlowSession::STATUS_COMPLETED, (new WhatsAppJourneyEngine())->resumeDueSession(WhatsAppFlowSession::sole()->id));
        $this->assertSame(['Before', 'First', 'Second'], $this->sent($account));
    }

    // ------------------------------------------------------------------ retry / failure

    public function test_a_provider_failure_is_retried_from_the_failed_step_without_resending_earlier_ones(): void
    {
        $account = $this->qrAccount();
        $this->delayedFlow($account);
        $this->start($account);
        $this->travel(5)->minutes();
        $this->freezeSecond();
        $this->failing = true;
        $this->failOn = 'Second';

        $this->assertSame('retrying', $this->resume());
        $s = $this->flowSession();
        $this->assertSame(WhatsAppFlowSession::STATUS_WAITING, $s->status);
        $this->assertSame('m1', $s->current_node_id, 'checkpointed after "First" went out');
        $this->assertSame(1, $s->attempts);
        $this->assertStringContainsString('Engine offline', $s->last_error);
        $this->assertTrue($s->wait_until->equalTo(now()->addSeconds(WhatsAppJourneyEngine::RETRY_BACKOFF_SECONDS)));

        $this->failing = false;
        $this->assertSame('skipped', $this->resume(), 'backoff is respected');
        $this->travel(WhatsAppJourneyEngine::RETRY_BACKOFF_SECONDS)->seconds();
        $this->assertSame(WhatsAppFlowSession::STATUS_COMPLETED, $this->resume());

        $this->assertSame(['Before', 'First', 'Second'], $this->sent($account), '"First" was sent exactly once');
        $s = $this->flowSession();
        $this->assertNull($s->last_error);
        $this->assertSame(0, $s->attempts);
    }

    public function test_a_step_that_keeps_failing_ends_in_failed_with_the_error_captured(): void
    {
        $account = $this->qrAccount();
        $this->delayedFlow($account);
        $this->start($account);
        $this->travel(5)->minutes();
        $this->failing = true;
        $this->failOn = 'First';

        $outcomes = [];
        for ($i = 0; $i < WhatsAppJourneyEngine::MAX_RESUME_ATTEMPTS; $i++) {
            $outcomes[] = $this->resume();
            $this->travel(1)->hours();
        }

        $this->assertSame(['retrying', 'retrying', WhatsAppFlowSession::STATUS_FAILED], $outcomes);
        $s = $this->flowSession();
        $this->assertSame(WhatsAppFlowSession::STATUS_FAILED, $s->status);
        $this->assertNull($s->wait_until);
        $this->assertStringContainsString("Node 'm0'", $s->last_error);
        $this->assertStringContainsString('Engine offline', $s->last_error);
        $this->assertSame('skipped', $this->resume(), 'a failed session is never picked up again');
        $this->assertSame(['Before'], $this->sent($account));
    }

    public function test_an_exhausted_quota_on_resume_is_a_captured_failure_not_a_silent_skip(): void
    {
        $account = $this->qrAccount();
        $this->delayedFlow($account);
        $this->start($account);
        $sub = $account->currentSubscription()->first();
        $sub->forceFill(['used_messages' => $sub->total_allocated_messages])->save();
        $this->travel(5)->minutes();

        $this->assertSame('retrying', $this->resume());
        $this->assertNotNull($this->flowSession()->last_error);
        $this->assertSame(['Before'], $this->sent($account));
    }

    /**
     * Phase 7 Task 2 changed this deliberately. It used to assert FAILED:
     * deleting the delay node from the live journey broke the waiting run.
     * Sessions are now pinned to an immutable version, so the edit creates
     * version 2 and the waiting run finishes on version 1, untouched.
     */
    public function test_a_node_removed_from_the_journey_while_waiting_does_not_affect_the_pinned_run(): void
    {
        $account = $this->qrAccount();
        $flow = $this->delayedFlow($account);
        $this->start($account);
        $graph = $flow->graph_data;
        $graph['nodes'] = array_values(array_filter($graph['nodes'], fn ($n) => $n['id'] !== 'd'));
        $flow->forceFill(['graph_data' => $graph])->save();
        $this->travel(5)->minutes();

        $this->assertSame(WhatsAppFlowSession::STATUS_COMPLETED, $this->resume());
        $this->assertSame(['Before', 'First', 'Second'], $this->sent($account));
    }

    /** The defensive path is kept: a pinned graph that is somehow missing the node still fails cleanly. */
    public function test_a_pinned_version_missing_the_waiting_node_fails_the_session(): void
    {
        $account = $this->qrAccount();
        $this->delayedFlow($account);
        $this->start($account);
        $versionId = $this->flowSession()->flow_version_id;
        // Corrupt the row below the model (versions are immutable through Eloquent).
        DB::table('whatsapp_flow_versions')->where('id', $versionId)
            ->update(['graph_data' => json_encode(['nodes' => [['id' => 't', 'type' => 'trigger', 'data' => []]], 'edges' => []])]);
        $this->travel(5)->minutes();

        $this->assertSame(WhatsAppFlowSession::STATUS_FAILED, $this->resume());
        $this->assertStringContainsString("'d'", $this->flowSession()->last_error);
    }

    // ------------------------------------------------------------------ pause (flow toggle)

    public function test_a_deactivated_flow_holds_its_waiting_sessions_until_reactivated(): void
    {
        Queue::fake();
        $account = $this->qrAccount();
        $flow = $this->delayedFlow($account);
        $this->start($account);
        $flow->forceFill(['is_active' => false])->save();
        $this->travel(10)->minutes();

        Artisan::call('journeys:resume-due');
        Queue::assertNothingPushed();
        $this->assertSame('held', $this->resume(), 'a job already queued before the pause holds too');
        $s = $this->flowSession();
        $this->assertSame(WhatsAppFlowSession::STATUS_WAITING, $s->status);
        $this->assertSame(0, $s->attempts, 'a hold does not spend an attempt');

        $flow->forceFill(['is_active' => true])->save();
        Artisan::call('journeys:resume-due');
        Queue::assertPushed(ResumeJourneySessionJob::class, 1);
        $this->assertSame(WhatsAppFlowSession::STATUS_COMPLETED, $this->resume());
    }

    // ------------------------------------------------------------------ cancellation

    public function test_a_waiting_session_can_be_cancelled_and_is_never_resumed(): void
    {
        $account = $this->qrAccount();
        $this->delayedFlow($account);
        $this->start($account);
        $s = $this->flowSession();

        $this->actingAs($this->user($account))->postJson($this->cancelUrl($s))
            ->assertOk()->assertJsonPath('data.status', WhatsAppFlowSession::STATUS_CANCELLED);

        $s->refresh();
        $this->assertSame(WhatsAppFlowSession::STATUS_CANCELLED, $s->status);
        $this->assertNull($s->wait_until);

        $this->travel(1)->hours();
        $this->assertSame('skipped', $this->resume());
        $this->assertSame(['Before'], $this->sent($account));

        $this->actingAs($this->user($account))->postJson($this->cancelUrl($s))->assertStatus(409);
    }

    public function test_a_session_awaiting_a_reply_can_be_cancelled_and_the_reply_no_longer_continues_it(): void
    {
        $account = $this->qrAccount();
        WhatsAppFlow::create([
            'account_id' => $account->id, 'name' => 'Q', 'trigger_type' => 'keyword', 'trigger_value' => 'join', 'is_active' => true,
            'graph_data' => [
                'nodes' => [['id' => 't', 'type' => 'trigger', 'data' => []], ['id' => 'q', 'type' => 'question', 'data' => ['prompt_text' => 'Name?', 'variable_name' => 'name', 'input_type' => 'text']], ['id' => 'm', 'type' => 'message', 'data' => ['text' => 'Hi']]],
                'edges' => [['id' => 'a', 'source' => 't', 'target' => 'q'], ['id' => 'b', 'source' => 'q', 'target' => 'm']],
            ],
        ]);
        $this->start($account);

        $this->actingAs($this->user($account))->postJson($this->cancelUrl($this->flowSession()))->assertOk();
        $this->start($account, 'Meera');

        $this->assertSame(WhatsAppFlowSession::STATUS_CANCELLED, $this->flowSession()->status);
        $this->assertSame(['Name?'], $this->sent($account));
    }

    public function test_a_cancellation_during_a_resumed_run_stops_it_before_the_next_node(): void
    {
        $account = $this->qrAccount();
        $this->delayedFlow($account);
        $this->start($account);
        $this->travel(5)->minutes();
        $id = $this->flowSession()->id;

        // The tenant cancels while "First" is being sent.
        Http::fake(function (HttpRequest $request) use ($id) {
            if (str_contains($request->body(), 'First')) {
                app(WhatsAppJourneyEngine::class)->cancelSession(WhatsAppFlowSession::findOrFail($id));
            }

            return Http::response(['success' => true, 'message_id' => 'QR_OK'], 200);
        });

        $this->assertSame(WhatsAppFlowSession::STATUS_CANCELLED, $this->resume());
        $this->assertSame(WhatsAppFlowSession::STATUS_CANCELLED, $this->flowSession()->status);
        $this->assertSame(['Before', 'First'], $this->sent($account));
    }

    public function test_cancel_requires_the_flow_edit_permission(): void
    {
        $account = $this->qrAccount();
        $this->delayedFlow($account);
        $this->start($account);

        $this->actingAs($this->user($account, 'user'))->postJson($this->cancelUrl($this->flowSession()))->assertForbidden();
        $this->assertSame(WhatsAppFlowSession::STATUS_WAITING, $this->flowSession()->status);
    }

    // ------------------------------------------------------------------ tenant isolation

    public function test_another_tenant_cannot_cancel_or_see_a_session(): void
    {
        $a = $this->qrAccount();
        $b = $this->qrAccount();
        $this->delayedFlow($a);
        $this->delayedFlow($b);
        $this->start($a);
        $sa = WhatsAppFlowSession::where('account_id', $a->id)->sole();
        $sb = WhatsAppFlowSession::where('account_id', $b->id)->first();
        $this->assertNull($sb, 'B has no session: its flow was never triggered');

        $bAdmin = $this->user($b);
        $this->actingAs($bAdmin)->postJson($this->cancelUrl($sa))->assertNotFound();
        // Pointing B's own flow at A's session id does not reach it either.
        $bFlow = WhatsAppFlow::where('account_id', $b->id)->sole();
        $this->actingAs($bAdmin)->postJson("/api/whatsapp/flows/{$bFlow->id}/sessions/{$sa->id}/cancel")->assertNotFound();
        // A client admin cannot switch tenant with ?account_id= either.
        $this->actingAs($bAdmin)->postJson($this->cancelUrl($sa).'?account_id='.$a->id)->assertNotFound();

        $this->assertSame(WhatsAppFlowSession::STATUS_WAITING, $sa->fresh()->status);
    }

    public function test_an_agent_can_cancel_for_its_own_sub_client_but_not_for_a_stranger(): void
    {
        $agent = $this->qrAccount(fn ($f) => $f->agent());
        $sub = $this->qrAccount(fn ($f) => $f->client($agent));
        $stranger = $this->qrAccount();
        $this->delayedFlow($sub);
        $this->delayedFlow($stranger);
        $this->start($sub);
        $this->start($stranger);
        $mine = WhatsAppFlowSession::where('account_id', $sub->id)->sole();
        $theirs = WhatsAppFlowSession::where('account_id', $stranger->id)->sole();
        $agentUser = $this->user($agent, null, ['manage-chatbot']);

        $this->actingAs($agentUser)->postJson($this->cancelUrl($theirs).'?account_id='.$stranger->id)->assertNotFound();
        $this->actingAs($agentUser)->postJson($this->cancelUrl($theirs).'?account_id='.$sub->id)->assertNotFound();
        $this->actingAs($agentUser)->postJson($this->cancelUrl($mine).'?account_id='.$sub->id)->assertOk();

        $this->assertSame(WhatsAppFlowSession::STATUS_CANCELLED, $mine->fresh()->status);
        $this->assertSame(WhatsAppFlowSession::STATUS_WAITING, $theirs->fresh()->status);
    }

    public function test_a_background_resume_only_ever_acts_for_the_sessions_own_account(): void
    {
        $a = $this->qrAccount();
        $b = $this->qrAccount();
        $this->delayedFlow($a, ['A-only']);
        $this->delayedFlow($b, ['B-only']);
        $this->start($a);
        $this->start($b);
        $this->travel(5)->minutes();

        $this->resume(WhatsAppFlowSession::where('account_id', $a->id)->sole());

        $this->assertSame(['Before', 'A-only'], $this->sent($a));
        $this->assertSame(['Before'], $this->sent($b));
        $this->assertSame(WhatsAppFlowSession::STATUS_WAITING, WhatsAppFlowSession::where('account_id', $b->id)->sole()->status);
    }

    public function test_a_session_whose_flow_belongs_to_another_account_is_refused(): void
    {
        $a = $this->qrAccount();
        $b = $this->qrAccount();
        $this->delayedFlow($a);
        $bFlow = $this->delayedFlow($b, ['B secret']);
        $this->start($a);
        // Corrupt row: A's session pointing at B's flow (not reachable through the app; defence in depth).
        WhatsAppFlowSession::query()->update(['flow_id' => $bFlow->id]);
        $this->travel(5)->minutes();

        $this->assertSame(WhatsAppFlowSession::STATUS_FAILED, $this->resume());
        $this->assertSame(['Before'], $this->sent($a));
        $this->assertSame([], $this->sent($b));
    }
}
