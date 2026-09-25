<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\AccountEntitlement;
use App\Models\Capability;
use App\Models\ChatbotLog;
use App\Models\CrmCaptureLinkFailure;
use App\Models\CrmLead;
use App\Models\Invoice;
use App\Models\Lead;
use App\Models\MessageDispatchLog;
use App\Models\User;
use App\Models\WhatsAppFlow;
use App\Models\WhatsAppFlowSession;
use App\Models\WhatsAppSession;
use App\Services\Billing\InvoiceCreditService;
use App\Services\Chatbot\ChatbotEngineService;
use App\Services\Crm\CaptureLeadLinker;
use App\Services\WhatsApp\JourneyActionConfig;
use App\Services\WhatsApp\WhatsAppJourneyEngine;
use App\Support\PlanCatalog;
use Database\Seeders\Phase1FoundationSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

/**
 * Phase 7 Task 5 — Journey action execution hardening.
 *
 * Every executable action (message, question, delay, save_lead, and the two
 * branching nodes routing between them) driven through the real inbound path
 * and the real scheduler resume, against the Growth QR plan (journeys + CRM).
 * Sends go through the unified driver, faked at the HTTP boundary; a message
 * text listed in $failTexts is rejected by the "provider".
 */
class JourneyActionExecutionTest extends TestCase
{
    use RefreshDatabase;

    private const PHONE = '919812345678';

    /** @var list<string> texts the fake provider refuses */
    private array $failTexts = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        $this->seed(Phase1FoundationSeeder::class);

        Http::fake(function (HttpRequest $request) {
            foreach ($this->failTexts as $text) {
                if (($request['message'] ?? null) === $text) {
                    return Http::response(['success' => false, 'error' => 'Engine offline'], 200);
                }
            }

            return Http::response(['success' => true, 'message_id' => 'QR_'.uniqid()], 200);
        });
    }

    // ------------------------------------------------------------------ fixtures

    private function account(): Account
    {
        $account = Account::factory()->create();
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

    private function msg(string $id, string $text): array
    {
        return ['id' => $id, 'type' => 'message', 'data' => ['text' => $text]];
    }

    private function q(string $id, string $variable, string $prompt, array $extra = []): array
    {
        return ['id' => $id, 'type' => 'question', 'data' => ['prompt_text' => $prompt, 'variable_name' => $variable, 'input_type' => 'text'] + $extra];
    }

    private function delay(string $id, int $minutes = 5): array
    {
        return ['id' => $id, 'type' => 'delay', 'data' => ['amount' => $minutes, 'unit' => 'minutes']];
    }

    private function save(string $id, array $data = []): array
    {
        return ['id' => $id, 'type' => 'save_lead', 'data' => $data];
    }

    /**
     * A linear or branched flow. $nodes after the trigger; $edges as
     * [source, target, extra?]; the trigger connects to the first node.
     */
    private function flow(Account $account, array $nodes, array $edges = [], string $keyword = 'go'): WhatsAppFlow
    {
        if ($edges === []) {
            for ($i = 1; $i < count($nodes); $i++) {
                $edges[] = [$nodes[$i - 1]['id'], $nodes[$i]['id']];
            }
        }

        $allEdges = [['id' => 'e_t', 'source' => 't', 'target' => $nodes[0]['id']]];
        foreach ($edges as $i => $edge) {
            $allEdges[] = ['id' => "e{$i}", 'source' => $edge[0], 'target' => $edge[1]] + ($edge[2] ?? []);
        }

        return WhatsAppFlow::create([
            'account_id' => $account->id, 'name' => 'Actions', 'trigger_type' => 'keyword', 'trigger_value' => $keyword,
            'is_active' => true,
            'graph_data' => ['nodes' => [['id' => 't', 'type' => 'trigger', 'data' => []], ...$nodes], 'edges' => $allEdges],
        ]);
    }

    private function inbound(Account $account, string $text, string $phone = self::PHONE, ?string $messageId = null): void
    {
        app(ChatbotEngineService::class)->handleInboundMessage($account->id, $phone, $text, null, 'qr', $messageId ? "wamsg:{$messageId}" : null);
    }

    private function flowSession(Account $account, string $phone = self::PHONE): WhatsAppFlowSession
    {
        return WhatsAppFlowSession::where('account_id', $account->id)->where('phone_number', $phone)->latest('id')->firstOrFail();
    }

    private function resume(WhatsAppFlowSession $session): string
    {
        return app(WhatsAppJourneyEngine::class)->resumeDueSession($session->id);
    }

    /** @return list<string> successfully sent journey messages, in order */
    private function sent(Account $account, string $phone = self::PHONE): array
    {
        return MessageDispatchLog::where('account_id', $account->id)->where('source', 'journey')->where('status', 'sent')
            ->where('recipient_phone', $phone)->orderBy('id')->pluck('message_preview')->all();
    }

    private function grant(Account $account, string $slug): void
    {
        AccountEntitlement::updateOrCreate(
            ['account_id' => $account->id, 'capability_id' => Capability::where('slug', $slug)->value('id')],
            ['source' => 'manual_grant', 'granted_by_account_id' => null, 'revoked_at' => null],
        );
    }

    private function revoke(Account $account, string $slug): void
    {
        AccountEntitlement::where('account_id', $account->id)
            ->where('capability_id', Capability::where('slug', $slug)->value('id'))
            ->update(['revoked_at' => now()]);
    }

    private function assertFailedAt(WhatsAppFlowSession $session, string $nodeId, string $error): void
    {
        $session->refresh();
        $this->assertSame(WhatsAppFlowSession::STATUS_FAILED, $session->status);
        $this->assertSame($nodeId, $session->current_node_id);
        $this->assertStringContainsString($error, (string) $session->last_error);
    }

    // ==================================================================
    // 1. Inventory
    // ==================================================================

    public function test_the_action_contract_governs_exactly_the_executable_actions(): void
    {
        $this->assertSame(['message', 'question', 'save_lead', 'text', 'image', 'video', 'document', 'audio'], JourneyActionConfig::ACTION_TYPES);

        // Everything else is either control flow with its own contract or not executable.
        foreach (['trigger', 'delay', 'condition', 'conditional', 'email', 'api', 'code'] as $type) {
            $this->assertNull(JourneyActionConfig::error($type, 'anything'), $type);
        }
    }

    public function test_a_non_executable_palette_node_expires_with_a_recorded_reason_and_nothing_after_it(): void
    {
        $account = $this->account();
        $this->flow($account, [$this->msg('a', 'A'), ['id' => 'x', 'type' => 'api', 'data' => ['method' => 'GET', 'url' => 'https://e.x']], $this->msg('b', 'B')]);

        $this->inbound($account, 'go');

        $this->assertSame(['A'], $this->sent($account));
        $s = $this->flowSession($account);
        $this->assertSame(WhatsAppFlowSession::STATUS_EXPIRED, $s->status);
        $this->assertSame('x', $s->current_node_id);
        $this->assertStringContainsString("type 'api'", $s->last_error);
    }

    // ==================================================================
    // 2. Malformed action configuration
    // ==================================================================

    public static function malformedActions(): array
    {
        return [
            'message without text' => [['id' => 'bad', 'type' => 'message', 'data' => ['text' => '   ']], 'non-empty text'],
            'message data not an object' => [['id' => 'bad', 'type' => 'message', 'data' => 'Hello'], 'must be an object'],
            'question without prompt' => [['id' => 'bad', 'type' => 'question', 'data' => ['variable_name' => 'x']], 'prompt text'],
            'question without variable' => [['id' => 'bad', 'type' => 'question', 'data' => ['prompt_text' => 'Name?']], 'variable name'],
            'question unknown input type' => [['id' => 'bad', 'type' => 'question', 'data' => ['prompt_text' => 'P', 'variable_name' => 'x', 'input_type' => 'slider']], 'input type'],
            'buttons question without options' => [['id' => 'bad', 'type' => 'question', 'data' => ['prompt_text' => 'P', 'variable_name' => 'x', 'input_type' => 'buttons', 'options' => []]], 'at least one option'],
            'question option without id or title' => [['id' => 'bad', 'type' => 'question', 'data' => ['prompt_text' => 'P', 'variable_name' => 'x', 'input_type' => 'list', 'options' => [['foo' => 'bar']]]], 'id or a title'],
            'question unknown validation' => [['id' => 'bad', 'type' => 'question', 'data' => ['prompt_text' => 'P', 'variable_name' => 'x', 'validation' => ['type' => 'regex']]], 'validation type'],
            'save_lead mapping not a name' => [['id' => 'bad', 'type' => 'save_lead', 'data' => ['name_variable' => ['x']]], 'must name a variable'],
            'save_lead completion not text' => [['id' => 'bad', 'type' => 'save_lead', 'data' => ['completion_message' => ['x']]], 'completion message'],
        ];
    }

    #[DataProvider('malformedActions')]
    public function test_a_malformed_action_fails_at_the_node_and_nothing_downstream_runs(array $badNode, string $error): void
    {
        $account = $this->account();
        $this->flow($account, [$this->msg('a', 'A'), $badNode, $this->msg('after', 'AFTER')]);

        $this->inbound($account, 'go');

        $this->assertSame(['A'], $this->sent($account), 'nothing from or after the malformed node');
        $this->assertFailedAt($this->flowSession($account), 'bad', $error);
        $this->assertSame(0, Lead::count());
        $this->assertSame(0, ChatbotLog::count(), 'the chatbot did not answer in its place');
    }

    public function test_an_edge_into_a_missing_node_fails_instead_of_completing(): void
    {
        $account = $this->account();
        $this->flow($account, [$this->msg('a', 'A')], [['a', 'ghost']]);

        $this->inbound($account, 'go');

        $this->assertFailedAt($this->flowSession($account), 'ghost', "Node 'ghost' is not in this journey version");
    }

    public function test_the_save_api_refuses_action_values_that_could_never_run_but_keeps_drafts(): void
    {
        $account = $this->account();
        $user = User::factory()->create(['account_id' => $account->id, 'is_active' => true]);
        $user->assignRole('admin');
        // P5-7 — a half-built draft saves with `publish: false`; publishing needs a runnable graph.
        $post = fn (array $node, bool $publish = true) => $this->actingAs($user)->postJson('/api/whatsapp/flows', [
            'name' => 'X', 'trigger_type' => 'keyword', 'trigger_value' => 'go', 'is_active' => true, 'publish' => $publish,
            'graph_data' => ['nodes' => [['id' => 't', 'type' => 'trigger', 'data' => []], $node], 'edges' => [['id' => 'e', 'source' => 't', 'target' => $node['id']]]],
        ]);

        $post(['id' => 'q', 'type' => 'question', 'data' => ['prompt_text' => 'P', 'variable_name' => 'x', 'input_type' => 'slider']])
            ->assertStatus(422)->assertJsonValidationErrors('graph_data.nodes.1.data');
        $post(['id' => 'q', 'type' => 'question', 'data' => ['prompt_text' => 'P', 'variable_name' => 'x', 'validation' => ['type' => 'regex']]])
            ->assertStatus(422)->assertJsonValidationErrors('graph_data.nodes.1.data');
        $post(['id' => 'm', 'type' => 'message', 'data' => ['text' => ['not', 'text']]])
            ->assertStatus(422)->assertJsonValidationErrors('graph_data.nodes.1.data');

        // Half-built drafts still save.
        $post(['id' => 'm', 'type' => 'message', 'data' => []], false)->assertCreated();
        $post(['id' => 'q', 'type' => 'question', 'data' => ['input_type' => 'buttons']], false)->assertCreated();
        // ...and cannot be published as they are.
        $post(['id' => 'm', 'type' => 'message', 'data' => []])->assertStatus(422)->assertJsonPath('error_code', 'JOURNEY_NOT_PUBLISHABLE');
    }

    // ==================================================================
    // 3. Message: send success / provider failure
    // ==================================================================

    public function test_message_chains_run_in_order_on_the_pinned_version(): void
    {
        $account = $this->account();
        $this->flow($account, [$this->msg('a', 'A'), $this->msg('b', 'B'), $this->msg('c', 'C')]);

        $this->inbound($account, 'go');

        $this->assertSame(['A', 'B', 'C'], $this->sent($account));
        $this->assertSame(WhatsAppFlowSession::STATUS_COMPLETED, $this->flowSession($account)->status);
        $this->assertSame(3, (int) $account->currentSubscription()->first()->used_messages);
    }

    public function test_a_failed_send_on_the_immediate_path_parks_for_retry_and_runs_nothing_downstream(): void
    {
        $account = $this->account();
        $this->flow($account, [$this->msg('a', 'A'), $this->msg('b', 'B'), $this->q('q', 'name', 'Name?')]);
        $this->failTexts = ['B'];

        $this->inbound($account, 'go');

        $this->assertSame(['A'], $this->sent($account), 'the question after the failed message was not asked');
        $s = $this->flowSession($account);
        $this->assertSame(WhatsAppFlowSession::STATUS_WAITING, $s->status);
        $this->assertSame('b', $s->current_node_id);
        $this->assertSame(0, $s->attempts);
        $this->assertStringContainsString('Engine offline', $s->last_error);
        $this->assertSame(0, ChatbotLog::count(), 'the inbound message stays consumed by the journey');

        // Provider back: the retry resumes at B, never re-sends A, then asks the question.
        $this->failTexts = [];
        $this->assertSame('skipped', $this->resume($s), 'backoff respected');
        $this->travel(WhatsAppJourneyEngine::RETRY_BACKOFF_SECONDS)->seconds();
        $this->assertSame(WhatsAppFlowSession::STATUS_ACTIVE, $this->resume($s));

        $this->assertSame(['A', 'B', 'Name?'], $this->sent($account));
        $s->refresh();
        $this->assertSame('q', $s->current_node_id);
        $this->assertNull($s->last_error);
    }

    public function test_a_send_that_keeps_failing_ends_failed_after_the_task1_retry_budget(): void
    {
        $account = $this->account();
        $this->flow($account, [$this->msg('a', 'A'), $this->msg('b', 'B')]);
        $this->failTexts = ['A'];
        $this->inbound($account, 'go');
        $s = $this->flowSession($account);

        $outcomes = [];
        for ($i = 0; $i < WhatsAppJourneyEngine::MAX_RESUME_ATTEMPTS; $i++) {
            $this->travel(1)->hours();
            $outcomes[] = $this->resume($s);
        }

        $this->assertSame(['retrying', 'retrying', WhatsAppFlowSession::STATUS_FAILED], $outcomes);
        $this->assertFailedAt($s, 'a', 'Engine offline');
        $this->assertSame([], $this->sent($account));
        $this->travel(1)->hours();
        $this->assertSame('skipped', $this->resume($s), 'failure is terminal and durable');
    }

    public function test_an_exhausted_quota_is_a_captured_retryable_failure_not_a_silent_skip(): void
    {
        $account = $this->account();
        $this->flow($account, [$this->msg('a', 'A'), $this->msg('b', 'B')]);
        $sub = $account->currentSubscription()->first();
        $sub->forceFill(['used_messages' => $sub->total_allocated_messages])->save();

        $this->inbound($account, 'go');

        $s = $this->flowSession($account);
        $this->assertSame(WhatsAppFlowSession::STATUS_WAITING, $s->status);
        $this->assertSame('a', $s->current_node_id);
        $this->assertNotNull($s->last_error);
        $this->assertSame([], $this->sent($account));
    }

    public function test_an_unexpected_exception_mid_chain_is_parked_not_swallowed(): void
    {
        $account = $this->account();
        $this->flow($account, [$this->msg('a', 'A'), $this->save('s'), $this->msg('never', 'NEVER')]);
        Lead::creating(fn () => throw new RuntimeException('database is down'));

        $this->inbound($account, 'go');

        $s = $this->flowSession($account);
        $this->assertSame(WhatsAppFlowSession::STATUS_WAITING, $s->status);
        $this->assertSame('s', $s->current_node_id);
        $this->assertStringContainsString('database is down', $s->last_error);
        $this->assertSame(['A'], $this->sent($account));
        $this->assertSame(0, ChatbotLog::count());
    }

    // ==================================================================
    // 4. Question
    // ==================================================================

    public function test_a_question_is_sent_once_pauses_and_the_answer_is_persisted_before_downstream_runs(): void
    {
        $account = $this->account();
        $this->flow($account, [$this->q('q', 'city', 'City?'), $this->msg('m', 'Thanks'), $this->msg('boom', 'BOOM')]);
        $this->inbound($account, 'go');

        $s = $this->flowSession($account);
        $this->assertSame(['City?'], $this->sent($account));
        $this->assertSame(WhatsAppFlowSession::STATUS_ACTIVE, $s->status);
        $this->assertSame('q', $s->current_node_id);

        // Downstream fails AFTER the answer: the answer must already be durable.
        $this->failTexts = ['BOOM'];
        $this->inbound($account, 'Pune');

        $s->refresh();
        $this->assertSame(['city' => 'Pune'], $s->context_data);
        $this->assertSame(['City?', 'Thanks'], $this->sent($account));
        $this->assertSame('boom', $s->current_node_id);
    }

    public function test_a_failed_question_prompt_does_not_leave_the_session_waiting_for_an_answer_nobody_was_asked(): void
    {
        $account = $this->account();
        $this->flow($account, [$this->q('q', 'city', 'City?'), $this->msg('m', 'Thanks')]);
        $this->failTexts = ['City?'];

        $this->inbound($account, 'go');
        $s = $this->flowSession($account);
        $this->assertSame(WhatsAppFlowSession::STATUS_WAITING, $s->status, 'not "active at the question"');

        // A reply now is not taken as the answer.
        $this->inbound($account, 'Mumbai');
        $this->assertSame([], $s->fresh()->context_data ?? []);

        $this->failTexts = [];
        $this->travel(WhatsAppJourneyEngine::RETRY_BACKOFF_SECONDS)->seconds();
        $this->assertSame(WhatsAppFlowSession::STATUS_ACTIVE, $this->resume($s));
        $this->inbound($account, 'Pune');

        $this->assertSame(['City?', 'Thanks'], $this->sent($account));
        $this->assertSame(['city' => 'Pune'], $s->fresh()->context_data);
    }

    public function test_a_redelivered_answer_does_not_advance_the_question_twice(): void
    {
        $account = $this->account();
        $this->flow($account, [$this->q('q1', 'a', 'First?'), $this->q('q2', 'b', 'Second?'), $this->save('s', ['completion_message' => 'Done'])]);
        $this->inbound($account, 'go', messageId: 'M0');

        $this->inbound($account, 'one', messageId: 'M1');
        $this->inbound($account, 'one', messageId: 'M1');

        $s = $this->flowSession($account);
        $this->assertSame('q2', $s->current_node_id, 'still waiting for the second answer');
        $this->assertSame(['a' => 'one'], $s->context_data);
        $this->assertSame(['First?', 'Second?'], $this->sent($account));

        $this->inbound($account, 'two', messageId: 'M2');
        $this->inbound($account, 'two', messageId: 'M2');
        $this->assertSame(['First?', 'Second?', 'Done'], $this->sent($account));
        $this->assertSame(1, Lead::count());
    }

    public function test_question_then_condition_then_message(): void
    {
        $account = $this->account();
        $this->flow($account, [
            $this->q('q', 'size', 'Size?'),
            ['id' => 'c', 'type' => 'conditional', 'data' => ['conditions' => [['variable' => 'size', 'operator' => 'greater_than', 'value' => '10']]]],
            $this->msg('big', 'BIG'), $this->msg('small', 'SMALL'),
        ], [['q', 'c'], ['c', 'big', ['sourceHandle' => 'true']], ['c', 'small', ['sourceHandle' => 'false']]]);

        $this->inbound($account, 'go');
        $this->inbound($account, '42');

        $this->assertSame(['Size?', 'BIG'], $this->sent($account));
    }

    public function test_question_then_delay_then_message(): void
    {
        $account = $this->account();
        $this->flow($account, [$this->q('q', 'x', 'X?'), $this->delay('d'), $this->msg('later', 'LATER')]);
        $this->inbound($account, 'go');
        $this->inbound($account, 'ok');

        $s = $this->flowSession($account);
        $this->assertSame(WhatsAppFlowSession::STATUS_WAITING, $s->status);
        $this->assertSame('d', $s->current_node_id);

        $this->travel(5)->minutes();
        $this->assertSame(WhatsAppFlowSession::STATUS_COMPLETED, $this->resume($s));
        $this->assertSame(['X?', 'LATER'], $this->sent($account));
    }

    public function test_question_then_save_lead_maps_the_answers(): void
    {
        $account = $this->account();
        $this->flow($account, [$this->q('q1', 'n', 'Name?'), $this->q('q2', 'e', 'Email?'), $this->save('s', ['name_variable' => 'n', 'email_variable' => 'e', 'completion_message' => 'Saved'])]);

        $this->inbound($account, 'go');
        $this->inbound($account, 'Asha');
        $this->inbound($account, 'asha@example.com');

        $lead = Lead::sole();
        $this->assertSame([$account->id, 'whatsapp_journey', 'Asha', 'asha@example.com'], [$lead->account_id, $lead->provider, $lead->lead_name, $lead->lead_email]);
        $this->assertSame(['Name?', 'Email?', 'Saved'], $this->sent($account));
    }

    // ==================================================================
    // 5. save_lead
    // ==================================================================

    public function test_save_lead_writes_one_capture_and_one_journey_crm_lead_attributed_to_the_version(): void
    {
        $account = $this->account();
        $this->flow($account, [$this->save('s', ['completion_message' => 'Saved'])]);

        $this->inbound($account, 'go');

        $s = $this->flowSession($account);
        $lead = Lead::sole();
        $this->assertSame("journey:{$s->flow_id}:{$s->id}", $lead->provider_lead_id);
        $this->assertSame($s->flow_version_id, $lead->raw_field_data['flow_version_id']);
        $crm = CrmLead::sole();
        $this->assertSame([$account->id, CrmLead::SOURCE_JOURNEY, $lead->id], [$crm->account_id, $crm->source, $crm->capture_lead_id]);
        $this->assertSame(WhatsAppFlowSession::STATUS_COMPLETED, $s->status);
    }

    public function test_save_lead_is_terminal_nothing_after_it_runs(): void
    {
        $account = $this->account();
        $this->flow($account, [$this->save('s', ['completion_message' => 'Saved']), $this->msg('after', 'AFTER')]);

        $this->inbound($account, 'go');

        $this->assertSame(['Saved'], $this->sent($account));
    }

    public function test_a_retried_save_lead_never_duplicates_the_lead_or_the_crm_lead(): void
    {
        $account = $this->account();
        $this->flow($account, [$this->save('s', ['completion_message' => 'Saved'])]);
        $this->failTexts = ['Saved'];

        $this->inbound($account, 'go');
        $s = $this->flowSession($account);
        $this->assertSame(WhatsAppFlowSession::STATUS_WAITING, $s->status);
        $this->assertSame(1, Lead::count());

        $this->failTexts = [];
        $this->travel(WhatsAppJourneyEngine::RETRY_BACKOFF_SECONDS)->seconds();
        $this->assertSame(WhatsAppFlowSession::STATUS_COMPLETED, $this->resume($s));

        $this->assertSame(1, Lead::count());
        $this->assertSame(1, CrmLead::count());
        $this->assertSame(['Saved'], $this->sent($account));
    }

    public function test_a_failed_crm_write_does_not_advance_the_journey_and_the_retry_completes_it_once(): void
    {
        $account = $this->account();
        $this->flow($account, [$this->save('s', ['completion_message' => 'Saved'])]);

        // CRM entitled, but the promotion fails.
        $broken = \Mockery::mock(CaptureLeadLinker::class);
        $broken->shouldReceive('linkQuietly')->andReturnNull();
        $broken->shouldReceive('accountMayUseCrm')->andReturnTrue();
        $this->app->instance(CaptureLeadLinker::class, $broken);

        $this->inbound($account, 'go');

        $s = $this->flowSession($account);
        $this->assertSame(WhatsAppFlowSession::STATUS_WAITING, $s->status, 'not completed as if saved');
        $this->assertSame('s', $s->current_node_id);
        $this->assertStringContainsString('could not be written to the CRM', $s->last_error);
        $this->assertSame([], $this->sent($account), 'no completion message');
        $this->assertSame(0, CrmLead::count());

        $this->app->forgetInstance(CaptureLeadLinker::class);
        $this->travel(WhatsAppJourneyEngine::RETRY_BACKOFF_SECONDS)->seconds();
        $this->assertSame(WhatsAppFlowSession::STATUS_COMPLETED, $this->resume($s));

        $this->assertSame(1, Lead::count());
        $this->assertSame(1, CrmLead::count());
        $this->assertSame(['Saved'], $this->sent($account));
    }

    public function test_without_a_crm_entitlement_the_capture_is_kept_the_crm_write_is_blocked_and_the_journey_completes(): void
    {
        $account = $this->account();
        $this->revoke($account, 'crm');
        $this->flow($account, [$this->save('s', ['completion_message' => 'Saved'])]);

        $this->inbound($account, 'go');

        $this->assertSame(1, Lead::count());
        $this->assertSame(0, CrmLead::count());
        $this->assertSame(CrmCaptureLinkFailure::REASON_NOT_ENTITLED, CrmCaptureLinkFailure::sole()->reason);
        $this->assertSame(WhatsAppFlowSession::STATUS_COMPLETED, $this->flowSession($account)->status, 'Task 10 contract: not a failure');
    }

    public function test_save_lead_writes_only_to_the_sessions_own_account(): void
    {
        $a = $this->account();
        $b = $this->account();
        $this->flow($a, [$this->q('q', 'n', 'Name?'), $this->save('s', ['name_variable' => 'n'])]);
        $this->flow($b, [$this->q('q', 'n', 'Name?'), $this->save('s', ['name_variable' => 'n'])]);

        $this->inbound($a, 'go');
        $this->inbound($b, 'go');
        $this->inbound($a, 'Alpha');
        $this->inbound($b, 'Beta');

        $this->assertSame('Alpha', Lead::where('account_id', $a->id)->sole()->lead_name);
        $this->assertSame('Beta', Lead::where('account_id', $b->id)->sole()->lead_name);
        $this->assertSame(1, CrmLead::where('account_id', $a->id)->count());
        $this->assertSame(1, CrmLead::where('account_id', $b->id)->count());
    }

    // ==================================================================
    // 6. Delay / resume
    // ==================================================================

    public function test_condition_message_delay_message_resumes_on_the_pinned_version(): void
    {
        $account = $this->account();
        $flow = $this->flow($account, [
            $this->q('q', 'x', 'X?'),
            ['id' => 'c', 'type' => 'condition', 'data' => ['variable' => 'x']],
            $this->msg('before', 'BEFORE'), $this->delay('d'), $this->msg('after', 'AFTER_V1'),
        ], [['q', 'c'], ['c', 'before', ['is_default' => true]], ['before', 'd'], ['d', 'after']]);
        $this->inbound($account, 'go');
        $this->inbound($account, 'anything');
        $s = $this->flowSession($account);
        $this->assertSame(WhatsAppFlowSession::STATUS_WAITING, $s->status);

        // Publish version 2 with a different post-delay message.
        $graph = $flow->graph_data;
        foreach ($graph['nodes'] as &$node) {
            if ($node['id'] === 'after') {
                $node['data']['text'] = 'AFTER_V2';
            }
        }
        unset($node);
        $flow->update(['graph_data' => $graph]);

        $this->travel(5)->minutes();
        $this->assertSame(WhatsAppFlowSession::STATUS_COMPLETED, $this->resume($s));
        $this->assertSame(['X?', 'BEFORE', 'AFTER_V1'], $this->sent($account));
    }

    public function test_entitlement_and_module_are_rechecked_at_resume(): void
    {
        $account = $this->account();
        $this->flow($account, [$this->delay('d'), $this->msg('m', 'LATER')]);
        $this->inbound($account, 'go');
        $s = $this->flowSession($account);
        $this->travel(5)->minutes();

        $this->revoke($account, 'journey_automation');
        $this->assertSame(WhatsAppFlowSession::STATUS_BLOCKED, $this->resume($s));
        $this->assertSame([], $this->sent($account));

        $this->grant($account, 'journey_automation');
        $account->forceFill(['allowed_modules' => array_values(array_diff(Account::MODULES, ['chatbot']))])->save();
        app(WhatsAppJourneyEngine::class)->restoreEntitledBlockedSessions();
        $this->assertSame(WhatsAppFlowSession::STATUS_BLOCKED, $s->fresh()->status, 'module still off: not restored');

        $account->forceFill(['allowed_modules' => null])->save();
        app(WhatsAppJourneyEngine::class)->restoreEntitledBlockedSessions();
        $this->assertSame(WhatsAppFlowSession::STATUS_COMPLETED, $this->resume($s->fresh()));
        $this->assertSame(['LATER'], $this->sent($account));
    }

    public function test_cancellation_is_terminal_even_for_a_session_parked_for_retry(): void
    {
        $account = $this->account();
        $this->flow($account, [$this->msg('a', 'A')]);
        $this->failTexts = ['A'];
        $this->inbound($account, 'go');
        $s = $this->flowSession($account);

        $this->assertTrue(app(WhatsAppJourneyEngine::class)->cancelSession($s));
        $this->failTexts = [];
        $this->travel(1)->hours();

        $this->assertSame('skipped', $this->resume($s));
        $this->assertSame(WhatsAppFlowSession::STATUS_CANCELLED, $s->fresh()->status);
        $this->assertSame([], $this->sent($account));
    }

    public function test_a_worker_that_died_mid_run_resumes_at_its_checkpoint_without_replaying_completed_actions(): void
    {
        $account = $this->account();
        $this->flow($account, [$this->delay('d'), $this->msg('a', 'A'), $this->msg('b', 'B'), $this->save('s', ['completion_message' => 'Saved'])]);
        $this->inbound($account, 'go');
        $s = $this->flowSession($account);
        $this->travel(5)->minutes();

        // A worker claims the run, sends A, checkpoints B … and dies (no settle).
        $claimed = WhatsAppFlowSession::query()->whereKey($s->id)->where('status', 'waiting')
            ->update(['wait_until' => now()->addSeconds(WhatsAppJourneyEngine::RESUME_LEASE_SECONDS), 'attempts' => 1]);
        $this->assertSame(1, $claimed);
        MessageDispatchLog::record($account->id, 'journey', self::PHONE, success: true, messagePreview: 'A');
        WhatsAppFlowSession::query()->whereKey($s->id)->update(['current_node_id' => 'b']);

        $this->assertSame('skipped', $this->resume($s), 'the lease still holds');
        $this->travel(WhatsAppJourneyEngine::RESUME_LEASE_SECONDS + 1)->seconds();
        $this->assertSame(WhatsAppFlowSession::STATUS_COMPLETED, $this->resume($s));

        $this->assertSame(['A', 'B', 'Saved'], $this->sent($account), 'A is not re-sent');
        $this->assertSame(1, Lead::count());
    }

    public function test_two_concurrent_resumes_of_one_session_run_its_actions_once(): void
    {
        $account = $this->account();
        $this->flow($account, [$this->delay('d'), $this->msg('a', 'A'), $this->save('s')]);
        $this->inbound($account, 'go');
        $s = $this->flowSession($account);
        $this->travel(5)->minutes();

        // The second worker arrives while the first is sending A.
        $second = null;
        Http::fake(function () use (&$second, $s) {
            if ($second === null) {
                $second = $this->resume($s);
            }

            return Http::response(['success' => true, 'message_id' => 'QR_'.uniqid()], 200);
        });

        $this->assertSame(WhatsAppFlowSession::STATUS_COMPLETED, $this->resume($s));
        $this->assertSame('skipped', $second);
        $this->assertSame(['A'], $this->sent($account));
        $this->assertSame(1, Lead::count());
    }

    // ==================================================================
    // 7. Chains and the step limit
    // ==================================================================

    public function test_message_then_question(): void
    {
        $account = $this->account();
        $this->flow($account, [$this->msg('hi', 'Hi'), $this->q('q', 'x', 'X?')]);

        $this->inbound($account, 'go');

        $this->assertSame(['Hi', 'X?'], $this->sent($account));
        $this->assertSame('q', $this->flowSession($account)->current_node_id);
    }

    public function test_condition_condition_message(): void
    {
        $account = $this->account();
        $this->flow($account, [
            $this->q('q', 'x', 'X?'),
            ['id' => 'c1', 'type' => 'condition', 'data' => ['variable' => 'x']],
            ['id' => 'c2', 'type' => 'conditional', 'data' => ['conditions' => [['variable' => 'x', 'operator' => 'starts_with', 'value' => 'a']]]],
            $this->msg('m', 'CHAIN'),
        ], [['q', 'c1'], ['c1', 'c2', ['is_default' => true]], ['c2', 'm', ['sourceHandle' => 'true']]]);

        $this->inbound($account, 'go');
        $this->inbound($account, 'abc');

        $this->assertSame(['X?', 'CHAIN'], $this->sent($account));
    }

    public function test_a_loop_of_messages_stops_at_the_step_limit_durably(): void
    {
        $account = $this->account();
        $this->flow($account, [$this->msg('a', 'A'), $this->msg('b', 'B')], [['a', 'b'], ['b', 'a']]);

        $this->inbound($account, 'go');

        $this->assertCount(25, $this->sent($account));
        $s = $this->flowSession($account)->fresh();
        $this->assertSame(WhatsAppFlowSession::STATUS_EXPIRED, $s->status);
        $this->assertStringContainsString('Stopped after 25 steps', $s->last_error);
    }

    public function test_a_loop_through_a_question_is_not_cut_by_the_step_limit(): void
    {
        $account = $this->account();
        $this->flow($account, [$this->msg('m', 'Again'), $this->q('q', 'x', 'X?')], [['m', 'q'], ['q', 'm']]);

        $this->inbound($account, 'go');
        for ($i = 0; $i < 15; $i++) {
            $this->inbound($account, "answer {$i}");
        }

        $this->assertCount(32, $this->sent($account), 'each inbound starts a fresh step budget');
        $this->assertSame(WhatsAppFlowSession::STATUS_ACTIVE, $this->flowSession($account)->status);
    }

    // ==================================================================
    // 8. Version isolation for actions
    // ==================================================================

    public function test_an_old_session_keeps_its_versions_actions_and_a_new_session_uses_the_new_ones(): void
    {
        $account = $this->account();
        $flow = $this->flow($account, [$this->q('q', 'n', 'Name v1?'), $this->msg('m', 'MSG_V1'), $this->save('s', ['name_variable' => 'n', 'completion_message' => 'SAVED_V1'])]);
        $this->inbound($account, 'go');
        $old = $this->flowSession($account);

        $graph = $flow->graph_data;
        foreach ($graph['nodes'] as &$node) {
            match ($node['id']) {
                'q' => $node['data']['prompt_text'] = 'Name v2?',
                'm' => $node['data']['text'] = 'MSG_V2',
                's' => $node['data']['completion_message'] = 'SAVED_V2',
                default => null,
            };
        }
        unset($node);
        $flow->update(['graph_data' => $graph]);

        $this->inbound($account, 'Old');
        $this->assertSame(['Name v1?', 'MSG_V1', 'SAVED_V1'], $this->sent($account));
        $this->assertSame($old->flow_version_id, Lead::where('provider_lead_id', "journey:{$flow->id}:{$old->id}")->sole()->raw_field_data['flow_version_id']);

        $this->inbound($account, 'go', '919800000002');
        $this->inbound($account, 'New', '919800000002');
        $this->assertSame(['Name v2?', 'MSG_V2', 'SAVED_V2'], $this->sent($account, '919800000002'));
        $this->assertNotSame($old->flow_version_id, $this->flowSession($account, '919800000002')->flow_version_id);
    }

    public function test_a_retry_runs_the_pinned_version_not_the_edit_made_after_the_failure(): void
    {
        $account = $this->account();
        $flow = $this->flow($account, [$this->msg('a', 'A_V1'), $this->msg('b', 'B_V1')]);
        $this->failTexts = ['A_V1'];
        $this->inbound($account, 'go');
        $s = $this->flowSession($account);

        $graph = $flow->graph_data;
        $graph['nodes'][1]['data']['text'] = 'A_V2';
        $graph['nodes'][2]['data']['text'] = 'B_V2';
        $flow->update(['graph_data' => $graph]);

        $this->failTexts = [];
        $this->travel(WhatsAppJourneyEngine::RETRY_BACKOFF_SECONDS)->seconds();
        $this->resume($s);

        $this->assertSame(['A_V1', 'B_V1'], $this->sent($account));
    }

    // ==================================================================
    // 9. Duplicate / replay
    // ==================================================================

    public function test_a_redelivered_trigger_runs_the_action_chain_once(): void
    {
        $account = $this->account();
        $this->flow($account, [$this->msg('a', 'A'), $this->save('s', ['completion_message' => 'Saved'])]);

        $this->inbound($account, 'go', messageId: 'SAME');
        $this->inbound($account, 'go', messageId: 'SAME');
        $this->inbound($account, 'go', messageId: 'SAME');

        $this->assertSame(['A', 'Saved'], $this->sent($account));
        $this->assertSame(1, WhatsAppFlowSession::count());
        $this->assertSame(1, Lead::count());
        $this->assertSame(1, CrmLead::count());
    }

    // ==================================================================
    // 10. Entitlement / module / tenant
    // ==================================================================

    public function test_nothing_runs_when_the_chatbot_module_is_disabled(): void
    {
        $account = $this->account();
        $this->flow($account, [$this->msg('a', 'A')]);
        $account->forceFill(['allowed_modules' => array_values(array_diff(Account::MODULES, ['chatbot']))])->save();

        $this->inbound($account, 'go');

        $this->assertSame([], $this->sent($account));
        $this->assertSame(0, WhatsAppFlowSession::count());
    }

    public function test_nothing_runs_when_journey_automation_is_revoked_and_an_open_session_is_blocked(): void
    {
        $account = $this->account();
        $this->flow($account, [$this->q('q', 'x', 'X?'), $this->msg('m', 'AFTER')]);
        $this->inbound($account, 'go');

        $this->revoke($account, 'journey_automation');
        $this->inbound($account, 'answer');

        $this->assertSame(['X?'], $this->sent($account));
        $this->assertSame(WhatsAppFlowSession::STATUS_BLOCKED, $this->flowSession($account)->status);
    }

    public function test_a_session_whose_flow_belongs_to_another_account_never_runs_an_action(): void
    {
        $a = $this->account();
        $b = $this->account();
        $foreign = $this->flow($b, [$this->q('q', 'x', 'X?'), $this->msg('m', 'LEAK')]);
        $this->flow($a, [$this->q('q', 'x', 'X?'), $this->msg('m', 'MINE')]);
        $this->inbound($a, 'go');

        // A corrupted/forged row pointing A's session at B's journey.
        $s = $this->flowSession($a);
        $s->forceFill(['flow_id' => $foreign->id, 'flow_version_id' => $foreign->published_version_id])->save();
        $this->inbound($a, 'answer');

        $this->assertSame(['X?'], $this->sent($a));
        $this->assertSame([], $this->sent($b));
        $this->assertFailedAt($s, 'q', 'does not belong');
    }

    public function test_every_journey_send_goes_through_the_unified_driver_only(): void
    {
        $source = file_get_contents(app_path('Services/WhatsApp/WhatsAppJourneyEngine.php'));

        $this->assertStringContainsString('WhatsAppEngineFactory::make(', $source);
        foreach (['new BaileysDriver', 'new MetaCloudApiDriver', 'Http::', 'graph.facebook.com', 'eval(', 'increment(\'used_messages\''] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $source, $forbidden);
        }
    }
}
