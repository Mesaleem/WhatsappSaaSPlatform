<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\AccountEntitlement;
use App\Models\Capability;
use App\Models\Invoice;
use App\Models\User;
use App\Models\WhatsAppFlow;
use App\Models\WhatsAppNumber;
use App\Models\WhatsAppSession;
use App\Services\Billing\InvoiceCreditService;
use App\Support\PlanCatalog;
use Database\Seeders\Phase1FoundationSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Task 25 — Connexxa parity. The 'journey' (sub-journey) node's config
 * extended from a bare `journeyId` string to Static (pick a journey +
 * optional specific start node) / Dynamic (runtime-variable-bound
 * journey/node IDs) modes — the backbone of Connexxa's hub-and-spoke
 * production architecture.
 *
 * Save-time only: this node is NOT runtime-executable yet
 * (JourneyNodeCatalog::RUNTIME_EXECUTABLE_TYPES is untouched by this
 * task — see JourneyActionConfig::journeyError()'s docblock for the
 * explicit scope decision). These tests exercise the real save API
 * (WhatsAppFlowController::store()/update()), the same layer every
 * other action node's config contract is proven at
 * (JourneyConditionBranchingTest::saveAs(), mirrored here).
 */
class JourneySubJourneyNodeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        $this->seed(Phase1FoundationSeeder::class);
        Http::fake(['*' => Http::response(['success' => true, 'message_id' => 'QR_OK'], 200)]);
    }

    // ------------------------------------------------------------------ fixtures

    private function account(): Account
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

    private function admin(Account $account): User
    {
        $user = User::factory()->create(['account_id' => $account->id, 'is_active' => true]);
        $user->assignRole('admin');

        return $user;
    }

    private function channel(Account $account): WhatsAppNumber
    {
        return WhatsAppNumber::firstOrCreate(
            ['account_id' => $account->id],
            ['phone_number' => '91903'.str_pad((string) $account->id, 6, '0', STR_PAD_LEFT), 'status' => WhatsAppNumber::STATUS_LINKED],
        );
    }

    private function e(string $source, string $target, array $extra = []): array
    {
        return ['id' => "{$source}_{$target}_".uniqid(), 'source' => $source, 'target' => $target] + $extra;
    }

    private function journeyNode(array $data): array
    {
        return ['id' => 'j', 'type' => 'journey', 'data' => $data];
    }

    /** A plain target flow (trigger → message → end) this account can jump into. */
    private function targetFlow(Account $account): WhatsAppFlow
    {
        $flow = new WhatsAppFlow([
            'account_id' => $account->id,
            'name' => 'Target',
            'trigger_type' => 'keyword',
            'trigger_value' => 'target',
            'graph_data' => [
                'nodes' => [
                    ['id' => 't', 'type' => 'trigger', 'data' => []],
                    ['id' => 'm', 'type' => 'message', 'data' => ['text' => 'hi']],
                ],
                'edges' => [$this->e('t', 'm')],
            ],
            'is_active' => false,
        ]);
        $flow->publishOnSave = false;
        $flow->save();

        return $flow->fresh();
    }

    /** POSTs a draft journey (never publishes — this node type cannot be published yet). */
    private function saveAs(Account $account, array $journeyData)
    {
        $user = $this->admin($account);
        $number = $this->channel($account);
        $node = $this->journeyNode($journeyData);

        return $this->actingAs($user)->postJson('/api/whatsapp/flows', [
            'name' => 'Hub', 'trigger_type' => 'keyword', 'trigger_value' => 'go', 'is_active' => true, 'publish' => false,
            'whatsapp_number_ids' => [$number->id],
            'graph_data' => ['nodes' => [['id' => 't', 'type' => 'trigger', 'data' => []], $node], 'edges' => [$this->e('t', 'j')]],
        ]);
    }

    // ================================================================== backward compatibility

    public function test_the_legacy_bare_journey_id_shape_still_saves_exactly_as_before(): void
    {
        $account = $this->account();
        $target = $this->targetFlow($account);

        // No `mode` key at all — the pre-Task-25 shape.
        $this->saveAs($account, ['journeyId' => (string) $target->id])->assertStatus(201);
    }

    public function test_an_empty_draft_journey_node_is_still_savable(): void
    {
        $this->saveAs($this->account(), [])->assertStatus(201);
    }

    // ================================================================== static mode

    public function test_static_mode_accepts_a_journey_of_the_same_account(): void
    {
        $account = $this->account();
        $target = $this->targetFlow($account);

        $this->saveAs($account, ['mode' => 'static', 'journeyId' => (string) $target->id])->assertStatus(201);
    }

    public function test_static_mode_accepts_an_explicit_start_node_id(): void
    {
        $account = $this->account();
        $target = $this->targetFlow($account);

        $this->saveAs($account, ['mode' => 'static', 'journeyId' => (string) $target->id, 'startNodeId' => 'm'])
            ->assertStatus(201);
    }

    public function test_static_mode_refuses_another_accounts_journey(): void
    {
        $account = $this->account();
        $other = $this->account();
        $target = $this->targetFlow($other);

        $this->saveAs($account, ['mode' => 'static', 'journeyId' => (string) $target->id])
            ->assertStatus(422)->assertJsonValidationErrors('graph_data.nodes.1.data.journeyId');
    }

    public function test_static_mode_refuses_a_journey_that_does_not_exist(): void
    {
        $this->saveAs($this->account(), ['mode' => 'static', 'journeyId' => '999999'])
            ->assertStatus(422)->assertJsonValidationErrors('graph_data.nodes.1.data.journeyId');
    }

    public function test_static_mode_refuses_a_non_numeric_journey_id(): void
    {
        // Malformed (not even a positive id) is refused by
        // JourneyActionConfig::journeyError() itself, before the
        // ownership check ever runs — same `data` key as every other
        // action node's structural error (e.g. listError()).
        $this->saveAs($this->account(), ['mode' => 'static', 'journeyId' => 'not-a-number'])
            ->assertStatus(422)->assertJsonValidationErrors('graph_data.nodes.1.data');
    }

    public function test_static_mode_start_node_id_must_be_text(): void
    {
        $account = $this->account();
        $target = $this->targetFlow($account);

        $this->saveAs($account, ['mode' => 'static', 'journeyId' => (string) $target->id, 'startNodeId' => 123])
            ->assertStatus(422)->assertJsonValidationErrors('graph_data.nodes.1.data');
    }

    // ================================================================== dynamic mode

    public function test_dynamic_mode_accepts_runtime_expressions_with_no_ownership_check(): void
    {
        $this->saveAs($this->account(), [
            'mode' => 'dynamic',
            'journeyIdTemplate' => '{{ var_local.targetJourneyId }}',
            'nodeIdTemplate' => '{{ var_local.targetNodeId }}',
        ])->assertStatus(201);
    }

    public function test_dynamic_mode_draft_is_savable_with_empty_expressions(): void
    {
        $this->saveAs($this->account(), ['mode' => 'dynamic'])->assertStatus(201);
    }

    public function test_an_unknown_mode_is_refused(): void
    {
        $this->saveAs($this->account(), ['mode' => 'teleport', 'journeyId' => '1'])
            ->assertStatus(422)->assertJsonValidationErrors('graph_data.nodes.1.data');
    }

    // ================================================================== self-reference guard

    public function test_a_journey_cannot_point_at_itself(): void
    {
        $account = $this->account();
        $user = $this->admin($account);
        $number = $this->channel($account);

        $flow = new WhatsAppFlow([
            'account_id' => $account->id, 'name' => 'Loop', 'trigger_type' => 'keyword', 'trigger_value' => 'loop',
            'graph_data' => ['nodes' => [['id' => 't', 'type' => 'trigger', 'data' => []]], 'edges' => []],
            'is_active' => true,
        ]);
        $flow->publishOnSave = false;
        $flow->save();

        $node = $this->journeyNode(['mode' => 'static', 'journeyId' => (string) $flow->id]);

        $this->actingAs($user)->putJson("/api/whatsapp/flows/{$flow->id}", [
            'name' => 'Loop', 'trigger_type' => 'keyword', 'trigger_value' => 'loop', 'is_active' => true, 'publish' => false,
            'whatsapp_number_ids' => [$number->id],
            'graph_data' => ['nodes' => [['id' => 't', 'type' => 'trigger', 'data' => []], $node], 'edges' => [$this->e('t', 'j')]],
        ])->assertStatus(422)->assertJsonValidationErrors('graph_data.nodes.1.data.journeyId');
    }
}
