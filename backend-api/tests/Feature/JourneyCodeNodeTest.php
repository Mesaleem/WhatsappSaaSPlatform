<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Invoice;
use App\Models\MessageDispatchLog;
use App\Models\User;
use App\Models\WhatsAppFlow;
use App\Models\WhatsAppFlowSession;
use App\Models\WhatsAppNumber;
use App\Models\WhatsAppSession;
use App\Services\Billing\InvoiceCreditService;
use App\Services\Chatbot\ChatbotEngineService;
use App\Support\PlanCatalog;
use Database\Seeders\Phase1FoundationSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Task 26 — Connexxa parity, scoped: the 'code' node. NOT real
 * JavaScript — JourneyCodeSandbox is a tiny, closed expression language
 * of this platform's own (no loops, no function calls, no eval of any
 * kind — see that class's docblock for the full reasoning, confirmed
 * with the account owner before it was built). These tests prove both
 * layers: save-time parsing (JourneyActionConfig::codeError()) and real
 * run-time execution through the inbound path (ChatbotEngineService →
 * WhatsAppJourneyEngine), same structure as JourneyInteractiveOptionTest.
 */
class JourneyCodeNodeTest extends TestCase
{
    use RefreshDatabase;

    private const PHONE = '919812345678';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        $this->seed(Phase1FoundationSeeder::class);
        Http::fake(['*' => Http::response(['success' => true, 'message_id' => 'QR_OK'], 200)]);
    }

    // ================================================================== fixtures

    /** 'business' plan — bundles 'custom_code', so no manual entitlement grant is needed (unlike journey_automation on the lighter 'qr' plans elsewhere). */
    private function account(): Account
    {
        $account = Account::factory()->create();
        $p = PlanCatalog::find('business');
        $invoice = Invoice::create([
            'account_id' => $account->id, 'invoice_number' => 'INV-'.uniqid(), 'plan_key' => 'business',
            'plan_label' => $p['label'], 'amount' => $p['price'], 'tax_amount' => 0,
            'total_amount' => $p['price'], 'currency' => 'INR', 'payment_gateway' => 'razorpay',
            'gateway_order_id' => 'order_'.uniqid(), 'status' => 'pending',
        ]);
        app(InvoiceCreditService::class)->markPaidAndCreditQuota($invoice->id, 'pay_'.uniqid());
        WhatsAppSession::create(['account_id' => $account->id, 'status' => 'connected']);

        return $account->fresh();
    }

    private function admin(Account $account): User
    {
        $user = User::factory()->create(['account_id' => $account->id, 'is_active' => true]);
        $user->assignRole('admin');

        return $user;
    }

    private function e(string $source, string $target, array $extra = []): array
    {
        return ['id' => "{$source}_{$target}_".uniqid(), 'source' => $source, 'target' => $target] + $extra;
    }

    private function q(string $id, string $variable): array
    {
        return ['id' => $id, 'type' => 'question', 'data' => ['prompt_text' => "Q {$variable}?", 'variable_name' => $variable, 'input_type' => 'text']];
    }

    private function msg(string $id, string $text): array
    {
        return ['id' => $id, 'type' => 'message', 'data' => ['text' => $text]];
    }

    private function codeNode(string $code, string $outputVariable = 'result'): array
    {
        return ['id' => 'c', 'type' => 'code', 'data' => ['code' => $code, 'outputVariable' => $outputVariable]];
    }

    /** trigger → question('score') → code('c') → message using {{result}}. */
    private function scoreFlow(Account $account, string $code): WhatsAppFlow
    {
        return WhatsAppFlow::create([
            'account_id' => $account->id, 'name' => 'Scored', 'trigger_type' => 'keyword',
            'trigger_value' => 'go', 'is_active' => true,
            'graph_data' => [
                'nodes' => [
                    ['id' => 't', 'type' => 'trigger', 'data' => []],
                    $this->q('q', 'score'),
                    $this->codeNode($code),
                    $this->msg('m', 'Result: {{result}}'),
                ],
                'edges' => [$this->e('t', 'q'), $this->e('q', 'c'), $this->e('c', 'm')],
            ],
        ]);
    }

    private function inbound(Account $account, string $text, string $phone = self::PHONE): void
    {
        app(ChatbotEngineService::class)->handleInboundMessage($account->id, $phone, $text, null, 'meta', 'wamid:'.uniqid());
    }

    /** @return list<string> */
    private function sent(Account $account, string $phone = self::PHONE): array
    {
        return MessageDispatchLog::where('account_id', $account->id)->where('source', 'journey')->where('status', 'sent')
            ->where('recipient_phone', $phone)->orderBy('id')->pluck('message_preview')->all();
    }

    private function flowSession(Account $account, string $phone = self::PHONE): WhatsAppFlowSession
    {
        return WhatsAppFlowSession::where('account_id', $account->id)->where('phone_number', $phone)->latest('id')->firstOrFail();
    }

    // ================================================================== run-time execution

    public function test_code_node_computes_from_a_collected_answer_and_continues(): void
    {
        $account = $this->account();
        $this->scoreFlow($account, "let score = var_local.score; if (score >= 50) { output = 'pass'; } else { output = 'fail'; }");

        $this->inbound($account, 'go');
        $this->inbound($account, '72');

        $this->assertSame(['Q score?', 'Result: pass'], $this->sent($account));
        $this->assertSame(WhatsAppFlowSession::STATUS_COMPLETED, $this->flowSession($account)->status);
        $this->assertSame('pass', $this->flowSession($account)->context_data['result'] ?? null);
    }

    public function test_code_node_takes_the_else_branch(): void
    {
        $account = $this->account();
        $this->scoreFlow($account, "let score = var_local.score; if (score >= 50) { output = 'pass'; } else { output = 'fail'; }");

        $this->inbound($account, 'go');
        $this->inbound($account, '10');

        $this->assertSame(['Q score?', 'Result: fail'], $this->sent($account));
    }

    public function test_code_node_reads_var_system(): void
    {
        $account = $this->account();
        $flow = WhatsAppFlow::create([
            'account_id' => $account->id, 'name' => 'System var', 'trigger_type' => 'keyword',
            'trigger_value' => 'go', 'is_active' => true,
            'graph_data' => [
                'nodes' => [
                    ['id' => 't', 'type' => 'trigger', 'data' => []],
                    $this->codeNode('output = var_system.userChatId;'),
                    $this->msg('m', 'Phone: {{result}}'),
                ],
                'edges' => [$this->e('t', 'c'), $this->e('c', 'm')],
            ],
        ]);

        $this->inbound($account, 'go');

        $this->assertSame(['Phone: '.self::PHONE], $this->sent($account));
    }

    public function test_an_unknown_variable_fails_the_session_at_run_time(): void
    {
        // Syntactically valid (a bare identifier is always parseable),
        // so this is NOT caught by codeError()'s save-time parse check
        // — only at run time, once the sandbox actually tries to
        // resolve it. Same "never guess past it" contract as a
        // malformed conditional rule.
        $account = $this->account();
        WhatsAppFlow::create([
            'account_id' => $account->id, 'name' => 'Bad ref', 'trigger_type' => 'keyword',
            'trigger_value' => 'go', 'is_active' => true,
            'graph_data' => [
                'nodes' => [
                    ['id' => 't', 'type' => 'trigger', 'data' => []],
                    $this->codeNode('output = mystery;'),
                ],
                'edges' => [$this->e('t', 'c')],
            ],
        ]);

        $this->inbound($account, 'go');

        $session = $this->flowSession($account);
        $this->assertSame(WhatsAppFlowSession::STATUS_FAILED, $session->status);
        $this->assertStringContainsString("Unknown variable 'mystery'", (string) $session->last_error);
    }

    public function test_division_by_zero_fails_the_session_at_run_time(): void
    {
        $account = $this->account();
        WhatsAppFlow::create([
            'account_id' => $account->id, 'name' => 'Div zero', 'trigger_type' => 'keyword',
            'trigger_value' => 'go', 'is_active' => true,
            'graph_data' => [
                'nodes' => [
                    ['id' => 't', 'type' => 'trigger', 'data' => []],
                    $this->codeNode('output = 1 / 0;'),
                ],
                'edges' => [$this->e('t', 'c')],
            ],
        ]);

        $this->inbound($account, 'go');

        $this->assertSame(WhatsAppFlowSession::STATUS_FAILED, $this->flowSession($account)->status);
    }

    // ================================================================== save-time validation

    private function saveAs(Account $account, array $codeData)
    {
        $user = $this->admin($account);
        $number = WhatsAppNumber::firstOrCreate(
            ['account_id' => $account->id],
            ['phone_number' => '91903'.str_pad((string) $account->id, 6, '0', STR_PAD_LEFT), 'status' => WhatsAppNumber::STATUS_LINKED],
        );
        $node = ['id' => 'c', 'type' => 'code', 'data' => $codeData];

        return $this->actingAs($user)->postJson('/api/whatsapp/flows', [
            'name' => 'Code', 'trigger_type' => 'keyword', 'trigger_value' => 'go', 'is_active' => true, 'publish' => false,
            'whatsapp_number_ids' => [$number->id],
            'graph_data' => ['nodes' => [['id' => 't', 'type' => 'trigger', 'data' => []], $node], 'edges' => [$this->e('t', 'c')]],
        ]);
    }

    public function test_an_empty_draft_code_node_is_savable(): void
    {
        $this->saveAs($this->account(), [])->assertStatus(201);
    }

    public function test_well_formed_code_saves(): void
    {
        $this->saveAs($this->account(), ['code' => "output = 1 + 2;", 'outputVariable' => 'sum'])->assertStatus(201);
    }

    public function test_malformed_code_is_refused_even_as_a_draft(): void
    {
        $this->saveAs($this->account(), ['code' => 'output = (1 + 2;', 'outputVariable' => 'sum'])
            ->assertStatus(422)->assertJsonValidationErrors('graph_data.nodes.1.data');
    }

    public function test_real_javascript_syntax_is_refused(): void
    {
        // The exact trap this feature exists to avoid: a user pasting
        // real JS (a for-loop) gets a clear parse error, not silent
        // misbehaviour or, worse, something that looks like it ran.
        $this->saveAs($this->account(), ['code' => 'for (let i = 0; i < 10; i++) { output = i; }', 'outputVariable' => 'x'])
            ->assertStatus(422)->assertJsonValidationErrors('graph_data.nodes.1.data');
    }

    public function test_output_variable_name_is_checked(): void
    {
        $this->saveAs($this->account(), ['code' => 'output = 1;', 'outputVariable' => 'not a valid name!'])
            ->assertStatus(422)->assertJsonValidationErrors('graph_data.nodes.1.data');
    }

    public function test_a_missing_output_variable_is_still_a_savable_draft(): void
    {
        // Same draft-permissive rule as every other value-producing node
        // (prompt/agent/rag's own outputVariable) — only non-empty-but-
        // malformed values are refused at draft time; completeness is
        // enforced only at publish time (JourneyPublishValidator, via
        // JourneyActionConfig::error(..., draft: false)).
        $this->saveAs($this->account(), ['code' => 'output = 1;'])->assertStatus(201);
    }
}
