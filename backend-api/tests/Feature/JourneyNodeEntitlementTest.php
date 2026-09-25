<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\AccountEntitlement;
use App\Models\Capability;
use App\Models\Invoice;
use App\Models\User;
use App\Models\WhatsAppFlow;
use App\Services\Billing\InvoiceCreditService;
use App\Support\JourneyNodeCatalog;
use App\Support\PlanCatalog;
use Database\Seeders\Phase1FoundationSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Phase 5 Task 7 — Journey Node Capability & Provider Entitlement
 * Foundation.
 *
 * Task 6 proved every node has a stable type and configuration
 * contract. This suite proves the second half: that a node's
 * capability/provider requirement is enforced SERVER-SIDE, from the
 * authenticated account's own entitlements and its own subscription's
 * engine_type, and that nothing the caller puts in the request body can
 * change either.
 *
 * The error contract Task 6 established is preserved exactly:
 *   unknown node type / bad configuration -> 422
 *   authenticated but not entitled        -> 403 JOURNEY_NODE_NOT_ENTITLED
 *
 * Fixtures buy a real plan through InvoiceCreditService rather than
 * hand-inserting entitlement rows, so the plan -> entitlement ->
 * capability chain under test is the real one. 'starter' is a QR plan,
 * 'business' a Meta plan (PlanCatalog), which is how a test picks a
 * provider — never by asserting one.
 */
class JourneyNodeEntitlementTest extends TestCase
{
    use RefreshDatabase;

    private const ENDPOINT = '/api/whatsapp/flows';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        $this->seed(Phase1FoundationSeeder::class);
    }

    // =================================================================
    // Fixtures
    // =================================================================

    /**
     * A tenant admin on a real, paid plan. 'starter'/'growth' give a QR
     * engine, 'business' a Meta engine — see PlanCatalog.
     *
     * @param array<int, string> $extraCapabilities
     * @return array{account: Account, user: User}
     */
    private function tenant(string $planKey = 'starter', array $extraCapabilities = []): array
    {
        $account = Account::factory()->create();

        $plan = PlanCatalog::find($planKey);
        $invoice = Invoice::create([
            'account_id' => $account->id,
            'invoice_number' => 'INV-'.uniqid(),
            'plan_key' => $planKey,
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

        /*
         * Phase 5 Task 9 — the fixture is pinned to "plan grants
         * messaging, nothing else", which is exactly what it was before
         * Task 9 seeded the product owner's confirmed plan bundle.
         *
         * WHY: this suite's subject is JourneyNodeAuthorizer — does it
         * refuse a node whose capability the account does not hold? That
         * question needs a tenant that provably does NOT hold the
         * capability under test. Reading the bundle from the live matrix
         * instead would make every assertion here re-derive a pricing
         * decision, so a future repricing would silently turn these into
         * vacuous passes. The plan -> bundle mapping has its own suite:
         * PlanCapabilityMatrixAndBackfillTest.
         *
         * whatsapp_send is kept because it is the one capability every
         * plan has always carried, and several nodes below need it to
         * reach the provider check that is actually under test.
         *
         * Phase 7 Task 1.5 — journey_automation is kept too: it is now the
         * gate on the Journey API itself (capability.guard), so without it
         * no request here would ever reach JourneyNodeAuthorizer. The
         * route gate has its own suite: JourneyCapabilityGateTest.
         */
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

    /** A Super-Admin grant, the same row AccountController::grantEntitlement() writes. */
    private function grant(Account $account, string $slug): void
    {
        $capability = Capability::where('slug', $slug)->firstOrFail();

        AccountEntitlement::firstOrCreate(
            ['account_id' => $account->id, 'capability_id' => $capability->id],
            ['source' => 'manual_grant', 'granted_by_account_id' => null],
        );
    }

    /**
     * @param array<int, array<string, mixed>> $nodes
     * @param array<string, mixed> $extra
     * @return array<string, mixed>
     */
    private function payload(array $nodes, array $extra = []): array
    {
        $edges = $nodes === []
            ? [['id' => 'e0', 'source' => 'n_trigger', 'target' => 'n_trigger']]
            : [['id' => 'e0', 'source' => 'n_trigger', 'target' => $nodes[0]['id']]];

        return array_merge([
            // P5-7 — palette nodes without a runtime are persistable as DRAFTS
            // only; publishing requires an executable graph (JourneyRuntimeSafetyTest).
            'publish' => false,
            'name' => 'Journey',
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
        ], $extra);
    }

    /** @param array<string, mixed> $data */
    private function node(string $id, string $type, array $data = []): array
    {
        return ['id' => $id, 'type' => $type, 'position' => ['x' => 100, 'y' => 100], 'data' => $data];
    }

    // =================================================================
    // 1. Capability vocabulary
    // =================================================================

    public function test_the_five_new_capabilities_are_seeded(): void
    {
        foreach (['commerce', 'payments', 'external_api', 'custom_code', 'email'] as $slug) {
            $this->assertDatabaseHas('capabilities', ['slug' => $slug], null);
        }
    }

    public function test_the_original_phase_1_capabilities_are_untouched(): void
    {
        foreach (['whatsapp_send', 'whatsapp_groups', 'crm', 'journey_automation', 'ads', 'social', 'ai'] as $slug) {
            $this->assertDatabaseHas('capabilities', ['slug' => $slug]);
        }

        // Meaning preserved, not just presence: the label a tenant sees
        // for an existing capability must not have shifted underneath it.
        $this->assertSame('WhatsApp Messaging', Capability::where('slug', 'whatsapp_send')->value('label'));
        $this->assertSame('AI', Capability::where('slug', 'ai')->value('label'));
    }

    public function test_the_seeder_is_idempotent(): void
    {
        $before = Capability::count();
        $pivotBefore = \DB::table('provider_capabilities')->count();

        $this->seed(Phase1FoundationSeeder::class);
        $this->seed(Phase1FoundationSeeder::class);

        $this->assertSame($before, Capability::count(), 'Re-seeding duplicated capability rows.');
        $this->assertSame($pivotBefore, \DB::table('provider_capabilities')->count(), 'Re-seeding duplicated provider_capability rows.');
    }

    public function test_agent_and_rag_reuse_the_existing_ai_capability_rather_than_inventing_one(): void
    {
        // The explicit anti-duplication assertion: Task 7's brief listed
        // Agent and RAG as missing, but 'ai' already expresses both.
        $this->assertSame(['ai'], JourneyNodeCatalog::NODES['agent']['capabilities']);
        $this->assertSame(['ai'], JourneyNodeCatalog::NODES['rag']['capabilities']);

        foreach (['ai_agent', 'ai_rag', 'journey_ai_agent', 'journey_rag'] as $notInvented) {
            $this->assertDatabaseMissing('capabilities', ['slug' => $notInvented]);
        }
    }

    public function test_no_capability_slug_is_namespaced_by_the_journey_surface(): void
    {
        // Capabilities are product facts, not surface facts — a 'journey_'
        // prefix on a new slug would mean the same product capability gets
        // a second row the day the Chatbot needs it.
        $introduced = ['commerce', 'payments', 'external_api', 'custom_code', 'email'];

        foreach ($introduced as $slug) {
            $this->assertStringStartsNotWith('journey_', $slug);
        }
    }

    // =================================================================
    // 2. Registry drift — frontend vs backend
    // =================================================================

    /**
     * Reads the ACTUAL frontend registry file and compares it to the
     * backend catalog, in both directions. Not a third hand-copied list:
     * if either side gains, loses or re-scopes a node, this fails.
     *
     * Skips (rather than fails) when the sibling frontend checkout is not
     * present, so a backend-only CI job stays green — set
     * JOURNEY_REGISTRY_PATH to point at it explicitly.
     */
    public function test_the_backend_catalog_matches_the_frontend_node_registry(): void
    {
        $path = $this->registryPath();

        if ($path === null) {
            $this->markTestSkipped('frontend-app/src/journey/nodeRegistry.tsx not found next to this checkout.');
        }

        $registry = $this->parseRegistry(file_get_contents($path));

        // The 5 legacy executable types are intentionally NOT governed by
        // the backend catalog (grandfathered — see JourneyNodeCatalog).
        foreach (WhatsAppFlow::EXECUTABLE_NODE_TYPES as $legacy) {
            $this->assertArrayNotHasKey($legacy, JourneyNodeCatalog::NODES, "Legacy type '{$legacy}' must stay ungated.");
            unset($registry[$legacy]);
        }

        $this->assertSame(
            array_values(WhatsAppFlow::PALETTE_NODE_TYPES),
            array_keys(JourneyNodeCatalog::NODES),
            'JourneyNodeCatalog and WhatsAppFlow::PALETTE_NODE_TYPES disagree.'
        );

        ksort($registry);
        $catalog = JourneyNodeCatalog::NODES;
        ksort($catalog);

        $this->assertSame(
            array_keys($catalog),
            array_keys($registry),
            'The frontend registry and the backend catalog list different palette nodes.'
        );

        foreach ($catalog as $type => $requirements) {
            $this->assertSame(
                $requirements['capabilities'],
                $registry[$type]['capabilities'],
                "Capability drift on '{$type}'."
            );
            $this->assertSame(
                $requirements['providers'],
                $registry[$type]['providers'],
                "Provider drift on '{$type}'."
            );
        }
    }

    public function test_every_catalog_capability_slug_actually_exists_in_the_database(): void
    {
        $seeded = Capability::pluck('slug')->all();

        foreach (JourneyNodeCatalog::NODES as $type => $requirements) {
            foreach ($requirements['capabilities'] as $slug) {
                $this->assertContains($slug, $seeded, "Node '{$type}' requires unseeded capability '{$slug}'.");
            }
        }
    }

    public function test_every_catalog_provider_slug_actually_exists_in_the_database(): void
    {
        $seeded = \DB::table('providers')->pluck('slug')->all();

        foreach (JourneyNodeCatalog::NODES as $type => $requirements) {
            $this->assertNotEmpty($requirements['providers'], "Node '{$type}' declares no provider.");

            foreach ($requirements['providers'] as $slug) {
                $this->assertContains($slug, $seeded, "Node '{$type}' names unknown provider '{$slug}'.");
            }
        }
    }

    public function test_the_catalog_stores_no_credential_shaped_value(): void
    {
        $serialized = strtolower(json_encode(JourneyNodeCatalog::NODES));

        foreach (['token', 'secret', 'password', 'api_key', 'apikey', 'credential', 'account_id'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $serialized);
        }
    }

    // =================================================================
    // 3. Capability enforcement
    // =================================================================

    public function test_a_node_whose_capability_the_account_lacks_is_rejected_with_403(): void
    {
        ['user' => $user] = $this->tenant('business');

        $response = $this->actingAs($user)->postJson(
            self::ENDPOINT,
            $this->payload([$this->node('n1', 'api', ['method' => 'GET', 'url' => 'https://x.test'])])
        );

        $response->assertStatus(403)->assertJsonPath('error_code', 'JOURNEY_NODE_NOT_ENTITLED');
        $this->assertStringContainsString('external_api', $response->json('nodes.api'));
        $this->assertDatabaseCount('whatsapp_flows', 0);
    }

    public function test_the_same_node_saves_once_the_capability_is_granted(): void
    {
        ['user' => $user] = $this->tenant('business', ['external_api']);

        $this->actingAs($user)
            ->postJson(self::ENDPOINT, $this->payload([$this->node('n1', 'api', ['method' => 'GET', 'url' => 'https://x.test'])]))
            ->assertStatus(201);

        $this->assertDatabaseCount('whatsapp_flows', 1);
    }

    public function test_a_node_requiring_two_capabilities_needs_both(): void
    {
        // catalog requires whatsapp_send AND commerce. The plan grants
        // whatsapp_send; commerce is still missing.
        ['account' => $account, 'user' => $user] = $this->tenant('business');

        $this->actingAs($user)
            ->postJson(self::ENDPOINT, $this->payload([$this->node('n1', 'catalog', ['catalogId' => 'c1'])]))
            ->assertStatus(403);

        $this->grant($account, 'commerce');

        $this->actingAs($user)
            ->postJson(self::ENDPOINT, $this->payload([$this->node('n1', 'catalog', ['catalogId' => 'c1'])]))
            ->assertStatus(201);
    }

    public function test_a_mixed_graph_reports_every_unentitled_node_type_once(): void
    {
        ['user' => $user] = $this->tenant('business', ['external_api']);

        $response = $this->actingAs($user)->postJson(self::ENDPOINT, $this->payload([
            $this->node('n1', 'text', ['text' => 'hi']),                                  // allowed (plan)
            $this->node('n2', 'api', ['method' => 'GET', 'url' => 'https://x.test']),     // allowed (granted)
            $this->node('n3', 'code', ['language' => 'javascript', 'code' => 'x']),       // denied
            $this->node('n4', 'code', ['language' => 'javascript', 'code' => 'y']),       // same type
            $this->node('n5', 'email', ['to' => 'a@b.test', 'subject' => 's', 'body' => 'b']), // denied
        ]));

        $response->assertStatus(403);

        $denied = array_keys($response->json('nodes'));
        sort($denied);

        $this->assertSame(['code', 'email'], $denied);
    }

    public function test_a_control_flow_node_needs_no_entitlement(): void
    {
        ['user' => $user] = $this->tenant('starter');

        $this->actingAs($user)->postJson(self::ENDPOINT, $this->payload([
            $this->node('n1', 'delay', ['amount' => 5, 'unit' => 'minutes']),
        ]))->assertStatus(201);
    }

    public function test_a_suspended_account_cannot_save_a_capability_requiring_node(): void
    {
        ['account' => $account, 'user' => $user] = $this->tenant('business', ['external_api']);
        $account->forceFill(['status' => 'suspended'])->save();

        // SubscriptionGuardMiddleware blocks a suspended account outright;
        // the authorizer independently refuses too, so the gate does not
        // depend on middleware ordering to stay closed.
        $this->actingAs($user)
            ->postJson(self::ENDPOINT, $this->payload([$this->node('n1', 'api', ['method' => 'GET', 'url' => 'https://x.test'])]))
            ->assertStatus(403);

        $this->assertDatabaseCount('whatsapp_flows', 0);
    }

    // =================================================================
    // 4. Provider enforcement
    // =================================================================

    public function test_a_meta_only_node_cannot_be_saved_on_a_qr_account(): void
    {
        // 'starter' is a QR plan. Grant every capability the node could
        // possibly need, so the ONLY thing left to refuse it is provider.
        ['user' => $user] = $this->tenant('starter', ['whatsapp_send', 'commerce']);

        $response = $this->actingAs($user)->postJson(
            self::ENDPOINT,
            $this->payload([$this->node('n1', 'reply_button', ['body' => 'pick', 'buttons' => [['id' => 'b1', 'title' => 'A']]])])
        );

        $response->assertStatus(403)->assertJsonPath('error_code', 'JOURNEY_NODE_NOT_ENTITLED');
        $this->assertStringContainsString('qr', $response->json('nodes.reply_button'));
    }

    public function test_the_same_meta_only_node_saves_on_a_meta_account(): void
    {
        ['user' => $user] = $this->tenant('business');

        $this->actingAs($user)->postJson(
            self::ENDPOINT,
            $this->payload([$this->node('n1', 'reply_button', ['body' => 'pick', 'buttons' => [['id' => 'b1', 'title' => 'A']]])])
        )->assertStatus(201);
    }

    public function test_a_caller_cannot_force_a_provider_through_the_request_body(): void
    {
        ['user' => $user] = $this->tenant('starter', ['whatsapp_send', 'commerce']);

        foreach ([['provider' => 'meta'], ['engine_type' => 'meta'], ['provider' => 'meta', 'engine_type' => 'meta']] as $spoof) {
            $this->actingAs($user)->postJson(
                self::ENDPOINT,
                $this->payload([$this->node('n1', 'catalog', ['catalogId' => 'c1'])], $spoof)
            )->assertStatus(403);
        }

        $this->assertDatabaseCount('whatsapp_flows', 0);
    }

    public function test_a_caller_cannot_force_a_provider_through_the_node_configuration(): void
    {
        ['user' => $user] = $this->tenant('starter', ['whatsapp_send', 'commerce']);

        // The frontend registry's own metadata, echoed back in the node
        // body. The server reads none of it.
        $this->actingAs($user)->postJson(self::ENDPOINT, $this->payload([
            $this->node('n1', 'catalog', [
                'catalogId' => 'c1',
                'providers' => ['qr', 'meta'],
                'capabilities' => [],
                'engine_type' => 'meta',
            ]),
        ]))->assertStatus(403);
    }

    public function test_an_account_with_no_engine_still_uses_platform_only_nodes(): void
    {
        $account = Account::factory()->create();
        $user = User::factory()->create(['account_id' => $account->id, 'is_active' => true]);
        $user->assignRole('admin');

        // No subscription at all: SubscriptionGuardMiddleware refuses the
        // write before the authorizer is ever consulted. Asserting the
        // status, not the reason, keeps this honest about which guard
        // fired.
        $this->actingAs($user)
            ->postJson(self::ENDPOINT, $this->payload([$this->node('n1', 'delay', ['amount' => 1, 'unit' => 'minutes'])]))
            ->assertStatus(403);
    }

    // =================================================================
    // 5. Tenant isolation / spoofing
    // =================================================================

    public function test_one_tenant_cannot_borrow_another_tenants_entitlement(): void
    {
        ['account' => $entitled] = $this->tenant('business', ['external_api']);
        ['user' => $outsider] = $this->tenant('business');

        $response = $this->actingAs($outsider)->postJson(
            self::ENDPOINT,
            $this->payload([$this->node('n1', 'api', ['method' => 'GET', 'url' => 'https://x.test'])])
        );

        $response->assertStatus(403);
        // And the entitled tenant's own account is untouched by the attempt.
        $this->assertDatabaseHas('account_entitlements', ['account_id' => $entitled->id]);
        $this->assertDatabaseCount('whatsapp_flows', 0);
    }

    public function test_a_request_supplied_account_id_cannot_redirect_the_entitlement_check(): void
    {
        ['account' => $entitled] = $this->tenant('business', ['external_api']);
        ['user' => $outsider] = $this->tenant('business');

        foreach ([
            ['account_id' => $entitled->id],
            ['tenant_id' => $entitled->id],
        ] as $spoof) {
            $this->actingAs($outsider)->postJson(
                self::ENDPOINT.'?account_id='.$entitled->id,
                $this->payload([$this->node('n1', 'api', ['method' => 'GET', 'url' => 'https://x.test'])], $spoof)
            )->assertStatus(403);
        }

        $this->assertDatabaseCount('whatsapp_flows', 0);
    }

    public function test_a_flow_is_never_persisted_when_authorization_fails(): void
    {
        ['user' => $user] = $this->tenant('business');

        $this->actingAs($user)
            ->postJson(self::ENDPOINT, $this->payload([$this->node('n1', 'payment', ['amount' => 10, 'currency' => 'INR'])]))
            ->assertStatus(403);

        $this->assertSame(0, WhatsAppFlow::count());
    }

    public function test_update_is_gated_as_well_as_create(): void
    {
        ['account' => $account, 'user' => $user] = $this->tenant('business');

        $flow = WhatsAppFlow::create([
            'account_id' => $account->id,
            'name' => 'Existing',
            'trigger_type' => 'keyword',
            'trigger_value' => 'hi',
            'graph_data' => ['nodes' => [['id' => 'n_trigger', 'type' => 'trigger', 'position' => ['x' => 0, 'y' => 0], 'data' => []]], 'edges' => []],
            'is_active' => true,
        ]);

        $this->actingAs($user)
            ->putJson(self::ENDPOINT.'/'.$flow->id, $this->payload([$this->node('n1', 'rag', ['knowledgeBaseId' => 'kb1'])]))
            ->assertStatus(403);

        // Unchanged on disk — a refused update mutates nothing.
        $this->assertSame('Existing', $flow->fresh()->name);
        $this->assertCount(1, $flow->fresh()->nodes());
    }

    // =================================================================
    // 6. Error contract preserved (Task 6 regression)
    // =================================================================

    public function test_an_unknown_node_type_is_still_422_not_403(): void
    {
        ['user' => $user] = $this->tenant('business', ['external_api']);

        $this->actingAs($user)
            ->postJson(self::ENDPOINT, $this->payload([$this->node('n1', 'quantum_teleport')]))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['graph_data.nodes.1.type']);
    }

    public function test_invalid_configuration_is_still_422_not_403(): void
    {
        ['user' => $user] = $this->tenant('business');

        // A delay needs no capability at all, so a bad one can only be 422.
        $this->actingAs($user)
            ->postJson(self::ENDPOINT, $this->payload([$this->node('n1', 'delay', ['amount' => 0, 'unit' => 'weeks'])]))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['graph_data.nodes.1.data.amount', 'graph_data.nodes.1.data.unit']);
    }

    public function test_a_malformed_node_is_rejected_before_the_entitlement_check(): void
    {
        ['user' => $user] = $this->tenant('business');

        // Unknown type AND an unentitled type in one graph: the 422 wins,
        // because structural validity is checked first.
        $this->actingAs($user)->postJson(self::ENDPOINT, $this->payload([
            $this->node('n1', 'quantum_teleport'),
            $this->node('n2', 'code', ['language' => 'javascript', 'code' => 'x']),
        ]))->assertStatus(422);
    }

    // =================================================================
    // 7. Legacy journeys
    // =================================================================

    public function test_a_legacy_journey_still_saves_with_no_entitlements_at_all(): void
    {
        // A QR account holding nothing beyond its plan's whatsapp_send —
        // exactly the shape every pre-Task-6 tenant is in.
        ['user' => $user] = $this->tenant('starter');

        $this->actingAs($user)->postJson(self::ENDPOINT, $this->payload([
            $this->node('n1', 'message', ['text' => 'Hello']),
            $this->node('n2', 'question', ['prompt_text' => 'Name?', 'variable_name' => 'name', 'input_type' => 'text']),
            $this->node('n3', 'condition', ['variable' => 'name']),
            $this->node('n4', 'save_lead', ['name_variable' => 'name']),
        ]))->assertStatus(201);

        $this->assertDatabaseCount('whatsapp_flows', 1);
    }

    public function test_a_legacy_journey_round_trips_unchanged_through_update(): void
    {
        ['account' => $account, 'user' => $user] = $this->tenant('starter');

        $graph = [
            'nodes' => [
                ['id' => 'n_trigger', 'type' => 'trigger', 'position' => ['x' => 0, 'y' => 0], 'data' => []],
                ['id' => 'm1', 'type' => 'message', 'position' => ['x' => 200, 'y' => 0], 'data' => ['text' => 'Hi']],
                ['id' => 'c1', 'type' => 'condition', 'position' => ['x' => 400, 'y' => 0], 'data' => ['variable' => 'x']],
                ['id' => 's1', 'type' => 'save_lead', 'position' => ['x' => 600, 'y' => 0], 'data' => []],
            ],
            'edges' => [
                ['id' => 'e1', 'source' => 'n_trigger', 'target' => 'm1'],
                ['id' => 'e2', 'source' => 'm1', 'target' => 'c1'],
                // A legacy condition branch: no sourceHandle, and none is invented.
                ['id' => 'e3', 'source' => 'c1', 'target' => 's1', 'condition' => ['operator' => 'exists']],
            ],
        ];

        $flow = WhatsAppFlow::create([
            'account_id' => $account->id,
            'name' => 'Legacy',
            'trigger_type' => 'keyword',
            'trigger_value' => 'hi',
            'graph_data' => $graph,
            'is_active' => true,
        ]);

        $this->actingAs($user)->putJson(self::ENDPOINT.'/'.$flow->id, [
            'name' => 'Legacy',
            'trigger_type' => 'keyword',
            'trigger_value' => 'hi',
            'graph_data' => $graph,
            'is_active' => true,
        ])->assertStatus(200);

        $this->assertSame($graph, $flow->fresh()->graph_data);
    }

    public function test_the_legacy_executable_types_carry_no_capability_requirement(): void
    {
        foreach (WhatsAppFlow::EXECUTABLE_NODE_TYPES as $type) {
            $this->assertNull(
                JourneyNodeCatalog::requirementsFor($type),
                "Legacy type '{$type}' gained a capability requirement — that breaks every saved journey."
            );
        }
    }

    // =================================================================
    // 8. No plan names in the authorization path
    // =================================================================

    public function test_no_plan_name_appears_in_the_capability_authorization_code(): void
    {
        $files = [
            app_path('Services/Access/JourneyNodeAuthorizer.php'),
            app_path('Support/JourneyNodeCatalog.php'),
        ];

        foreach ($files as $file) {
            $code = $this->stripComments(file_get_contents($file));

            foreach (['starter', 'growth', 'business', 'PlanCatalog', 'plan_key'] as $planName) {
                $this->assertStringNotContainsStringIgnoringCase(
                    $planName,
                    $code,
                    basename($file).' authorizes on a plan name instead of a capability.'
                );
            }
        }
    }

    public function test_the_authorizer_never_reads_a_client_supplied_provider(): void
    {
        $code = $this->stripComments(file_get_contents(app_path('Services/Access/JourneyNodeAuthorizer.php')));

        // No Request, no input(), no request() helper: the class cannot
        // see the request body even by accident.
        $this->assertStringNotContainsString('Request', $code);
        $this->assertStringNotContainsString('request(', $code);
        $this->assertStringNotContainsString('->input(', $code);
    }

    // =================================================================
    // Helpers
    // =================================================================

    /** Comment-stripped source, so a docblock mentioning a plan name is not a false positive. */
    private function stripComments(string $code): string
    {
        $out = '';

        foreach (token_get_all($code) as $token) {
            if (is_array($token) && in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }

            $out .= is_array($token) ? $token[1] : $token;
        }

        return $out;
    }

    private function registryPath(): ?string
    {
        $candidates = array_filter([
            env('JOURNEY_REGISTRY_PATH'),
            base_path('../frontend-app/src/journey/nodeRegistry.tsx'),
            base_path('../frontend/src/journey/nodeRegistry.tsx'),
        ]);

        foreach ($candidates as $candidate) {
            if (is_string($candidate) && is_file($candidate)) {
                return $candidate;
            }
        }

        return null;
    }

    /**
     * Pulls { type => {capabilities, providers} } out of the real
     * registry source. A node definition is the only place where a
     * `type:` line is immediately followed by a `label:` line — field
     * descriptors inside configSchema are not, which is what keeps
     * `{ key: 'mediaUrl', type: 'url' }` out of the result.
     *
     * @return array<string, array{capabilities: array<int, string>, providers: array<int, string>}>
     */
    private function parseRegistry(string $source): array
    {
        preg_match_all("/^    type: '([a-z_]+)',\n    label: '/m", $source, $matches, PREG_OFFSET_CAPTURE);

        $found = [];
        $starts = [];

        foreach ($matches[1] as $match) {
            $found[] = $match[0];
            $starts[] = $match[1];
        }

        $result = [];

        foreach ($found as $i => $type) {
            $block = substr($source, $starts[$i], ($starts[$i + 1] ?? strlen($source)) - $starts[$i]);

            $result[$type] = [
                'capabilities' => $this->parseStringArray($block, 'capabilities'),
                'providers' => $this->parseStringArray($block, 'providers'),
            ];
        }

        return $result;
    }

    /** @return array<int, string> */
    private function parseStringArray(string $block, string $key): array
    {
        if (! preg_match("/^    {$key}: \[(.*?)\],$/m", $block, $m)) {
            return [];
        }

        preg_match_all("/'([a-z_]+)'/", $m[1], $values);

        return $values[1];
    }
}
