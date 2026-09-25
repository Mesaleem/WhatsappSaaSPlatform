<?php

namespace Tests\Feature;

use App\Jobs\ResumeJourneySessionJob;
use App\Models\Account;
use App\Models\AccountEntitlement;
use App\Models\Capability;
use App\Models\CrmLead;
use App\Models\InboundMessageEvent;
use App\Models\Invoice;
use App\Models\JourneyExecutionEvent as Ev;
use App\Models\Lead;
use App\Models\MessageDispatchLog;
use App\Models\SocialProviderConfig;
use App\Models\Subscription;
use App\Models\User;
use App\Models\WhatsAppFlow;
use App\Models\WhatsAppFlowSession;
use App\Models\WhatsAppFlowVersion;
use App\Models\WhatsAppSession;
use App\Services\Billing\InvoiceCreditService;
use App\Services\Chatbot\ChatbotEngineService;
use App\Services\Crm\CaptureLeadLinker;
use App\Services\WhatsApp\WhatsAppJourneyEngine;
use App\Support\PlanCatalog;
use Database\Seeders\Phase1FoundationSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Phase 7 Task 9 — production hardening of the Journey execution system:
 * the session state machine, crash-window recovery (including the new
 * interrupted-run recovery), retry classification, scheduler/job safety,
 * stale data, API IDOR, both provider boundaries, history consistency and
 * query bounds. Real cross-process concurrency on MariaDB is exercised by
 * tests/Probes/journey_concurrency_probe.php (PHPUnit wraps each test in a
 * transaction no other process can see).
 */
class JourneyProductionHardeningTest extends TestCase
{
    use RefreshDatabase;

    private const PHONE = '919812345678';

    private const PHONE_ID = '109876543210009';

    private const SECRET = 'meta_app_secret_task9';

    private const INTERNAL = 'internal-secret-task9';

    private const BASE = '/api/whatsapp/flows';

    /** @var list<string> texts / media URLs the fake provider refuses */
    private array $fail = [];

    /** @var list<array<string, mixed>> */
    private array $calls = [];

    /** @var (\Closure(): void)|null runs inside the next provider call */
    private ?\Closure $during = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        $this->seed(Phase1FoundationSeeder::class);
        config(['services.qr_engine.internal_secret' => self::INTERNAL]);
        SocialProviderConfig::create(['provider' => 'meta', 'client_id' => 'app', 'client_secret' => self::SECRET, 'is_active' => true]);

        Http::fake(function (HttpRequest $request) {
            $data = $request->data();
            $this->calls[] = ['url' => $request->url()] + $data;

            if ($this->during) {
                $during = $this->during;
                $this->during = null;
                $during();
            }

            $keys = [$data['message'] ?? null, $data['media_url'] ?? null, $data['text']['body'] ?? null, $data['image']['link'] ?? null];
            foreach ($this->fail as $bad) {
                if (in_array($bad, $keys, true)) {
                    return str_contains($request->url(), 'graph.facebook.com')
                        ? Http::response(['error' => ['message' => 'Engine offline']], 500)
                        : Http::response(['success' => false, 'error' => 'Engine offline'], 200);
                }
            }

            return str_contains($request->url(), 'graph.facebook.com')
                ? Http::response(['messages' => [['id' => 'wamid.OUT'.uniqid()]]], 200)
                : Http::response(['success' => true, 'message_id' => 'QR_'.uniqid()], 200);
        });
    }

    // ------------------------------------------------------------------ fixtures

    private function account(string $plan = 'growth', ?callable $factory = null): Account
    {
        $builder = Account::factory();
        if ($factory) {
            $builder = $factory($builder);
        }
        $account = $builder->create();
        $p = PlanCatalog::find($plan);
        $invoice = Invoice::create([
            'account_id' => $account->id, 'invoice_number' => 'INV-'.uniqid(), 'plan_key' => $plan,
            'plan_label' => $p['label'], 'amount' => $p['price'], 'tax_amount' => 0,
            'total_amount' => $p['price'], 'currency' => 'INR', 'payment_gateway' => 'razorpay',
            'gateway_order_id' => 'order_'.uniqid(), 'status' => 'pending',
        ]);
        app(InvoiceCreditService::class)->markPaidAndCreditQuota($invoice->id, 'pay_'.uniqid());
        WhatsAppSession::create(['account_id' => $account->id, 'status' => 'connected'] + ($p['engine_type'] === 'meta' ? [
            'meta_phone_number_id' => self::PHONE_ID, 'meta_waba_id' => '123456789012345',
            'meta_access_token' => 'EAAG_task9_token_0123456789', 'meta_webhook_verify_token' => 'V'.uniqid(),
        ] : []));

        return $account->fresh();
    }

    private function user(Account $account, ?string $role = 'admin', array $permissions = []): User
    {
        $user = User::factory()->create(['account_id' => $account->id, 'is_active' => true]);
        if ($role) {
            $user->assignRole($role);
        }
        foreach ($permissions as $permission) {
            $user->givePermissionTo($permission);
        }

        return $user;
    }

    private function text(string $id, string $text): array
    {
        return ['id' => $id, 'type' => 'text', 'data' => ['text' => $text]];
    }

    private function q(string $id, string $prompt = 'Q?'): array
    {
        return ['id' => $id, 'type' => 'question', 'data' => ['prompt_text' => $prompt, 'variable_name' => 'v', 'input_type' => 'text']];
    }

    private function delay(string $id): array
    {
        return ['id' => $id, 'type' => 'delay', 'data' => ['amount' => 5, 'unit' => 'minutes']];
    }

    /** $edges as [source, target]; default: a chain in node order. */
    private function flow(Account $account, array $nodes, string $keyword = 'go', ?array $edges = null): WhatsAppFlow
    {
        if ($edges === null) {
            $edges = [];
            for ($i = 1; $i < count($nodes); $i++) {
                $edges[] = [$nodes[$i - 1]['id'], $nodes[$i]['id']];
            }
        }
        $all = [['id' => 'e_t', 'source' => 't', 'target' => $nodes[0]['id']]];
        foreach ($edges as $i => $e) {
            $all[] = ['id' => "e{$i}", 'source' => $e[0], 'target' => $e[1]];
        }

        return WhatsAppFlow::create([
            'account_id' => $account->id, 'name' => 'Task 9', 'trigger_type' => 'keyword', 'trigger_value' => $keyword,
            'is_active' => true,
            'graph_data' => ['nodes' => [['id' => 't', 'type' => 'trigger', 'data' => []], ...$nodes], 'edges' => $all],
        ]);
    }

    private function inbound(Account $account, string $text, ?string $key = null, string $phone = self::PHONE): void
    {
        app(ChatbotEngineService::class)->handleInboundMessage($account->id, $phone, $text, null, 'qr', $key);
    }

    private function engine(): WhatsAppJourneyEngine
    {
        return app(WhatsAppJourneyEngine::class);
    }

    private function flowSession(Account $account, string $phone = self::PHONE): WhatsAppFlowSession
    {
        return WhatsAppFlowSession::where('account_id', $account->id)->where('phone_number', $phone)->latest('id')->firstOrFail();
    }

    private function resume(WhatsAppFlowSession $session): string
    {
        return $this->engine()->resumeDueSession($session->id);
    }

    /** @return list<string> */
    private function sent(Account $account, string $phone = self::PHONE): array
    {
        return MessageDispatchLog::where('account_id', $account->id)->where('source', 'journey')->where('status', 'sent')
            ->where('recipient_phone', $phone)->orderBy('id')->pluck('message_preview')->all();
    }

    private function setCapability(Account $account, string $slug, bool $revoked): void
    {
        AccountEntitlement::updateOrCreate(
            ['account_id' => $account->id, 'capability_id' => Capability::where('slug', $slug)->value('id')],
            ['source' => 'manual_grant', 'granted_by_account_id' => null, 'revoked_at' => $revoked ? now() : null],
        );
    }

    private function exhaust(Account $account): void
    {
        Subscription::where('account_id', $account->id)->update(['used_messages' => DB::raw('total_allocated_messages')]);
    }

    /**
     * A run that died mid-way on the immediate path, exactly as the engine
     * leaves it: 'active', checkpointed at $node, run lease still set.
     */
    private function interruptedAt(Account $account, WhatsAppFlow $flow, string $node, array $context = []): WhatsAppFlowSession
    {
        return WhatsAppFlowSession::create([
            'account_id' => $account->id, 'flow_id' => $flow->id, 'flow_version_id' => $flow->published_version_id,
            'phone_number' => self::PHONE, 'status' => WhatsAppFlowSession::STATUS_ACTIVE, 'current_node_id' => $node,
            'context_data' => $context, 'last_interaction_at' => now(),
            'wait_until' => now()->addSeconds(WhatsAppJourneyEngine::RESUME_LEASE_SECONDS),
        ]);
    }

    private function afterLease(): void
    {
        $this->travel(WhatsAppJourneyEngine::RESUME_LEASE_SECONDS + 1)->seconds();
    }

    // ==================================================================
    // 1. State machine
    // ==================================================================

    public function test_the_transition_contract_is_complete_and_terminal_states_have_no_exit(): void
    {
        $all = [
            WhatsAppFlowSession::STATUS_ACTIVE, WhatsAppFlowSession::STATUS_WAITING, WhatsAppFlowSession::STATUS_BLOCKED,
            WhatsAppFlowSession::STATUS_COMPLETED, WhatsAppFlowSession::STATUS_FAILED, WhatsAppFlowSession::STATUS_EXPIRED,
            WhatsAppFlowSession::STATUS_CANCELLED,
        ];
        $this->assertEqualsCanonicalizing($all, array_keys(WhatsAppFlowSession::TRANSITIONS));
        $this->assertEqualsCanonicalizing($all, [...WhatsAppFlowSession::OPEN_STATUSES, ...WhatsAppFlowSession::TERMINAL_STATUSES]);

        foreach (WhatsAppFlowSession::TERMINAL_STATUSES as $terminal) {
            $this->assertSame([], WhatsAppFlowSession::TRANSITIONS[$terminal], $terminal);
        }
        $this->assertFalse(WhatsAppFlowSession::canTransition(WhatsAppFlowSession::STATUS_BLOCKED, WhatsAppFlowSession::STATUS_COMPLETED), 'a blocked session runs nothing, so it cannot complete');
        $this->assertTrue(WhatsAppFlowSession::canTransition(WhatsAppFlowSession::STATUS_BLOCKED, WhatsAppFlowSession::STATUS_CANCELLED));
    }

    public static function terminalStatuses(): array
    {
        return array_combine(WhatsAppFlowSession::TERMINAL_STATUSES, array_map(fn ($s) => [$s], WhatsAppFlowSession::TERMINAL_STATUSES));
    }

    #[DataProvider('terminalStatuses')]
    public function test_an_eloquent_save_can_never_move_a_terminal_session(string $terminal): void
    {
        $account = $this->account();
        $flow = $this->flow($account, [$this->q('q')]);
        $session = $this->interruptedAt($account, $flow, 'q');
        WhatsAppFlowSession::whereKey($session->id)->update(['status' => $terminal]);

        $this->expectException(\LogicException::class);
        $session->fresh()->forceFill(['status' => WhatsAppFlowSession::STATUS_ACTIVE])->save();
    }

    #[DataProvider('terminalStatuses')]
    public function test_no_entry_point_revives_a_terminal_session(string $terminal): void
    {
        $account = $this->account();
        $flow = $this->flow($account, [$this->q('q'), $this->text('x', 'X')]);
        $session = $this->interruptedAt($account, $flow, 'q');
        WhatsAppFlowSession::whereKey($session->id)->update(['status' => $terminal, 'wait_until' => now()->subHour(), 'last_error' => 'kept']);
        $this->afterLease();

        $this->inbound($account, 'answer', 'wamid.late');
        $this->resume($session);
        $this->engine()->restoreEntitledBlockedSessions();
        $this->engine()->recoverInterruptedRuns();
        $this->artisan('journeys:resume-due')->assertSuccessful();
        $cancelled = $this->engine()->cancelSession($session->fresh());

        $session->refresh();
        $this->assertSame([$terminal, 'kept'], [$session->status, $session->last_error]);
        $this->assertFalse($cancelled);
        $this->assertNotContains('X', $this->sent($account));
    }

    // ==================================================================
    // 2. Interrupted immediate runs (crash windows) — recovery
    // ==================================================================

    public function test_a_finished_immediate_run_never_leaves_a_run_lease_behind(): void
    {
        $account = $this->account();
        $this->flow($account, [$this->text('a', 'A'), $this->q('q'), $this->text('b', 'B'), $this->delay('d'), $this->text('c', 'C')]);

        $this->inbound($account, 'go');
        $this->assertSame([WhatsAppFlowSession::STATUS_ACTIVE, 'q', null], [$this->flowSession($account)->status, $this->flowSession($account)->current_node_id, $this->flowSession($account)->wait_until]);

        $this->inbound($account, 'ans');
        $s = $this->flowSession($account);
        $this->assertSame([WhatsAppFlowSession::STATUS_WAITING, 'd'], [$s->status, $s->current_node_id]);
        $this->assertSame(0, $this->engine()->recoverInterruptedRuns(), 'nothing to recover: every run ended cleanly');
    }

    public function test_a_crash_before_the_first_node_resumes_from_the_trigger(): void
    {
        $account = $this->account();
        $flow = $this->flow($account, [$this->text('a', 'A'), $this->text('b', 'B')]);
        $s = $this->interruptedAt($account, $flow, 't');

        $this->assertSame(0, $this->engine()->recoverInterruptedRuns(), 'the lease still holds — the run may still be going');
        $this->afterLease();
        $this->assertSame(1, $this->engine()->recoverInterruptedRuns());
        $this->assertSame(WhatsAppFlowSession::STATUS_COMPLETED, $this->resume($s));
        $this->assertSame(['A', 'B'], $this->sent($account));
    }

    public function test_a_crash_after_a_checkpoint_resumes_at_that_node_without_repeating_earlier_ones(): void
    {
        $account = $this->account();
        $flow = $this->flow($account, [$this->text('a', 'A'), $this->text('b', 'B'), $this->text('c', 'C')]);
        MessageDispatchLog::record($account->id, 'journey', self::PHONE, success: true, messagePreview: 'A');
        $s = $this->interruptedAt($account, $flow, 'b');
        $this->afterLease();

        $this->artisan('journeys:resume-due')->expectsOutputToContain('recovered 1 interrupted')->assertSuccessful();
        $this->resume($s);

        $this->assertSame(['A', 'B', 'C'], $this->sent($account));
        $this->assertSame(WhatsAppFlowSession::STATUS_COMPLETED, $s->fresh()->status);
        $h = Ev::where('session_id', $s->id)->orderBy('id')->get();
        $this->assertSame(['node_failed', 'node_retry_scheduled', 'session_resumed'], $h->take(3)->pluck('event')->all());
        $this->assertSame(['b', 'internal_error', 'scheduler'], [$h[0]->node_id, $h[0]->error_category, $h[0]->source]);
    }

    public function test_a_crash_after_the_provider_accepted_a_message_resends_it_once_at_least_once_not_exactly_once(): void
    {
        $account = $this->account();
        $flow = $this->flow($account, [$this->text('a', 'A'), $this->text('b', 'B')]);
        // The provider accepted A and the dispatch log was written, but the
        // process died before the next checkpoint: the session still says 'a'.
        MessageDispatchLog::record($account->id, 'journey', self::PHONE, success: true, messagePreview: 'A');
        $s = $this->interruptedAt($account, $flow, 'a');
        $this->afterLease();

        $this->engine()->recoverInterruptedRuns();
        $this->resume($s);

        $this->assertSame(['A', 'A', 'B'], $this->sent($account), 'documented limitation: the interrupted node is re-sent; downstream runs once');
        $this->assertSame(WhatsAppFlowSession::STATUS_COMPLETED, $s->fresh()->status);
    }

    public function test_a_crash_after_the_crm_write_does_not_duplicate_the_lead_on_recovery(): void
    {
        $account = $this->account();
        $flow = $this->flow($account, [$this->q('q'), ['id' => 's', 'type' => 'save_lead', 'data' => ['name_variable' => 'v', 'completion_message' => 'Saved']]]);
        $this->inbound($account, 'go');
        $s = $this->flowSession($account);

        // The save_lead ran (lead + CRM lead written) and the process died
        // before completing: simulate by running it, then rewinding the row.
        $this->inbound($account, 'Ada');
        $this->assertSame(1, Lead::count());
        $this->assertSame(1, CrmLead::count());
        WhatsAppFlowSession::whereKey($s->id)->update(['status' => 'active', 'current_node_id' => 's', 'wait_until' => now()]);
        $this->travel(1)->seconds();

        $this->engine()->recoverInterruptedRuns();
        $this->assertSame(WhatsAppFlowSession::STATUS_COMPLETED, $this->resume($s));

        $this->assertSame(1, Lead::count());
        $this->assertSame(1, CrmLead::count());
        $this->assertSame('Ada', Lead::sole()->lead_name);
    }

    public function test_a_question_interrupted_before_its_prompt_is_prompted_once_and_then_awaits_the_reply(): void
    {
        $account = $this->account();
        $flow = $this->flow($account, [$this->q('q', 'Name?'), $this->text('x', 'Hi {{v}}')]);
        $s = $this->interruptedAt($account, $flow, 'q');
        $this->afterLease();

        $this->engine()->recoverInterruptedRuns();
        $this->assertSame(WhatsAppFlowSession::STATUS_ACTIVE, $this->resume($s));
        $this->assertNull($s->fresh()->wait_until, 'awaiting a reply: no lease, never "recovered" again');
        $this->assertSame(0, $this->engine()->recoverInterruptedRuns());

        $this->inbound($account, 'Ada');
        $this->assertSame(['Name?', 'Hi Ada'], $this->sent($account));
    }

    public function test_an_interrupted_run_that_keeps_failing_ends_failed_not_stuck(): void
    {
        $account = $this->account();
        $flow = $this->flow($account, [$this->text('a', 'A')]);
        $this->fail = ['A'];
        $s = $this->interruptedAt($account, $flow, 'a');
        $this->afterLease();

        $this->engine()->recoverInterruptedRuns();
        for ($i = 0; $i < WhatsAppJourneyEngine::MAX_RESUME_ATTEMPTS; $i++) {
            $status = $this->resume($s);
            $this->travel(1)->hours();
        }

        $this->assertSame(WhatsAppFlowSession::STATUS_FAILED, $status);
        $this->assertSame('provider_failure', Ev::where('session_id', $s->id)->where('event', Ev::SESSION_FAILED)->value('error_category'));
    }

    /**
     * P5-7 contract change: a question session is never mistaken for an
     * interrupted run (Task 9 guarantee, kept), but it no longer waits
     * forever — past WhatsAppJourneyEngine::QUESTION_REPLY_TTL_SECONDS it
     * expires (reply_timeout) instead of capturing a much later message.
     */
    public function test_a_session_awaiting_a_reply_is_never_treated_as_stuck_and_expires_only_after_the_reply_window(): void
    {
        $account = $this->account();
        $this->flow($account, [$this->q('q'), $this->text('x', 'X')]);
        $this->inbound($account, 'go');
        Subscription::where('account_id', $account->id)->update(['expires_at' => now()->addYear()]);
        $this->travel(WhatsAppJourneyEngine::QUESTION_REPLY_TTL_SECONDS - 60)->seconds();

        $this->artisan('journeys:resume-due')->assertSuccessful();

        $s = $this->flowSession($account);
        $this->assertSame([WhatsAppFlowSession::STATUS_ACTIVE, 'q'], [$s->status, $s->current_node_id]);

        $this->travel(90)->days();
        $this->artisan('journeys:resume-due')->assertSuccessful();

        $s = $this->flowSession($account);
        $this->assertSame([WhatsAppFlowSession::STATUS_EXPIRED, 'q', null], [$s->status, $s->current_node_id, $s->wait_until], 'expired, never parked as an interrupted run');
        $this->inbound($account, 'still here');
        $this->assertSame(['Q?'], $this->sent($account), 'the late message is not captured as the answer');
    }

    public function test_recovery_is_bounded_per_run(): void
    {
        $account = $this->account();
        $flow = $this->flow($account, [$this->text('a', 'A')]);
        foreach (range(1, 5) as $i) {
            $this->interruptedAt($account, $flow, 'a')->update(['phone_number' => "91980000000{$i}"]);
        }
        $this->afterLease();

        $this->assertSame(2, $this->engine()->recoverInterruptedRuns(2));
        $this->assertSame(3, $this->engine()->recoverInterruptedRuns(10));
        $this->assertSame(0, $this->engine()->recoverInterruptedRuns(10));
    }

    // ==================================================================
    // 3. Races (in-process interleavings; cross-process: the MariaDB probe)
    // ==================================================================

    public function test_a_cancellation_during_an_immediate_run_stops_it_at_the_next_node(): void
    {
        $account = $this->account();
        $this->flow($account, [$this->text('a', 'A'), $this->text('b', 'B'), $this->text('c', 'C')]);
        $this->during = fn () => $this->engine()->cancelSession(WhatsAppFlowSession::where('account_id', $account->id)->sole());

        $this->inbound($account, 'go');

        $this->assertSame(['A'], $this->sent($account), 'B and C never ran (the checkpoint write refused)');
        $this->assertSame(WhatsAppFlowSession::STATUS_CANCELLED, $this->flowSession($account)->status);
    }

    public function test_a_test_run_replacing_the_session_stops_the_run_in_flight(): void
    {
        $account = $this->account();
        $this->flow($account, [$this->delay('d'), $this->text('a', 'A'), $this->text('b', 'B')]);
        $this->inbound($account, 'go');
        $old = $this->flowSession($account);
        $this->travel(5)->minutes();
        $this->during = function () use ($old) {
            WhatsAppFlowSession::whereKey($old->id)->update(['status' => WhatsAppFlowSession::STATUS_EXPIRED]);
        };

        $this->resume($old);

        $this->assertSame(['A'], $this->sent($account));
        $this->assertSame(WhatsAppFlowSession::STATUS_EXPIRED, $old->fresh()->status);
    }

    public function test_entitlement_revoked_during_a_resumed_run_blocks_before_the_next_node_and_restores_there(): void
    {
        $account = $this->account();
        $this->flow($account, [$this->delay('d'), $this->text('a', 'A'), $this->text('b', 'B')]);
        $this->inbound($account, 'go');
        $s = $this->flowSession($account);
        $this->travel(5)->minutes();
        $this->during = fn () => $this->setCapability($account, 'journey_automation', true);

        $this->assertSame(WhatsAppFlowSession::STATUS_BLOCKED, $this->resume($s));
        $this->assertSame(['A'], $this->sent($account));
        $this->assertSame('b', $s->fresh()->current_node_id);

        $this->setCapability($account, 'journey_automation', false);
        $this->engine()->restoreEntitledBlockedSessions();
        $this->assertSame(WhatsAppFlowSession::STATUS_COMPLETED, $this->resume($s->fresh()));
        $this->assertSame(['A', 'B'], $this->sent($account));
    }

    public function test_entitlement_restored_while_a_retry_is_queued_runs_it_once(): void
    {
        $account = $this->account();
        $this->flow($account, [$this->text('a', 'A')]);
        $this->exhaust($account);
        $this->inbound($account, 'go');
        $s = $this->flowSession($account);
        Subscription::where('account_id', $account->id)->update(['used_messages' => 0]);
        $this->setCapability($account, 'journey_automation', true);
        $this->travel(2)->minutes();

        $this->assertSame(WhatsAppFlowSession::STATUS_BLOCKED, $this->resume($s));
        $this->setCapability($account, 'journey_automation', false);
        $this->engine()->restoreEntitledBlockedSessions();
        $this->engine()->restoreEntitledBlockedSessions();

        (new ResumeJourneySessionJob($s->id))->handle($this->engine());
        (new ResumeJourneySessionJob($s->id))->handle($this->engine());
        $this->assertSame(['A'], $this->sent($account));
        $this->assertSame(1, Ev::where('session_id', $s->id)->where('event', Ev::SESSION_RESTORED)->count());
    }

    public function test_a_redelivered_job_and_a_second_scheduler_run_do_nothing_extra(): void
    {
        $account = $this->account();
        $this->flow($account, [$this->delay('d'), $this->text('a', 'A')]);
        $this->inbound($account, 'go');
        $s = $this->flowSession($account);
        $this->travel(5)->minutes();

        Queue::fake();
        $this->artisan('journeys:resume-due')->assertSuccessful();
        $this->artisan('journeys:resume-due')->assertSuccessful();
        Queue::assertPushed(ResumeJourneySessionJob::class, fn ($job) => $job->sessionId === $s->id);

        $job = new ResumeJourneySessionJob($s->id);
        $job->handle($this->engine());
        $job->handle($this->engine());

        $this->assertSame(['A'], $this->sent($account));
        $this->assertSame(1, Ev::where('session_id', $s->id)->where('event', Ev::SESSION_RESUMED)->count());
    }

    public function test_an_inbound_message_for_a_session_being_resumed_does_not_start_a_second_journey(): void
    {
        $account = $this->account();
        $this->flow($account, [$this->delay('d'), $this->text('a', 'A'), $this->q('q'), $this->text('b', 'B')]);
        $this->inbound($account, 'go');
        $s = $this->flowSession($account);
        $this->travel(5)->minutes();
        $this->during = fn () => $this->inbound($account, 'go', 'wamid.mid-resume');

        $this->resume($s);

        $this->assertSame(1, WhatsAppFlowSession::where('account_id', $account->id)->count());
        $this->assertSame(['A', 'Q?'], $this->sent($account));
    }

    public function test_a_manual_test_replaces_every_open_session_of_the_phone_and_is_serialized_with_inbound(): void
    {
        $account = $this->account();
        $flow = $this->flow($account, [$this->delay('d'), $this->text('a', 'A')]);
        $this->inbound($account, 'go');
        $waiting = $this->flowSession($account);
        $admin = $this->user($account);

        $this->actingAs($admin)->postJson(self::BASE."/{$flow->id}/test", ['phone_number' => self::PHONE])->assertOk();

        $this->assertSame(WhatsAppFlowSession::STATUS_EXPIRED, $waiting->fresh()->status, 'a waiting session no longer runs beside the test');
        $this->assertSame(1, WhatsAppFlowSession::where('account_id', $account->id)->whereIn('status', WhatsAppFlowSession::OPEN_STATUSES)->count());
        $this->travel(10)->minutes();
        $this->assertSame('skipped', $this->resume($waiting));

        // Another request holds this conversation: the test is refused, not run beside it.
        config(['journeys.inbound_lock_wait_ms' => 0]);
        DB::table('journey_conversation_locks')->where('account_id', $account->id)->where('phone_number', self::PHONE)
            ->update(['owner' => 'other-worker', 'locked_until' => now()->addMinute()]);
        $this->actingAs($admin)->postJson(self::BASE."/{$flow->id}/test", ['phone_number' => self::PHONE])
            ->assertStatus(422)->assertJsonPath('message', 'This phone number is in the middle of another conversation right now — try the test again in a moment.');
    }

    // ==================================================================
    // 4. Retry / recovery matrix
    // ==================================================================

    /** [nodes, arrange, expected status after the immediate run, category, retried?] */
    public static function failureMatrix(): array
    {
        $x = ['id' => 'x', 'type' => 'text', 'data' => ['text' => 'X']];

        return [
            'malformed config' => [[['id' => 'x', 'type' => 'text', 'data' => ['text' => '']]], null, 'failed', 'invalid_configuration', false],
            'missing node' => [[['id' => 'x', 'type' => 'text', 'data' => ['text' => 'X']]], 'ghost-edge', 'failed', 'missing_node', false],
            'unsupported node' => [[['id' => 'x', 'type' => 'api', 'data' => []]], null, 'expired', 'unsupported_node', false],
            'quota exhausted' => [[$x], 'quota', 'waiting', 'quota_failure', true],
            'expired plan' => [[$x], 'expired-plan', 'waiting', 'quota_failure', true],
            'suspended account' => [[$x], 'suspended', 'failed', 'entitlement_blocked', false],
            'no subscription' => [[['id' => 'x', 'type' => 'message', 'data' => ['text' => 'X']]], 'no-subscription', 'failed', 'entitlement_blocked', false],
            'whatsapp_send revoked' => [[$x], 'no-send', 'failed', 'entitlement_blocked', false],
            'provider failure' => [[$x], 'provider', 'waiting', 'provider_failure', true],
            'crm failure' => [[['id' => 'x', 'type' => 'save_lead', 'data' => []]], 'crm', 'waiting', 'crm_failure', true],
        ];
    }

    #[DataProvider('failureMatrix')]
    public function test_the_failure_matrix_and_its_classification_never_changes_across_attempts(array $nodes, ?string $arrange, string $status, string $category, bool $retried): void
    {
        $account = $this->account();
        $this->flow($account, $nodes, 'go', $arrange === 'ghost-edge' ? [] : null);
        if ($arrange === 'ghost-edge') {
            $flow = WhatsAppFlow::where('account_id', $account->id)->sole();
            $graph = $flow->graph_data;
            $graph['edges'][] = ['id' => 'ghost', 'source' => 'x', 'target' => 'nowhere'];
            $flow->update(['graph_data' => $graph]);
        }
        match ($arrange) {
            'quota' => $this->exhaust($account),
            'expired-plan' => Subscription::where('account_id', $account->id)->update(['expires_at' => now()->subDay()]),
            'suspended' => $account->forceFill(['status' => 'suspended'])->save(),
            'no-subscription' => Subscription::where('account_id', $account->id)->delete(),
            'no-send' => $this->setCapability($account, 'whatsapp_send', true),
            'provider' => $this->fail = ['X'],
            'crm' => $this->app->instance(CaptureLeadLinker::class, \Mockery::mock(CaptureLeadLinker::class, fn ($m) => $m->shouldReceive('linkQuietly')->andReturnNull()->shouldReceive('accountMayUseCrm')->andReturnTrue())),
            default => null,
        };

        $this->inbound($account, 'go');
        $s = $this->flowSession($account);
        $this->assertSame($status, $s->status);

        if ($retried) {
            for ($i = 0; $i < WhatsAppJourneyEngine::MAX_RESUME_ATTEMPTS; $i++) {
                $this->travel(1)->hours();
                $this->resume($s);
            }
            $this->assertSame(WhatsAppFlowSession::STATUS_FAILED, $s->fresh()->status, 'bounded: never retried forever');
        }

        $categories = Ev::where('session_id', $s->id)->whereNotNull('error_category')->pluck('error_category')->unique()->values()->all();
        $this->assertSame([$category], $categories, 'one classification from first failure to the terminal outcome');
        // Nothing past the failing node ever ran (for the broken edge, X itself is fine and did run).
        $this->assertSame($arrange === 'ghost-edge' ? ['X'] : [], $this->sent($account));
    }

    public function test_the_step_limit_expires_and_cancellation_is_terminal_in_the_matrix(): void
    {
        $account = $this->account();
        $this->flow($account, [$this->text('a', 'A'), $this->text('b', 'B')], 'loop', [['a', 'b'], ['b', 'a']]);
        $this->inbound($account, 'loop');
        $this->assertSame([WhatsAppFlowSession::STATUS_EXPIRED, 'execution_limit'], [$this->flowSession($account)->status, Ev::where('event', Ev::SESSION_EXPIRED)->value('error_category')]);

        $other = $this->account();
        $this->flow($other, [$this->q('q')]);
        $this->inbound($other, 'go');
        $this->assertTrue($this->engine()->cancelSession($this->flowSession($other)));
        $this->assertFalse($this->engine()->cancelSession($this->flowSession($other)));
    }

    // ==================================================================
    // 5. Stale / missing data
    // ==================================================================

    public function test_deleting_a_journey_removes_its_sessions_and_queued_jobs_become_no_ops(): void
    {
        $account = $this->account();
        $flow = $this->flow($account, [$this->delay('d'), $this->text('a', 'A')]);
        $this->inbound($account, 'go');
        $s = $this->flowSession($account);
        $this->actingAs($this->user($account))->deleteJson(self::BASE."/{$flow->id}")->assertOk();
        $this->travel(5)->minutes();

        $this->assertSame('skipped', $this->resume($s));
        $this->assertSame(0, WhatsAppFlowSession::count());
        $this->assertSame([], $this->sent($account));
        $this->assertGreaterThan(0, Ev::where('session_id', $s->id)->count(), 'history outlives the journey');
    }

    public function test_a_deactivated_journey_holds_a_due_session_until_reactivated(): void
    {
        $account = $this->account();
        $flow = $this->flow($account, [$this->delay('d'), $this->text('a', 'A')]);
        $this->inbound($account, 'go');
        $s = $this->flowSession($account);
        $flow->update(['is_active' => false]);
        $this->travel(5)->minutes();

        $this->assertSame('held', $this->resume($s));
        $this->assertSame([WhatsAppFlowSession::STATUS_WAITING, 0], [$s->fresh()->status, $s->fresh()->attempts]);

        $flow->update(['is_active' => true]);
        $this->assertSame(WhatsAppFlowSession::STATUS_COMPLETED, $this->resume($s));
        $this->assertSame(['A'], $this->sent($account));
    }

    public function test_a_held_session_cancelled_meanwhile_stays_cancelled(): void
    {
        $account = $this->account();
        $flow = $this->flow($account, [$this->delay('d'), $this->text('a', 'A')]);
        $this->inbound($account, 'go');
        $s = $this->flowSession($account);
        $flow->update(['is_active' => false]);
        $this->travel(5)->minutes();
        $this->engine()->cancelSession($s);

        $this->assertSame('skipped', $this->resume($s));
        $this->assertSame([WhatsAppFlowSession::STATUS_CANCELLED, null], [$s->fresh()->status, $s->fresh()->wait_until]);
    }

    public function test_a_session_whose_checkpoint_is_missing_from_its_version_fails_as_missing_node(): void
    {
        $account = $this->account();
        $flow = $this->flow($account, [$this->text('a', 'A')]);
        $s = $this->interruptedAt($account, $flow, 'deleted-node');
        $this->afterLease();

        $this->engine()->recoverInterruptedRuns();
        $this->assertSame(WhatsAppFlowSession::STATUS_FAILED, $this->resume($s));
        $this->assertSame('missing_node', Ev::where('session_id', $s->id)->where('event', Ev::SESSION_FAILED)->value('error_category'));
    }

    public function test_a_deleted_account_takes_its_sessions_and_history_with_it(): void
    {
        $account = $this->account();
        $this->flow($account, [$this->delay('d'), $this->text('a', 'A')]);
        $this->inbound($account, 'go');
        $s = $this->flowSession($account);

        DB::table('accounts')->where('id', $account->id)->delete();

        $this->travel(5)->minutes();
        $this->assertSame('skipped', $this->resume($s));
        $this->assertSame(0, Ev::where('account_id', $account->id)->count());
        $this->assertSame(0, WhatsAppFlowVersion::where('flow_id', $s->flow_id)->count());
    }

    // ==================================================================
    // 6. API security regression (IDOR)
    // ==================================================================

    /** @return list<array{0: string, 1: string}> every Journey route, with {flow}/{session}/{version} placeholders */
    private function routes(): array
    {
        return [
            ['GET', '{flow}'], ['GET', '{flow}/sessions'], ['GET', '{flow}/sessions/{session}'],
            ['GET', '{flow}/versions'], ['GET', '{flow}/versions/{version}'],
            ['PUT', '{flow}'], ['POST', '{flow}/toggle'], ['POST', '{flow}/test'],
            ['POST', '{flow}/sessions/{session}/cancel'], ['POST', '{flow}/versions/{version}/publish'],
            ['DELETE', '{flow}'],
        ];
    }

    private function url(string $template, WhatsAppFlow $flow, WhatsAppFlowSession $session, string $query = ''): string
    {
        return self::BASE.'/'.strtr($template, ['{flow}' => $flow->id, '{session}' => $session->id, '{version}' => $flow->published_version_id]).$query;
    }

    private function body(): array
    {
        return ['phone_number' => '919800000055', 'name' => 'X', 'trigger_type' => 'keyword', 'trigger_value' => 'x', 'is_active' => true,
            'graph_data' => ['nodes' => [['id' => 't', 'type' => 'trigger', 'data' => []]], 'edges' => []]];
    }

    public function test_every_journey_route_refuses_another_tenants_ids(): void
    {
        $victim = $this->account();
        $vFlow = $this->flow($victim, [$this->q('q')]);
        $this->inbound($victim, 'go');
        $vSession = $this->flowSession($victim);
        $attacker = $this->account();
        $aFlow = $this->flow($attacker, [$this->q('q')]);
        $this->inbound($attacker, 'go', null, '919800000077');
        $aSession = $this->flowSession($attacker, '919800000077');
        $admin = $this->user($attacker);

        foreach ($this->routes() as [$method, $template]) {
            // Their journey, their session, their version.
            $this->actingAs($admin)->json($method, $this->url($template, $vFlow, $vSession), $this->body())->assertNotFound();
            // Their journey addressed with a forged ?account_id=.
            $this->actingAs($admin)->json($method, $this->url($template, $vFlow, $vSession, "?account_id={$victim->id}"), $this->body())->assertNotFound();
            // My journey, their session / version.
            if (str_contains($template, '{session}') || str_contains($template, '{version}')) {
                $mixed = self::BASE.'/'.strtr($template, ['{flow}' => $aFlow->id, '{session}' => $vSession->id, '{version}' => $vFlow->published_version_id]);
                $this->actingAs($admin)->json($method, $mixed, $this->body())->assertNotFound();
            }
        }

        $this->assertSame(WhatsAppFlowSession::STATUS_ACTIVE, $vSession->fresh()->status);
        $this->assertTrue($vFlow->fresh()->is_active);
        $this->assertSame(['Q?'], $this->sent($victim));
        $this->assertSame(WhatsAppFlowSession::STATUS_ACTIVE, $aSession->fresh()->status);
    }

    public function test_agents_reach_only_their_sub_clients_and_super_admin_only_the_selected_client(): void
    {
        $agent = $this->account('growth', fn ($f) => $f->agent());
        $sub = $this->account('growth', fn ($f) => $f->client($agent));
        $stranger = $this->account();
        $subFlow = $this->flow($sub, [$this->q('q')]);
        $this->inbound($sub, 'go');
        $subSession = $this->flowSession($sub);
        $strangerFlow = $this->flow($stranger, [$this->q('q')]);
        $this->inbound($stranger, 'go', null, '919800000066');
        $strangerSession = $this->flowSession($stranger, '919800000066');
        $agentUser = $this->user($agent, null, ['manage-chatbot']);
        $sa = User::factory()->create(['account_id' => Account::factory()->create()->id, 'is_active' => true]);
        $sa->assignRole('super_admin');

        foreach ([['GET', '{flow}/sessions/{session}'], ['GET', '{flow}/versions'], ['POST', '{flow}/sessions/{session}/cancel']] as [$method, $template]) {
            $this->actingAs($agentUser)->json($method, $this->url($template, $strangerFlow, $strangerSession, "?account_id={$stranger->id}"))->assertNotFound();
            $this->actingAs($sa)->json($method, $this->url($template, $subFlow, $subSession, "?account_id={$stranger->id}"))->assertNotFound();
            $this->actingAs($sa)->json($method, $this->url($template, $subFlow, $subSession))->assertStatus(422);
        }

        $this->actingAs($agentUser)->getJson($this->url('{flow}/sessions/{session}', $subFlow, $subSession, "?account_id={$sub->id}"))->assertOk();
        $this->actingAs($sa)->getJson($this->url('{flow}/sessions/{session}', $subFlow, $subSession, "?account_id={$sub->id}"))->assertOk();
        $this->assertSame(WhatsAppFlowSession::STATUS_ACTIVE, $strangerSession->fresh()->status);
    }

    public function test_module_capability_and_permission_gates_cover_every_route(): void
    {
        $account = $this->account();
        $flow = $this->flow($account, [$this->q('q')]);
        $this->inbound($account, 'go');
        $session = $this->flowSession($account);
        $plain = $this->user($account, 'user');
        $admin = $this->user($account);

        foreach ($this->routes() as [$method, $template]) {
            $this->actingAs($plain)->json($method, $this->url($template, $flow, $session), $this->body())->assertForbidden();
        }

        $this->setCapability($account, 'journey_automation', true);
        foreach ($this->routes() as [$method, $template]) {
            $this->actingAs($admin)->json($method, $this->url($template, $flow, $session), $this->body())->assertStatus(403)->assertJsonPath('error_code', 'CAPABILITY_NOT_ENTITLED');
        }
        $this->setCapability($account, 'journey_automation', false);

        $account->forceFill(['allowed_modules' => array_values(array_diff(Account::MODULES, ['chatbot']))])->save();
        foreach ($this->routes() as [$method, $template]) {
            $this->actingAs($admin)->json($method, $this->url($template, $flow, $session), $this->body())->assertStatus(403)->assertJsonPath('error_code', 'MODULE_DISABLED');
        }

        $this->assertSame(WhatsAppFlowSession::STATUS_ACTIVE, $session->fresh()->status);
    }

    // ==================================================================
    // 7. Provider boundaries (real inbound endpoints)
    // ==================================================================

    private function qrInbound(Account $account, string $text, string $messageId)
    {
        return $this->withHeader('X-Internal-Secret', self::INTERNAL)->postJson('/api/internal/whatsapp-inbound', [
            'account_id' => $account->id, 'sender_phone' => self::PHONE, 'message' => $text, 'message_id' => $messageId,
        ]);
    }

    private function metaInbound(string $wamid, string $text)
    {
        $body = json_encode(['object' => 'whatsapp_business_account', 'entry' => [[
            'id' => 'waba', 'changes' => [['field' => 'messages', 'value' => [
                'messaging_product' => 'whatsapp',
                'metadata' => ['display_phone_number' => '919999999999', 'phone_number_id' => self::PHONE_ID],
                'contacts' => [['profile' => ['name' => 'Bob'], 'wa_id' => self::PHONE]],
                'messages' => [['from' => self::PHONE, 'id' => $wamid, 'timestamp' => '1700000000', 'type' => 'text', 'text' => ['body' => $text]]],
            ]]],
        ]]]);

        return $this->call('POST', '/api/webhooks/meta', [], [], [], [
            'CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json',
            'HTTP_X_HUB_SIGNATURE_256' => 'sha256='.hash_hmac('sha256', $body, self::SECRET),
        ], $body);
    }

    public static function providers(): array
    {
        return ['qr' => ['qr'], 'meta' => ['meta']];
    }

    #[DataProvider('providers')]
    public function test_each_provider_runs_text_media_and_questions_with_the_inbound_id_propagated(string $provider): void
    {
        $account = $this->account($provider === 'meta' ? 'business' : 'growth');
        $this->flow($account, [$this->text('a', 'A'), ['id' => 'i', 'type' => 'image', 'data' => ['mediaUrl' => 'https://cdn.example.com/p.jpg', 'caption' => 'Pic']], $this->q('q', 'Name?'), $this->text('b', 'Hi {{v}}')]);
        $in = fn (string $text, string $id) => $provider === 'meta' ? $this->metaInbound($id, $text) : $this->qrInbound($account, $text, $id);

        $in('go', 'wamid.p1')->assertOk();
        $in('go', 'wamid.p1')->assertOk();
        $in('Ada', 'wamid.p2')->assertOk();

        $s = $this->flowSession($account);
        $this->assertSame(WhatsAppFlowSession::STATUS_COMPLETED, $s->status);
        $this->assertSame(['A', 'Pic', 'Name?', 'Hi Ada'], $this->sent($account));
        $outbound = array_values(array_filter($this->calls, fn ($c) => $provider === 'meta' ? str_contains($c['url'], 'graph.facebook.com') : ! str_contains($c['url'], 'graph.facebook.com')));
        $this->assertCount(4, $outbound, 'the redelivered trigger sent nothing');

        $keys = InboundMessageEvent::where('account_id', $account->id)->pluck('event_key', 'id');
        $started = Ev::where('session_id', $s->id)->where('event', Ev::SESSION_STARTED)->sole();
        $reply = Ev::where('session_id', $s->id)->where('event', Ev::REPLY_RECEIVED)->sole();
        $this->assertStringContainsString('wamid.p1', $keys[$started->inbound_event_id]);
        $this->assertStringContainsString('wamid.p2', $keys[$reply->inbound_event_id]);
        $this->assertSame(1, Ev::where('session_id', $s->id)->where('event', Ev::INBOUND_DEDUPLICATED)->count());
    }

    #[DataProvider('providers')]
    public function test_each_provider_retries_quota_and_provider_failures(string $provider): void
    {
        $account = $this->account($provider === 'meta' ? 'business' : 'growth');
        $this->flow($account, [$this->text('a', 'A'), $this->text('b', 'B')]);
        $in = fn (string $text, string $id) => $provider === 'meta' ? $this->metaInbound($id, $text) : $this->qrInbound($account, $text, $id);

        $this->fail = ['A'];
        $in('go', 'wamid.f1')->assertOk();
        $s = $this->flowSession($account);
        $this->assertSame([WhatsAppFlowSession::STATUS_WAITING, 'a'], [$s->status, $s->current_node_id]);
        $this->assertSame('provider_failure', Ev::where('session_id', $s->id)->where('event', Ev::NODE_FAILED)->value('error_category'));

        $this->fail = [];
        $this->exhaust($account);
        $this->travel(2)->minutes();
        $this->assertSame('retrying', $this->resume($s));
        $this->assertSame('quota_failure', Ev::where('session_id', $s->id)->where('event', Ev::NODE_FAILED)->orderByDesc('id')->value('error_category'));

        Subscription::where('account_id', $account->id)->update(['used_messages' => 0]);
        $this->travel(5)->minutes();
        $this->assertSame(WhatsAppFlowSession::STATUS_COMPLETED, $this->resume($s));
        $this->assertSame(['A', 'B'], $this->sent($account));
    }

    // ==================================================================
    // 8. History consistency
    // ==================================================================

    public function test_every_retry_block_and_terminal_event_answers_the_operator_questions(): void
    {
        $account = $this->account();
        $this->flow($account, [$this->text('a', 'A'), $this->delay('d'), $this->text('b', 'SECRET_BODY')]);
        $this->fail = ['A'];
        $this->inbound($account, 'go', 'wamid.h1');
        $s = $this->flowSession($account);
        $this->fail = [];
        $this->travel(2)->minutes();
        $this->resume($s);
        $this->setCapability($account, 'journey_automation', true);
        $this->travel(5)->minutes();
        $this->resume($s);
        $this->setCapability($account, 'journey_automation', false);
        $this->engine()->restoreEntitledBlockedSessions();
        $this->resume($s->fresh());

        $rows = Ev::where('session_id', $s->id)->orderBy('id')->get();
        $this->assertContains(Ev::SESSION_BLOCKED, $rows->pluck('event'));
        $this->assertSame(Ev::SESSION_COMPLETED, $rows->last()->event);

        foreach ($rows->whereIn('event', [Ev::NODE_FAILED, Ev::NODE_RETRY_SCHEDULED, Ev::SESSION_BLOCKED, Ev::SESSION_RESTORED, Ev::SESSION_RESUMED, Ev::SESSION_COMPLETED]) as $e) {
            $this->assertSame($account->id, $e->account_id, $e->event);
            $this->assertSame($s->flow_id, $e->flow_id, $e->event);
            $this->assertSame($s->flow_version_id, $e->flow_version_id, $e->event);
            $this->assertNotNull($e->node_id, $e->event);
            $this->assertNotNull($e->attempt, $e->event);
        }
        $retry = $rows->firstWhere('event', Ev::NODE_RETRY_SCHEDULED);
        $this->assertNotNull($retry->scheduled_for);
        $this->assertSame(InboundMessageEvent::where('event_key', 'wamid.h1')->value('id'), $retry->inbound_event_id);
        $this->assertStringNotContainsString('SECRET_BODY', json_encode(DB::table('journey_execution_events')->get()));
        $this->assertStringNotContainsString('EAAG', json_encode(DB::table('journey_execution_events')->get()));
    }

    // ==================================================================
    // 9. Performance bounds
    // ==================================================================

    private function queriesFor(\Closure $run): int
    {
        $count = 0;
        DB::listen(function () use (&$count) {
            $count++;
        });
        $run();

        return $count;
    }

    public function test_the_cost_of_a_run_grows_linearly_with_its_length_not_quadratically(): void
    {
        $chain = fn (int $n) => array_map(fn ($i) => $this->text("n{$i}", "M{$i}"), range(1, $n));
        $a = $this->account();
        $this->flow($a, $chain(4));
        $b = $this->account();
        $this->flow($b, $chain(20));

        $short = $this->queriesFor(fn () => $this->inbound($a, 'go'));
        $long = $this->queriesFor(fn () => $this->inbound($b, 'go'));
        $perNode = ($long - $short) / 16;

        $this->assertCount(20, $this->sent($b));
        // Measured at Task 9: 9 queries per text node (checkpoint, history ×2,
        // subscription re-read, capability, quota consume, dispatch log).
        $this->assertLessThanOrEqual(12, $perNode, "per-node query cost {$perNode}");
        // Linear: the 20-node run costs about 4-node run + 16 × per-node.
        $this->assertLessThanOrEqual($short + (int) ceil($perNode * 16) + 2, $long);
    }

    public function test_many_due_sessions_are_dispatched_in_bounded_batches(): void
    {
        $account = $this->account();
        $flow = $this->flow($account, [$this->delay('d'), $this->text('a', 'A')]);
        foreach (range(1, 30) as $i) {
            WhatsAppFlowSession::create(['account_id' => $account->id, 'flow_id' => $flow->id, 'flow_version_id' => $flow->published_version_id,
                'phone_number' => '9198000'.str_pad((string) $i, 5, '0', STR_PAD_LEFT), 'status' => 'waiting', 'current_node_id' => 'd',
                'context_data' => [], 'wait_until' => now()->subMinute()]);
        }
        Queue::fake();

        $q10 = $this->queriesFor(fn () => $this->artisan('journeys:resume-due', ['--limit' => 10])->assertSuccessful());
        Queue::assertPushed(ResumeJourneySessionJob::class, 10);
        $q25 = $this->queriesFor(fn () => $this->artisan('journeys:resume-due', ['--limit' => 25])->assertSuccessful());

        $this->assertLessThanOrEqual($q10 + 2, $q25, 'the scan is not one query per session');
    }

    public function test_a_session_with_a_large_history_is_served_in_one_bounded_page(): void
    {
        $account = $this->account();
        $flow = $this->flow($account, [$this->q('q')]);
        $this->inbound($account, 'go');
        $s = $this->flowSession($account);
        $rows = [];
        foreach (range(1, 3000) as $i) {
            $rows[] = ['account_id' => $account->id, 'flow_id' => $flow->id, 'session_id' => $s->id, 'source' => 'inbound', 'event' => 'reply_received', 'node_id' => 'q', 'result' => "r{$i}", 'created_at' => now()];
        }
        foreach (array_chunk($rows, 500) as $chunk) {
            DB::table('journey_execution_events')->insert($chunk);
        }
        $admin = $this->user($account);

        $queries = $this->queriesFor(fn () => $this->actingAs($admin)->getJson(self::BASE."/{$flow->id}/sessions/{$s->id}")->assertOk()->assertJsonCount(200, 'data.history'));
        $this->assertLessThan(40, $queries);
    }
}
