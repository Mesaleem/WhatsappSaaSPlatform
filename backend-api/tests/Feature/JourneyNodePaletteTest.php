<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\AccountEntitlement;
use App\Models\Capability;
use App\Models\Invoice;
use App\Models\User;
use App\Models\WhatsAppFlow;
use App\Services\Billing\InvoiceCreditService;
use App\Support\PlanCatalog;
use Database\Seeders\Phase1FoundationSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Phase 5 — Journey / Automation: 27-node palette foundation, backend half.
 *
 * FOUNDATION ONLY. Nothing here executes a node; these tests pin the
 * persistence and validation contract:
 *
 *   - all 27 palette types are accepted and stored;
 *   - the 5 legacy types saved journeys already contain still are;
 *   - an unknown type is rejected with a 422, never stored silently;
 *   - the invariants the brief names (max 3 reply buttons, delay > 0,
 *     explicit conditional branch handles) hold server-side, not only in
 *     the browser;
 *   - tenant isolation is unchanged.
 *
 * The frontend registry is the single source of the palette list; this
 * suite asserts the server-side allow-list agrees with it exactly, so a
 * node can never be offered in the palette that the API would reject.
 */
class JourneyNodePaletteTest extends TestCase
{
    use RefreshDatabase;

    private const ENDPOINT = '/api/whatsapp/flows';

    /** The 27, in the brief's own order and grouping. */
    private const MESSAGE_NODES = ['prompt', 'text', 'image', 'video', 'document', 'audio', 'sticker'];

    private const INTERACTIVE_NODES = ['list', 'external_url', 'reply_button', 'location', 'location_request', 'address_request'];

    private const ADVANCED_NODES = ['flow', 'api', 'payment', 'template', 'conditional', 'catalog', 'product', 'agent', 'rag', 'human_intervention'];

    private const UTILITY_NODES = ['code', 'email', 'journey', 'delay'];

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        // Phase 5 Task 7 — the capability/provider catalog this suite's
        // fixture account is entitled against. See tenantAdmin().
        $this->seed(Phase1FoundationSeeder::class);
    }

    private function paletteTypes(): array
    {
        return [...self::MESSAGE_NODES, ...self::INTERACTIVE_NODES, ...self::ADVANCED_NODES, ...self::UTILITY_NODES];
    }

    /**
     * A FULLY ENTITLED tenant admin on the Meta engine.
     *
     * Phase 5 Task 7 changed this deliberately (it was the QR 'starter'
     * plan with only its plan-granted whatsapp_send). This suite exists
     * to pin the STRUCTURAL contract — that every palette type is
     * accepted, stored and round-trips — so its fixture must not also be
     * an entitlement test, or every assertion here would start failing
     * for a reason it was never written to detect.
     *
     * The entitlement dimension has its own suite now:
     * JourneyNodeEntitlementTest covers missing capability, incompatible
     * provider, tenant isolation and provider/account spoofing against
     * deliberately UNDER-entitled fixtures.
     */
    private function tenantAdmin(): array
    {
        $account = Account::factory()->create();

        // 'business' is the Meta-engine plan (PlanCatalog) — chosen
        // because the interactive/template/commerce nodes are genuinely
        // Meta-only, not because of the plan's name or price.
        $plan = PlanCatalog::find('business');
        $invoice = Invoice::create([
            'account_id' => $account->id,
            'invoice_number' => 'INV-'.uniqid(),
            'plan_key' => 'business',
            'plan_label' => $plan['label'],
            'amount' => $plan['price'],
            'tax_amount' => 0,
            'total_amount' => $plan['price'],
            'currency' => 'INR',
            'payment_gateway' => 'razorpay',
            'gateway_order_id' => 'order_'.uniqid(),
            'gateway_payment_id' => null,
            'status' => 'pending',
            'paid_at' => null,
            'gateway_raw_response' => null,
        ]);
        app(InvoiceCreditService::class)->markPaidAndCreditQuota($invoice->id, 'pay_'.uniqid());

        // Every seeded capability, granted the same way
        // AccountController::grantEntitlement() grants one. Not a
        // shortcut around the gate — the gate's own behaviour is
        // asserted in JourneyNodeEntitlementTest.
        foreach (Capability::all() as $capability) {
            AccountEntitlement::firstOrCreate(
                ['account_id' => $account->id, 'capability_id' => $capability->id],
                ['source' => 'manual_grant', 'granted_by_account_id' => null],
            );
        }

        $user = User::factory()->create(['account_id' => $account->id, 'is_active' => true]);
        $user->assignRole('admin');

        return ['account' => $account, 'user' => $user];
    }

    /**
     * @param array<int, array<string, mixed>> $nodes
     *
     * graph_data.edges is `required|array` in the pre-existing validation
     * rules, and Laravel treats an empty array as absent -- so when a test
     * supplies no edges this wires the trigger to the first node, which is
     * what the builder would have produced anyway. That rule is left
     * exactly as it was; a test helper is not a reason to relax it.
     */
    private function payload(array $nodes, array $edges = [], string $name = 'Journey'): array
    {
        if ($edges === [] && $nodes !== []) {
            $edges = [['id' => 'e_auto', 'source' => 'n_trigger', 'target' => $nodes[0]['id']]];
        }

        return [
            // P5-7 — palette nodes without a runtime are persistable as DRAFTS
            // only; publishing requires an executable graph (JourneyRuntimeSafetyTest).
            'publish' => false,
            'name' => $name,
            'trigger_type' => 'keyword',
            'trigger_value' => 'start',
            'graph_data' => [
                'nodes' => array_merge(
                    [['id' => 'n_trigger', 'type' => 'trigger', 'position' => ['x' => 0, 'y' => 0], 'data' => []]],
                    $nodes,
                ),
                'edges' => $edges,
            ],
            'is_active' => true,
        ];
    }

    /** @param array<string, mixed> $data */
    private function node(string $id, string $type, array $data = []): array
    {
        return ['id' => $id, 'type' => $type, 'position' => ['x' => 100, 'y' => 100], 'data' => $data];
    }

    // ==================================================================
    // 1. The allow-list itself
    // ==================================================================
    public function test_the_model_declares_exactly_the_twenty_seven_palette_types(): void
    {
        $this->assertSame($this->paletteTypes(), WhatsAppFlow::PALETTE_NODE_TYPES);
        $this->assertCount(27, WhatsAppFlow::PALETTE_NODE_TYPES);
    }

    public function test_every_palette_type_is_unique(): void
    {
        $types = WhatsAppFlow::PALETTE_NODE_TYPES;

        $this->assertSame(array_values(array_unique($types)), $types, 'node identity is the type string; duplicates are not allowed');
    }

    public function test_the_five_legacy_executable_types_are_still_accepted(): void
    {
        $this->assertSame(['trigger', 'message', 'question', 'condition', 'save_lead'], WhatsAppFlow::EXECUTABLE_NODE_TYPES);

        foreach (WhatsAppFlow::EXECUTABLE_NODE_TYPES as $type) {
            $this->assertContains($type, WhatsAppFlow::NODE_TYPES);
        }
    }

    public function test_the_accepted_set_is_the_union_of_both(): void
    {
        $this->assertCount(32, WhatsAppFlow::NODE_TYPES);
        $this->assertSame(
            array_values(array_unique(WhatsAppFlow::NODE_TYPES)),
            WhatsAppFlow::NODE_TYPES,
            'a type must not appear in both lists',
        );
    }

    // ==================================================================
    // 2. Persistence — all 27 accepted and stored
    // ==================================================================
    public function test_every_one_of_the_twenty_seven_node_types_is_accepted_and_stored(): void
    {
        $t = $this->tenantAdmin();

        foreach ($this->paletteTypes() as $type) {
            $data = match ($type) {
                'reply_button' => ['body' => 'Pick', 'buttons' => [['id' => 'b1', 'title' => 'Yes']]],
                'delay' => ['amount' => 5, 'unit' => 'minutes'],
                default => [],
            };

            $response = $this->actingAs($t['user'])
                ->postJson(self::ENDPOINT, $this->payload([$this->node("n_{$type}", $type, $data)], [], "Journey {$type}"));

            $response->assertStatus(201);

            $stored = WhatsAppFlow::findOrFail($response->json('data.id'));
            $types = array_column($stored->graph_data['nodes'], 'type');

            $this->assertContains($type, $types, "{$type} was not persisted");
        }

        $this->assertSame(27, WhatsAppFlow::where('account_id', $t['account']->id)->count());
    }

    public function test_a_node_configuration_round_trips_unchanged(): void
    {
        $t = $this->tenantAdmin();

        $config = [
            'method' => 'POST',
            'url' => 'https://api.example.com/v1/thing',
            'headers' => [['key' => 'Accept', 'value' => 'application/json']],
            'responseMapping' => [['key' => 'order_id', 'value' => 'data.id']],
        ];

        $response = $this->actingAs($t['user'])
            ->postJson(self::ENDPOINT, $this->payload([$this->node('n_api', 'api', $config)]));

        $response->assertStatus(201);

        $stored = WhatsAppFlow::findOrFail($response->json('data.id'));
        $node = collect($stored->graph_data['nodes'])->firstWhere('id', 'n_api');

        $this->assertSame($config, $node['data'], 'graph_data JSON must store the node config verbatim');
    }

    public function test_a_mixed_legacy_and_new_graph_is_accepted(): void
    {
        $t = $this->tenantAdmin();

        $this->actingAs($t['user'])->postJson(self::ENDPOINT, $this->payload([
            $this->node('n_old', 'message', ['text' => 'Legacy hello']),
            $this->node('n_new', 'text', ['text' => 'New hello']),
        ], [
            ['id' => 'e1', 'source' => 'n_trigger', 'target' => 'n_old'],
            ['id' => 'e2', 'source' => 'n_old', 'target' => 'n_new'],
        ]))->assertStatus(201);
    }

    // ==================================================================
    // 3. Unknown / malformed rejection
    // ==================================================================
    public function test_an_unknown_node_type_is_rejected_with_422(): void
    {
        $t = $this->tenantAdmin();

        $this->actingAs($t['user'])
            ->postJson(self::ENDPOINT, $this->payload([$this->node('n_bad', 'teleport')]))
            ->assertStatus(422)
            ->assertJsonValidationErrors('graph_data.nodes.1.type');

        $this->assertSame(0, WhatsAppFlow::count(), 'an unknown type must never be stored silently');
    }

    public function test_a_node_with_a_scalar_config_is_rejected(): void
    {
        $t = $this->tenantAdmin();

        $payload = $this->payload([['id' => 'n_text', 'type' => 'text', 'position' => ['x' => 0, 'y' => 0], 'data' => 'oops']]);

        $this->actingAs($t['user'])->postJson(self::ENDPOINT, $payload)
            ->assertStatus(422)
            ->assertJsonValidationErrors('graph_data.nodes.1.data');
    }

    public function test_more_than_three_reply_buttons_is_rejected(): void
    {
        $t = $this->tenantAdmin();

        $buttons = [
            ['id' => 'b1', 'title' => 'One'],
            ['id' => 'b2', 'title' => 'Two'],
            ['id' => 'b3', 'title' => 'Three'],
            ['id' => 'b4', 'title' => 'Four'],
        ];

        $this->actingAs($t['user'])
            ->postJson(self::ENDPOINT, $this->payload([$this->node('n_rb', 'reply_button', ['body' => 'Pick', 'buttons' => $buttons])]))
            ->assertStatus(422)
            ->assertJsonValidationErrors('graph_data.nodes.1.data.buttons');
    }

    public function test_exactly_three_reply_buttons_is_accepted(): void
    {
        $t = $this->tenantAdmin();

        $buttons = [
            ['id' => 'b1', 'title' => 'One'],
            ['id' => 'b2', 'title' => 'Two'],
            ['id' => 'b3', 'title' => 'Three'],
        ];

        $this->actingAs($t['user'])
            ->postJson(self::ENDPOINT, $this->payload([$this->node('n_rb', 'reply_button', ['body' => 'Pick', 'buttons' => $buttons])]))
            ->assertStatus(201);
    }

    public function test_a_non_positive_delay_is_rejected(): void
    {
        $t = $this->tenantAdmin();

        foreach ([0, -5] as $amount) {
            $this->actingAs($t['user'])
                ->postJson(self::ENDPOINT, $this->payload([$this->node('n_d', 'delay', ['amount' => $amount, 'unit' => 'minutes'])]))
                ->assertStatus(422)
                ->assertJsonValidationErrors('graph_data.nodes.1.data.amount');
        }
    }

    public function test_an_unsupported_delay_unit_is_rejected(): void
    {
        $t = $this->tenantAdmin();

        $this->actingAs($t['user'])
            ->postJson(self::ENDPOINT, $this->payload([$this->node('n_d', 'delay', ['amount' => 5, 'unit' => 'fortnights'])]))
            ->assertStatus(422)
            ->assertJsonValidationErrors('graph_data.nodes.1.data.unit');
    }

    // ==================================================================
    // 4. Conditional branch identity
    // ==================================================================
    public function test_a_conditional_edge_without_a_branch_handle_is_rejected(): void
    {
        $t = $this->tenantAdmin();

        $this->actingAs($t['user'])->postJson(self::ENDPOINT, $this->payload([
            $this->node('n_if', 'conditional', ['conditions' => [['variable' => 'x', 'operator' => 'equals', 'value' => '1']]]),
            $this->node('n_yes', 'text', ['text' => 'yes']),
        ], [
            ['id' => 'e1', 'source' => 'n_if', 'target' => 'n_yes'],
        ]))->assertStatus(422)->assertJsonValidationErrors('graph_data.nodes.1.sourceHandle');
    }

    public function test_conditional_true_and_false_branches_are_accepted_and_stored(): void
    {
        $t = $this->tenantAdmin();

        $response = $this->actingAs($t['user'])->postJson(self::ENDPOINT, $this->payload([
            $this->node('n_if', 'conditional', ['conditions' => [['variable' => 'x', 'operator' => 'equals', 'value' => '1']]]),
            $this->node('n_yes', 'text', ['text' => 'yes']),
            $this->node('n_no', 'text', ['text' => 'no']),
        ], [
            ['id' => 'e1', 'source' => 'n_if', 'target' => 'n_yes', 'sourceHandle' => 'true'],
            ['id' => 'e2', 'source' => 'n_if', 'target' => 'n_no', 'sourceHandle' => 'false'],
        ]));

        $response->assertStatus(201);

        $edges = collect(WhatsAppFlow::findOrFail($response->json('data.id'))->graph_data['edges']);

        $this->assertSame('true', $edges->firstWhere('id', 'e1')['sourceHandle']);
        $this->assertSame('false', $edges->firstWhere('id', 'e2')['sourceHandle']);
    }

    public function test_an_invalid_branch_handle_is_rejected(): void
    {
        $t = $this->tenantAdmin();

        $this->actingAs($t['user'])->postJson(self::ENDPOINT, $this->payload([
            $this->node('n_if', 'conditional', ['conditions' => []]),
            $this->node('n_x', 'text', ['text' => 'x']),
        ], [
            ['id' => 'e1', 'source' => 'n_if', 'target' => 'n_x', 'sourceHandle' => 'maybe'],
        ]))->assertStatus(422)->assertJsonValidationErrors('graph_data.nodes.1.sourceHandle');
    }

    /** The legacy 'condition' node carries its branch on the EDGE, and must keep working untouched. */
    public function test_a_legacy_condition_node_still_saves_without_a_branch_handle(): void
    {
        $t = $this->tenantAdmin();

        $this->actingAs($t['user'])->postJson(self::ENDPOINT, $this->payload([
            $this->node('n_cond', 'condition', ['variable' => 'answer']),
            $this->node('n_a', 'message', ['text' => 'A']),
            $this->node('n_b', 'message', ['text' => 'B']),
        ], [
            ['id' => 'e1', 'source' => 'n_cond', 'target' => 'n_a', 'condition' => ['operator' => 'equals', 'value' => 'yes']],
            ['id' => 'e2', 'source' => 'n_cond', 'target' => 'n_b', 'is_default' => true],
        ]))->assertStatus(201);
    }

    // ==================================================================
    // 5. Existing behaviour preserved
    // ==================================================================
    public function test_a_journey_saved_before_this_task_still_loads_and_resaves(): void
    {
        $t = $this->tenantAdmin();

        // Written the way the pre-existing builder wrote it: legacy types
        // only, no sourceHandle anywhere.
        $flow = WhatsAppFlow::create([
            'account_id' => $t['account']->id,
            'name' => 'Old Journey',
            'trigger_type' => 'keyword',
            'trigger_value' => 'hi',
            'is_active' => true,
            'graph_data' => [
                'nodes' => [
                    ['id' => 'n1', 'type' => 'trigger', 'position' => ['x' => 0, 'y' => 0], 'data' => []],
                    ['id' => 'n2', 'type' => 'message', 'position' => ['x' => 200, 'y' => 0], 'data' => ['text' => 'Hello']],
                    ['id' => 'n3', 'type' => 'question', 'position' => ['x' => 400, 'y' => 0], 'data' => ['prompt_text' => 'Name?', 'variable_name' => 'name']],
                    ['id' => 'n4', 'type' => 'save_lead', 'position' => ['x' => 600, 'y' => 0], 'data' => ['name_variable' => 'name']],
                ],
                'edges' => [
                    ['id' => 'e1', 'source' => 'n1', 'target' => 'n2'],
                    ['id' => 'e2', 'source' => 'n2', 'target' => 'n3'],
                    ['id' => 'e3', 'source' => 'n3', 'target' => 'n4'],
                ],
            ],
        ]);

        $this->actingAs($t['user'])->getJson(self::ENDPOINT."/{$flow->id}")
            ->assertStatus(200)
            ->assertJsonPath('data.graph_data.nodes.1.type', 'message');

        // Re-saving it verbatim must not start failing validation.
        $this->actingAs($t['user'])->putJson(self::ENDPOINT."/{$flow->id}", [
            'name' => $flow->name,
            'trigger_type' => $flow->trigger_type,
            'trigger_value' => $flow->trigger_value,
            'graph_data' => $flow->graph_data,
            'is_active' => true,
        ])->assertStatus(200);
    }

    public function test_a_flow_without_a_trigger_node_is_still_rejected(): void
    {
        $t = $this->tenantAdmin();

        $this->actingAs($t['user'])->postJson(self::ENDPOINT, [
            'name' => 'No trigger',
            'trigger_type' => 'keyword',
            'trigger_value' => 'x',
            'graph_data' => [
                'nodes' => [$this->node('n_text', 'text', ['text' => 'hi'])],
                'edges' => [['id' => 'e1', 'source' => 'n_text', 'target' => 'n_text']],
            ],
        ])->assertStatus(422)->assertJsonValidationErrors('graph_data.nodes');
    }

    // ==================================================================
    // 6. Tenant isolation
    // ==================================================================
    public function test_a_tenant_cannot_read_another_tenants_journey(): void
    {
        $mine = $this->tenantAdmin();
        $theirs = $this->tenantAdmin();

        $flow = WhatsAppFlow::create([
            'account_id' => $theirs['account']->id,
            'name' => 'Theirs',
            'trigger_type' => 'keyword',
            'trigger_value' => 'x',
            'is_active' => true,
            'graph_data' => ['nodes' => [['id' => 'n1', 'type' => 'trigger', 'position' => ['x' => 0, 'y' => 0], 'data' => []]], 'edges' => []],
        ]);

        $this->actingAs($mine['user'])->getJson(self::ENDPOINT."/{$flow->id}")->assertStatus(404);
        $this->actingAs($mine['user'])->deleteJson(self::ENDPOINT."/{$flow->id}")->assertStatus(404);

        $this->assertDatabaseHas('whatsapp_flows', ['id' => $flow->id]);
    }

    public function test_a_created_journey_belongs_to_the_callers_own_account(): void
    {
        $t = $this->tenantAdmin();

        $response = $this->actingAs($t['user'])->postJson(self::ENDPOINT, $this->payload([$this->node('n_text', 'text', ['text' => 'hi'])]));

        $this->assertSame(
            $t['account']->id,
            WhatsAppFlow::findOrFail($response->json('data.id'))->account_id,
            'account_id comes from the authenticated context, never from the payload',
        );
    }

    public function test_an_account_id_in_the_payload_is_ignored(): void
    {
        $mine = $this->tenantAdmin();
        $theirs = $this->tenantAdmin();

        $payload = $this->payload([$this->node('n_text', 'text', ['text' => 'hi'])]);
        $payload['account_id'] = $theirs['account']->id;

        $response = $this->actingAs($mine['user'])->postJson(self::ENDPOINT, $payload);

        $this->assertSame($mine['account']->id, WhatsAppFlow::findOrFail($response->json('data.id'))->account_id);
    }

    // ==================================================================
    // 7. Security — nothing here executes, nothing stores a secret
    // ==================================================================
    /**
     * The code node stores source, and storing source is all it does.
     * Nothing in the request path may evaluate it — there is no eval(),
     * no create_function(), no shell escape anywhere in the Journey
     * controller, model or engine.
     */
    public function test_a_code_node_is_stored_and_never_evaluated(): void
    {
        $t = $this->tenantAdmin();

        $code = 'process.exit(1); // would be catastrophic if ever run';

        $response = $this->actingAs($t['user'])->postJson(self::ENDPOINT, $this->payload([
            $this->node('n_code', 'code', ['language' => 'javascript', 'code' => $code]),
        ]));

        $response->assertStatus(201);

        $stored = collect(WhatsAppFlow::findOrFail($response->json('data.id'))->graph_data['nodes'])->firstWhere('id', 'n_code');
        $this->assertSame($code, $stored['data']['code']);

        foreach ([
            app_path('Http/Controllers/Api/WhatsAppFlowController.php'),
            app_path('Models/WhatsAppFlow.php'),
            app_path('Services/WhatsApp/WhatsAppJourneyEngine.php'),
        ] as $path) {
            $source = file_get_contents($path);

            foreach (['eval(', 'create_function', 'shell_exec', 'proc_open', 'passthru('] as $forbidden) {
                $this->assertStringNotContainsString($forbidden, $source, basename($path).' must never evaluate node configuration');
            }
        }
    }

    public function test_a_journey_response_exposes_no_provider_credential(): void
    {
        $t = $this->tenantAdmin();

        $response = $this->actingAs($t['user'])->postJson(self::ENDPOINT, $this->payload([
            $this->node('n_api', 'api', ['method' => 'GET', 'url' => 'https://api.example.com', 'credentialRef' => 'stripe_main']),
            $this->node('n_agent', 'agent', ['agentId' => 'support-bot']),
        ]));

        $body = $response->getContent();

        // A credential REFERENCE is fine; an actual secret is not, and
        // nothing on the server ever resolves one into this payload.
        $this->assertStringContainsString('stripe_main', $body);

        foreach (['meta_access_token', 'client_secret', 'api_secret', 'smtp_password'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $body);
        }
    }
}
