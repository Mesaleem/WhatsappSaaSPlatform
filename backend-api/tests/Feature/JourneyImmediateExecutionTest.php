<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\AccountEntitlement;
use App\Models\Capability;
use App\Models\CrmLead;
use App\Models\Invoice;
use App\Models\Lead;
use App\Models\MessageDispatchLog;
use App\Models\WhatsAppFlow;
use App\Models\WhatsAppFlowSession;
use App\Models\WhatsAppSession;
use App\Services\Billing\InvoiceCreditService;
use App\Services\Chatbot\ChatbotEngineService;
use App\Services\WhatsApp\WhatsAppJourneyEngine;
use App\Support\PlanCatalog;
use Database\Seeders\Phase1FoundationSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Phase 7 Task 1 — characterization of the EXISTING immediate Journey
 * behaviour (trigger → message → question → condition → save_lead), written
 * and run green against the engine BEFORE the temporal backbone was added,
 * then kept unchanged. If the backbone ever alters an immediate path, this
 * suite fails.
 *
 * Every send goes through the unified driver (QR engine, faked over HTTP);
 * what was sent is read back from message_dispatch_logs, the engine's own
 * audit trail.
 */
class JourneyImmediateExecutionTest extends TestCase
{
    use RefreshDatabase;

    private const PHONE = '919812345678';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(Phase1FoundationSeeder::class);
        Http::fake(['*' => Http::response(['success' => true, 'message_id' => 'QR_OK'], 200)]);
    }

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
        // Phase 7 Task 1.6 — journeys only RUN for an account entitled to journey_automation.
        AccountEntitlement::create(['account_id' => $account->id, 'capability_id' => Capability::where('slug', 'journey_automation')->value('id'), 'source' => 'manual_grant', 'granted_by_account_id' => null]);

        return $account;
    }

    /** trigger → message → question(size) → condition(size) → [big] save_lead / [default] message */
    private function qualifierFlow(Account $account, array $overrides = []): WhatsAppFlow
    {
        return WhatsAppFlow::create($overrides + [
            'account_id' => $account->id,
            'name' => 'Qualifier',
            'trigger_type' => 'keyword',
            'trigger_value' => 'join',
            'is_active' => true,
            'graph_data' => [
                'nodes' => [
                    ['id' => 't', 'type' => 'trigger', 'data' => []],
                    ['id' => 'hello', 'type' => 'message', 'data' => ['text' => 'Welcome!']],
                    ['id' => 'q', 'type' => 'question', 'data' => ['prompt_text' => 'Team size?', 'variable_name' => 'size', 'input_type' => 'text', 'validation' => ['type' => 'number', 'error_message' => 'Numbers only.']]],
                    ['id' => 'c', 'type' => 'condition', 'data' => ['variable' => 'size']],
                    ['id' => 'save', 'type' => 'save_lead', 'data' => ['completion_message' => 'Thanks, saved.']],
                    ['id' => 'small', 'type' => 'message', 'data' => ['text' => 'We will be in touch.']],
                ],
                'edges' => [
                    ['id' => 'e1', 'source' => 't', 'target' => 'hello'],
                    ['id' => 'e2', 'source' => 'hello', 'target' => 'q'],
                    ['id' => 'e3', 'source' => 'q', 'target' => 'c'],
                    ['id' => 'e4', 'source' => 'c', 'target' => 'save', 'condition' => ['operator' => 'equals', 'value' => '50']],
                    ['id' => 'e5', 'source' => 'c', 'target' => 'small', 'is_default' => true],
                ],
            ],
        ]);
    }

    private function inbound(Account $account, string $text, string $phone = self::PHONE): void
    {
        app(ChatbotEngineService::class)->handleInboundMessage($account->id, $phone, $text);
    }

    /** @return list<string> */
    private function sent(Account $account): array
    {
        return MessageDispatchLog::where('account_id', $account->id)->where('source', 'journey')
            ->orderBy('id')->pluck('message_preview')->all();
    }

    public function test_a_keyword_starts_a_session_that_sends_then_pauses_at_the_question(): void
    {
        $account = $this->qrAccount();
        $flow = $this->qualifierFlow($account);

        $this->inbound($account, 'I want to JOIN please');

        $this->assertSame(['Welcome!', 'Team size?'], $this->sent($account));
        $session = WhatsAppFlowSession::sole();
        $this->assertSame(WhatsAppFlowSession::STATUS_ACTIVE, $session->status);
        $this->assertSame('q', $session->current_node_id);
        $this->assertSame($flow->id, $session->flow_id);
        $this->assertSame($account->id, $session->account_id);
    }

    public function test_an_invalid_answer_reprompts_and_keeps_the_session_paused(): void
    {
        $account = $this->qrAccount();
        $this->qualifierFlow($account);
        $this->inbound($account, 'join');

        $this->inbound($account, 'lots');

        $this->assertSame(['Welcome!', 'Team size?', "Numbers only.\n\nTeam size?"], $this->sent($account));
        $session = WhatsAppFlowSession::sole();
        $this->assertSame(WhatsAppFlowSession::STATUS_ACTIVE, $session->status);
        $this->assertSame('q', $session->current_node_id);
    }

    public function test_an_answer_branches_through_the_condition_to_save_lead_and_the_crm(): void
    {
        $account = $this->qrAccount();
        // The CRM promotion is gated on the crm capability (Task 10); grant it as an admin would.
        AccountEntitlement::create(['account_id' => $account->id, 'capability_id' => Capability::where('slug', 'crm')->value('id'), 'source' => 'manual_grant', 'granted_by_account_id' => null]);
        $this->qualifierFlow($account);
        $this->inbound($account, 'join');

        $this->inbound($account, '50');

        $this->assertSame(['Welcome!', 'Team size?', 'Thanks, saved.'], $this->sent($account));
        $session = WhatsAppFlowSession::sole();
        $this->assertSame(WhatsAppFlowSession::STATUS_COMPLETED, $session->status);
        $this->assertSame(['size' => '50'], $session->context_data);

        $lead = Lead::sole();
        $this->assertSame('whatsapp_journey', $lead->provider);
        $this->assertSame("journey:{$session->flow_id}:{$session->id}", $lead->provider_lead_id);
        $this->assertSame(CrmLead::SOURCE_JOURNEY, CrmLead::sole()->source);
    }

    public function test_the_default_branch_is_followed_and_a_dead_end_completes(): void
    {
        $account = $this->qrAccount();
        $this->qualifierFlow($account);
        $this->inbound($account, 'join');

        $this->inbound($account, '3');

        $this->assertSame(['Welcome!', 'Team size?', 'We will be in touch.'], $this->sent($account));
        $this->assertSame(WhatsAppFlowSession::STATUS_COMPLETED, WhatsAppFlowSession::sole()->status);
        $this->assertSame(0, Lead::count());
    }

    public function test_a_reply_to_a_deactivated_flow_expires_the_session(): void
    {
        $account = $this->qrAccount();
        $flow = $this->qualifierFlow($account);
        $this->inbound($account, 'join');
        $flow->forceFill(['is_active' => false])->save();

        $this->inbound($account, '50');

        $this->assertSame(WhatsAppFlowSession::STATUS_EXPIRED, WhatsAppFlowSession::sole()->status);
        $this->assertSame(['Welcome!', 'Team size?'], $this->sent($account));
    }

    public function test_a_node_type_the_engine_does_not_execute_expires_the_session(): void
    {
        $account = $this->qrAccount();
        WhatsAppFlow::create([
            'account_id' => $account->id, 'name' => 'Palette', 'trigger_type' => 'keyword', 'trigger_value' => 'go', 'is_active' => true,
            'graph_data' => [
                'nodes' => [['id' => 't', 'type' => 'trigger', 'data' => []], ['id' => 'm', 'type' => 'message', 'data' => ['text' => 'Hi']], ['id' => 'x', 'type' => 'email', 'data' => []]],
                'edges' => [['id' => 'e1', 'source' => 't', 'target' => 'm'], ['id' => 'e2', 'source' => 'm', 'target' => 'x']],
            ],
        ]);

        $this->inbound($account, 'go');

        $this->assertSame(['Hi'], $this->sent($account));
        $this->assertSame(WhatsAppFlowSession::STATUS_EXPIRED, WhatsAppFlowSession::sole()->status);
    }

    public function test_a_cyclic_graph_is_cut_off_by_the_step_guard(): void
    {
        $account = $this->qrAccount();
        WhatsAppFlow::create([
            'account_id' => $account->id, 'name' => 'Loop', 'trigger_type' => 'keyword', 'trigger_value' => 'loop', 'is_active' => true,
            'graph_data' => [
                'nodes' => [['id' => 't', 'type' => 'trigger', 'data' => []], ['id' => 'a', 'type' => 'message', 'data' => ['text' => 'A']], ['id' => 'b', 'type' => 'message', 'data' => ['text' => 'B']]],
                'edges' => [['id' => 'e1', 'source' => 't', 'target' => 'a'], ['id' => 'e2', 'source' => 'a', 'target' => 'b'], ['id' => 'e3', 'source' => 'b', 'target' => 'a']],
            ],
        ]);

        $this->inbound($account, 'loop');

        $this->assertCount(25, $this->sent($account));
        $this->assertSame(WhatsAppFlowSession::STATUS_EXPIRED, WhatsAppFlowSession::sole()->status);
    }

    public function test_no_matching_flow_falls_through_to_chatbot_rules(): void
    {
        $account = $this->qrAccount();
        $this->qualifierFlow($account);

        $this->assertFalse(app(WhatsAppJourneyEngine::class)->handleInboundMessage($account->id, self::PHONE, 'unrelated'));
        $this->assertSame(0, WhatsAppFlowSession::count());
    }

    public function test_sessions_are_scoped_to_their_own_account(): void
    {
        $a = $this->qrAccount();
        $b = $this->qrAccount();
        $this->qualifierFlow($a);
        $this->inbound($a, 'join');

        // Same phone, other tenant, no flow there: nothing of A's session is visible to B.
        $this->assertFalse(app(WhatsAppJourneyEngine::class)->handleInboundMessage($b->id, self::PHONE, '50'));
        $this->assertSame('q', WhatsAppFlowSession::sole()->current_node_id);
        $this->assertSame([], $this->sent($b));
    }

    public function test_the_manual_test_run_expires_a_previous_session_and_starts_clean(): void
    {
        $account = $this->qrAccount();
        $flow = $this->qualifierFlow($account);
        $this->inbound($account, 'join');

        app(WhatsAppJourneyEngine::class)->testFlow($account, $flow, self::PHONE);

        $this->assertSame([WhatsAppFlowSession::STATUS_EXPIRED, WhatsAppFlowSession::STATUS_ACTIVE], WhatsAppFlowSession::orderBy('id')->pluck('status')->all());
    }
}
