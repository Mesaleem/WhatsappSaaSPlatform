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
 * Phase 5 Task 9 — the confirmed plan -> capability matrix, the explicit
 * revoked state, and the existing-tenant backfill.
 *
 * THE MATRIX BELOW IS THE PRODUCT OWNER'S, TRANSCRIBED VERBATIM. It is
 * duplicated here on purpose: this is the regression guard the brief
 * asks for, so a later change to the seeder fails loudly against the
 * decision that was actually approved, rather than quietly redefining
 * what customers bought. Updating the seeder without updating this
 * constant is meant to break the build.
 */
class PlanCapabilityMatrixAndBackfillTest extends TestCase
{
    use RefreshDatabase;

    /** The approved matrix. `include` only — `exclude` and `manual` are absent by definition. */
    private const CONFIRMED_MATRIX = [
        'starter' => ['external_api', 'social', 'whatsapp_groups', 'whatsapp_send'],
        'growth' => ['ai', 'crm', 'email', 'external_api', 'journey_automation', 'payments', 'social', 'whatsapp_groups', 'whatsapp_send'],
        'business' => ['ads', 'ai', 'commerce', 'crm', 'custom_code', 'email', 'external_api', 'journey_automation', 'payments', 'social', 'whatsapp_send'],
    ];

    /** Cells the product owner marked `manual` — reachable by grant, never bundled. */
    private const MANUAL_CELLS = [
        'starter' => ['ai', 'payments', 'email'],
        'growth' => [],
        'business' => [],
    ];

    /** Cells marked `exclude` — not bundled and not expected to be. */
    private const EXCLUDED_CELLS = [
        'starter' => ['crm', 'journey_automation', 'ads', 'commerce', 'custom_code'],
        'growth' => ['ads', 'commerce', 'custom_code'],
        'business' => ['whatsapp_groups'],
    ];

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
    private function tenant(string $planKey = 'business', bool $pay = true): array
    {
        $account = Account::factory()->create();

        if ($pay) {
            $this->payFor($account, $planKey);
        }

        $user = User::factory()->create(['account_id' => $account->id, 'is_active' => true]);
        $user->assignRole('admin');

        return ['account' => $account->fresh(), 'user' => $user];
    }

    private function payFor(Account $account, string $planKey): void
    {
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

    private function bundled(string $planSlug): array
    {
        return Plan::where('slug', $planSlug)->firstOrFail()
            ->capabilities->pluck('slug')->sort()->values()->all();
    }

    /** @return array<int, string> */
    private function heldBy(Account $account): array
    {
        return AccountEntitlement::where('account_id', $account->id)
            ->active()
            ->with('capability')
            ->get()
            ->pluck('capability.slug')
            ->sort()
            ->values()
            ->all();
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
    // 1. The matrix
    // =================================================================

    public function test_each_plan_bundles_exactly_the_confirmed_capabilities(): void
    {
        foreach (self::CONFIRMED_MATRIX as $planSlug => $expected) {
            $this->assertSame(
                $expected,
                $this->bundled($planSlug),
                "Plan '{$planSlug}' no longer matches the product owner's approved matrix."
            );
        }
    }

    public function test_manual_cells_are_not_bundled(): void
    {
        foreach (self::MANUAL_CELLS as $planSlug => $manual) {
            foreach ($manual as $slug) {
                $this->assertNotContains(
                    $slug,
                    $this->bundled($planSlug),
                    "'{$slug}' is marked manual on '{$planSlug}' but was bundled into the plan."
                );
            }
        }
    }

    public function test_excluded_cells_are_not_bundled(): void
    {
        foreach (self::EXCLUDED_CELLS as $planSlug => $excluded) {
            foreach ($excluded as $slug) {
                $this->assertNotContains(
                    $slug,
                    $this->bundled($planSlug),
                    "'{$slug}' is marked exclude on '{$planSlug}' but was bundled into the plan."
                );
            }
        }
    }

    public function test_re_seeding_is_idempotent_and_creates_no_duplicates(): void
    {
        $capabilities = Capability::count();
        $planEntitlements = \DB::table('plan_entitlements')->count();

        $this->seed(Phase1FoundationSeeder::class);
        $this->seed(Phase1FoundationSeeder::class);

        $this->assertSame($capabilities, Capability::count());
        $this->assertSame($planEntitlements, \DB::table('plan_entitlements')->count());

        foreach (self::CONFIRMED_MATRIX as $planSlug => $expected) {
            $this->assertSame($expected, $this->bundled($planSlug));
        }
    }

    public function test_the_message_quota_rides_on_whatsapp_send_and_the_bundle_is_unmetered(): void
    {
        foreach (PlanCatalog::all() as $slug => $plan) {
            $rows = Plan::where('slug', $slug)->firstOrFail()->capabilities;

            $this->assertSame(
                $plan['total_allocated_messages'],
                $rows->firstWhere('slug', 'whatsapp_send')->pivot->usage_limit,
            );

            foreach ($rows->where('slug', '!=', 'whatsapp_send') as $row) {
                $this->assertNull($row->pivot->usage_limit, "{$slug}/{$row->slug} should be unmetered.");
            }
        }
    }

    public function test_pricing_quota_and_engine_are_untouched(): void
    {
        // The brief's explicit pricing boundary.
        $this->assertSame(499.00, PlanCatalog::find('starter')['price']);
        $this->assertSame(1999.00, PlanCatalog::find('growth')['price']);
        $this->assertSame(7999.00, PlanCatalog::find('business')['price']);
        $this->assertSame('qr', PlanCatalog::find('starter')['engine_type']);
        $this->assertSame('qr', PlanCatalog::find('growth')['engine_type']);
        $this->assertSame('meta', PlanCatalog::find('business')['engine_type']);
        $this->assertSame(500, PlanCatalog::find('starter')['total_allocated_messages']);
        $this->assertSame(2500, PlanCatalog::find('growth')['total_allocated_messages']);
        $this->assertSame(10000, PlanCatalog::find('business')['total_allocated_messages']);

        foreach (PlanCatalog::all() as $slug => $expected) {
            $plan = Plan::where('slug', $slug)->firstOrFail();

            $this->assertSame($expected['price'], (float) $plan->price);
            $this->assertSame($expected['duration_days'], $plan->duration_days);
        }
    }

    // =================================================================
    // 2. Provider compatibility is still independent
    // =================================================================

    /**
     * Phase 5 Task 9 FOLLOW-UP — the provider catalog's two QR rows, which
     * are the whole reason the Growth bundle behaves the way it does.
     *
     * These are asserted against the DATABASE, not against the seeder
     * constant, so a change to either one has to be made deliberately.
     */
    public function test_the_qr_provider_rows_match_the_corrected_product_rule(): void
    {
        $providerCapabilities = app(\App\Services\Access\ProviderCapabilityService::class);

        // CORRECTED: CRM is engine-agnostic, so the QR engine no longer
        // blocks it. This is what makes `growth -> crm = include` real.
        $this->assertTrue(
            $providerCapabilities->supportsOrNull('qr', 'crm'),
            'qr => crm must be true, or the confirmed matrix cell is inert.'
        );

        // Phase 7 Task 1.5 — OWNER DECISION: Journey automation is now
        // supported on QR too (the Journey API is gated on the capability,
        // so a `false` row would have locked every QR tenant out). This is
        // what makes `growth -> journey_automation = include` real.
        $this->assertTrue(
            $providerCapabilities->supportsOrNull('qr', 'journey_automation'),
            'qr => journey_automation must be true (Phase 7 Task 1.5), or the Growth cell is inert.'
        );

        // Still a real refusal on QR: the tier rule for ads is untouched.
        $this->assertFalse($providerCapabilities->supportsOrNull('qr', 'ads'));
    }

    public function test_the_meta_provider_rows_are_untouched_by_the_crm_fix(): void
    {
        $providerCapabilities = app(\App\Services\Access\ProviderCapabilityService::class);

        $this->assertTrue($providerCapabilities->supportsOrNull('meta', 'crm'));
        $this->assertTrue($providerCapabilities->supportsOrNull('meta', 'journey_automation'));
        $this->assertTrue($providerCapabilities->supportsOrNull('meta', 'whatsapp_send'));
        // The genuine technical impossibility is still stated as one.
        $this->assertFalse($providerCapabilities->supportsOrNull('meta', 'whatsapp_groups'));
    }

    public function test_re_seeding_does_not_duplicate_or_revert_the_corrected_provider_row(): void
    {
        $before = \DB::table('provider_capabilities')->count();

        $this->seed(Phase1FoundationSeeder::class);
        $this->seed(Phase1FoundationSeeder::class);

        $this->assertSame($before, \DB::table('provider_capabilities')->count());
        $this->assertTrue(app(\App\Services\Access\ProviderCapabilityService::class)->supportsOrNull('qr', 'crm'));
    }

    public function test_a_growth_qr_account_now_receives_crm_and_journey_automation(): void
    {
        /*
         * Phase 5 Task 9 FOLLOW-UP. This test previously asserted that a
         * QR account received NEITHER crm nor journey_automation, because
         * provider_capabilities blocked both. The product owner resolved
         * that contradiction in favour of the confirmed matrix: CRM is
         * engine-agnostic and is now supported on QR, while the Journey
         * tier rule is deliberately untouched.
         *
         * So the assertion split rather than weakened — one capability
         * flipped, the other must still be refused, and the test now
         * pins both halves.
         *
         * Phase 7 Task 1.5: the owner then made journey_automation
         * supported on QR as well, so BOTH now arrive; ads (never in
         * growth, and QR-unsupported) still does not.
         */
        ['account' => $account] = $this->tenant('growth');

        $held = $this->heldBy($account);

        // The plan bundles both.
        $this->assertContains('crm', $this->bundled('growth'));
        $this->assertContains('journey_automation', $this->bundled('growth'));

        // CRM now actually arrives — the fix.
        $this->assertContains('crm', $held, 'growth -> crm = include must now be effective.');

        // Journey automation now arrives too (Phase 7 Task 1.5).
        $this->assertContains('journey_automation', $held, 'growth -> journey_automation = include must now be effective.');
        $this->assertNotContains('ads', $held);

        // The rest of the bundle is unaffected either way.
        $this->assertContains('whatsapp_send', $held);
        $this->assertContains('whatsapp_groups', $held);
        $this->assertContains('external_api', $held);
    }

    public function test_a_meta_plan_receives_its_meta_only_capabilities(): void
    {
        ['account' => $account] = $this->tenant('business');

        $this->assertContains('commerce', $this->heldBy($account));
        $this->assertContains('crm', $this->heldBy($account));
    }

    public function test_a_plan_entitlement_never_overrides_provider_capability_at_the_journey_gate(): void
    {
        ['user' => $user] = $this->tenant('growth');

        // growth bundles 'ai', and a QR account does receive it — but an
        // agent node is platform-only, so this proves the grant landed.
        $this->actingAs($user)->postJson(
            self::JOURNEYS,
            $this->journeyPayload([$this->node('n1', 'agent', ['agentId' => 'a1'])])
        )->assertStatus(201);

        // commerce is excluded from growth AND blocked on qr: denied.
        $this->actingAs($user)->postJson(
            self::JOURNEYS,
            $this->journeyPayload([$this->node('n2', 'catalog', ['catalogId' => 'c1'])])
        )->assertStatus(403);
    }

    public function test_qr_messaging_is_untouched(): void
    {
        // Growth, not Starter: since Phase 7 Task 1.5 the Journey API needs
        // journey_automation, which Starter does not include (its refusal is
        // pinned in JourneyCapabilityGateTest). The node check is unchanged.
        ['user' => $user] = $this->tenant('growth');

        $this->actingAs($user)->postJson(
            self::JOURNEYS,
            $this->journeyPayload([$this->node('n1', 'text', ['text' => 'hi'])])
        )->assertStatus(201);
    }

    public function test_a_meta_only_node_is_still_refused_on_a_qr_plan(): void
    {
        ['user' => $user] = $this->tenant('growth');

        $this->actingAs($user)->postJson(
            self::JOURNEYS,
            $this->journeyPayload([$this->node('n1', 'reply_button', ['body' => 'b', 'buttons' => [['id' => 'b1', 'title' => 'A']]])])
        )->assertStatus(403);
    }

    // =================================================================
    // 3. End to end: plan -> entitlement -> /auth/me -> Journey
    // =================================================================

    public function test_an_included_capability_unlocks_its_journey_node_end_to_end(): void
    {
        ['account' => $account, 'user' => $user] = $this->tenant('business');

        $this->assertDatabaseHas('account_entitlements', [
            'account_id' => $account->id,
            'capability_id' => $this->capabilityId('custom_code'),
            'source' => 'plan',
            'revoked_at' => null,
        ]);

        $this->actingAs($user)->getJson('/api/auth/me')
            ->assertJsonPath('user.capabilities.custom_code', true);

        $this->actingAs($user)->postJson(
            self::JOURNEYS,
            $this->journeyPayload([$this->node('n1', 'code', ['language' => 'javascript', 'code' => 'x'])])
        )->assertStatus(201);
    }

    public function test_an_excluded_capability_leaves_its_node_locked_end_to_end(): void
    {
        // custom_code is excluded from starter.
        ['account' => $account, 'user' => $user] = $this->tenant('starter');

        $this->assertDatabaseMissing('account_entitlements', [
            'account_id' => $account->id,
            'capability_id' => $this->capabilityId('custom_code'),
        ]);

        $this->actingAs($user)->getJson('/api/auth/me')
            ->assertJsonPath('user.capabilities.custom_code', false);

        // Without journey_automation the route gate answers first (Phase 7 Task 1.5)…
        $this->actingAs($user)->postJson(
            self::JOURNEYS,
            $this->journeyPayload([$this->node('n1', 'code', ['language' => 'javascript', 'code' => 'x'])])
        )->assertStatus(403)->assertJsonPath('error_code', 'CAPABILITY_NOT_ENTITLED');

        // …and with it (manual grant), the per-node check still locks the excluded node.
        $this->actingAs($this->superAdmin())
            ->postJson("/api/admin/accounts/{$account->id}/entitlements", ['capability' => 'journey_automation'])
            ->assertStatus(200);
        $this->actingAs($user)->postJson(
            self::JOURNEYS,
            $this->journeyPayload([$this->node('n1', 'code', ['language' => 'javascript', 'code' => 'x'])])
        )->assertStatus(403)->assertJsonPath('error_code', 'JOURNEY_NODE_NOT_ENTITLED');
        $this->assertDatabaseCount('whatsapp_flows', 0);
    }

    public function test_a_manual_cell_still_works_by_manual_grant_without_changing_the_plan(): void
    {
        // starter -> ai = manual.
        ['account' => $account, 'user' => $user] = $this->tenant('starter');

        $this->actingAs($user)->getJson('/api/auth/me')->assertJsonPath('user.capabilities.ai', false);

        $this->actingAs($this->superAdmin())
            ->postJson("/api/admin/accounts/{$account->id}/entitlements", ['capability' => 'ai'])
            ->assertStatus(200);

        $this->assertDatabaseHas('account_entitlements', [
            'account_id' => $account->id,
            'capability_id' => $this->capabilityId('ai'),
            'source' => 'manual_grant',
        ]);

        $this->actingAs($user)->getJson('/api/auth/me')->assertJsonPath('user.capabilities.ai', true);

        // The plan itself is unchanged by a manual grant.
        $this->assertSame(self::CONFIRMED_MATRIX['starter'], $this->bundled('starter'));
    }

    // =================================================================
    // 4. Revocation (the explicit state)
    // =================================================================

    public function test_revoking_marks_the_row_instead_of_deleting_it(): void
    {
        ['account' => $account] = $this->tenant('business');

        $this->actingAs($this->superAdmin())
            ->deleteJson("/api/admin/accounts/{$account->id}/entitlements/custom_code")
            ->assertStatus(200);

        // The row survives — that is the whole point.
        $row = AccountEntitlement::where('account_id', $account->id)
            ->where('capability_id', $this->capabilityId('custom_code'))
            ->first();

        $this->assertNotNull($row, 'The revocation must leave a record.');
        $this->assertNotNull($row->revoked_at);
        $this->assertTrue($row->isRevoked());
    }

    public function test_a_revoked_capability_is_not_held(): void
    {
        ['account' => $account, 'user' => $user] = $this->tenant('business');

        $this->actingAs($this->superAdmin())
            ->deleteJson("/api/admin/accounts/{$account->id}/entitlements/custom_code");

        $this->actingAs($user)->getJson('/api/auth/me')
            ->assertJsonPath('user.capabilities.custom_code', false);

        $this->actingAs($user)->postJson(
            self::JOURNEYS,
            $this->journeyPayload([$this->node('n1', 'code', ['language' => 'javascript', 'code' => 'x'])])
        )->assertStatus(403);
    }

    public function test_re_granting_clears_the_revocation(): void
    {
        ['account' => $account, 'user' => $user] = $this->tenant('business');
        $superAdmin = $this->superAdmin();

        $this->actingAs($superAdmin)->deleteJson("/api/admin/accounts/{$account->id}/entitlements/custom_code");
        $this->actingAs($superAdmin)->postJson("/api/admin/accounts/{$account->id}/entitlements", ['capability' => 'custom_code'])
            ->assertStatus(200);

        $this->assertDatabaseHas('account_entitlements', [
            'account_id' => $account->id,
            'capability_id' => $this->capabilityId('custom_code'),
            'revoked_at' => null,
        ]);

        $this->actingAs($user)->getJson('/api/auth/me')
            ->assertJsonPath('user.capabilities.custom_code', true);
    }

    public function test_the_admin_listing_reports_the_revoked_state(): void
    {
        ['account' => $account] = $this->tenant('business');

        $this->actingAs($this->superAdmin())->deleteJson("/api/admin/accounts/{$account->id}/entitlements/custom_code");

        $row = collect(
            $this->actingAs($this->superAdmin())->getJson("/api/admin/accounts/{$account->id}/entitlements")->json('data')
        )->firstWhere('slug', 'custom_code');

        $this->assertTrue($row['revoked']);
        $this->assertFalse($row['granted']);
        $this->assertTrue($row['plan_granted'], 'The plan still offers it — the administrator took it away.');
        $this->assertNotNull($row['revoked_at']);
    }

    // =================================================================
    // 5. Backfill
    // =================================================================

    /** Simulates a pre-Task-9 tenant: paid, but holding only whatsapp_send. */
    private function legacyPaidTenant(string $planKey): Account
    {
        ['account' => $account] = $this->tenant($planKey);

        AccountEntitlement::where('account_id', $account->id)
            ->where('capability_id', '!=', $this->capabilityId('whatsapp_send'))
            ->delete();

        return $account->fresh();
    }

    public function test_the_backfill_grants_the_missing_plan_bundle(): void
    {
        $account = $this->legacyPaidTenant('business');

        $this->assertSame(['whatsapp_send'], $this->heldBy($account));

        $this->artisan('entitlements:backfill-plan')->assertExitCode(0);

        $this->assertSame(self::CONFIRMED_MATRIX['business'], $this->heldBy($account));

        foreach ($this->heldBy($account) as $slug) {
            $this->assertDatabaseHas('account_entitlements', [
                'account_id' => $account->id,
                'capability_id' => $this->capabilityId($slug),
                'source' => 'plan',
            ]);
        }
    }

    public function test_the_backfill_does_not_grant_capabilities_outside_the_plan(): void
    {
        $account = $this->legacyPaidTenant('starter');

        $this->artisan('entitlements:backfill-plan')->assertExitCode(0);

        foreach (['custom_code', 'commerce', 'crm', 'ai'] as $notBundled) {
            $this->assertNotContains($notBundled, $this->heldBy($account));
        }
    }

    public function test_the_backfill_grants_crm_to_an_existing_growth_tenant(): void
    {
        // Phase 5 Task 9 FOLLOW-UP: the corrected provider row has to
        // reach tenants who already paid, not only new purchases.
        $account = $this->legacyPaidTenant('growth');

        $this->assertNotContains('crm', $this->heldBy($account));

        $this->artisan('entitlements:backfill-plan')->assertExitCode(0);

        $this->assertContains('crm', $this->heldBy($account));
        $this->assertDatabaseHas('account_entitlements', [
            'account_id' => $account->id,
            'capability_id' => $this->capabilityId('crm'),
            'source' => 'plan',
        ]);
    }

    public function test_the_backfill_still_skips_provider_incompatible_capabilities(): void
    {
        $account = $this->legacyPaidTenant('growth');

        // Since Phase 7 Task 1.5 the confirmed matrix has no incompatible
        // Growth cell left (journey_automation is now QR-supported), so the
        // test bundles one itself: commerce is a technical QR refusal.
        Plan::where('slug', 'growth')->firstOrFail()->capabilities()->syncWithoutDetaching([
            $this->capabilityId('commerce') => ['usage_limit' => null],
        ]);

        $this->artisan('entitlements:backfill-plan')->assertExitCode(0);

        // The backfill must keep honouring the provider dimension.
        $this->assertNotContains('commerce', $this->heldBy($account));
        $this->assertContains('journey_automation', $this->heldBy($account));
        $this->assertContains('whatsapp_groups', $this->heldBy($account));
    }

    public function test_the_backfill_does_not_restore_a_revoked_crm(): void
    {
        // The corrected provider row must not become a way around an
        // administrator's revocation.
        ['account' => $account] = $this->tenant('growth');

        $this->assertContains('crm', $this->heldBy($account));

        $this->actingAs($this->superAdmin())
            ->deleteJson("/api/admin/accounts/{$account->id}/entitlements/crm")
            ->assertStatus(200);

        $this->artisan('entitlements:backfill-plan')->assertExitCode(0);

        $this->assertNotContains('crm', $this->heldBy($account));
        $this->assertNotNull(
            AccountEntitlement::where('account_id', $account->id)
                ->where('capability_id', $this->capabilityId('crm'))
                ->value('revoked_at')
        );
    }

    public function test_the_backfill_is_idempotent(): void
    {
        $account = $this->legacyPaidTenant('business');

        $this->artisan('entitlements:backfill-plan')->assertExitCode(0);
        $first = $this->heldBy($account);
        $rowCount = AccountEntitlement::where('account_id', $account->id)->count();

        $this->artisan('entitlements:backfill-plan')->assertExitCode(0);

        $this->assertSame($first, $this->heldBy($account));
        $this->assertSame($rowCount, AccountEntitlement::where('account_id', $account->id)->count());
    }

    public function test_the_backfill_never_restores_a_revoked_capability(): void
    {
        // THE reason the revoked state exists.
        ['account' => $account] = $this->tenant('business');

        $this->actingAs($this->superAdmin())
            ->deleteJson("/api/admin/accounts/{$account->id}/entitlements/custom_code")
            ->assertStatus(200);

        $this->artisan('entitlements:backfill-plan')->assertExitCode(0);

        $this->assertNotContains('custom_code', $this->heldBy($account), 'The backfill reversed an administrative revocation.');
        $this->assertNotNull(
            AccountEntitlement::where('account_id', $account->id)
                ->where('capability_id', $this->capabilityId('custom_code'))
                ->value('revoked_at')
        );
    }

    public function test_the_backfill_preserves_a_manual_grant_and_its_source(): void
    {
        $account = $this->legacyPaidTenant('business');

        AccountEntitlement::create([
            'account_id' => $account->id,
            'capability_id' => $this->capabilityId('crm'),
            'source' => 'manual_grant',
            'granted_by_account_id' => null,
        ]);

        $this->artisan('entitlements:backfill-plan')->assertExitCode(0);

        // crm IS in the business bundle — the backfill must still leave
        // the existing row's source alone rather than rewriting it.
        $this->assertDatabaseHas('account_entitlements', [
            'account_id' => $account->id,
            'capability_id' => $this->capabilityId('crm'),
            'source' => 'manual_grant',
        ]);
    }

    public function test_the_backfill_preserves_an_agent_delegated_grant(): void
    {
        $account = $this->legacyPaidTenant('business');
        $agent = Account::factory()->create(['account_type' => 'agent']);

        AccountEntitlement::create([
            'account_id' => $account->id,
            'capability_id' => $this->capabilityId('ai'),
            'source' => 'agent_delegated',
            'granted_by_account_id' => $agent->id,
        ]);

        $this->artisan('entitlements:backfill-plan')->assertExitCode(0);

        $this->assertDatabaseHas('account_entitlements', [
            'account_id' => $account->id,
            'capability_id' => $this->capabilityId('ai'),
            'source' => 'agent_delegated',
            'granted_by_account_id' => $agent->id,
        ]);
    }

    public function test_the_backfill_skips_an_account_with_no_paid_invoice(): void
    {
        ['account' => $account] = $this->tenant('business', pay: false);

        $this->artisan('entitlements:backfill-plan')->assertExitCode(0);

        $this->assertSame([], $this->heldBy($account));
    }

    public function test_the_backfill_skips_a_suspended_account(): void
    {
        $account = $this->legacyPaidTenant('business');
        $account->forceFill(['status' => 'suspended'])->save();

        $this->artisan('entitlements:backfill-plan')->assertExitCode(0);

        $this->assertSame(['whatsapp_send'], $this->heldBy($account));
    }

    public function test_the_dry_run_writes_nothing(): void
    {
        $account = $this->legacyPaidTenant('business');

        $this->artisan('entitlements:backfill-plan', ['--dry-run' => true])->assertExitCode(0);

        $this->assertSame(['whatsapp_send'], $this->heldBy($account), 'A dry run must not mutate data.');
    }

    public function test_the_backfill_can_be_scoped_to_one_account(): void
    {
        $mine = $this->legacyPaidTenant('business');
        $theirs = $this->legacyPaidTenant('business');

        $this->artisan('entitlements:backfill-plan', ['--account' => $mine->id])->assertExitCode(0);

        $this->assertSame(self::CONFIRMED_MATRIX['business'], $this->heldBy($mine));
        $this->assertSame(['whatsapp_send'], $this->heldBy($theirs), 'Another tenant was touched.');
    }

    public function test_the_backfill_output_exposes_no_tenant_content(): void
    {
        $account = $this->legacyPaidTenant('business');
        $account->forceFill(['company_name' => 'Very Secret Client Ltd'])->save();

        $this->artisan('entitlements:backfill-plan')
            ->doesntExpectOutputToContain('Very Secret Client Ltd')
            ->assertExitCode(0);
    }

    // =================================================================
    // 6. Cross-tenant
    // =================================================================

    public function test_one_tenant_cannot_use_another_tenants_plan_bundle(): void
    {
        ['account' => $entitled] = $this->tenant('business');
        ['user' => $outsider] = $this->tenant('starter');

        $this->assertContains('custom_code', $this->heldBy($entitled));

        $this->actingAs($outsider)->postJson(
            self::JOURNEYS,
            $this->journeyPayload([$this->node('n1', 'code', ['language' => 'javascript', 'code' => 'x'])])
        )->assertStatus(403);
    }

    public function test_no_request_field_can_inject_a_plan_or_capability(): void
    {
        ['user' => $user] = $this->tenant('starter');

        foreach ([
            ['plan' => 'business'],
            ['plan_key' => 'business'],
            ['capability' => 'custom_code'],
            ['capabilities' => ['custom_code' => true]],
            ['provider' => 'meta'],
            ['engine_type' => 'meta'],
            ['account_id' => 999999],
        ] as $spoof) {
            $payload = array_merge(
                $this->journeyPayload([$this->node('n1', 'code', ['language' => 'javascript', 'code' => 'x'])]),
                $spoof,
            );

            $this->actingAs($user)->postJson(self::JOURNEYS, $payload)->assertStatus(403);
        }

        $this->assertDatabaseCount('whatsapp_flows', 0);
    }

    // =================================================================
    // 7. Legacy regression
    // =================================================================

    public function test_a_legacy_journey_still_saves_on_every_plan_that_includes_journeys(): void
    {
        foreach (['growth', 'business'] as $planKey) {
            ['user' => $user] = $this->tenant($planKey);

            $this->actingAs($user)->postJson(self::JOURNEYS, $this->journeyPayload([
                $this->node('n1', 'message', ['text' => 'Hello']),
                $this->node('n2', 'save_lead', ['name_variable' => 'name']),
            ]))->assertStatus(201);
        }

        // Phase 7 Task 1.5 — Starter does not include journey_automation.
        ['user' => $user] = $this->tenant('starter');
        $this->actingAs($user)->postJson(self::JOURNEYS, $this->journeyPayload([
            $this->node('n1', 'message', ['text' => 'Hello']),
        ]))->assertStatus(403)->assertJsonPath('error_code', 'CAPABILITY_NOT_ENTITLED');
        $this->assertDatabaseCount('whatsapp_flows', 2);
    }
}
