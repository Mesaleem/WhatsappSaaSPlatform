<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\AccountEntitlement;
use App\Models\Capability;
use App\Models\CrmLead;
use App\Models\InboundMessageEvent;
use App\Models\Invoice;
use App\Models\JourneyExecutionEvent as Ev;
use App\Models\Lead;
use App\Models\MessageDispatchLog;
use App\Models\Subscription;
use App\Models\User;
use App\Models\WhatsAppFlow;
use App\Models\WhatsAppFlowSession;
use App\Models\WhatsAppSession;
use App\Services\Billing\InvoiceCreditService;
use App\Services\Chatbot\ChatbotEngineService;
use App\Services\Crm\CaptureLeadLinker;
use App\Services\WhatsApp\JourneyExecutionRecorder;
use App\Services\WhatsApp\WhatsAppJourneyEngine;
use App\Support\PlanCatalog;
use Database\Seeders\Phase1FoundationSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Phase 7 Task 7 — Journey observability: the durable execution history
 * (journey_execution_events), its correlation identifiers, failure
 * categories and retry visibility, the read-only session endpoint, and the
 * opt-in retention command. Everything is driven through the real inbound
 * path, the real scheduler resume and the real HTTP API.
 */
class JourneyObservabilityTest extends TestCase
{
    use RefreshDatabase;

    private const PHONE = '919812345678';

    private const BASE = '/api/whatsapp/flows';

    /** @var list<string> message texts the fake provider refuses */
    private array $fail = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        $this->seed(Phase1FoundationSeeder::class);

        Http::fake(function (HttpRequest $request) {
            if (in_array($request->data()['message'] ?? null, $this->fail, true)) {
                return Http::response(['success' => false, 'error' => 'Engine offline'], 200);
            }

            return Http::response(['success' => true, 'message_id' => 'QR_'.uniqid()], 200);
        });
    }

    // ------------------------------------------------------------------ fixtures

    private function account(?callable $factory = null): Account
    {
        $builder = Account::factory();
        if ($factory) {
            $builder = $factory($builder);
        }
        $account = $builder->create();
        $plan = PlanCatalog::find('growth');
        $invoice = Invoice::create([
            'account_id' => $account->id, 'invoice_number' => 'INV-'.uniqid(), 'plan_key' => 'growth',
            'plan_label' => $plan['label'], 'amount' => $plan['price'], 'tax_amount' => 0,
            'total_amount' => $plan['price'], 'currency' => 'INR', 'payment_gateway' => 'razorpay',
            'gateway_order_id' => 'order_'.uniqid(), 'status' => 'pending',
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
        foreach ($permissions as $permission) {
            $user->givePermissionTo($permission);
        }

        return $user;
    }

    private function superAdmin(): User
    {
        $user = User::factory()->create(['account_id' => Account::factory()->create()->id, 'is_active' => true]);
        $user->assignRole('super_admin');

        return $user;
    }

    private function text(string $id, string $text): array
    {
        return ['id' => $id, 'type' => 'text', 'data' => ['text' => $text]];
    }

    private function q(string $id, string $variable, string $prompt, array $extra = []): array
    {
        return ['id' => $id, 'type' => 'question', 'data' => ['prompt_text' => $prompt, 'variable_name' => $variable, 'input_type' => 'text'] + $extra];
    }

    private function delay(string $id): array
    {
        return ['id' => $id, 'type' => 'delay', 'data' => ['amount' => 5, 'unit' => 'minutes']];
    }

    /** $edges as [source, target, extra?]; default: a chain in node order. */
    private function flow(Account $account, array $nodes, ?array $edges = null, string $keyword = 'go'): WhatsAppFlow
    {
        if ($edges === null) {
            $edges = [];
            for ($i = 1; $i < count($nodes); $i++) {
                $edges[] = [$nodes[$i - 1]['id'], $nodes[$i]['id']];
            }
        }

        $allEdges = [['id' => 'e_t', 'source' => 't', 'target' => $nodes[0]['id']]];
        foreach ($edges as $i => $edge) {
            $allEdges[] = ['id' => "e{$i}", 'source' => $edge[0], 'target' => $edge[1]] + ($edge[2] ?? []);
        }

        return WhatsAppFlow::create([
            'account_id' => $account->id, 'name' => 'Task 7', 'trigger_type' => 'keyword', 'trigger_value' => $keyword,
            'is_active' => true,
            'graph_data' => ['nodes' => [['id' => 't', 'type' => 'trigger', 'data' => []], ...$nodes], 'edges' => $allEdges],
        ]);
    }

    private function inbound(Account $account, string $text, ?string $messageId = null, string $phone = self::PHONE): void
    {
        app(ChatbotEngineService::class)->handleInboundMessage($account->id, $phone, $text, null, 'qr', $messageId);
    }

    private function flowSession(Account $account, string $phone = self::PHONE): WhatsAppFlowSession
    {
        return WhatsAppFlowSession::where('account_id', $account->id)->where('phone_number', $phone)->latest('id')->firstOrFail();
    }

    private function resume(WhatsAppFlowSession $session): string
    {
        return app(WhatsAppJourneyEngine::class)->resumeDueSession($session->id);
    }

    /** @return Collection<int, Ev> */
    private function history(WhatsAppFlowSession $session)
    {
        return Ev::where('session_id', $session->id)->orderBy('id')->get();
    }

    /** @return list<string> "event[:node][:result|category]" */
    private function trail(WhatsAppFlowSession $session): array
    {
        return $this->history($session)->map(fn (Ev $e) => implode(':', array_filter([
            $e->event, $e->node_id, $e->result ?? $e->error_category,
        ], fn ($v) => $v !== null)))->all();
    }

    private function setCapability(Account $account, string $slug, bool $revoked): void
    {
        AccountEntitlement::updateOrCreate(
            ['account_id' => $account->id, 'capability_id' => Capability::where('slug', $slug)->value('id')],
            ['source' => 'manual_grant', 'granted_by_account_id' => null, 'revoked_at' => $revoked ? now() : null],
        );
    }

    // ==================================================================
    // 1. Event creation + correlation on the inbound path
    // ==================================================================

    public function test_a_run_records_start_node_start_success_and_completion_with_every_identifier(): void
    {
        $account = $this->account();
        $flow = $this->flow($account, [$this->text('a', 'Hello')]);

        $this->inbound($account, 'go', 'wamid.first');

        $s = $this->flowSession($account);
        $this->assertSame(['session_started:t:keyword', 'node_started:a', 'node_succeeded:a:sent', 'session_completed:a'], $this->trail($s));

        $inbound = InboundMessageEvent::where('event_key', 'wamid.first')->sole();
        $dispatch = MessageDispatchLog::where('source', 'journey')->sole();
        foreach ($this->history($s) as $e) {
            $this->assertSame([$account->id, $flow->id, $flow->published_version_id, $s->id, $inbound->id, 'inbound'],
                [$e->account_id, $e->flow_id, $e->flow_version_id, $e->session_id, $e->inbound_event_id, $e->source], $e->event);
        }
        $this->assertSame('text', $this->history($s)[1]->node_type);
        $this->assertSame(['dispatch_log_id' => $dispatch->id], $this->history($s)[2]->details, 'action attempt → outbound side effect');
        $this->assertSame(0, $this->history($s)[2]->attempt, 'attempt 0 = the immediate path');
    }

    public function test_questions_record_the_prompt_each_reply_and_its_own_inbound_event(): void
    {
        $account = $this->account();
        $this->flow($account, [$this->q('q', 'n', 'How many?', ['validation' => ['type' => 'number']]), $this->text('a', 'Thanks')]);

        $this->inbound($account, 'go', 'm1');
        $this->inbound($account, 'lots', 'm2');
        $this->inbound($account, '4', 'm3');

        $s = $this->flowSession($account);
        $this->assertSame([
            'session_started:t:keyword', 'node_started:q', 'node_succeeded:q:prompted',
            'reply_received:q:invalid', 'reply_received:q:answered',
            'node_started:a', 'node_succeeded:a:sent', 'session_completed:a',
        ], $this->trail($s));

        $ids = InboundMessageEvent::orderBy('id')->pluck('id', 'event_key');
        $h = $this->history($s);
        $this->assertSame($ids['m1'], $h[0]->inbound_event_id);
        $this->assertSame($ids['m2'], $h[3]->inbound_event_id);
        $this->assertSame($ids['m3'], $h[4]->inbound_event_id);
        $this->assertSame($ids['m3'], $h[7]->inbound_event_id, 'the run the answer triggered carries its message');
        $this->assertNotNull($h[3]->details['dispatch_log_id'] ?? null, 'the re-prompt send is linked');
    }

    public function test_branching_records_the_branch_taken_and_a_dead_end(): void
    {
        $account = $this->account();
        $this->flow($account, [
            $this->q('q', 'n', 'N?'),
            ['id' => 'k', 'type' => 'conditional', 'data' => ['match' => 'all', 'conditions' => [['variable' => 'n', 'operator' => 'greater_than', 'value' => '10']]]],
            $this->text('big', 'BIG'),
        ], [['q', 'k'], ['k', 'big', ['sourceHandle' => 'true']]]);

        $this->inbound($account, 'go');
        $this->inbound($account, '50');
        $this->assertContains('node_succeeded:k:next:big', $this->trail($this->flowSession($account)));

        $this->inbound($account, 'go', null, '919800000002');
        $this->inbound($account, '3', null, '919800000002');
        $trail = $this->trail($this->flowSession($account, '919800000002'));
        $this->assertSame(['node_succeeded:k:dead_end', 'session_completed:k'], array_slice($trail, -2));
    }

    public function test_message_bodies_answers_and_graph_json_are_never_stored(): void
    {
        $account = $this->account();
        $this->flow($account, [$this->q('q', 'secret', 'PROMPT_BODY_X'), $this->text('a', 'BODY_{{ secret }}')]);

        $this->inbound($account, 'go');
        $this->inbound($account, 'ANSWER_Y');

        $dump = json_encode(DB::table('journey_execution_events')->get());
        foreach (['PROMPT_BODY_X', 'BODY_', 'ANSWER_Y', 'graph_data', 'variable_name'] as $needle) {
            $this->assertStringNotContainsString($needle, $dump, $needle);
        }
    }

    // ==================================================================
    // 2. Failures, categories, retries
    // ==================================================================

    public function test_a_provider_failure_is_visible_as_failed_retried_then_succeeded(): void
    {
        $account = $this->account();
        $this->flow($account, [$this->text('a', 'A'), $this->text('b', 'B')]);
        $this->fail = ['B'];

        $this->inbound($account, 'go');

        $s = $this->flowSession($account);
        $this->assertSame(['session_started:t:keyword', 'node_started:a', 'node_succeeded:a:sent', 'node_started:b', 'node_failed:b:provider_failure', 'node_retry_scheduled:b:provider_failure'], $this->trail($s));
        [$failed, $retry] = $this->history($s)->slice(-2)->values()->all();
        $this->assertSame(0, $failed->attempt);
        $this->assertStringContainsString('Engine offline', $failed->error_message);
        $this->assertStringContainsString("Node 'b': message not sent", $failed->error_message);
        $this->assertSame(MessageDispatchLog::where('status', 'failed')->sole()->id, $failed->details['dispatch_log_id']);
        $this->assertEquals($s->wait_until, $retry->scheduled_for, 'next retry time');

        $this->fail = [];
        $this->travel(2)->minutes();
        $this->assertSame(WhatsAppFlowSession::STATUS_COMPLETED, $this->resume($s));

        $tail = $this->history($s)->slice(6)->values();
        $this->assertSame(['session_resumed:b:retry', 'node_started:b', 'node_succeeded:b:sent', 'session_completed:b'], $tail->map(fn (Ev $e) => implode(':', array_filter([$e->event, $e->node_id, $e->result ?? $e->error_category])))->all());
        $this->assertSame(['scheduler', 1], [$tail[0]->source, $tail[0]->attempt]);
        $this->assertNull($tail[0]->inbound_event_id);
    }

    public function test_retries_until_exhaustion_show_every_attempt_and_one_terminal_failure(): void
    {
        $account = $this->account();
        $this->flow($account, [$this->text('a', 'A')]);
        $this->fail = ['A'];
        $this->inbound($account, 'go');
        $s = $this->flowSession($account);

        for ($i = 0; $i < WhatsAppJourneyEngine::MAX_RESUME_ATTEMPTS; $i++) {
            $this->travel(1)->hours();
            $this->resume($s);
        }

        $h = $this->history($s);
        $this->assertSame([0, 1, 2, 3], $h->where('event', Ev::NODE_FAILED)->pluck('attempt')->values()->all());
        $this->assertSame([0, 1, 2], $h->where('event', Ev::NODE_RETRY_SCHEDULED)->pluck('attempt')->values()->all());
        $this->assertSame([1, 2, 3], $h->where('event', Ev::SESSION_RESUMED)->pluck('attempt')->values()->all());
        $terminal = $h->where('event', Ev::SESSION_FAILED)->sole();
        $this->assertSame(['a', 'provider_failure', 3], [$terminal->node_id, $terminal->error_category, $terminal->attempt]);
        $this->assertSame($h->last()->id, $terminal->id);
    }

    public function test_quota_failure_is_categorised(): void
    {
        $account = $this->account();
        // Phase 7 Task 8 made every Journey send classify this the same way
        // (JourneySendGate); JourneyQuotaConsistencyTest covers each node type.
        $this->flow($account, [['id' => 'a', 'type' => 'message', 'data' => ['text' => 'A']]]);
        Subscription::where('account_id', $account->id)->update(['used_messages' => DB::raw('total_allocated_messages')]);

        $this->inbound($account, 'go');

        $this->assertContains('node_failed:a:quota_failure', $this->trail($this->flowSession($account)));
    }

    public function test_a_crm_failure_is_categorised_and_the_retry_links_the_lead_ids(): void
    {
        $account = $this->account();
        $this->flow($account, [['id' => 's', 'type' => 'save_lead', 'data' => []]]);
        $broken = \Mockery::mock(CaptureLeadLinker::class);
        $broken->shouldReceive('linkQuietly')->andReturnNull();
        $broken->shouldReceive('accountMayUseCrm')->andReturnTrue();
        $this->app->instance(CaptureLeadLinker::class, $broken);

        $this->inbound($account, 'go');
        $s = $this->flowSession($account);
        $this->assertContains('node_failed:s:crm_failure', $this->trail($s));
        $this->assertContains('node_retry_scheduled:s:crm_failure', $this->trail($s));

        $this->app->forgetInstance(CaptureLeadLinker::class);
        $this->travel(2)->minutes();
        $this->resume($s);

        $saved = $this->history($s)->firstWhere('result', 'saved');
        $this->assertSame(Lead::sole()->id, $saved->details['lead_id']);
        $this->assertSame(CrmLead::sole()->id, $saved->details['crm_lead_id']);
    }

    public static function terminalConfigs(): array
    {
        return [
            'malformed config' => [['id' => 'x', 'type' => 'text', 'data' => ['text' => '']], 'invalid_configuration', 'session_failed'],
            'node entitlement' => [['id' => 'x', 'type' => 'image', 'data' => ['mediaUrl' => 'https://cdn.example.com/a.png']], 'entitlement_blocked', 'session_failed'],
            'unsupported node' => [['id' => 'x', 'type' => 'api', 'data' => []], 'unsupported_node', 'session_expired'],
        ];
    }

    #[DataProvider('terminalConfigs')]
    public function test_terminal_outcomes_at_a_node_carry_node_type_and_category(array $node, string $category, string $event): void
    {
        $account = $this->account();
        $this->flow($account, [$node]);
        if ($category === 'entitlement_blocked') {
            $this->setCapability($account, 'whatsapp_send', true);
        }

        $this->inbound($account, 'go');

        $s = $this->flowSession($account);
        $last = $this->history($s)->last();
        $this->assertSame([$event, 'x', $node['type'], $category], [$last->event, $last->node_id, $last->node_type, $category === $last->error_category ? $category : $last->error_category]);
        $this->assertSame($s->last_error, $last->error_message, 'the same human-readable reason as the session');
        if ($event === 'session_failed') {
            $this->assertSame(Ev::NODE_FAILED, $this->history($s)->slice(-2)->first()->event);
        }
        $this->assertNotContains(Ev::NODE_STARTED, $this->history($s)->pluck('event')->all(), 'nothing was attempted');
    }

    public function test_an_edge_to_a_missing_node_is_categorised(): void
    {
        $account = $this->account();
        $this->flow($account, [$this->text('a', 'A')], [['a', 'ghost']]);

        $this->inbound($account, 'go');

        $this->assertSame(['node_failed:ghost:missing_node', 'session_failed:ghost:missing_node'], array_slice($this->trail($this->flowSession($account)), -2));
    }

    public function test_the_step_limit_is_recorded_once_as_execution_limit(): void
    {
        $account = $this->account();
        $this->flow($account, [$this->text('a', 'A'), $this->text('b', 'B')], [['a', 'b'], ['b', 'a']]);

        $this->inbound($account, 'go');

        $h = $this->history($this->flowSession($account));
        $expired = $h->where('event', Ev::SESSION_EXPIRED)->sole();
        $this->assertSame('execution_limit', $expired->error_category);
        $this->assertStringContainsString('Stopped after 25 steps', $expired->error_message);
        $this->assertSame(25, $h->where('event', Ev::NODE_SUCCEEDED)->count());
    }

    // ==================================================================
    // 3. Waiting / resume / blocked / restored / cancelled / test
    // ==================================================================

    public function test_a_delay_shows_why_and_until_when_it_waits_and_its_resume(): void
    {
        $account = $this->account();
        $this->flow($account, [$this->delay('d'), $this->text('a', 'A')]);
        $this->inbound($account, 'go');
        $s = $this->flowSession($account);

        $waiting = $this->history($s)->last();
        $this->assertSame(['session_waiting', 'd', 'delay'], [$waiting->event, $waiting->node_id, $waiting->node_type]);
        $this->assertEquals($s->wait_until, $waiting->scheduled_for);

        $this->assertSame('skipped', $this->resume($s));
        $this->assertCount(2, $this->history($s), 'a claim that did not happen records nothing');

        $this->travel(5)->minutes();
        $this->resume($s);
        $this->assertSame(['session_resumed:d:delay_due', 'node_started:a', 'node_succeeded:a:sent', 'session_completed:a'], array_slice($this->trail($s), 2));
    }

    public function test_entitlement_block_and_restore_are_recorded_on_the_scheduler_path(): void
    {
        $account = $this->account();
        $this->flow($account, [$this->delay('d'), $this->text('a', 'A')]);
        $this->inbound($account, 'go');
        $s = $this->flowSession($account);
        $this->travel(5)->minutes();

        $this->setCapability($account, 'journey_automation', true);
        $this->resume($s);
        $this->resume($s->fresh());
        $blocked = $this->history($s)->where('event', Ev::SESSION_BLOCKED);
        $this->assertCount(1, $blocked, 'blocked once, not once per scan');
        $this->assertSame(['entitlement_blocked', 'scheduler'], [$blocked->first()->error_category, $blocked->first()->source]);

        $this->setCapability($account, 'journey_automation', false);
        app(WhatsAppJourneyEngine::class)->restoreEntitledBlockedSessions();
        app(WhatsAppJourneyEngine::class)->restoreEntitledBlockedSessions();
        $restored = $this->history($s)->where('event', Ev::SESSION_RESTORED);
        $this->assertCount(1, $restored);
        $this->assertSame('waiting', $restored->first()->result);

        $this->resume($s->fresh());
        $this->assertSame('session_completed:a', $this->trail($s)[count($this->trail($s)) - 1]);
    }

    public function test_entitlement_block_and_restore_are_recorded_on_the_inbound_path(): void
    {
        $account = $this->account();
        $this->flow($account, [$this->q('q', 'x', 'X?'), $this->text('a', 'A')]);
        $this->inbound($account, 'go');
        $s = $this->flowSession($account);

        $this->setCapability($account, 'journey_automation', true);
        $this->inbound($account, 'hi', 'blocked-msg');
        $this->setCapability($account, 'journey_automation', false);
        $this->inbound($account, 'answer', 'restore-msg');

        $trail = $this->trail($s);
        $this->assertSame(['session_blocked:q:entitlement_blocked', 'session_restored:q:active', 'reply_received:q:answered'], array_slice($trail, 3, 3));
        $restored = $this->history($s)->firstWhere('event', Ev::SESSION_RESTORED);
        $this->assertSame(InboundMessageEvent::where('event_key', 'restore-msg')->value('id'), $restored->inbound_event_id);
    }

    public function test_cancellation_is_recorded_once_by_the_api_and_a_second_cancel_records_nothing(): void
    {
        $account = $this->account();
        $flow = $this->flow($account, [$this->delay('d'), $this->text('a', 'A')]);
        $this->inbound($account, 'go');
        $s = $this->flowSession($account);
        $admin = $this->user($account);

        $this->actingAs($admin)->postJson(self::BASE."/{$flow->id}/sessions/{$s->id}/cancel")->assertOk();
        $this->actingAs($admin)->postJson(self::BASE."/{$flow->id}/sessions/{$s->id}/cancel")->assertStatus(409);
        $this->travel(1)->hours();
        $this->resume($s);

        $cancelled = $this->history($s)->where('event', Ev::SESSION_CANCELLED);
        $this->assertCount(1, $cancelled);
        $this->assertSame(['api', 'cancelled', 'waiting'], [$cancelled->first()->source, $cancelled->first()->error_category, $cancelled->first()->result]);
        $this->assertSame(Ev::SESSION_CANCELLED, $this->history($s)->last()->event, 'nothing ran after it');
    }

    public function test_a_manual_test_run_is_marked_as_such_and_records_the_run_it_replaced(): void
    {
        $account = $this->account();
        $flow = $this->flow($account, [$this->q('q', 'x', 'X?')]);
        $this->inbound($account, 'go');
        $old = $this->flowSession($account);

        $this->actingAs($this->user($account))->postJson(self::BASE."/{$flow->id}/test", ['phone_number' => self::PHONE])->assertOk();

        $new = $this->flowSession($account);
        $this->assertNotSame($old->id, $new->id);
        $this->assertSame('session_expired:q:cancelled', $this->trail($old)[3]);
        $this->assertSame(['session_started:t:manual_test', 'node_started:q', 'node_succeeded:q:prompted'], $this->trail($new));
        $this->assertSame(['test'], $this->history($new)->pluck('source')->unique()->values()->all());
    }

    // ==================================================================
    // 4. Deduplication and version pinning
    // ==================================================================

    public function test_a_duplicate_inbound_is_recorded_against_the_run_it_duplicated_without_repeating_it(): void
    {
        $account = $this->account();
        $this->flow($account, [$this->q('q', 'x', 'X?')]);

        $this->inbound($account, 'go', 'dup');
        $this->inbound($account, 'go', 'dup');
        $this->inbound($account, 'go', 'dup');

        $s = $this->flowSession($account);
        $h = $this->history($s);
        $this->assertSame(1, $h->where('event', Ev::SESSION_STARTED)->count());
        $this->assertSame(1, $h->where('event', Ev::NODE_STARTED)->count(), 'no duplicate execution events');
        $dups = $h->where('event', Ev::INBOUND_DEDUPLICATED);
        $this->assertCount(2, $dups);
        $this->assertSame([InboundMessageEvent::where('event_key', 'dup')->value('id')], $dups->pluck('inbound_event_id')->unique()->values()->all());
        $this->assertSame('qr', $dups->first()->details['provider']);
    }

    public function test_a_duplicate_of_plain_chatbot_traffic_is_not_journey_history(): void
    {
        $account = $this->account();

        $this->inbound($account, 'hello', 'chat-1');
        $this->inbound($account, 'hello', 'chat-1');

        $this->assertSame(0, Ev::count());
    }

    public function test_history_names_the_pinned_version_before_and_after_an_edit(): void
    {
        $account = $this->account();
        $flow = $this->flow($account, [$this->q('q', 'x', 'X?'), $this->text('a', 'A')]);
        $this->inbound($account, 'go');
        $old = $this->flowSession($account);
        $v1 = $flow->published_version_id;

        $graph = $flow->graph_data;
        $graph['nodes'][2]['data']['text'] = 'A2';
        $flow->update(['graph_data' => $graph]);
        $v2 = $flow->fresh()->published_version_id;
        $this->assertNotSame($v1, $v2);

        $this->inbound($account, 'answer');
        $this->inbound($account, 'go', null, '919800000002');

        $this->assertSame([$v1], $this->history($old)->pluck('flow_version_id')->unique()->values()->all());
        $this->assertSame([$v2], $this->history($this->flowSession($account, '919800000002'))->pluck('flow_version_id')->unique()->values()->all());
    }

    // ==================================================================
    // 5. Instrumentation never changes execution
    // ==================================================================

    public function test_a_broken_history_table_never_breaks_a_journey(): void
    {
        $account = $this->account();
        $this->flow($account, [$this->text('a', 'A'), $this->text('b', 'B')]);
        Schema::drop('journey_execution_events');

        $this->inbound($account, 'go');

        $this->assertSame(WhatsAppFlowSession::STATUS_COMPLETED, $this->flowSession($account)->status);
        $this->assertSame(2, MessageDispatchLog::where('status', 'sent')->count());
    }

    public function test_the_recorder_keeps_rows_bounded_and_categories_normalised(): void
    {
        $account = $this->account();
        $this->flow($account, [$this->q('q', 'x', 'X?')]);
        $this->inbound($account, 'go');
        $s = $this->flowSession($account);

        app(JourneyExecutionRecorder::class)->record($s, Ev::NODE_FAILED, [
            'error_category' => 'made_up', 'error_message' => str_repeat('e', 2000), 'result' => str_repeat('r', 200),
            'details' => ['dispatch_log_id' => 5, 'token' => 'SECRET', 'next_node_id' => ['array'], 'lead_id' => 'x'],
            'source' => 'nowhere',
        ]);

        $row = $this->history($s)->last();
        $this->assertSame('internal_error', $row->error_category);
        $this->assertSame(500, mb_strlen($row->error_message));
        $this->assertSame(64, mb_strlen($row->result));
        $this->assertSame(['dispatch_log_id' => 5, 'lead_id' => 'x'], $row->details);
        $this->assertSame('system', $row->source);
        $this->assertSame(Ev::EVENTS, array_values(array_unique(Ev::EVENTS)));
        $this->assertCount(15, Ev::EVENTS);
    }

    // ==================================================================
    // 6. API visibility, tenant isolation, RBAC, Super Admin
    // ==================================================================

    private function failingRun(Account $account): array
    {
        $flow = $this->flow($account, [$this->text('a', 'A'), $this->text('b', 'B')]);
        $this->fail = ['B'];
        $this->inbound($account, 'go', 'wamid.api');
        $this->fail = [];

        return [$flow, $this->flowSession($account)];
    }

    public function test_the_session_endpoint_shows_state_retry_position_and_correlated_history(): void
    {
        $account = $this->account();
        [$flow, $s] = $this->failingRun($account);

        $data = $this->actingAs($this->user($account))->getJson(self::BASE."/{$flow->id}/sessions/{$s->id}")->assertOk()->json('data');

        $this->assertSame(['waiting', 'b', 'provider_failure'], [$data['session']['status'], $data['session']['current_node_id'], $data['session']['error_category']]);
        $this->assertSame(['retrying' => true, 'failed_node_id' => 'b', 'attempt' => 0, 'max_attempts' => 3], array_diff_key($data['session']['retry'], ['next_attempt_at' => 1]));
        $this->assertNotNull($data['session']['retry']['next_attempt_at']);
        $this->assertSame(['session_started', 'node_started', 'node_succeeded', 'node_started', 'node_failed', 'node_retry_scheduled'], array_column($data['history'], 'event'));
        $this->assertSame(['provider' => 'qr', 'event_key' => 'wamid.api'], $data['history'][0]['inbound_event']);
        $this->assertSame($flow->published_version_id, $data['history'][0]['flow_version_id']);
        $this->assertArrayNotHasKey('account_id', $data['history'][0]);

        // Once it succeeds the retry block clears.
        $this->travel(2)->minutes();
        $this->resume($s);
        $done = $this->actingAs($this->user($account))->getJson(self::BASE."/{$flow->id}/sessions/{$s->id}")->json('data');
        $this->assertSame(['completed', null, false], [$done['session']['status'], $done['session']['error_category'], $done['session']['retry']['retrying']]);
        $this->assertSame('session_completed', end($done['history'])['event']);
    }

    public function test_history_is_capped_to_the_most_recent_rows(): void
    {
        $account = $this->account();
        $flow = $this->flow($account, [$this->q('q', 'x', 'X?')]);
        $this->inbound($account, 'go');
        $s = $this->flowSession($account);
        for ($i = 0; $i < 205; $i++) {
            app(JourneyExecutionRecorder::class)->record($s, Ev::REPLY_RECEIVED, ['result' => "r{$i}"]);
        }

        $history = $this->actingAs($this->user($account))->getJson(self::BASE."/{$flow->id}/sessions/{$s->id}")->json('data.history');

        $this->assertCount(200, $history);
        $this->assertSame('r204', end($history)['result'], 'the newest are kept, oldest first');
    }

    public function test_another_tenants_session_or_history_is_never_reachable(): void
    {
        $a = $this->account();
        $b = $this->account();
        [$aFlow, $aSession] = $this->failingRun($a);
        $bFlow = $this->flow($b, [$this->q('q', 'x', 'X?')], null, 'hi');
        $this->inbound($b, 'hi', null, '919800000009');
        $bSession = $this->flowSession($b, '919800000009');
        $bAdmin = $this->user($b);

        $this->actingAs($bAdmin)->getJson(self::BASE."/{$aFlow->id}/sessions/{$aSession->id}")->assertNotFound();
        $this->actingAs($bAdmin)->getJson(self::BASE."/{$bFlow->id}/sessions/{$aSession->id}")->assertNotFound();
        $this->actingAs($bAdmin)->getJson(self::BASE."/{$aFlow->id}/sessions/{$aSession->id}?account_id={$a->id}")->assertNotFound();
        $this->actingAs($bAdmin)->getJson(self::BASE."/{$bFlow->id}/sessions/{$bSession->id}")->assertOk()
            ->assertJsonPath('data.session.id', $bSession->id);

        // A session of another journey of the same tenant is not reachable through this journey.
        $aOther = $this->flow($a, [$this->q('q', 'x', 'X?')], null, 'other');
        $this->actingAs($this->user($a))->getJson(self::BASE."/{$aOther->id}/sessions/{$aSession->id}")->assertNotFound();
        $this->actingAs($this->user($a))->getJson(self::BASE."/{$aFlow->id}/sessions/abc")->assertNotFound();
    }

    public function test_permission_module_and_capability_gates_apply(): void
    {
        $account = $this->account();
        [$flow, $s] = $this->failingRun($account);
        $url = self::BASE."/{$flow->id}/sessions/{$s->id}";

        $this->actingAs($this->user($account, null, ['whatsapp.view']))->getJson($url)->assertOk();
        $this->actingAs($this->user($account, 'user'))->getJson($url)->assertForbidden();

        $this->setCapability($account, 'journey_automation', true);
        $this->actingAs($this->user($account))->getJson($url)->assertStatus(403)->assertJsonPath('error_code', 'CAPABILITY_NOT_ENTITLED');
        $this->setCapability($account, 'journey_automation', false);

        $account->forceFill(['allowed_modules' => array_values(array_diff(Account::MODULES, ['chatbot']))])->save();
        $this->actingAs($this->user($account))->getJson($url)->assertStatus(403)->assertJsonPath('error_code', 'MODULE_DISABLED');
    }

    public function test_an_agent_sees_its_sub_clients_history_but_never_a_strangers(): void
    {
        $agent = $this->account(fn ($f) => $f->agent());
        $sub = $this->account(fn ($f) => $f->client($agent));
        $stranger = $this->account();
        [$subFlow, $subSession] = $this->failingRun($sub);
        $strangerFlow = $this->flow($stranger, [$this->q('q', 'x', 'X?')], null, 'hey');
        $this->inbound($stranger, 'hey', null, '919800000007');
        $strangerSession = $this->flowSession($stranger, '919800000007');
        $agentUser = $this->user($agent, null, ['manage-chatbot']);

        $this->actingAs($agentUser)->getJson(self::BASE."/{$subFlow->id}/sessions/{$subSession->id}?account_id={$sub->id}")
            ->assertOk()->assertJsonPath('data.session.id', $subSession->id);
        $this->actingAs($agentUser)->getJson(self::BASE."/{$strangerFlow->id}/sessions/{$strangerSession->id}?account_id={$stranger->id}")
            ->assertNotFound();
        $this->actingAs($agentUser)->getJson(self::BASE."/{$subFlow->id}/sessions/{$subSession->id}")
            ->assertNotFound();
    }

    public function test_super_admin_reads_only_the_selected_clients_history(): void
    {
        $a = $this->account();
        $b = $this->account();
        [$aFlow, $aSession] = $this->failingRun($a);
        $sa = $this->superAdmin();

        $this->actingAs($sa)->getJson(self::BASE."/{$aFlow->id}/sessions/{$aSession->id}?account_id={$a->id}")
            ->assertOk()->assertJsonPath('data.session.id', $aSession->id);
        $this->actingAs($sa)->getJson(self::BASE."/{$aFlow->id}/sessions/{$aSession->id}?account_id={$b->id}")->assertNotFound();
        $this->actingAs($sa)->getJson(self::BASE."/{$aFlow->id}/sessions/{$aSession->id}")->assertStatus(422);
    }

    // ==================================================================
    // 7. Retention (opt-in, bounded)
    // ==================================================================

    public function test_pruning_is_a_dry_run_unless_forced_and_keeps_live_runs_and_referenced_keys(): void
    {
        $account = $this->account();
        $this->flow($account, [$this->text('a', 'A')], null, 'done');
        $this->flow($account, [$this->q('q', 'x', 'X?')], null, 'open');
        $this->inbound($account, 'done', 'k-done', '919800000001');
        $this->inbound($account, 'open', 'k-open', '919800000002');
        $this->inbound($account, 'hello', 'k-chat', '919800000003');
        $ended = $this->flowSession($account, '919800000001');
        $open = $this->flowSession($account, '919800000002');

        $this->travel(100)->days();
        $this->inbound($account, 'hello', 'k-recent', '919800000004');
        $before = [Ev::count(), InboundMessageEvent::count()];

        $this->artisan('journeys:prune-history')->expectsOutputToContain('dry run')->assertSuccessful();
        $this->assertSame($before, [Ev::count(), InboundMessageEvent::count()], 'a dry run deletes nothing');

        $this->artisan('journeys:prune-history', ['--force' => true, '--batch' => 1])->assertSuccessful();

        $this->assertSame(0, Ev::where('session_id', $ended->id)->count(), 'an ended run past retention is pruned');
        $this->assertSame(3, Ev::where('session_id', $open->id)->count(), 'a live run keeps its history');
        $this->assertEqualsCanonicalizing(['k-open', 'k-recent'], InboundMessageEvent::pluck('event_key')->all(), 'kept: referenced by retained history, and recent');
        $this->assertSame(WhatsAppFlowSession::STATUS_COMPLETED, $ended->fresh()->status, 'sessions are never pruned');
    }

    public function test_pruning_is_bounded_per_run_and_not_scheduled_automatically(): void
    {
        $account = $this->account();
        $this->flow($account, [$this->text('a', 'A')]);
        foreach (range(1, 5) as $i) {
            $this->inbound($account, 'go', "m{$i}", '91980000001'.$i);
        }
        $this->travel(100)->days();
        $total = Ev::count();

        $this->artisan('journeys:prune-history', ['--force' => true, '--batch' => 2, '--max-batches' => 2])->assertSuccessful();
        $this->assertSame($total - 4, Ev::count());

        $scheduled = collect(app(Schedule::class)->events())->map(fn ($e) => (string) $e->command)->implode("\n");
        $this->assertStringContainsString('journeys:resume-due', $scheduled);
        $this->assertStringNotContainsString('journeys:prune-history', $scheduled);
    }
}
