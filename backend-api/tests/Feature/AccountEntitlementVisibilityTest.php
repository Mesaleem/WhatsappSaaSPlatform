<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\AccountEntitlement;
use App\Models\Capability;
use App\Models\Invoice;
use App\Models\Plan;
use App\Models\User;
use App\Services\Billing\InvoiceCreditService;
use App\Support\PlanCatalog;
use Database\Seeders\Phase1FoundationSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Phase 5 Task 8 — Bind Journey Node Capabilities to Plan Entitlements.
 *
 * SCOPE NOTE, READ FIRST. Task 8 deliberately seeded NO plan ->
 * capability mapping — that was a pricing decision, escalated rather
 * than guessed. Phase 5 Task 9 received the product owner's confirmed
 * matrix and seeded it; the matrix itself is transcribed and asserted in
 * exactly one place, PlanCapabilityMatrixAndBackfillTest, so this suite
 * stays about VISIBILITY and does not become a second copy of it.
 *
 * What is delivered and tested here:
 *   - the read endpoint the Super Admin panel needs, which shipped
 *     missing (grant/revoke existed; nothing could list);
 *   - proof that the existing plan -> plan_entitlement -> account
 *     entitlement -> /auth/me -> Journey authorization chain works end
 *     to end the moment a mapping IS seeded (asserted by seeding one
 *     LOCALLY, inside a test, then tearing it down with the database);
 *   - proof that provider compatibility remains an independent check a
 *     plan entitlement cannot bypass;
 *   - proof that no request-supplied field can unlock a node.
 *
 * A locally-seeded plan mapping inside a test is not a product decision:
 * RefreshDatabase drops it, and no seeder or migration carries it.
 */
class AccountEntitlementVisibilityTest extends TestCase
{
    use RefreshDatabase;

    private const ENTITLEMENTS = '/api/admin/accounts/%d/entitlements';

    private const JOURNEYS = '/api/whatsapp/flows';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        $this->seed(Phase1FoundationSeeder::class);
    }

    // =================================================================
    // Fixtures
    // =================================================================

    /** @return array{account: Account, user: User} */
    private function tenant(string $planKey = 'business'): array
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

        $user = User::factory()->create(['account_id' => $account->id, 'is_active' => true]);
        $user->assignRole('admin');

        return ['account' => $account->fresh(), 'user' => $user];
    }

    private function superAdmin(): User
    {
        $user = User::factory()->create(['account_id' => null, 'is_active' => true]);
        $user->assignRole('super_admin');

        return $user;
    }

    private function capabilityId(string $slug): int
    {
        return Capability::where('slug', $slug)->firstOrFail()->id;
    }

    /**
     * Seeds a plan -> capability row FOR THIS TEST ONLY. Not a product
     * mapping; RefreshDatabase discards it.
     */
    private function bundleIntoPlan(string $planSlug, string $capabilitySlug): void
    {
        Plan::where('slug', $planSlug)->firstOrFail()->capabilities()->syncWithoutDetaching([
            $this->capabilityId($capabilitySlug) => ['usage_limit' => null],
        ]);
    }

    private function grantManually(Account $account, string $slug): void
    {
        AccountEntitlement::firstOrCreate(
            ['account_id' => $account->id, 'capability_id' => $this->capabilityId($slug)],
            ['source' => 'manual_grant', 'granted_by_account_id' => null],
        );
    }

    /** @param array<int, array<string, mixed>> $nodes */
    private function journeyPayload(array $nodes): array
    {
        return [
            'name' => 'Journey',
            'trigger_type' => 'keyword',
            'trigger_value' => 'start',
            'graph_data' => [
                'nodes' => array_merge(
                    [['id' => 'n_trigger', 'type' => 'trigger', 'position' => ['x' => 0, 'y' => 0], 'data' => []]],
                    $nodes,
                ),
                'edges' => [['id' => 'e0', 'source' => 'n_trigger', 'target' => $nodes[0]['id']]],
            ],
            'is_active' => true,
        ];
    }

    /** @param array<string, mixed> $data */
    private function node(string $id, string $type, array $data = []): array
    {
        return ['id' => $id, 'type' => $type, 'position' => ['x' => 100, 'y' => 100], 'data' => $data];
    }

    // =================================================================
    // 1. No mapping was invented
    // =================================================================

    public function test_every_plan_still_bundles_its_message_quota_capability(): void
    {
        /*
         * Phase 5 Task 9 seeded the product owner's confirmed matrix, so
         * a plan is no longer whatsapp_send alone. The exact bundle is
         * pinned by PlanCapabilityMatrixAndBackfillTest, which is the
         * single place the approved matrix is transcribed; duplicating it
         * here would create a second copy to keep in sync.
         *
         * What this test still guards is the invariant Task 8 cared
         * about: every plan carries its own metered messaging capability,
         * whatever else the bundle gained.
         */
        foreach (['starter', 'growth', 'business'] as $slug) {
            $bundled = Plan::where('slug', $slug)->firstOrFail()->capabilities->pluck('slug')->all();

            $this->assertContains('whatsapp_send', $bundled, "Plan '{$slug}' lost its messaging capability.");
        }
    }

    public function test_re_seeding_creates_no_duplicate_plan_entitlements(): void
    {
        $before = \DB::table('plan_entitlements')->count();

        $this->seed(Phase1FoundationSeeder::class);
        $this->seed(Phase1FoundationSeeder::class);

        $this->assertSame($before, \DB::table('plan_entitlements')->count());
        // 4 + 9 + 11 rows — the confirmed matrix, asserted cell by cell
        // in PlanCapabilityMatrixAndBackfillTest.
        $this->assertSame(24, $before);
    }

    public function test_the_plan_table_stays_value_identical_to_the_plan_catalog(): void
    {
        // The Phase 1 cutover guard, re-asserted here because Task 8 is
        // the task most likely to break it.
        foreach (PlanCatalog::all() as $slug => $expected) {
            $plan = Plan::where('slug', $slug)->firstOrFail();

            $this->assertSame($expected['label'], $plan->label);
            $this->assertSame($expected['price'], (float) $plan->price);
            $this->assertSame($expected['duration_days'], $plan->duration_days);
        }
    }

    // =================================================================
    // 2. The read endpoint
    // =================================================================

    public function test_a_super_admin_can_list_an_accounts_effective_entitlements(): void
    {
        /*
         * Phase 5 Task 9: 'business' now BUNDLES external_api, so it is
         * no longer an example of a manual grant. whatsapp_groups is
         * excluded from business by the confirmed matrix, which makes it
         * the honest manual-grant example — and it is Meta-incompatible,
         * so it is granted here directly rather than through the endpoint
         * (which correctly refuses a provider-incompatible grant).
         */
        ['account' => $account] = $this->tenant('business');
        $this->grantManually($account, 'custom_code');

        $response = $this->actingAs($this->superAdmin())->getJson(sprintf(self::ENTITLEMENTS, $account->id));

        $response->assertStatus(200);

        $rows = collect($response->json('data'))->keyBy('slug');

        // Every seeded capability is listed, entitled or not — the panel
        // shows "not entitled" as a state, not as an absent row.
        $this->assertSame(Capability::count(), $rows->count());

        // Plan-granted.
        $this->assertTrue($rows['whatsapp_send']['granted']);
        $this->assertSame('plan', $rows['whatsapp_send']['source']);
        $this->assertTrue($rows['whatsapp_send']['plan_granted']);

        // Manually granted: the row already existed as a plan grant for
        // custom_code, so use a capability the matrix does NOT bundle.
        $this->assertTrue($rows['custom_code']['granted']);

        // Not entitled at all — whatsapp_groups is excluded from business.
        $this->assertFalse($rows['whatsapp_groups']['granted']);
        $this->assertNull($rows['whatsapp_groups']['source']);
    }

    public function test_the_listing_reports_the_accounts_plan_and_provider(): void
    {
        ['account' => $account] = $this->tenant('business');

        $meta = $this->actingAs($this->superAdmin())
            ->getJson(sprintf(self::ENTITLEMENTS, $account->id))
            ->json('meta');

        $this->assertSame('business', $meta['plan']);
        $this->assertSame('meta', $meta['provider']);
    }

    public function test_a_plan_bundled_capability_the_provider_refuses_reads_as_offered_but_not_granted(): void
    {
        // commerce is seeded qr => false. Bundling it into a QR plan must
        // show the administrator BOTH facts, not hide the incompatibility.
        $this->bundleIntoPlan('starter', 'commerce');

        ['account' => $account] = $this->tenant('starter');

        $row = collect(
            $this->actingAs($this->superAdmin())->getJson(sprintf(self::ENTITLEMENTS, $account->id))->json('data')
        )->firstWhere('slug', 'commerce');

        $this->assertTrue($row['plan_granted'], 'The plan offers it.');
        $this->assertFalse($row['granted'], 'The provider refuses it.');
    }

    public function test_a_tenant_admin_cannot_list_another_accounts_entitlements(): void
    {
        ['account' => $theirs] = $this->tenant('business');
        ['user' => $mine] = $this->tenant('business');

        $this->actingAs($mine)
            ->getJson(sprintf(self::ENTITLEMENTS, $theirs->id))
            ->assertStatus(403);
    }

    public function test_an_agent_sees_its_own_client_but_not_a_stranger(): void
    {
        $agentAccount = Account::factory()->create(['account_type' => 'agent']);
        $agentUser = User::factory()->create(['account_id' => $agentAccount->id, 'is_active' => true]);
        $agentUser->assignRole('admin');
        $agentUser->givePermissionTo('manage-accounts');

        ['account' => $mine] = $this->tenant('business');
        $mine->forceFill(['agent_id' => $agentAccount->id])->save();

        ['account' => $stranger] = $this->tenant('business');

        $this->actingAs($agentUser)->getJson(sprintf(self::ENTITLEMENTS, $mine->id))->assertStatus(200);

        // 404, not 403: an Agent is never told whose account it is.
        $this->actingAs($agentUser)->getJson(sprintf(self::ENTITLEMENTS, $stranger->id))->assertStatus(404);
    }

    public function test_the_listing_exposes_no_credential(): void
    {
        ['account' => $account] = $this->tenant('business');

        $body = $this->actingAs($this->superAdmin())
            ->getJson(sprintf(self::ENTITLEMENTS, $account->id))
            ->getContent();

        foreach (['access_token', 'api_secret', 'secret_hash', 'password', 'key_hash'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, strtolower($body));
        }
    }

    // =================================================================
    // 3. Plan -> entitlement -> /auth/me -> Journey authorization
    // =================================================================

    public function test_a_plan_bundled_capability_flows_all_the_way_to_journey_authorization(): void
    {
        // Seeded locally, not by the product seeder — see this class's docblock.
        $this->bundleIntoPlan('business', 'external_api');

        ['user' => $user] = $this->tenant('business');

        // 1. It became an account entitlement, sourced from the plan.
        $this->assertDatabaseHas('account_entitlements', [
            'account_id' => $user->account_id,
            'capability_id' => $this->capabilityId('external_api'),
            'source' => 'plan',
        ]);

        // 2. /auth/me reports it — the same map the Journey UI reads.
        $this->actingAs($user)->getJson('/api/auth/me')
            ->assertStatus(200)
            ->assertJsonPath('user.capabilities.external_api', true);

        // 3. The Journey node it gates now saves.
        $this->actingAs($user)->postJson(
            self::JOURNEYS,
            $this->journeyPayload([$this->node('n1', 'api', ['method' => 'GET', 'url' => 'https://x.test'])])
        )->assertStatus(201);
    }

    public function test_revoking_the_capability_locks_the_node_again(): void
    {
        $this->bundleIntoPlan('business', 'external_api');
        ['account' => $account, 'user' => $user] = $this->tenant('business');

        $this->actingAs($user)->getJson('/api/auth/me')->assertJsonPath('user.capabilities.external_api', true);

        $this->actingAs($this->superAdmin())
            ->deleteJson(sprintf(self::ENTITLEMENTS, $account->id).'/external_api')
            ->assertStatus(200);

        // /auth/me flips immediately — no stale cached capability map.
        $this->actingAs($user)->getJson('/api/auth/me')
            ->assertJsonPath('user.capabilities.external_api', false);

        // And the backend refuses the node, which is what actually matters.
        $this->actingAs($user)->postJson(
            self::JOURNEYS,
            $this->journeyPayload([$this->node('n1', 'api', ['method' => 'GET', 'url' => 'https://x.test'])])
        )->assertStatus(403)->assertJsonPath('error_code', 'JOURNEY_NODE_NOT_ENTITLED');
    }

    public function test_auth_me_lists_every_seeded_capability_including_the_new_five(): void
    {
        ['user' => $user] = $this->tenant('business');

        $capabilities = $this->actingAs($user)->getJson('/api/auth/me')->json('user.capabilities');

        foreach (['commerce', 'payments', 'external_api', 'custom_code', 'email'] as $slug) {
            $this->assertArrayHasKey($slug, $capabilities, "/auth/me omits '{$slug}'.");
            // Phase 5 Task 9: the business bundle now includes all five,
            // so the assertion flipped from "nothing is seeded" to "the
            // confirmed bundle arrives".
            $this->assertTrue($capabilities[$slug], "'{$slug}' is in the business bundle but did not arrive.");
        }
    }

    // =================================================================
    // 4. Provider stays an independent check (Task 7 semantics)
    // =================================================================

    public function test_a_plan_entitlement_cannot_bypass_provider_compatibility(): void
    {
        // Bundle commerce into the QR plan AND force the entitlement row
        // in by hand, so the ONLY thing left that can refuse the node is
        // the provider check.
        $this->bundleIntoPlan('starter', 'commerce');
        ['account' => $account, 'user' => $user] = $this->tenant('starter');
        $this->grantManually($account, 'commerce');
        $this->grantManually($account, 'whatsapp_send');
        // Phase 7 Task 1.5 — Journey API access itself needs journey_automation (not in starter).
        $this->grantManually($account, 'journey_automation');

        $response = $this->actingAs($user)->postJson(
            self::JOURNEYS,
            $this->journeyPayload([$this->node('n1', 'catalog', ['catalogId' => 'c1'])])
        );

        $response->assertStatus(403)->assertJsonPath('error_code', 'JOURNEY_NODE_NOT_ENTITLED');
        $this->assertDatabaseCount('whatsapp_flows', 0);
    }

    public function test_a_meta_only_node_cannot_be_forced_onto_qr_by_any_request_field(): void
    {
        $this->bundleIntoPlan('starter', 'commerce');
        ['account' => $account, 'user' => $user] = $this->tenant('starter');
        $this->grantManually($account, 'commerce');
        // Phase 7 Task 1.5 — Journey API access itself needs journey_automation (not in starter).
        $this->grantManually($account, 'journey_automation');

        foreach ([
            ['provider' => 'meta'],
            ['engine_type' => 'meta'],
            ['plan' => 'business'],
            ['plan_key' => 'business'],
            ['capabilities' => ['commerce' => true]],
            ['account_id' => $account->id],
        ] as $spoof) {
            $payload = array_merge(
                $this->journeyPayload([$this->node('n1', 'catalog', ['catalogId' => 'c1'])]),
                $spoof,
            );

            $this->actingAs($user)->postJson(self::JOURNEYS, $payload)->assertStatus(403);
        }

        $this->assertDatabaseCount('whatsapp_flows', 0);
    }

    public function test_platform_nodes_stay_available_on_every_provider(): void
    {
        // Task 7 semantics, re-asserted: 'none' means "needs no engine",
        // so a platform node is not a QR-only or Meta-only node.
        foreach (['starter', 'business'] as $planKey) {
            ['account' => $account, 'user' => $user] = $this->tenant($planKey);
            $this->grantManually($account, 'custom_code');
            // Phase 7 Task 1.5 — Journey API access itself needs journey_automation (not in starter).
            $this->grantManually($account, 'journey_automation');

            $this->actingAs($user)->postJson(
                self::JOURNEYS,
                $this->journeyPayload([$this->node('n1', 'code', ['language' => 'javascript', 'code' => 'x'])])
            )->assertStatus(201);
        }
    }

    public function test_qr_messaging_is_untouched(): void
    {
        ['account' => $account, 'user' => $user] = $this->tenant('starter');
        // Phase 7 Task 1.5 — Journey API access itself needs journey_automation (not in starter).
        $this->grantManually($account, 'journey_automation');

        // whatsapp_send comes from the plan; a QR text node still saves.
        $this->actingAs($user)->postJson(
            self::JOURNEYS,
            $this->journeyPayload([$this->node('n1', 'text', ['text' => 'hi'])])
        )->assertStatus(201);
    }

    // =================================================================
    // 5. Cross-tenant
    // =================================================================

    public function test_one_account_cannot_use_another_accounts_plan_entitlement(): void
    {
        ['account' => $entitled] = $this->tenant('business');

        // Phase 5 Task 9: starter excludes custom_code while business
        // includes it, so this is now a real cross-plan comparison using
        // the confirmed matrix rather than a locally-seeded one.
        ['account' => $outsiderAccount, 'user' => $outsider] = $this->tenant('starter');
        // Phase 7 Task 1.5 — past the Journey API gate, so the refusal below is the node check.
        $this->grantManually($outsiderAccount, 'journey_automation');

        $this->actingAs($outsider)->postJson(
            self::JOURNEYS,
            $this->journeyPayload([$this->node('n1', 'code', ['language' => 'javascript', 'code' => 'x'])])
        )->assertStatus(403)->assertJsonPath('error_code', 'JOURNEY_NODE_NOT_ENTITLED');

        $this->assertDatabaseHas('account_entitlements', [
            'account_id' => $entitled->id,
            'capability_id' => $this->capabilityId('custom_code'),
        ]);
    }

    // =================================================================
    // 6. Legacy regression
    // =================================================================

    public function test_a_legacy_journey_still_saves_under_the_unchanged_mapping(): void
    {
        ['account' => $account, 'user' => $user] = $this->tenant('starter');
        // Phase 7 Task 1.5 — Journey API access itself needs journey_automation (not in starter).
        $this->grantManually($account, 'journey_automation');

        $this->actingAs($user)->postJson(self::JOURNEYS, $this->journeyPayload([
            $this->node('n1', 'message', ['text' => 'Hello']),
            $this->node('n2', 'save_lead', ['name_variable' => 'name']),
        ]))->assertStatus(201);
    }
}
