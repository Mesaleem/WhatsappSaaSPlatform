<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\AccountEntitlement;
use App\Models\Capability;
use App\Models\InboundMessageEvent;
use App\Models\Invoice;
use App\Models\JourneyExecutionEvent as Ev;
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
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Phase 7 Task 10 — release readiness. The highest-value guarantees of
 * Tasks 1–9 checked TOGETHER, through the real inbound endpoints, the real
 * scheduler command and the real HTTP API, so a later task can never
 * silently undo an earlier one. Detailed per-task behaviour stays in the
 * per-task suites; the authoritative retry/recovery matrix lives here and
 * in PROJECT_STATE.md §4 and must agree.
 */
class JourneyPhase7ReleaseReadinessTest extends TestCase
{
    use RefreshDatabase;

    private const PHONE = '919812345678';

    private const PHONE_ID = '109876543210010';

    private const SECRET = 'meta_app_secret_task10';

    private const INTERNAL = 'internal-secret-task10';

    private const BASE = '/api/whatsapp/flows';

    /** @var list<string> texts the fake provider refuses */
    private array $fail = [];

    /** @var list<array<string, mixed>> */
    private array $calls = [];

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
            $meta = str_contains($request->url(), 'graph.facebook.com');

            if (array_intersect($this->fail, array_filter([$data['message'] ?? null, $data['text']['body'] ?? null])) !== []) {
                return $meta ? Http::response(['error' => ['message' => 'Engine offline']], 500) : Http::response(['success' => false, 'error' => 'Engine offline'], 200);
            }

            return $meta ? Http::response(['messages' => [['id' => 'wamid.OUT'.uniqid()]]], 200) : Http::response(['success' => true, 'message_id' => 'QR_'.uniqid()], 200);
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
            'meta_access_token' => 'EAAG_task10_token_0123456789', 'meta_webhook_verify_token' => 'V'.uniqid(),
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
            'account_id' => $account->id, 'name' => 'Release', 'trigger_type' => 'keyword', 'trigger_value' => $keyword,
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

    /** One scheduler minute, exactly as production runs it: the command, then the dispatched jobs. */
    private function schedulerTick(): void
    {
        $this->artisan('journeys:resume-due')->assertSuccessful();
        $this->artisan('queue:work', ['connection' => 'database', '--queue' => 'journeys', '--stop-when-empty' => true])->assertSuccessful();
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

    private function interruptedAt(Account $account, WhatsAppFlow $flow, string $node): WhatsAppFlowSession
    {
        return WhatsAppFlowSession::create([
            'account_id' => $account->id, 'flow_id' => $flow->id, 'flow_version_id' => $flow->published_version_id,
            'phone_number' => self::PHONE, 'status' => WhatsAppFlowSession::STATUS_ACTIVE, 'current_node_id' => $node,
            'context_data' => [], 'last_interaction_at' => now(),
            'wait_until' => now()->addSeconds(WhatsAppJourneyEngine::RESUME_LEASE_SECONDS),
        ]);
    }

    // ==================================================================
    // 1. The authoritative retry / recovery matrix (PROJECT_STATE.md §4)
    // ==================================================================

    /** [nodes, arrange, status after the run, category, retried then failed?] */
    public static function matrix(): array
    {
        $x = ['id' => 'x', 'type' => 'text', 'data' => ['text' => 'X']];

        return [
            'invalid configuration → permanent failure' => [[['id' => 'x', 'type' => 'image', 'data' => ['mediaUrl' => 'file:///etc/passwd']]], null, 'failed', 'invalid_configuration', false],
            'missing node → permanent failure' => [[$x], 'ghost-edge', 'failed', 'missing_node', false],
            'unsupported node → permanent (terminal expired)' => [[['id' => 'x', 'type' => 'email', 'data' => []]], null, 'expired', 'unsupported_node', false],
            'provider failure → retry' => [[$x], 'provider', 'waiting', 'provider_failure', true],
            'crm failure → retry' => [[['id' => 'x', 'type' => 'save_lead', 'data' => []]], 'crm', 'waiting', 'crm_failure', true],
            'quota exhausted → retry' => [[$x], 'quota', 'waiting', 'quota_failure', true],
            'expired plan → retry' => [[$x], 'expired-plan', 'waiting', 'quota_failure', true],
            'suspended account → permanent entitlement failure' => [[$x], 'suspended', 'failed', 'entitlement_blocked', false],
            'no subscription → permanent entitlement failure' => [[['id' => 'x', 'type' => 'message', 'data' => ['text' => 'X']]], 'no-subscription', 'failed', 'entitlement_blocked', false],
            'whatsapp_send revoked → permanent entitlement failure' => [[$x], 'no-send', 'failed', 'entitlement_blocked', false],
            'execution limit → expired' => [[$x, ['id' => 'y', 'type' => 'text', 'data' => ['text' => 'Y']]], 'loop', 'expired', 'execution_limit', false],
        ];
    }

    #[DataProvider('matrix')]
    public function test_the_authoritative_failure_matrix(array $nodes, ?string $arrange, string $status, string $category, bool $retried): void
    {
        $account = $this->account();
        $flow = $this->flow($account, $nodes, 'go', $arrange === 'loop' ? [['x', 'y'], ['y', 'x']] : null);
        if ($arrange === 'ghost-edge') {
            $graph = $flow->graph_data;
            $graph['edges'][] = ['id' => 'ghost', 'source' => 'x', 'target' => 'nowhere'];
            $flow->update(['graph_data' => $graph]);
        }
        match ($arrange) {
            'quota' => Subscription::where('account_id', $account->id)->update(['used_messages' => DB::raw('total_allocated_messages')]),
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
            $this->assertNotNull($s->wait_until);
            for ($i = 0; $i < WhatsAppJourneyEngine::MAX_RESUME_ATTEMPTS; $i++) {
                $this->travel(1)->hours();
                $this->schedulerTick();
            }
            $this->assertSame(WhatsAppFlowSession::STATUS_FAILED, $s->fresh()->status, 'bounded retries, then a durable failure');
        }

        $this->assertSame([$category], Ev::where('session_id', $s->id)->whereNotNull('error_category')->pluck('error_category')->unique()->values()->all());
        $this->assertSame($s->fresh()->last_error, Ev::where('session_id', $s->id)->whereIn('event', [Ev::SESSION_FAILED, Ev::SESSION_EXPIRED])->value('error_message'));

        // Terminal → never revived by anything that runs later.
        $final = $s->fresh();
        $this->assertContains($final->status, WhatsAppFlowSession::TERMINAL_STATUSES);
        $this->travel(1)->days();
        $this->schedulerTick();
        $this->inbound($account, 'unrelated reply');
        $this->assertSame([$final->status, $final->last_error, $final->current_node_id], [$s->fresh()->status, $s->fresh()->last_error, $s->fresh()->current_node_id]);
    }

    public function test_journey_or_chatbot_entitlement_revoked_blocks_and_restoration_resumes(): void
    {
        foreach (['capability', 'module'] as $what) {
            $account = $this->account();
            $this->flow($account, [$this->delay('d'), $this->text('a', 'A')]);
            $this->inbound($account, 'go');
            $s = $this->flowSession($account);
            $what === 'capability'
                ? $this->setCapability($account, 'journey_automation', true)
                : $account->forceFill(['allowed_modules' => array_values(array_diff(Account::MODULES, ['chatbot']))])->save();
            $this->travel(5)->minutes();

            $this->schedulerTick();
            $this->assertSame(WhatsAppFlowSession::STATUS_BLOCKED, $s->fresh()->status, $what);
            $this->assertSame([], $this->sent($account), $what);

            $what === 'capability' ? $this->setCapability($account, 'journey_automation', false) : $account->forceFill(['allowed_modules' => null])->save();
            $this->schedulerTick();
            $this->assertSame([WhatsAppFlowSession::STATUS_COMPLETED, ['A']], [$s->fresh()->status, $this->sent($account)], $what);
        }
    }

    public function test_cancellation_is_terminal_and_blocked_restoration_never_revives_it(): void
    {
        $account = $this->account();
        $flow = $this->flow($account, [$this->q('q'), $this->text('x', 'X')]);
        $this->inbound($account, 'go');
        $s = $this->flowSession($account);
        $this->setCapability($account, 'journey_automation', true);
        $this->inbound($account, 'hello');
        $this->assertSame(WhatsAppFlowSession::STATUS_BLOCKED, $s->fresh()->status);

        $this->actingAs($this->user($account))->postJson(self::BASE."/{$flow->id}/sessions/{$s->id}/cancel")->assertStatus(403);
        $this->assertTrue($this->engine()->cancelSession($s->fresh()), 'an operator can cancel a blocked session');
        $this->setCapability($account, 'journey_automation', false);
        $this->schedulerTick();
        $this->inbound($account, 'answer');

        $this->assertSame(WhatsAppFlowSession::STATUS_CANCELLED, $s->fresh()->status);
        $this->assertSame(['Q?'], $this->sent($account));
        $this->assertSame(0, Ev::where('session_id', $s->id)->where('event', Ev::SESSION_RESTORED)->count());
    }

    // ==================================================================
    // 2. Cross-task guarantees
    // ==================================================================

    public function test_version_pinning_survives_edits_and_rollback_and_a_new_session_uses_the_published_version(): void
    {
        $account = $this->account();
        $admin = $this->user($account);
        $flow = $this->flow($account, [$this->q('q'), $this->text('a', 'V1')]);
        $v1 = $flow->published_version_id;
        $this->inbound($account, 'go');
        $old = $this->flowSession($account);

        $graph = $flow->graph_data;
        $graph['nodes'][2]['data']['text'] = 'V2';
        $this->actingAs($admin)->putJson(self::BASE."/{$flow->id}", ['name' => 'R', 'trigger_type' => 'keyword', 'trigger_value' => 'go', 'is_active' => true, 'graph_data' => $graph])->assertOk();

        $this->inbound($account, 'ans');
        $this->assertSame(['Q?', 'V1'], $this->sent($account), 'the running session stayed on v1');

        $this->inbound($account, 'go', null, '919800000002');
        $this->inbound($account, 'ans', null, '919800000002');
        $this->assertSame(['Q?', 'V2'], $this->sent($account, '919800000002'));

        $this->actingAs($admin)->postJson(self::BASE."/{$flow->id}/versions/{$v1}/publish")->assertOk();
        $this->inbound($account, 'go', null, '919800000003');
        $this->inbound($account, 'ans', null, '919800000003');
        $this->assertSame(['Q?', 'V1'], $this->sent($account, '919800000003'), 'rollback = publishing v1 again');
        $this->assertSame([$v1], Ev::where('session_id', $old->id)->pluck('flow_version_id')->unique()->values()->all());
    }

    public function test_duplicate_inbound_and_conversation_serialization(): void
    {
        $account = $this->account();
        $this->flow($account, [$this->q('q'), $this->text('a', 'A')]);
        $this->inbound($account, 'go', 'wamid.one');
        $this->inbound($account, 'go', 'wamid.one');

        config(['journeys.inbound_lock_wait_ms' => 0]);
        DB::table('journey_conversation_locks')->where('account_id', $account->id)->where('phone_number', self::PHONE)
            ->update(['owner' => 'other-worker', 'locked_until' => now()->addMinute()]);
        $this->inbound($account, 'answer', 'wamid.two');
        $this->assertSame(['Q?'], $this->sent($account), 'not processed beside another worker');
        $this->assertSame(0, InboundMessageEvent::where('event_key', 'wamid.two')->count(), 'not claimed, so it can be processed later');

        $this->travel(3)->minutes();
        $this->inbound($account, 'answer', 'wamid.two');
        $this->inbound($account, 'answer', 'wamid.two');

        $this->assertSame(['Q?', 'A'], $this->sent($account));
        $this->assertSame(1, WhatsAppFlowSession::where('account_id', $account->id)->count());
        $this->assertSame(2, Ev::where('account_id', $account->id)->where('event', Ev::INBOUND_DEDUPLICATED)->count());
    }

    public function test_delay_resume_and_quota_retry_through_the_real_scheduler(): void
    {
        $account = $this->account();
        $this->flow($account, [$this->text('a', 'A'), $this->delay('d'), $this->text('b', 'B')]);
        $this->inbound($account, 'go');
        $s = $this->flowSession($account);
        Subscription::where('account_id', $account->id)->update(['used_messages' => DB::raw('total_allocated_messages')]);

        $this->travel(5)->minutes();
        $this->schedulerTick();
        $this->assertSame([WhatsAppFlowSession::STATUS_WAITING, 'b'], [$s->fresh()->status, $s->fresh()->current_node_id]);
        $this->assertStringContainsString('Quota exhausted', $s->fresh()->last_error);

        Subscription::where('account_id', $account->id)->update(['used_messages' => 0]);
        $this->travel(5)->minutes();
        $this->schedulerTick();
        $this->assertSame([WhatsAppFlowSession::STATUS_COMPLETED, ['A', 'B']], [$s->fresh()->status, $this->sent($account)]);
    }

    public function test_a_waiting_session_on_a_disabled_journey_is_held_and_a_reply_to_one_is_ended_cleanly(): void
    {
        $account = $this->account();
        $delayed = $this->flow($account, [$this->delay('d'), $this->text('a', 'A')], 'drip');
        $asking = $this->flow($account, [$this->q('q'), $this->text('b', 'B')], 'ask');
        $this->inbound($account, 'drip', null, '919800000001');
        $this->inbound($account, 'ask', null, '919800000002');
        $delayed->update(['is_active' => false]);
        $asking->update(['is_active' => false]);
        $this->travel(1)->hours();

        $this->schedulerTick();
        $this->inbound($account, 'answer', null, '919800000002');

        $this->assertSame(WhatsAppFlowSession::STATUS_WAITING, $this->flowSession($account, '919800000001')->status, 'held, not failed');
        $this->assertSame(WhatsAppFlowSession::STATUS_EXPIRED, $this->flowSession($account, '919800000002')->status);
        $this->assertSame('flow_unavailable', Ev::where('session_id', $this->flowSession($account, '919800000002')->id)->where('event', Ev::SESSION_EXPIRED)->value('error_category'));

        $delayed->update(['is_active' => true]);
        $this->schedulerTick();
        $this->assertSame(['A'], $this->sent($account, '919800000001'));
    }

    /**
     * P5-7 contract change (was "stays open indefinitely"): a session
     * awaiting the customer's answer is legitimately open for the whole
     * reply window, and closes (reply_timeout) once it has passed.
     */
    public function test_a_customer_waiting_session_stays_open_for_the_reply_window_then_expires(): void
    {
        $account = $this->account();
        $this->flow($account, [$this->q('q'), $this->text('a', 'A')]);
        $this->inbound($account, 'go');
        Subscription::where('account_id', $account->id)->update(['expires_at' => now()->addYears(2)]);
        $this->travel(WhatsAppJourneyEngine::QUESTION_REPLY_TTL_SECONDS - 60)->seconds();

        $this->schedulerTick();
        $this->assertSame(WhatsAppFlowSession::STATUS_ACTIVE, $this->flowSession($account)->status);
        $this->inbound($account, 'in time');
        $this->assertSame(['Q?', 'A'], $this->sent($account));

        $this->flow($account, [$this->q('q2'), $this->text('b', 'B')], 'again');
        $this->inbound($account, 'again');
        $this->travel(365)->days();
        $this->schedulerTick();
        $this->assertSame(WhatsAppFlowSession::STATUS_EXPIRED, $this->flowSession($account)->status);
    }

    // ==================================================================
    // 3. Worker crash recovery, and the two Task 10 fixes
    // ==================================================================

    public function test_an_interrupted_run_is_recovered_by_the_scheduler_without_repeating_earlier_nodes(): void
    {
        $account = $this->account();
        $flow = $this->flow($account, [$this->text('a', 'A'), $this->text('b', 'B')]);
        MessageDispatchLog::record($account->id, 'journey', self::PHONE, success: true, messagePreview: 'A');
        $s = $this->interruptedAt($account, $flow, 'b');

        $this->schedulerTick();
        $this->assertSame(WhatsAppFlowSession::STATUS_ACTIVE, $s->fresh()->status, 'the run lease still holds');
        $this->travel(WhatsAppJourneyEngine::RESUME_LEASE_SECONDS + 1)->seconds();
        $this->schedulerTick();

        $this->assertSame([WhatsAppFlowSession::STATUS_COMPLETED, ['A', 'B']], [$s->fresh()->status, $this->sent($account)]);
    }

    public function test_a_customer_message_arriving_at_an_interrupted_run_no_longer_destroys_its_recovery(): void
    {
        $account = $this->account();
        $flow = $this->flow($account, [$this->text('a', 'A'), $this->text('b', 'B')]);
        $s = $this->interruptedAt($account, $flow, 'b');

        // Before Task 10 this reply expired the run ("interrupted run").
        $this->inbound($account, 'hello?', 'wamid.mid');
        $this->assertSame(WhatsAppFlowSession::STATUS_ACTIVE, $s->fresh()->status);
        $this->assertSame(1, WhatsAppFlowSession::where('account_id', $account->id)->count(), 'no second journey either');

        $this->travel(WhatsAppJourneyEngine::RESUME_LEASE_SECONDS + 1)->seconds();
        $this->schedulerTick();
        $this->assertSame([WhatsAppFlowSession::STATUS_COMPLETED, ['B']], [$s->fresh()->status, $this->sent($account)]);
    }

    public function test_an_interrupted_run_blocked_by_entitlement_returns_to_the_scheduler_on_restoration(): void
    {
        $account = $this->account();
        $flow = $this->flow($account, [$this->text('a', 'A')]);
        $s = $this->interruptedAt($account, $flow, 'a');
        $this->setCapability($account, 'journey_automation', true);

        $this->inbound($account, 'hi');
        $this->assertSame(WhatsAppFlowSession::STATUS_BLOCKED, $s->fresh()->status);
        $this->assertNotNull($s->fresh()->wait_until, 'keeps a timer: it is a run, not a question');

        $this->setCapability($account, 'journey_automation', false);
        $this->schedulerTick();
        $this->assertSame([WhatsAppFlowSession::STATUS_COMPLETED, ['A']], [$s->fresh()->status, $this->sent($account)]);
    }

    // ==================================================================
    // 4. History, security, providers, operations
    // ==================================================================

    public function test_history_is_complete_bounded_and_never_load_bearing(): void
    {
        $account = $this->account();
        $flow = $this->flow($account, [$this->text('a', 'A'), $this->delay('d'), $this->text('b', 'B')]);
        $this->fail = ['A'];
        $this->inbound($account, 'go', 'wamid.h');
        $this->fail = [];
        $s = $this->flowSession($account);
        $this->travel(2)->minutes();
        $this->schedulerTick();
        $this->travel(5)->minutes();
        $this->schedulerTick();

        $events = Ev::where('session_id', $s->id)->orderBy('id')->pluck('event')->all();
        foreach ([Ev::SESSION_STARTED, Ev::NODE_FAILED, Ev::NODE_RETRY_SCHEDULED, Ev::SESSION_RESUMED, Ev::SESSION_WAITING, Ev::SESSION_COMPLETED] as $expected) {
            $this->assertContains($expected, $events);
        }
        $detail = $this->actingAs($this->user($account))->getJson(self::BASE."/{$flow->id}/sessions/{$s->id}")->assertOk()->json('data');
        $this->assertSame(200, $detail['history_limit']);
        $this->assertSame('wamid.h', $detail['history'][0]['inbound_event']['event_key']);

        // A missing history table never stops a journey.
        Schema::drop('journey_execution_events');
        $this->inbound($account, 'go', 'wamid.after', '919800000009');
        $this->assertSame(['A'], $this->sent($account, '919800000009'));
    }

    public function test_pruning_stays_manual_and_the_scheduler_runs_only_the_expected_journey_commands(): void
    {
        $commands = collect(app(Schedule::class)->events())->map(fn ($e) => (string) $e->command)->implode("\n");

        $this->assertStringContainsString('journeys:resume-due', $commands);
        $this->assertStringContainsString('--queue=journeys', $commands);
        $this->assertStringNotContainsString('journeys:prune-history', $commands);
        $this->artisan('journeys:prune-history')->expectsOutputToContain('dry run')->assertSuccessful();
    }

    public function test_every_route_is_server_side_gated_and_refuses_another_tenants_ids(): void
    {
        $victim = $this->account();
        $vFlow = $this->flow($victim, [$this->q('q')]);
        $this->inbound($victim, 'go');
        $vSession = $this->flowSession($victim);
        $attacker = $this->account();
        $this->flow($attacker, [$this->q('q')]);
        $admin = $this->user($attacker);
        $plain = $this->user($victim, 'user');
        $routes = [
            ['GET', ''], ['POST', ''], ['GET', '/{f}'], ['PUT', '/{f}'], ['DELETE', '/{f}'], ['POST', '/{f}/toggle'], ['POST', '/{f}/test'],
            ['GET', '/{f}/sessions'], ['GET', '/{f}/sessions/{s}'], ['POST', '/{f}/sessions/{s}/cancel'],
            ['GET', '/{f}/versions'], ['GET', '/{f}/versions/{v}'], ['POST', '/{f}/versions/{v}/publish'],
        ];
        $body = ['phone_number' => '919800000055', 'name' => 'X', 'trigger_type' => 'keyword', 'trigger_value' => 'x', 'is_active' => true,
            'graph_data' => ['nodes' => [['id' => 't', 'type' => 'trigger', 'data' => []]], 'edges' => []]];

        foreach ($routes as [$method, $path]) {
            $url = self::BASE.strtr($path, ['{f}' => $vFlow->id, '{s}' => $vSession->id, '{v}' => $vFlow->published_version_id]);
            // No permission → 403, whoever owns the ids.
            $this->actingAs($plain)->json($method, $url, $body)->assertForbidden();
            // Someone else's ids, even with a forged ?account_id= → 404 (list/create act on the caller's own account).
            if (str_contains($path, '{f}')) {
                $this->actingAs($admin)->json($method, $url."?account_id={$victim->id}", $body)->assertNotFound();
            }
        }
        $this->actingAs($admin)->getJson(self::BASE."?account_id={$victim->id}")->assertOk()->assertJsonMissing(['id' => $vFlow->id]);

        $this->assertSame([WhatsAppFlowSession::STATUS_ACTIVE, true], [$vSession->fresh()->status, $vFlow->fresh()->is_active]);
        $this->assertSame(1, WhatsAppFlowVersion::where('flow_id', $vFlow->id)->count());
    }

    public function test_agent_and_super_admin_account_selection_rules(): void
    {
        $agent = $this->account('growth', fn ($f) => $f->agent());
        $sub = $this->account('growth', fn ($f) => $f->client($agent));
        $stranger = $this->account();
        $subFlow = $this->flow($sub, [$this->q('q')]);
        $strangerFlow = $this->flow($stranger, [$this->q('q')]);
        $agentUser = $this->user($agent, null, ['manage-chatbot']);
        $sa = User::factory()->create(['account_id' => Account::factory()->create()->id, 'is_active' => true]);
        $sa->assignRole('super_admin');

        $this->actingAs($agentUser)->getJson(self::BASE."/{$subFlow->id}?account_id={$sub->id}")->assertOk();
        $this->actingAs($agentUser)->getJson(self::BASE."/{$strangerFlow->id}?account_id={$stranger->id}")->assertNotFound();
        $this->actingAs($sa)->getJson(self::BASE."/{$subFlow->id}")->assertStatus(422);
        $this->actingAs($sa)->getJson(self::BASE."/{$subFlow->id}?account_id={$sub->id}")->assertOk();
        $this->actingAs($sa)->getJson(self::BASE."/{$subFlow->id}?account_id={$stranger->id}")->assertNotFound();
    }

    public function test_save_time_and_runtime_both_enforce_node_entitlement(): void
    {
        $account = $this->account();
        $admin = $this->user($account);
        $this->setCapability($account, 'whatsapp_send', true);

        $this->actingAs($admin)->postJson(self::BASE, ['name' => 'T', 'trigger_type' => 'keyword', 'trigger_value' => 'go', 'is_active' => true,
            'graph_data' => ['nodes' => [['id' => 't', 'type' => 'trigger', 'data' => []], $this->text('x', 'X')], 'edges' => [['id' => 'e', 'source' => 't', 'target' => 'x']]]])
            ->assertStatus(403)->assertJsonPath('error_code', 'JOURNEY_NODE_NOT_ENTITLED');

        // A journey saved before the revocation is refused again at run time.
        $this->flow($account, [$this->text('x', 'X')]);
        $this->inbound($account, 'go');
        $this->assertSame(WhatsAppFlowSession::STATUS_FAILED, $this->flowSession($account)->status);
        $this->assertSame([], $this->sent($account));
    }

    public static function providers(): array
    {
        return ['QR / Baileys' => ['qr'], 'Meta Cloud API' => ['meta']];
    }

    #[DataProvider('providers')]
    public function test_the_provider_boundary(string $provider): void
    {
        $account = $this->account($provider === 'meta' ? 'business' : 'growth');
        $this->flow($account, [$this->text('a', 'A'), ['id' => 'm', 'type' => 'document', 'data' => ['mediaUrl' => 'https://cdn.example.com/q.pdf', 'filename' => 'q.pdf', 'caption' => 'Doc']], $this->q('q', 'Name?'), $this->text('b', 'B {{v}}')]);
        $send = function (string $text, string $id) use ($account, $provider) {
            if ($provider === 'qr') {
                return $this->withHeader('X-Internal-Secret', self::INTERNAL)->postJson('/api/internal/whatsapp-inbound', [
                    'account_id' => $account->id, 'sender_phone' => self::PHONE, 'message' => $text, 'message_id' => $id,
                ]);
            }
            $body = json_encode(['object' => 'whatsapp_business_account', 'entry' => [['id' => 'waba', 'changes' => [['field' => 'messages', 'value' => [
                'messaging_product' => 'whatsapp', 'metadata' => ['display_phone_number' => '919999999999', 'phone_number_id' => self::PHONE_ID],
                'contacts' => [['profile' => ['name' => 'Bob'], 'wa_id' => self::PHONE]],
                'messages' => [['from' => self::PHONE, 'id' => $id, 'timestamp' => '1700000000', 'type' => 'text', 'text' => ['body' => $text]]],
            ]]]]]]);

            return $this->call('POST', '/api/webhooks/meta', [], [], [], [
                'CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json',
                'HTTP_X_HUB_SIGNATURE_256' => 'sha256='.hash_hmac('sha256', $body, self::SECRET),
            ], $body);
        };

        $this->fail = ['A'];
        $send('go', 'wamid.r1')->assertOk();
        $send('go', 'wamid.r1')->assertOk();
        $s = $this->flowSession($account);
        $this->assertSame([WhatsAppFlowSession::STATUS_WAITING, 'provider_failure'], [$s->status, Ev::where('session_id', $s->id)->where('event', Ev::NODE_FAILED)->value('error_category')]);

        $this->fail = [];
        Subscription::where('account_id', $account->id)->update(['used_messages' => DB::raw('total_allocated_messages')]);
        $this->travel(2)->minutes();
        $this->schedulerTick();
        $this->assertSame('quota_failure', Ev::where('session_id', $s->id)->where('event', Ev::NODE_FAILED)->orderByDesc('id')->value('error_category'));

        Subscription::where('account_id', $account->id)->update(['used_messages' => 0]);
        $this->travel(5)->minutes();
        $this->schedulerTick();
        $send('Ada', 'wamid.r2')->assertOk();

        $this->assertSame(WhatsAppFlowSession::STATUS_COMPLETED, $s->fresh()->status);
        $this->assertSame(['A', 'Doc', 'Name?', 'B Ada'], $this->sent($account));
        $this->assertTrue((bool) MessageDispatchLog::where('message_preview', 'Doc')->where('status', 'sent')->value('has_media'));
        $keys = InboundMessageEvent::where('account_id', $account->id)->pluck('event_key')->implode(',');
        $this->assertStringContainsString('wamid.r1', $keys);
        $this->assertStringContainsString('wamid.r2', $keys);
        $this->assertSame(1, Ev::where('session_id', $s->id)->where('event', Ev::INBOUND_DEDUPLICATED)->count());
        $outbound = array_filter($this->calls, fn ($c) => $provider === 'meta' ? str_contains($c['url'], 'graph.facebook.com') : ! str_contains($c['url'], 'graph.facebook.com'));
        $this->assertNotEmpty($outbound, 'sent through the account\'s own provider');
    }
}
