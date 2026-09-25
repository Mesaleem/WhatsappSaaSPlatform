<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\AccountEntitlement;
use App\Models\Capability;
use App\Models\ChatbotLog;
use App\Models\ChatbotRule;
use App\Models\Invoice;
use App\Models\Lead;
use App\Models\MessageDispatchLog;
use App\Models\User;
use App\Models\WhatsAppFlow;
use App\Models\WhatsAppFlowSession;
use App\Models\WhatsAppSession;
use App\Services\Billing\InvoiceCreditService;
use App\Services\Chatbot\ChatbotEngineService;
use App\Support\PlanCatalog;
use Database\Seeders\Phase1FoundationSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Phase 7 Task 4 — Journey branching, end to end through the real inbound
 * path (ChatbotEngineService → InboundEventGate → WhatsAppJourneyEngine),
 * for both branching nodes: the legacy edge-carried `condition` and the
 * palette `conditional` (rules + match all/any + true/false handles).
 *
 * Every flow asks one or two questions, so the condition always evaluates
 * against real collected answers. What was sent is read back from
 * message_dispatch_logs.
 */
class JourneyConditionBranchingTest extends TestCase
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
            'gateway_order_id' => 'order_'.uniqid(), 'status' => 'pending',
        ]);
        app(InvoiceCreditService::class)->markPaidAndCreditQuota($invoice->id, 'pay_'.uniqid());
        WhatsAppSession::create(['account_id' => $account->id, 'status' => 'connected']);
        AccountEntitlement::firstOrCreate(
            ['account_id' => $account->id, 'capability_id' => Capability::where('slug', 'journey_automation')->value('id')],
            ['source' => 'manual_grant', 'granted_by_account_id' => null],
        );

        return $account->fresh();
    }

    private function q(string $id, string $variable, ?string $prompt = null): array
    {
        return ['id' => $id, 'type' => 'question', 'data' => ['prompt_text' => $prompt ?? "Q {$variable}?", 'variable_name' => $variable, 'input_type' => 'text']];
    }

    private function msg(string $id, string $text): array
    {
        return ['id' => $id, 'type' => 'message', 'data' => ['text' => $text]];
    }

    private function e(string $source, string $target, array $extra = []): array
    {
        return ['id' => "{$source}_{$target}_".count($extra).uniqid(), 'source' => $source, 'target' => $target] + $extra;
    }

    /**
     * trigger → question(ans) → [branch node + rest]. $nodes/$edges are the
     * part after the question; the question's edge goes to $branchId.
     */
    private function flow(Account $account, array $nodes, array $edges, string $branchId = 'c', string $keyword = 'go', ?array $questions = null): WhatsAppFlow
    {
        $questions ??= [$this->q('q', 'ans')];
        $chain = [['id' => 't', 'type' => 'trigger', 'data' => []], ...$questions];
        $chainEdges = [];
        $prev = 't';
        foreach ($questions as $question) {
            $chainEdges[] = $this->e($prev, $question['id']);
            $prev = $question['id'];
        }
        $chainEdges[] = $this->e($prev, $branchId);

        return WhatsAppFlow::create([
            'account_id' => $account->id, 'name' => 'Branching', 'trigger_type' => 'keyword',
            'trigger_value' => $keyword, 'is_active' => true,
            'graph_data' => ['nodes' => [...$chain, ...$nodes], 'edges' => [...$chainEdges, ...$edges]],
        ]);
    }

    /** Legacy condition on `ans`: each [operator, value, text] is one edge, in order; then an optional default. */
    private function legacyFlow(Account $account, array $branches, ?string $defaultText = 'DEFAULT'): WhatsAppFlow
    {
        $nodes = [['id' => 'c', 'type' => 'condition', 'data' => ['variable' => 'ans']]];
        $edges = [];
        foreach ($branches as $i => [$operator, $value, $text]) {
            $nodes[] = $this->msg("m{$i}", $text);
            $condition = ['operator' => $operator];
            if ($value !== null) {
                $condition['value'] = $value;
            }
            $edges[] = $this->e('c', "m{$i}", ['condition' => $condition]);
        }
        if ($defaultText !== null) {
            $nodes[] = $this->msg('md', $defaultText);
            $edges[] = $this->e('c', 'md', ['is_default' => true]);
        }

        return $this->flow($account, $nodes, $edges);
    }

    /** Conditional node with rules + match; TRUE → "YES", FALSE → "NO". */
    private function conditionalFlow(Account $account, array $conditions, ?string $match = 'all', ?array $questions = null, bool $withFalse = true): WhatsAppFlow
    {
        $data = ['conditions' => $conditions];
        if ($match !== null) {
            $data['match'] = $match;
        }
        $nodes = [['id' => 'c', 'type' => 'conditional', 'data' => $data], $this->msg('yes', 'YES'), $this->msg('no', 'NO')];
        $edges = [$this->e('c', 'yes', ['sourceHandle' => 'true'])];
        if ($withFalse) {
            $edges[] = $this->e('c', 'no', ['sourceHandle' => 'false']);
        }

        return $this->flow($account, $nodes, $edges, questions: $questions);
    }

    private function inbound(Account $account, string $text, string $phone = self::PHONE, ?string $messageId = null): void
    {
        app(ChatbotEngineService::class)->handleInboundMessage($account->id, $phone, $text, null, 'qr', $messageId ? "wamsg:{$messageId}" : null);
    }

    /** Start the flow and answer its question(s). */
    private function runJourney(Account $account, string ...$answers): void
    {
        $this->inbound($account, 'go');
        foreach ($answers as $answer) {
            $this->inbound($account, $answer);
        }
    }

    /** Messages sent AFTER the question prompts (i.e. what the branch did). */
    private function branchOutput(Account $account, string $phone = self::PHONE): array
    {
        return MessageDispatchLog::where('account_id', $account->id)->where('source', 'journey')->where('recipient_phone', $phone)
            ->orderBy('id')->pluck('message_preview')
            ->reject(fn ($p) => str_starts_with((string) $p, 'Q '))->values()->all();
    }

    private function flowSession(Account $account, string $phone = self::PHONE): WhatsAppFlowSession
    {
        return WhatsAppFlowSession::where('account_id', $account->id)->where('phone_number', $phone)->latest('id')->firstOrFail();
    }

    // ==================================================================
    // Operators, end to end (legacy condition node)
    // ==================================================================

    public static function legacyOperatorCases(): array
    {
        return [
            'equals' => ['equals', 'yes', ' YES ', 'HIT'],
            'not_equals' => ['not_equals', 'yes', 'no', 'HIT'],
            'contains' => ['contains', 'price', 'what is the Price?', 'HIT'],
            'not_contains' => ['not_contains', 'price', 'hello', 'HIT'],
            'starts_with' => ['starts_with', 'order', 'Order 55', 'HIT'],
            'ends_with' => ['ends_with', 'please', 'call me please', 'HIT'],
            'greater_than' => ['greater_than', '50', '51', 'HIT'],
            'greater_than miss' => ['greater_than', '50', '50', 'DEFAULT'],
            'less_than' => ['less_than', '10', '9.5', 'HIT'],
            'greater_or_equal' => ['greater_or_equal', '50', '50', 'HIT'],
            'less_or_equal' => ['less_or_equal', '10', '11', 'DEFAULT'],
            'numeric vs text answer' => ['greater_than', '5', 'many', 'DEFAULT'],
            'exists' => ['exists', null, 'anything', 'HIT'],
            'not_exists on an answered question' => ['not_exists', null, 'x', 'DEFAULT'],
        ];
    }

    #[DataProvider('legacyOperatorCases')]
    public function test_every_operator_selects_its_branch_end_to_end(string $operator, ?string $value, string $answer, string $expected): void
    {
        $account = $this->qrAccount();
        $this->legacyFlow($account, [[$operator, $value, 'HIT']]);

        $this->runJourney($account, $answer);

        $this->assertSame([$expected], $this->branchOutput($account));
        $this->assertSame(WhatsAppFlowSession::STATUS_COMPLETED, $this->flowSession($account)->status);
    }

    public function test_the_existing_legacy_operators_keep_their_meaning(): void
    {
        // equals/not_equals/contains/exists as the pre-Task-4 engine: trimmed, case-insensitive.
        $account = $this->qrAccount();
        $this->legacyFlow($account, [['equals', 'Gold', 'GOLD'], ['contains', 'silver', 'SILVER'], ['not_equals', 'x', 'OTHER']]);

        $this->runJourney($account, '  gold ');
        $this->assertSame(['GOLD'], $this->branchOutput($account));
    }

    public function test_an_edge_condition_without_an_operator_still_means_equals(): void
    {
        $account = $this->qrAccount();
        $this->flow($account, [
            ['id' => 'c', 'type' => 'condition', 'data' => ['variable' => 'ans']],
            $this->msg('a', 'A'), $this->msg('b', 'B'),
        ], [
            $this->e('c', 'a', ['condition' => ['value' => 'x']]),
            $this->e('c', 'b', ['is_default' => true]),
        ]);

        $this->runJourney($account, 'X');
        $this->assertSame(['A'], $this->branchOutput($account));
    }

    // ==================================================================
    // Multiple branches, order, default, dead end
    // ==================================================================

    public function test_the_first_matching_edge_in_stored_order_wins(): void
    {
        $account = $this->qrAccount();
        // Both match "gold plan"; the first edge must win, every time.
        $this->legacyFlow($account, [['contains', 'plan', 'FIRST'], ['contains', 'gold', 'SECOND']]);

        $this->runJourney($account, 'gold plan');
        $this->assertSame(['FIRST'], $this->branchOutput($account));
    }

    public function test_no_match_follows_the_default_branch(): void
    {
        $account = $this->qrAccount();
        $this->legacyFlow($account, [['equals', 'a', 'A'], ['equals', 'b', 'B']]);

        $this->runJourney($account, 'c');
        $this->assertSame(['DEFAULT'], $this->branchOutput($account));
    }

    public function test_no_match_and_no_default_is_a_dead_end_that_completes_silently(): void
    {
        $account = $this->qrAccount();
        $this->legacyFlow($account, [['equals', 'a', 'A']], defaultText: null);

        $this->runJourney($account, 'zzz');

        $this->assertSame([], $this->branchOutput($account));
        $session = $this->flowSession($account);
        $this->assertSame(WhatsAppFlowSession::STATUS_COMPLETED, $session->status);
        $this->assertNull($session->last_error);
    }

    // ==================================================================
    // conditional: AND / OR / true / false
    // ==================================================================

    public function test_conditional_all_is_and(): void
    {
        $questions = [$this->q('q1', 'size'), $this->q('q2', 'plan')];
        $rules = [['variable' => 'size', 'operator' => 'greater_or_equal', 'value' => '10'], ['variable' => 'plan', 'operator' => 'equals', 'value' => 'pro']];

        $both = $this->qrAccount();
        $this->conditionalFlow($both, $rules, 'all', $questions);
        $this->runJourney($both, '12', 'Pro');
        $this->assertSame(['YES'], $this->branchOutput($both));

        $one = $this->qrAccount();
        $this->conditionalFlow($one, $rules, 'all', $questions);
        $this->runJourney($one, '12', 'free');
        $this->assertSame(['NO'], $this->branchOutput($one));
    }

    public function test_conditional_any_is_or(): void
    {
        $questions = [$this->q('q1', 'size'), $this->q('q2', 'plan')];
        $rules = [['variable' => 'size', 'operator' => 'greater_or_equal', 'value' => '10'], ['variable' => 'plan', 'operator' => 'equals', 'value' => 'pro']];

        $one = $this->qrAccount();
        $this->conditionalFlow($one, $rules, 'any', $questions);
        $this->runJourney($one, '3', 'pro');
        $this->assertSame(['YES'], $this->branchOutput($one));

        $none = $this->qrAccount();
        $this->conditionalFlow($none, $rules, 'any', $questions);
        $this->runJourney($none, '3', 'free');
        $this->assertSame(['NO'], $this->branchOutput($none));
    }

    public function test_conditional_match_defaults_to_all(): void
    {
        $account = $this->qrAccount();
        $this->conditionalFlow($account, [['variable' => 'ans', 'operator' => 'exists'], ['variable' => 'missing', 'operator' => 'exists']], null);

        $this->runJourney($account, 'hi');
        $this->assertSame(['NO'], $this->branchOutput($account));
    }

    public function test_conditional_on_a_variable_the_journey_never_collected(): void
    {
        $account = $this->qrAccount();
        $this->conditionalFlow($account, [['variable' => 'never_asked', 'operator' => 'not_exists']]);

        $this->runJourney($account, 'hi');
        $this->assertSame(['YES'], $this->branchOutput($account));
    }

    public function test_conditional_with_no_edge_on_the_chosen_handle_completes_silently(): void
    {
        $account = $this->qrAccount();
        $this->conditionalFlow($account, [['variable' => 'ans', 'operator' => 'equals', 'value' => 'yes']], withFalse: false);

        $this->runJourney($account, 'no');

        $this->assertSame([], $this->branchOutput($account));
        $this->assertSame(WhatsAppFlowSession::STATUS_COMPLETED, $this->flowSession($account)->status);
    }

    // ==================================================================
    // Malformed definitions: fail safely, send nothing, record why
    // ==================================================================

    public static function malformedFlows(): array
    {
        return [
            'legacy unknown operator' => ['legacy', ['operator' => 'regex', 'value' => '.*'], 'Unknown condition operator'],
            'legacy numeric op, text value' => ['legacy', ['operator' => 'greater_than', 'value' => 'ten'], 'must be a number'],
            'legacy condition not an object' => ['legacy', 'equals', 'malformed condition'],
            'conditional unknown operator' => ['conditional', [['variable' => 'ans', 'operator' => 'php_eval', 'value' => 'x']], 'Unknown condition operator'],
            'conditional numeric op, text value' => ['conditional', [['variable' => 'ans', 'operator' => 'less_than', 'value' => 'abc']], 'must be a number'],
            'conditional no rules' => ['conditional', [], 'at least one condition'],
            'conditional rule without variable' => ['conditional', [['operator' => 'exists']], 'variable is required'],
            'conditional rules not a list' => ['conditional', 'ans == 1', 'must be a list'],
        ];
    }

    #[DataProvider('malformedFlows')]
    public function test_a_malformed_condition_fails_the_session_and_sends_nothing(string $kind, mixed $definition, string $error): void
    {
        $account = $this->qrAccount();
        // Hand-crafted graphs, written straight to the model: the save API refuses these.
        ChatbotRule::create(['account_id' => $account->id, 'name' => 'Fallback', 'match_type' => 'fallback', 'keywords' => [], 'response_type' => 'text', 'response_payload' => ['text' => 'FALLBACK'], 'priority' => 1, 'is_active' => true]);

        if ($kind === 'legacy') {
            $this->flow($account, [
                ['id' => 'c', 'type' => 'condition', 'data' => ['variable' => 'ans']],
                $this->msg('a', 'A'), $this->msg('d', 'DEFAULT'),
            ], [
                $this->e('c', 'a', ['condition' => $definition]),
                $this->e('c', 'd', ['is_default' => true]),
            ]);
        } else {
            $this->flow($account, [
                ['id' => 'c', 'type' => 'conditional', 'data' => ['conditions' => $definition, 'match' => 'all']],
                $this->msg('yes', 'YES'), $this->msg('no', 'NO'),
            ], [
                $this->e('c', 'yes', ['sourceHandle' => 'true']),
                $this->e('c', 'no', ['sourceHandle' => 'false']),
            ]);
        }

        $this->runJourney($account, 'anything');

        $this->assertSame([], $this->branchOutput($account), 'no branch message, no default, no guess');
        $session = $this->flowSession($account);
        $this->assertSame(WhatsAppFlowSession::STATUS_FAILED, $session->status);
        $this->assertSame('c', $session->current_node_id);
        $this->assertStringContainsString("Condition node 'c'", (string) $session->last_error);
        $this->assertStringContainsString($error, (string) $session->last_error);
        $this->assertSame(0, ChatbotLog::where('account_id', $account->id)->count(), 'the chatbot fallback is not run either');
        $this->assertSame(0, MessageDispatchLog::where('account_id', $account->id)->where('message_preview', 'FALLBACK')->count());

        // The conversation is released: the next message is handled normally, nothing crashes.
        $this->inbound($account, 'hello again');
        $this->assertSame(WhatsAppFlowSession::STATUS_FAILED, $session->fresh()->status);
    }

    public function test_a_legacy_condition_without_a_variable_fails_instead_of_branching_on_nothing(): void
    {
        $account = $this->qrAccount();
        $this->flow($account, [
            ['id' => 'c', 'type' => 'condition', 'data' => []],
            $this->msg('d', 'DEFAULT'),
        ], [$this->e('c', 'd', ['is_default' => true])]);

        $this->runJourney($account, 'x');

        $this->assertSame([], $this->branchOutput($account));
        $this->assertSame(WhatsAppFlowSession::STATUS_FAILED, $this->flowSession($account)->status);
        $this->assertStringContainsString('No variable', (string) $this->flowSession($account)->last_error);
    }

    public function test_two_edges_on_one_conditional_handle_is_ambiguous_and_fails(): void
    {
        $account = $this->qrAccount();
        $this->flow($account, [
            ['id' => 'c', 'type' => 'conditional', 'data' => ['conditions' => [['variable' => 'ans', 'operator' => 'exists']]]],
            $this->msg('y1', 'Y1'), $this->msg('y2', 'Y2'),
        ], [
            $this->e('c', 'y1', ['sourceHandle' => 'true']),
            $this->e('c', 'y2', ['sourceHandle' => 'true']),
        ]);

        $this->runJourney($account, 'x');

        $this->assertSame([], $this->branchOutput($account));
        $this->assertStringContainsString('More than one connection', (string) $this->flowSession($account)->last_error);
    }

    // ==================================================================
    // Determinism
    // ==================================================================

    public function test_the_same_version_state_and_answer_always_select_the_same_branch(): void
    {
        $outputs = [];

        for ($i = 0; $i < 5; $i++) {
            $account = $this->qrAccount();
            $this->legacyFlow($account, [['greater_than', '10', 'BIG'], ['contains', '1', 'HAS_ONE']]);
            $this->runJourney($account, '15');
            $outputs[] = $this->branchOutput($account);
        }

        $this->assertSame(array_fill(0, 5, ['BIG']), $outputs);
    }

    // ==================================================================
    // Condition → Delay / save_lead / condition
    // ==================================================================

    public function test_a_condition_can_branch_into_a_delay_which_parks_the_session(): void
    {
        $account = $this->qrAccount();
        $this->flow($account, [
            ['id' => 'c', 'type' => 'conditional', 'data' => ['conditions' => [['variable' => 'ans', 'operator' => 'equals', 'value' => 'later']]]],
            ['id' => 'wait', 'type' => 'delay', 'data' => ['amount' => 2, 'unit' => 'hours']],
            $this->msg('after', 'AFTER'), $this->msg('now', 'NOW'),
        ], [
            $this->e('c', 'wait', ['sourceHandle' => 'true']),
            $this->e('c', 'now', ['sourceHandle' => 'false']),
            $this->e('wait', 'after'),
        ]);

        $this->runJourney($account, 'later');

        $session = $this->flowSession($account);
        $this->assertSame(WhatsAppFlowSession::STATUS_WAITING, $session->status);
        $this->assertSame('wait', $session->current_node_id);
        $this->assertSame([], $this->branchOutput($account));
    }

    public function test_a_condition_can_branch_into_save_lead(): void
    {
        $account = $this->qrAccount();
        $this->flow($account, [
            ['id' => 'c', 'type' => 'condition', 'data' => ['variable' => 'ans']],
            ['id' => 'save', 'type' => 'save_lead', 'data' => ['completion_message' => 'SAVED']],
            $this->msg('bye', 'BYE'),
        ], [
            $this->e('c', 'save', ['condition' => ['operator' => 'greater_or_equal', 'value' => '50']]),
            $this->e('c', 'bye', ['is_default' => true]),
        ]);

        $this->runJourney($account, '75');

        $this->assertSame(['SAVED'], $this->branchOutput($account));
        $this->assertSame(1, Lead::where('account_id', $account->id)->where('provider', 'whatsapp_journey')->count());
        $this->assertSame(WhatsAppFlowSession::STATUS_COMPLETED, $this->flowSession($account)->status);
    }

    public function test_a_condition_can_branch_into_another_condition(): void
    {
        $account = $this->qrAccount();
        $this->flow($account, [
            ['id' => 'c', 'type' => 'condition', 'data' => ['variable' => 'ans']],
            ['id' => 'c2', 'type' => 'conditional', 'data' => ['conditions' => [['variable' => 'ans', 'operator' => 'ends_with', 'value' => '9']]]],
            $this->msg('nine', 'ENDS_9'), $this->msg('other', 'OTHER'), $this->msg('small', 'SMALL'),
        ], [
            $this->e('c', 'c2', ['condition' => ['operator' => 'greater_than', 'value' => '100']]),
            $this->e('c', 'small', ['is_default' => true]),
            $this->e('c2', 'nine', ['sourceHandle' => 'true']),
            $this->e('c2', 'other', ['sourceHandle' => 'false']),
        ]);

        $this->runJourney($account, '199');
        $this->assertSame(['ENDS_9'], $this->branchOutput($account));
    }

    // ==================================================================
    // Loops and the step limit
    // ==================================================================

    public function test_a_loop_through_a_question_is_allowed_and_ends_when_the_condition_is_met(): void
    {
        $account = $this->qrAccount();
        // q → c: "yes" → done; else → retry message → back to q.
        $this->flow($account, [
            ['id' => 'c', 'type' => 'condition', 'data' => ['variable' => 'ans']],
            $this->msg('retry', 'RETRY'), $this->msg('done', 'DONE'),
        ], [
            $this->e('c', 'done', ['condition' => ['operator' => 'equals', 'value' => 'yes']]),
            $this->e('c', 'retry', ['is_default' => true]),
            $this->e('retry', 'q'),
        ]);

        $this->runJourney($account, 'no', 'maybe', 'yes');

        $this->assertSame(['RETRY', 'RETRY', 'DONE'], $this->branchOutput($account));
        $this->assertSame(WhatsAppFlowSession::STATUS_COMPLETED, $this->flowSession($account)->status);
    }

    public function test_a_loop_through_conditions_alone_is_stopped_by_the_step_limit(): void
    {
        $account = $this->qrAccount();
        // c → m → c2 → m → c … with an answer that never changes: an infinite loop.
        $this->flow($account, [
            ['id' => 'c', 'type' => 'condition', 'data' => ['variable' => 'ans']],
            ['id' => 'c2', 'type' => 'conditional', 'data' => ['conditions' => [['variable' => 'ans', 'operator' => 'exists']]]],
            $this->msg('m', 'LOOP'),
        ], [
            $this->e('c', 'm', ['is_default' => true]),
            $this->e('m', 'c2'),
            $this->e('c2', 'c', ['sourceHandle' => 'true']),
        ]);

        $this->runJourney($account, 'x');

        $session = $this->flowSession($account);
        $this->assertSame(WhatsAppFlowSession::STATUS_EXPIRED, $session->status);
        $this->assertStringContainsString('Stopped after 25 steps', (string) $session->last_error);
        // 25 steps over a 3-node cycle starting at c: m is visited 8 times.
        $this->assertCount(8, $this->branchOutput($account));
    }

    public function test_a_pure_condition_self_loop_is_stopped_without_sending(): void
    {
        $account = $this->qrAccount();
        $this->flow($account, [
            ['id' => 'c', 'type' => 'conditional', 'data' => ['conditions' => [['variable' => 'ans', 'operator' => 'exists']]]],
        ], [$this->e('c', 'c', ['sourceHandle' => 'true'])]);

        $this->runJourney($account, 'x');

        $this->assertSame([], $this->branchOutput($account));
        $this->assertSame(WhatsAppFlowSession::STATUS_EXPIRED, $this->flowSession($account)->status);
    }

    // ==================================================================
    // Version isolation
    // ==================================================================

    public function test_a_paused_session_branches_on_its_pinned_version_after_the_journey_is_edited(): void
    {
        $account = $this->qrAccount();
        $flow = $this->legacyFlow($account, [['equals', 'yes', 'OLD_YES']], 'OLD_DEFAULT');
        $this->inbound($account, 'go');
        $pinned = $this->flowSession($account)->flow_version_id;

        // New version: the same answer now means something else.
        $graph = $flow->graph_data;
        foreach ($graph['edges'] as &$edge) {
            if (isset($edge['condition'])) {
                $edge['condition'] = ['operator' => 'not_equals', 'value' => 'yes'];
            }
        }
        unset($edge);
        foreach ($graph['nodes'] as &$node) {
            if (($node['type'] ?? null) === 'message') {
                $node['data']['text'] = 'NEW_'.$node['data']['text'];
            }
        }
        unset($node);
        $flow->update(['graph_data' => $graph]);
        $this->assertNotSame($pinned, $flow->fresh()->published_version_id);

        $this->inbound($account, 'yes');

        $this->assertSame(['OLD_YES'], $this->branchOutput($account));
        $this->assertSame($pinned, $this->flowSession($account)->flow_version_id);

        // A NEW session uses the new version.
        $this->inbound($account, 'go', '919800000002');
        $this->inbound($account, 'yes', '919800000002');
        $this->assertSame(['NEW_OLD_DEFAULT'], $this->branchOutput($account, '919800000002'));
    }

    // ==================================================================
    // Idempotency
    // ==================================================================

    public function test_a_redelivered_inbound_event_does_not_evaluate_the_condition_twice(): void
    {
        $account = $this->qrAccount();
        $this->legacyFlow($account, [['equals', 'yes', 'YES_BRANCH']]);
        $this->inbound($account, 'go', messageId: 'M1');

        $this->inbound($account, 'yes', messageId: 'M2');
        $this->inbound($account, 'yes', messageId: 'M2');

        $this->assertSame(['YES_BRANCH'], $this->branchOutput($account));
    }

    // ==================================================================
    // Entitlement / module / tenant
    // ==================================================================

    public function test_a_revoked_journey_entitlement_stops_the_run_before_the_condition(): void
    {
        $account = $this->qrAccount();
        $this->legacyFlow($account, [['equals', 'yes', 'YES_BRANCH']]);
        $this->inbound($account, 'go');

        AccountEntitlement::where('account_id', $account->id)
            ->where('capability_id', Capability::where('slug', 'journey_automation')->value('id'))
            ->update(['revoked_at' => now()]);
        $this->inbound($account, 'yes');

        $this->assertSame([], $this->branchOutput($account));
        $this->assertSame(WhatsAppFlowSession::STATUS_BLOCKED, $this->flowSession($account)->status);
    }

    public function test_a_disabled_chatbot_module_stops_the_run_before_the_condition(): void
    {
        $account = $this->qrAccount();
        $this->legacyFlow($account, [['equals', 'yes', 'YES_BRANCH']]);
        $this->inbound($account, 'go');

        $account->forceFill(['allowed_modules' => array_values(array_diff(Account::MODULES, ['chatbot']))])->save();
        $this->inbound($account, 'yes');

        $this->assertSame([], $this->branchOutput($account));
        $this->assertSame(WhatsAppFlowSession::STATUS_BLOCKED, $this->flowSession($account)->status);
    }

    public function test_each_tenant_branches_on_its_own_answers_only(): void
    {
        $a = $this->qrAccount();
        $b = $this->qrAccount();
        $this->legacyFlow($a, [['equals', 'gold', 'A_GOLD']], 'A_DEFAULT');
        $this->legacyFlow($b, [['equals', 'gold', 'B_GOLD']], 'B_DEFAULT');

        // Same customer phone in both tenants, different answers, interleaved.
        $this->inbound($a, 'go');
        $this->inbound($b, 'go');
        $this->inbound($a, 'gold');
        $this->inbound($b, 'silver');

        $this->assertSame(['A_GOLD'], $this->branchOutput($a));
        $this->assertSame(['B_DEFAULT'], $this->branchOutput($b));
        $this->assertSame(['ans' => 'gold'], $this->flowSession($a)->context_data);
        $this->assertSame(['ans' => 'silver'], $this->flowSession($b)->context_data);
    }

    // ==================================================================
    // Save-time validation (same rules as run time)
    // ==================================================================

    private function saveAs(Account $account, array $nodes, array $edges, bool $publish = true)
    {
        $user = User::factory()->create(['account_id' => $account->id, 'is_active' => true]);
        $user->assignRole('admin');

        return $this->actingAs($user)->postJson('/api/whatsapp/flows', [
            'name' => 'Branching', 'trigger_type' => 'keyword', 'trigger_value' => 'go', 'is_active' => true, 'publish' => $publish,
            'graph_data' => ['nodes' => [['id' => 't', 'type' => 'trigger', 'data' => []], ...$nodes], 'edges' => [$this->e('t', $nodes[0]['id']), ...$edges]],
        ]);
    }

    public function test_the_save_api_refuses_conditions_the_engine_would_refuse(): void
    {
        $account = $this->qrAccount();
        $cond = ['id' => 'c', 'type' => 'condition', 'data' => ['variable' => 'ans']];
        $a = $this->msg('a', 'A');
        $b = $this->msg('b', 'B');

        $this->saveAs($account, [$cond, $a], [$this->e('c', 'a', ['condition' => ['operator' => 'regex', 'value' => 'x']])])
            ->assertStatus(422)->assertJsonValidationErrors('graph_data.edges.1.condition');

        $this->saveAs($account, [$cond, $a], [$this->e('c', 'a', ['condition' => ['operator' => 'greater_than', 'value' => 'ten']])])
            ->assertStatus(422)->assertJsonValidationErrors('graph_data.edges.1.condition');

        $this->saveAs($account, [$cond, $a, $b], [$this->e('c', 'a', ['is_default' => true]), $this->e('c', 'b', ['is_default' => true])])
            ->assertStatus(422)->assertJsonValidationErrors('graph_data.nodes.1.default_branch');

        $conditional = fn (array $data) => ['id' => 'c', 'type' => 'conditional', 'data' => $data];
        $this->saveAs($account, [$conditional(['conditions' => [['variable' => 'ans', 'operator' => 'eval', 'value' => '1']]]), $a], [$this->e('c', 'a', ['sourceHandle' => 'true'])])
            ->assertStatus(422)->assertJsonValidationErrors('graph_data.nodes.1.data.conditions');

        $this->saveAs($account, [$conditional(['conditions' => [['variable' => 'ans', 'operator' => 'exists']], 'match' => 'xor']), $a], [$this->e('c', 'a', ['sourceHandle' => 'true'])])
            ->assertStatus(422)->assertJsonValidationErrors('graph_data.nodes.1.data.conditions');
    }

    public function test_the_save_api_accepts_valid_conditions_and_drafts(): void
    {
        $account = $this->qrAccount();
        $a = $this->msg('a', 'A');
        $b = $this->msg('b', 'B');

        $this->saveAs($account, [['id' => 'c', 'type' => 'condition', 'data' => ['variable' => 'ans']], $a, $b], [
            $this->e('c', 'a', ['condition' => ['operator' => 'less_or_equal', 'value' => '5']]),
            $this->e('c', 'b', ['is_default' => true]),
        ])->assertCreated();

        $this->saveAs($account, [['id' => 'c', 'type' => 'conditional', 'data' => ['conditions' => [['variable' => 'ans', 'operator' => 'starts_with', 'value' => 'a'], ['variable' => 'n', 'operator' => 'greater_than', 'value' => 3]], 'match' => 'any']], $a, $b], [
            $this->e('c', 'a', ['sourceHandle' => 'true']),
            $this->e('c', 'b', ['sourceHandle' => 'false']),
        ])->assertCreated();

        // A half-built draft is still savable as a draft (P5-7: `publish: false`)...
        $this->saveAs($account, [['id' => 'c', 'type' => 'conditional', 'data' => ['conditions' => [], 'match' => 'all']], $a], [
            $this->e('c', 'a', ['sourceHandle' => 'true']),
        ], publish: false)->assertCreated();

        // ...but cannot be published: the engine would fail it when reached.
        $this->saveAs($account, [['id' => 'c', 'type' => 'conditional', 'data' => ['conditions' => [], 'match' => 'all']], $a], [
            $this->e('c', 'a', ['sourceHandle' => 'true']),
        ])->assertStatus(422)->assertJsonPath('error_code', 'JOURNEY_NOT_PUBLISHABLE');
    }
}
