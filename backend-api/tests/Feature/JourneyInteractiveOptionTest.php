<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Capability;
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
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Task 24 — Connexxa parity. 'list' and 'reply_button' each get a
 * genuine per-option outgoing handle (JourneyActionConfig::
 * LIST_ROW_HANDLES / REPLY_BUTTON_HANDLES), as distinct from the
 * legacy 'question' node's input_type:'list'/'buttons' (still one NEXT
 * edge for every answer, left completely untouched — see this file's
 * own regression test at the bottom).
 *
 * Driven through the real inbound path (ChatbotEngineService →
 * WhatsAppJourneyEngine), on a Meta-provider account — both node types
 * are gated to providers => ['meta'] (JourneyNodeCatalog), same as
 * every other interactive Cloud API node.
 */
class JourneyInteractiveOptionTest extends TestCase
{
    use RefreshDatabase;

    private const PHONE = '919812345678';

    private const FLOWS = '/api/whatsapp/flows';

    /** @var list<array<string, mixed>> */
    private array $calls = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        $this->seed(Phase1FoundationSeeder::class);

        Http::fake(function (HttpRequest $request) {
            $this->calls[] = ['url' => $request->url()] + $request->data();

            if (str_contains($request->url(), 'graph.facebook.com')) {
                return Http::response(['messages' => [['id' => 'wamid.'.uniqid()]]], 200);
            }

            return Http::response(['success' => true, 'message_id' => 'QR_'.uniqid()], 200);
        });
    }

    // ================================================================== fixtures

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
        WhatsAppSession::create([
            'account_id' => $account->id, 'status' => 'connected',
            'meta_phone_number_id' => '1098'.random_int(100000000, 999999999),
            'meta_waba_id' => '123456789012345',
            'meta_access_token' => 'EAAG_p57_token_0123456789',
        ]);

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
        return ['id' => "{$source}_{$target}_".count($extra).uniqid(), 'source' => $source, 'target' => $target] + $extra;
    }

    private function msg(string $id, string $text): array
    {
        return ['id' => $id, 'type' => 'message', 'data' => ['text' => $text]];
    }

    /** trigger → 'n' (the node under test) → whatever $nodes/$edges wire up from there. */
    private function flow(Account $account, array $node, array $nodes, array $edges, string $keyword = 'go'): WhatsAppFlow
    {
        return WhatsAppFlow::create([
            'account_id' => $account->id, 'name' => 'Interactive', 'trigger_type' => 'keyword',
            'trigger_value' => $keyword, 'is_active' => true,
            'graph_data' => [
                'nodes' => [['id' => 't', 'type' => 'trigger', 'data' => []], $node, ...$nodes],
                'edges' => [$this->e('t', 'n'), ...$edges],
            ],
        ]);
    }

    private function listNode(array $sections, string $body = 'Choose one'): array
    {
        return ['id' => 'n', 'type' => 'list', 'data' => ['body' => $body, 'buttonText' => 'Menu', 'sections' => $sections]];
    }

    private function buttonNode(array $buttons, string $body = 'Pick one'): array
    {
        return ['id' => 'n', 'type' => 'reply_button', 'data' => ['body' => $body, 'buttons' => $buttons]];
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

    private function interactiveCall(): ?array
    {
        return collect($this->calls)->firstWhere(fn ($c) => str_contains($c['url'], 'graph.facebook.com') && ($c['type'] ?? null) === 'interactive');
    }

    // ================================================================== list node

    public function test_a_list_node_sends_the_interactive_message_and_pauses(): void
    {
        $account = $this->account();
        $this->flow($account, $this->listNode([
            ['title' => 'Plans', 'rows' => [['id' => 'gold', 'title' => 'Gold'], ['id' => 'silver', 'title' => 'Silver']]],
        ]), [$this->msg('yes', 'Picked gold')], [$this->e('n', 'yes', ['sourceHandle' => 'row_1'])]);

        $this->inbound($account, 'go');

        $this->assertSame(['Choose one'], $this->sent($account));
        $this->assertSame([WhatsAppFlowSession::STATUS_ACTIVE, 'n'], [$this->flowSession($account)->status, $this->flowSession($account)->current_node_id]);

        $call = $this->interactiveCall();
        $this->assertSame('list', $call['interactive']['type'] ?? null);
        $this->assertSame('Menu', $call['interactive']['action']['button'] ?? null);
        $this->assertSame(['gold', 'silver'], array_column($call['interactive']['action']['sections'][0]['rows'] ?? [], 'id'));
    }

    public function test_a_list_node_routes_to_the_tapped_rows_own_handle(): void
    {
        $account = $this->account();
        $this->flow($account, $this->listNode([
            ['title' => 'Plans', 'rows' => [['id' => 'gold', 'title' => 'Gold'], ['id' => 'silver', 'title' => 'Silver']]],
        ]), [$this->msg('g', 'GOLD PATH'), $this->msg('s', 'SILVER PATH')], [
            $this->e('n', 'g', ['sourceHandle' => 'row_1']),
            $this->e('n', 's', ['sourceHandle' => 'row_2']),
        ]);

        $this->inbound($account, 'go');
        $this->inbound($account, 'Silver');

        $this->assertSame(['Choose one', 'SILVER PATH'], $this->sent($account));
        $this->assertSame(WhatsAppFlowSession::STATUS_COMPLETED, $this->flowSession($account)->status);
    }

    public function test_a_list_reply_matches_by_id_when_the_title_does_not_match(): void
    {
        $account = $this->account();
        $this->flow($account, $this->listNode([
            ['title' => 'Plans', 'rows' => [['id' => 'ROW_ID_1', 'title' => 'Gold']]],
        ]), [$this->msg('g', 'HIT')], [$this->e('n', 'g', ['sourceHandle' => 'row_1'])]);

        $this->inbound($account, 'go');
        // Simulates a reply that arrived as the row id rather than its title
        // (MetaWebhookController flattens title first, id as fallback).
        $this->inbound($account, 'ROW_ID_1');

        $this->assertSame(['Choose one', 'HIT'], $this->sent($account));
    }

    public function test_a_list_reply_matching_no_row_reprompts_with_the_same_message(): void
    {
        $account = $this->account();
        $this->flow($account, $this->listNode([
            ['title' => 'Plans', 'rows' => [['id' => 'gold', 'title' => 'Gold']]],
        ]), [$this->msg('g', 'HIT')], [$this->e('n', 'g', ['sourceHandle' => 'row_1'])]);

        $this->inbound($account, 'go');
        $this->inbound($account, 'something else entirely');

        // Re-prompted (reminder + the list again), never advanced.
        $this->assertSame(['Choose one', 'Please choose one of the options below.', 'Choose one'], $this->sent($account));
        $this->assertSame([WhatsAppFlowSession::STATUS_ACTIVE, 'n'], [$this->flowSession($account)->status, $this->flowSession($account)->current_node_id]);

        // A correct reply afterward still advances normally.
        $this->inbound($account, 'Gold');
        $this->assertSame(WhatsAppFlowSession::STATUS_COMPLETED, $this->flowSession($account)->status);
    }

    public function test_a_list_node_with_no_edge_on_the_tapped_row_completes_silently(): void
    {
        $account = $this->account();
        $this->flow($account, $this->listNode([
            ['title' => 'Plans', 'rows' => [['id' => 'gold', 'title' => 'Gold'], ['id' => 'silver', 'title' => 'Silver']]],
        ]), [$this->msg('g', 'GOLD PATH')], [$this->e('n', 'g', ['sourceHandle' => 'row_1'])]);

        $this->inbound($account, 'go');
        $this->inbound($account, 'Silver');

        $this->assertSame(['Choose one'], $this->sent($account));
        $this->assertSame(WhatsAppFlowSession::STATUS_COMPLETED, $this->flowSession($account)->status);
    }

    // ================================================================== reply_button node

    public function test_a_reply_button_node_sends_the_interactive_message_and_routes_by_button(): void
    {
        $account = $this->account();
        $this->flow($account, $this->buttonNode([
            ['id' => 'yes', 'title' => 'Yes'], ['id' => 'no', 'title' => 'No'],
        ]), [$this->msg('y', 'YES PATH'), $this->msg('n2', 'NO PATH')], [
            $this->e('n', 'y', ['sourceHandle' => 'button_1']),
            $this->e('n', 'n2', ['sourceHandle' => 'button_2']),
        ]);

        $this->inbound($account, 'go');
        $this->inbound($account, 'no');

        $this->assertSame(['Pick one', 'NO PATH'], $this->sent($account));
        $this->assertSame(WhatsAppFlowSession::STATUS_COMPLETED, $this->flowSession($account)->status);

        $call = $this->interactiveCall();
        $this->assertSame('button', $call['interactive']['type'] ?? null);
        $this->assertSame(['yes', 'no'], array_column(array_column($call['interactive']['action']['buttons'] ?? [], 'reply'), 'id'));
    }

    public function test_more_than_three_buttons_are_truncated_to_whatsapps_limit_when_sent(): void
    {
        $account = $this->account();
        $this->flow($account, $this->buttonNode([
            ['id' => 'a', 'title' => 'A'], ['id' => 'b', 'title' => 'B'], ['id' => 'c', 'title' => 'C'],
        ]), [$this->msg('x', 'HIT')], [$this->e('n', 'x', ['sourceHandle' => 'button_1'])]);

        $this->inbound($account, 'go');

        $call = $this->interactiveCall();
        $this->assertCount(3, $call['interactive']['action']['buttons'] ?? []);
    }

    // ================================================================== save-time validation

    private function saveAs(Account $account, array $nodes, array $edges): \Illuminate\Testing\TestResponse
    {
        $user = User::factory()->create(['account_id' => $account->id, 'is_active' => true]);
        $user->assignRole('admin');
        $number = WhatsAppNumber::firstOrCreate(
            ['account_id' => $account->id],
            ['phone_number' => '91903'.str_pad((string) $account->id, 6, '0', STR_PAD_LEFT), 'status' => WhatsAppNumber::STATUS_LINKED],
        );

        return $this->actingAs($user)->postJson(self::FLOWS, [
            'name' => 'Interactive', 'trigger_type' => 'keyword', 'trigger_value' => 'go', 'is_active' => true,
            'whatsapp_number_ids' => [$number->id],
            'graph_data' => ['nodes' => [['id' => 't', 'type' => 'trigger', 'data' => []], ...$nodes], 'edges' => [$this->e('t', $nodes[0]['id']), ...$edges]],
        ]);
    }

    public function test_the_save_api_refuses_a_list_or_button_node_the_engine_would_refuse(): void
    {
        $account = $this->account();
        $a = $this->msg('a', 'A');

        // No rows at all (published, not a draft).
        $this->saveAs($account, [$this->listNode([]), $a], [$this->e('n', 'a', ['sourceHandle' => 'row_1'])])
            ->assertStatus(422)->assertJsonValidationErrors('graph_data.nodes.1.data');

        // More rows than LIST_ROW_HANDLES allows (11 > 10), spread across sections.
        $tooManyRows = array_fill(0, 11, ['id' => 'r', 'title' => 'R']);
        $this->saveAs($account, [$this->listNode([['title' => 'S', 'rows' => $tooManyRows]]), $a], [$this->e('n', 'a', ['sourceHandle' => 'row_1'])])
            ->assertStatus(422)->assertJsonValidationErrors('graph_data.nodes.1.data');

        // No buttons at all.
        $this->saveAs($account, [$this->buttonNode([]), $a], [$this->e('n', 'a', ['sourceHandle' => 'button_1'])])
            ->assertStatus(422)->assertJsonValidationErrors('graph_data.nodes.1.data');

        // More buttons than WhatsApp allows.
        $this->saveAs($account, [$this->buttonNode([['id' => 'a', 'title' => 'A'], ['id' => 'b', 'title' => 'B'], ['id' => 'c', 'title' => 'C'], ['id' => 'd', 'title' => 'D']]), $a], [$this->e('n', 'a', ['sourceHandle' => 'button_1'])])
            ->assertStatus(422)->assertJsonValidationErrors('graph_data.nodes.1.data');

        // An edge on a list node declaring the WRONG handle vocabulary ('true' is conditional's, not a list's).
        $this->saveAs($account, [$this->listNode([['title' => 'S', 'rows' => [['id' => 'g', 'title' => 'Gold']]]]), $a], [$this->e('n', 'a', ['sourceHandle' => 'true'])])
            ->assertStatus(422)->assertJsonValidationErrors('graph_data.nodes.1.sourceHandle');
    }

    public function test_the_save_api_accepts_a_valid_list_and_button_node(): void
    {
        $account = $this->account();
        $a = $this->msg('a', 'A');
        $b = $this->msg('b', 'B');

        $this->saveAs($account, [
            $this->listNode([['title' => 'Plans', 'rows' => [['id' => 'gold', 'title' => 'Gold'], ['id' => 'silver', 'title' => 'Silver']]]]), $a, $b,
        ], [
            $this->e('n', 'a', ['sourceHandle' => 'row_1']),
            $this->e('n', 'b', ['sourceHandle' => 'row_2']),
        ])->assertCreated();

        $this->saveAs($account, [
            $this->buttonNode([['id' => 'yes', 'title' => 'Yes'], ['id' => 'no', 'title' => 'No']]), $a, $b,
        ], [
            $this->e('n', 'a', ['sourceHandle' => 'button_1']),
            $this->e('n', 'b', ['sourceHandle' => 'button_2']),
        ])->assertCreated();
    }

    // ================================================================== regression: legacy question node untouched

    public function test_the_legacy_question_node_with_list_or_button_input_is_completely_unchanged(): void
    {
        $account = $this->account();
        $this->flow($account, [
            'id' => 'n', 'type' => 'question',
            'data' => ['prompt_text' => 'Pick one', 'variable_name' => 'choice', 'input_type' => 'list', 'options' => [['id' => 'gold', 'title' => 'Gold'], ['id' => 'silver', 'title' => 'Silver']]],
        ], [$this->msg('after', 'Thanks')], [$this->e('n', 'after')]);

        $this->inbound($account, 'go');
        $this->inbound($account, 'Silver');

        // One NEXT edge regardless of which option was picked — unchanged.
        $this->assertSame(['Pick one', 'Thanks'], $this->sent($account));
        $this->assertSame('Silver', $this->flowSession($account)->context_data['choice'] ?? null);
    }
}
