<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\AccountEntitlement;
use App\Models\Capability;
use App\Models\Invoice;
use App\Models\User;
use App\Models\WhatsAppFlow;
use App\Services\Billing\InvoiceCreditService;
use App\Services\WhatsApp\JourneyActionConfig;
use App\Support\JourneyNodeCatalog;
use App\Support\PlanCatalog;
use Database\Seeders\Phase1FoundationSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Phase 8 Task 16 — the 'classifier' node (LLM-based intent routing with
 * up to 5 named branches). Deliberately draft-only: not in
 * JourneyNodeCatalog::RUNTIME_EXECUTABLE_TYPES, so WhatsAppJourneyEngine
 * is never touched by this task (see that constant's docblock) — this
 * suite covers the save-time contract only: JourneyActionConfig's
 * classifierError(), WhatsAppFlowController's classifierBranchErrors(),
 * the 'ai' capability entitlement gate it reuses from agent/rag, and
 * that a journey containing one can be saved as a draft but never
 * published.
 *
 * Fixture pattern copied verbatim from JourneyNodeEntitlementTest (a real
 * plan via InvoiceCreditService, capabilities trimmed to a known set,
 * then grant() for whichever capability a given test needs/withholds).
 *
 * UNVERIFIED: written without a reachable PHP/phpunit runtime in the
 * authoring session — run `php artisan test --filter=JourneyClassifier`
 * before trusting this file; see Phase 8 Task 16's row in PROJECT_STATE.md.
 */
class JourneyClassifierTest extends TestCase
{
    use RefreshDatabase;

    private const ENDPOINT = '/api/whatsapp/flows';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        $this->seed(Phase1FoundationSeeder::class);
    }

    // ================================================================== fixtures

    /** @param array<int, string> $extraCapabilities */
    private function tenant(array $extraCapabilities = []): array
    {
        $account = Account::factory()->create();

        $plan = PlanCatalog::find('starter');
        $invoice = Invoice::create([
            'account_id' => $account->id, 'invoice_number' => 'INV-'.uniqid(), 'plan_key' => 'starter',
            'plan_label' => $plan['label'], 'amount' => $plan['price'], 'tax_amount' => 0,
            'total_amount' => $plan['price'], 'currency' => 'INR', 'payment_gateway' => 'razorpay',
            'gateway_order_id' => 'order_'.uniqid(), 'status' => 'pending',
        ]);
        app(InvoiceCreditService::class)->markPaidAndCreditQuota($invoice->id, 'pay_'.uniqid());

        // Same trim JourneyNodeEntitlementTest uses: keep only what a
        // classifier-focused test needs, grant the rest explicitly.
        AccountEntitlement::where('account_id', $account->id)
            ->where('capability_id', '!=', $this->capabilityId('whatsapp_send'))
            ->delete();
        $this->grant($account, 'journey_automation');

        foreach ($extraCapabilities as $slug) {
            $this->grant($account, $slug);
        }

        $user = User::factory()->create(['account_id' => $account->id, 'is_active' => true]);
        $user->assignRole('admin');

        return ['account' => $account->fresh(), 'user' => $user];
    }

    private function capabilityId(string $slug): int
    {
        return Capability::where('slug', $slug)->firstOrFail()->id;
    }

    private function grant(Account $account, string $slug): void
    {
        $capability = Capability::where('slug', $slug)->firstOrFail();

        AccountEntitlement::firstOrCreate(
            ['account_id' => $account->id, 'capability_id' => $capability->id],
            ['source' => 'manual_grant', 'granted_by_account_id' => null],
        );
    }

    /** A fully entitled ('ai' included) tenant — the common case for validation-only tests. */
    private function aiTenant(): array
    {
        return $this->tenant(['ai']);
    }

    /**
     * @param array<string, mixed> $classifierData
     * @param array<int, array<string, mixed>> $extraEdges
     */
    private function payload(array $classifierData, array $extraEdges = [], bool $publish = false): array
    {
        return [
            'publish' => $publish,
            'name' => 'Classifier Journey',
            'trigger_type' => 'keyword',
            'trigger_value' => 'start',
            'is_active' => true,
            'graph_data' => [
                'nodes' => [
                    ['id' => 'n_trigger', 'type' => 'trigger', 'position' => ['x' => 0, 'y' => 0], 'data' => []],
                    ['id' => 'c', 'type' => 'classifier', 'position' => ['x' => 100, 'y' => 100], 'data' => $classifierData],
                ],
                'edges' => [
                    ['id' => 'e0', 'source' => 'n_trigger', 'target' => 'c'],
                    ...$extraEdges,
                ],
            ],
        ];
    }

    // ==================================================================
    // 1. Catalog / palette wiring
    // ==================================================================

    public function test_classifier_is_in_the_palette_but_not_runtime_executable(): void
    {
        $this->assertContains('classifier', WhatsAppFlow::PALETTE_NODE_TYPES);
        $this->assertArrayHasKey('classifier', JourneyNodeCatalog::NODES);
        $this->assertSame(['ai'], JourneyNodeCatalog::NODES['classifier']['capabilities']);
        $this->assertSame(['none'], JourneyNodeCatalog::NODES['classifier']['providers']);
        $this->assertFalse(JourneyNodeCatalog::isRuntimeExecutable('classifier'));
        $this->assertNotContains('classifier', JourneyNodeCatalog::RUNTIME_EXECUTABLE_TYPES);
    }

    // ==================================================================
    // 2. classifierError() — save-time configuration contract
    // ==================================================================

    public function test_an_empty_classifier_is_a_savable_draft(): void
    {
        $t = $this->aiTenant();

        $this->actingAs($t['user'])->postJson(self::ENDPOINT, $this->payload([]))->assertCreated();
    }

    public function test_a_fully_configured_classifier_saves(): void
    {
        $t = $this->aiTenant();

        $response = $this->actingAs($t['user'])->postJson(self::ENDPOINT, $this->payload([
            'inputVariable' => 'customer_message',
            'outputVariable' => 'intent_branch',
            'branches' => [
                ['label' => 'Sales', 'intent' => 'wants to buy something'],
                ['label' => 'Support', 'intent' => 'has a problem'],
            ],
        ]))->assertCreated();

        $stored = WhatsAppFlow::findOrFail($response->json('data.id'));
        $node = collect($stored->graph_data['nodes'])->firstWhere('type', 'classifier');
        $this->assertSame('customer_message', $node['data']['inputVariable']);
        $this->assertCount(2, $node['data']['branches']);
    }

    public function test_an_invalid_variable_name_is_rejected(): void
    {
        $t = $this->aiTenant();

        $this->actingAs($t['user'])->postJson(self::ENDPOINT, $this->payload([
            'inputVariable' => 'has spaces',
            'outputVariable' => 'ok',
        ]))->assertStatus(422)->assertJsonValidationErrors('graph_data.nodes.1.data');
    }

    public function test_more_than_five_branches_is_rejected(): void
    {
        $t = $this->aiTenant();

        $branches = array_map(fn ($i) => ['label' => "Branch {$i}"], range(1, 6));

        $this->actingAs($t['user'])->postJson(self::ENDPOINT, $this->payload([
            'inputVariable' => 'ans', 'outputVariable' => 'out', 'branches' => $branches,
        ]))->assertStatus(422)->assertJsonValidationErrors('graph_data.nodes.1.data');
    }

    public function test_branches_must_be_a_list(): void
    {
        $t = $this->aiTenant();

        $this->actingAs($t['user'])->postJson(self::ENDPOINT, $this->payload([
            'inputVariable' => 'ans', 'outputVariable' => 'out', 'branches' => 'not-a-list',
        ]))->assertStatus(422)->assertJsonValidationErrors('graph_data.nodes.1.data');
    }

    public function test_a_branch_without_a_label_is_rejected(): void
    {
        $t = $this->aiTenant();

        $this->actingAs($t['user'])->postJson(self::ENDPOINT, $this->payload([
            'inputVariable' => 'ans', 'outputVariable' => 'out', 'branches' => [['intent' => 'no label here']],
        ]))->assertStatus(422)->assertJsonValidationErrors('graph_data.nodes.1.data');
    }

    public function test_the_classifier_branch_handles_constant_has_exactly_five_slots(): void
    {
        $this->assertSame(
            ['branch_1', 'branch_2', 'branch_3', 'branch_4', 'branch_5'],
            JourneyActionConfig::CLASSIFIER_BRANCH_HANDLES
        );
    }

    // ==================================================================
    // 3. classifierBranchErrors() — explicit branch identity on edges
    // ==================================================================

    public function test_an_edge_leaving_a_classifier_without_a_branch_handle_is_rejected(): void
    {
        $t = $this->aiTenant();

        $response = $this->actingAs($t['user'])->postJson(self::ENDPOINT, $this->payload(
            ['inputVariable' => 'ans', 'outputVariable' => 'out', 'branches' => [['label' => 'A']]],
            [['id' => 'e1', 'source' => 'c', 'target' => 'n_trigger']], // no sourceHandle at all
        ));

        $response->assertStatus(422)->assertJsonValidationErrors('graph_data.nodes.1.sourceHandle');
    }

    public function test_an_edge_leaving_a_classifier_with_an_unknown_handle_is_rejected(): void
    {
        $t = $this->aiTenant();

        $response = $this->actingAs($t['user'])->postJson(self::ENDPOINT, $this->payload(
            ['inputVariable' => 'ans', 'outputVariable' => 'out', 'branches' => [['label' => 'A']]],
            [['id' => 'e1', 'source' => 'c', 'target' => 'n_trigger', 'sourceHandle' => 'branch_6']],
        ));

        $response->assertStatus(422)->assertJsonValidationErrors('graph_data.nodes.1.sourceHandle');
    }

    public function test_an_edge_leaving_a_classifier_on_a_valid_branch_handle_is_accepted(): void
    {
        $t = $this->aiTenant();

        $this->actingAs($t['user'])->postJson(self::ENDPOINT, $this->payload(
            ['inputVariable' => 'ans', 'outputVariable' => 'out', 'branches' => [['label' => 'A'], ['label' => 'B']]],
            [
                ['id' => 'e1', 'source' => 'c', 'target' => 'n_trigger', 'sourceHandle' => 'branch_1'],
            ],
        ))->assertCreated();
    }

    public function test_a_conditional_nodes_edges_are_unaffected_by_the_classifier_handle_check(): void
    {
        // Regression guard: classifierBranchErrors() must only ever look
        // at edges leaving a 'classifier' node — a 'conditional' node's
        // own true/false handles (branchErrors(), untouched by this task)
        // must keep working exactly as before.
        $t = $this->aiTenant();

        $this->actingAs($t['user'])->postJson(self::ENDPOINT, [
            'publish' => false,
            'name' => 'Conditional Only',
            'trigger_type' => 'keyword',
            'trigger_value' => 'start',
            'is_active' => true,
            'graph_data' => [
                'nodes' => [
                    ['id' => 'n_trigger', 'type' => 'trigger', 'data' => []],
                    ['id' => 'cond', 'type' => 'conditional', 'data' => ['conditions' => [['variable' => 'ans', 'operator' => 'exists']]]],
                ],
                'edges' => [['id' => 'e0', 'source' => 'n_trigger', 'target' => 'cond']],
            ],
        ])->assertCreated();
    }

    // ==================================================================
    // 4. Entitlement — reuses the 'ai' capability, same as agent/rag
    // ==================================================================

    public function test_a_classifier_node_is_rejected_without_the_ai_capability(): void
    {
        $t = $this->tenant(); // no 'ai' granted

        $response = $this->actingAs($t['user'])->postJson(self::ENDPOINT, $this->payload([]));

        $response->assertStatus(403)->assertJsonPath('error_code', 'JOURNEY_NODE_NOT_ENTITLED');
        $this->assertSame(0, WhatsAppFlow::count());
    }

    public function test_a_classifier_node_saves_once_ai_is_granted(): void
    {
        $t = $this->aiTenant();

        $this->actingAs($t['user'])->postJson(self::ENDPOINT, $this->payload([]))->assertCreated();
    }

    // ==================================================================
    // 5. Draft-only — never publishable, exactly like the other 13
    //    not-yet-wired palette types (see JourneyRuntimeSafetyTest).
    // ==================================================================

    public function test_a_journey_containing_a_classifier_cannot_be_published(): void
    {
        $t = $this->aiTenant();

        $response = $this->actingAs($t['user'])->postJson(self::ENDPOINT, $this->payload(
            ['inputVariable' => 'ans', 'outputVariable' => 'out', 'branches' => [['label' => 'A']]],
            [['id' => 'e1', 'source' => 'c', 'target' => 'n_trigger', 'sourceHandle' => 'branch_1']],
            publish: true,
        ));

        $response->assertStatus(422)->assertJsonPath('error_code', 'JOURNEY_NOT_PUBLISHABLE');
        $this->assertSame(0, WhatsAppFlow::count());
    }

    public function test_the_same_journey_saves_fine_as_a_draft(): void
    {
        $t = $this->aiTenant();

        $this->actingAs($t['user'])->postJson(self::ENDPOINT, $this->payload(
            ['inputVariable' => 'ans', 'outputVariable' => 'out', 'branches' => [['label' => 'A']]],
            [['id' => 'e1', 'source' => 'c', 'target' => 'n_trigger', 'sourceHandle' => 'branch_1']],
            publish: false,
        ))->assertCreated();
    }
}
