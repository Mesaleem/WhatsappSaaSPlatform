<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\AccountEntitlement;
use App\Models\Capability;
use App\Models\Invoice;
use App\Models\User;
use App\Models\WhatsAppFlow;
use App\Models\WhatsAppFlowSession;
use App\Models\WhatsAppSession;
use App\Services\Access\ProviderCapabilityService;
use App\Services\Billing\InvoiceCreditService;
use App\Support\PlanCatalog;
use Database\Seeders\Phase1FoundationSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Phase 7 Task 1.5 — Journey capability / entitlement hardening.
 *
 * Every /api/whatsapp/flows route now also requires the tenant to HOLD
 * journey_automation (capability.guard), on top of the unchanged
 * module.guard:chatbot, per-route permission and per-node entitlement
 * checks. The capability arrives through the plan (Growth on QR — owner
 * decision: qr → journey_automation is now supported — and Business on
 * Meta); Starter does not include it.
 *
 * Denial is the project's standard 403 CAPABILITY_NOT_ENTITLED envelope
 * (the one CRM uses) and writes nothing.
 */
class JourneyCapabilityGateTest extends TestCase
{
    use RefreshDatabase;

    private const BASE = '/api/whatsapp/flows';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        $this->seed(Phase1FoundationSeeder::class);
        Http::fake(['*' => Http::response(['success' => true, 'message_id' => 'OK', 'messages' => [['id' => 'wamid.OK']]], 200)]);
    }

    // ------------------------------------------------------------------ fixtures

    /** A tenant on a real, paid plan — its entitlements come from the plan grant, not from test inserts. */
    private function tenant(string $planKey, ?callable $factory = null, array $attributes = []): Account
    {
        $account = ($factory ? $factory(Account::factory()) : Account::factory())->create($attributes);
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

        return $account->fresh();
    }

    private function user(Account $account, ?string $role = 'admin', array $permissions = []): User
    {
        $user = User::factory()->create(['account_id' => $account->id, 'is_active' => true]);
        if ($role) {
            $user->assignRole($role);
        }
        foreach ($permissions as $p) {
            $user->givePermissionTo($p);
        }

        return $user;
    }

    private function superAdmin(): User
    {
        $user = User::factory()->create(['account_id' => null, 'is_active' => true]);
        $user->assignRole('super_admin');

        return $user;
    }

    private function holds(Account $account): bool
    {
        return AccountEntitlement::where('account_id', $account->id)
            ->where('capability_id', Capability::where('slug', 'journey_automation')->value('id'))
            ->whereNull('revoked_at')->exists();
    }

    private function revoke(Account $account): void
    {
        AccountEntitlement::where('account_id', $account->id)
            ->where('capability_id', Capability::where('slug', 'journey_automation')->value('id'))
            ->update(['revoked_at' => now()]);
    }

    private function flow(Account $account, bool $active = true): WhatsAppFlow
    {
        return WhatsAppFlow::create([
            'account_id' => $account->id, 'name' => 'Welcome', 'trigger_type' => 'keyword', 'trigger_value' => 'hi', 'is_active' => $active,
            'graph_data' => [
                'nodes' => [['id' => 't', 'type' => 'trigger', 'data' => []], ['id' => 'q', 'type' => 'question', 'data' => ['prompt_text' => 'Name?', 'variable_name' => 'name', 'input_type' => 'text']]],
                'edges' => [['id' => 'e', 'source' => 't', 'target' => 'q']],
            ],
        ]);
    }

    private function openSession(WhatsAppFlow $flow): WhatsAppFlowSession
    {
        return WhatsAppFlowSession::create([
            'account_id' => $flow->account_id, 'flow_id' => $flow->id, 'phone_number' => '919800000001',
            'current_node_id' => 'q', 'context_data' => [], 'status' => WhatsAppFlowSession::STATUS_ACTIVE,
        ]);
    }

    private function payload(string $name = 'New journey'): array
    {
        return [
            'name' => $name, 'trigger_type' => 'keyword', 'trigger_value' => 'start', 'is_active' => true,
            'graph_data' => [
                'nodes' => [['id' => 't', 'type' => 'trigger', 'data' => []], ['id' => 'm', 'type' => 'message', 'data' => ['text' => 'Hello']]],
                'edges' => [['id' => 'e', 'source' => 't', 'target' => 'm']],
            ],
        ];
    }

    /**
     * Every Journey route, as [method, uri, body].
     *
     * @return array<string, array{0: string, 1: string, 2: array<string, mixed>}>
     */
    private function routes(WhatsAppFlow $flow, WhatsAppFlowSession $session): array
    {
        return [
            'list' => ['GET', self::BASE, []],
            'show' => ['GET', self::BASE."/{$flow->id}", []],
            'sessions' => ['GET', self::BASE."/{$flow->id}/sessions", []],
            'create' => ['POST', self::BASE, $this->payload()],
            'update' => ['PUT', self::BASE."/{$flow->id}", $this->payload('Renamed')],
            'toggle' => ['POST', self::BASE."/{$flow->id}/toggle", []],
            'test' => ['POST', self::BASE."/{$flow->id}/test", ['phone_number' => '919800000009']],
            'cancel' => ['POST', self::BASE."/{$flow->id}/sessions/{$session->id}/cancel", []],
            'delete' => ['DELETE', self::BASE."/{$flow->id}", []],
        ];
    }

    /** Everything the Journey API can write, as one comparable snapshot. */
    private function journeyState(): array
    {
        return [
            'flows' => DB::table('whatsapp_flows')->orderBy('id')->get()->map(fn ($r) => (array) $r)->all(),
            'sessions' => DB::table('whatsapp_flow_sessions')->orderBy('id')->get()->map(fn ($r) => (array) $r)->all(),
            'dispatch' => DB::table('message_dispatch_logs')->count(),
        ];
    }

    // ------------------------------------------------------------------ plan / provider integration

    public function test_the_plans_that_include_journeys_grant_it_and_starter_does_not(): void
    {
        $this->assertTrue(app(ProviderCapabilityService::class)->supports('qr', 'journey_automation'), 'owner decision: QR supports Journey automation');
        $this->assertTrue(app(ProviderCapabilityService::class)->supports('meta', 'journey_automation'));

        $this->assertTrue($this->holds($this->tenant('growth')), 'Growth (QR) receives it from the plan');
        $this->assertTrue($this->holds($this->tenant('business')), 'Business (Meta) receives it from the plan');
        $this->assertFalse($this->holds($this->tenant('starter')), 'Starter does not include it');
        $this->assertSame('plan', AccountEntitlement::where('account_id', $this->tenant('growth')->id)
            ->where('capability_id', Capability::where('slug', 'journey_automation')->value('id'))->value('source'));
    }

    public function test_the_backfill_grants_it_to_an_existing_growth_account(): void
    {
        $account = $this->tenant('growth');
        // An account that paid before QR supported it: no row yet.
        AccountEntitlement::where('account_id', $account->id)
            ->where('capability_id', Capability::where('slug', 'journey_automation')->value('id'))->delete();
        $this->assertFalse($this->holds($account));

        $this->artisan('entitlements:backfill-plan', ['--dry-run' => true])->assertExitCode(0);
        $this->assertFalse($this->holds($account), 'dry run writes nothing');

        $this->artisan('entitlements:backfill-plan')->assertExitCode(0);
        $this->assertTrue($this->holds($account));
    }

    public function test_the_data_migration_flips_an_existing_installs_qr_row_and_rolls_back(): void
    {
        $pivot = fn () => DB::table('provider_capabilities')
            ->where('provider_id', DB::table('providers')->where('slug', 'qr')->value('id'))
            ->where('capability_id', Capability::where('slug', 'journey_automation')->value('id'));
        // An install seeded before Task 1.5.
        $pivot()->update(['supported' => false]);
        $migration = require database_path('migrations/2026_09_24_110000_support_journey_automation_on_qr_provider.php');

        $migration->up();
        $this->assertTrue((bool) $pivot()->value('supported'));
        $this->assertTrue(app(ProviderCapabilityService::class)->supports('qr', 'journey_automation'), 'cache cleared');

        $migration->down();
        $this->assertFalse((bool) $pivot()->value('supported'));
        $this->assertFalse(app(ProviderCapabilityService::class)->supports('qr', 'journey_automation'));
    }

    // ------------------------------------------------------------------ enabled: unchanged

    public function test_with_the_capability_every_journey_route_still_works(): void
    {
        foreach (['growth', 'business'] as $planKey) {
            $account = $this->tenant($planKey);
            $admin = $this->user($account);
            $flow = $this->flow($account);
            $session = $this->openSession($flow);

            $expected = ['list' => 200, 'show' => 200, 'sessions' => 200, 'create' => 201, 'update' => 200, 'toggle' => 200, 'test' => 200, 'cancel' => 200, 'delete' => 200];
            foreach ($this->routes($flow, $session) as $name => [$method, $uri, $body]) {
                $this->actingAs($admin)->json($method, $uri, $body)->assertStatus($expected[$name]);
            }

            $this->assertNull(WhatsAppFlow::find($flow->id), "{$planKey}: delete went through");
            $this->assertSame(1, WhatsAppFlow::where('account_id', $account->id)->count(), "{$planKey}: create went through");
        }
    }

    // ------------------------------------------------------------------ disabled: blocked, nothing written

    public function test_without_the_capability_every_journey_route_is_blocked_and_nothing_changes(): void
    {
        foreach (['starter plan' => fn () => $this->tenant('starter'), 'revoked' => function () {
            $a = $this->tenant('growth');
            $this->revoke($a);

            return $a;
        }] as $case => $make) {
            DB::table('whatsapp_flow_sessions')->delete();
            DB::table('whatsapp_flows')->delete();
            $account = $make();
            $admin = $this->user($account);
            $flow = $this->flow($account);
            $session = $this->openSession($flow);
            $before = $this->journeyState();

            foreach ($this->routes($flow, $session) as $name => [$method, $uri, $body]) {
                $this->actingAs($admin)->json($method, $uri, $body)
                    ->assertStatus(403)
                    ->assertExactJson([
                        'success' => false,
                        'message' => 'Your current plan does not include this feature. Please upgrade your subscription to unlock it.',
                        'error_code' => 'CAPABILITY_NOT_ENTITLED',
                    ]);
            }

            $this->assertSame($before, $this->journeyState(), "{$case}: a denied request must not write anything");
        }
    }

    // ------------------------------------------------------------------ other gates still apply

    public function test_the_module_gate_still_applies_with_the_capability(): void
    {
        $account = $this->tenant('growth', attributes: ['allowed_modules' => array_values(array_diff(Account::MODULES, ['chatbot']))]);
        $this->assertTrue($this->holds($account));

        $this->actingAs($this->user($account))->getJson(self::BASE)
            ->assertStatus(403)->assertJsonPath('error_code', 'MODULE_DISABLED');
        $this->actingAs($this->user($account))->postJson(self::BASE, $this->payload())->assertStatus(403);
        $this->assertSame(0, WhatsAppFlow::count());
    }

    public function test_the_permission_gate_still_applies_with_the_capability(): void
    {
        $account = $this->tenant('growth');
        $flow = $this->flow($account);
        $plain = $this->user($account, 'user');

        $this->actingAs($plain)->postJson(self::BASE, $this->payload())->assertForbidden();
        $this->actingAs($plain)->deleteJson(self::BASE."/{$flow->id}")->assertForbidden();
        $this->assertSame(1, WhatsAppFlow::count());

        // A view-only grant reads but cannot write.
        $viewer = $this->user($account, null, ['whatsapp.view']);
        $this->actingAs($viewer)->getJson(self::BASE)->assertOk();
        $this->actingAs($viewer)->postJson(self::BASE."/{$flow->id}/toggle")->assertForbidden();
        $this->assertTrue($flow->fresh()->is_active);
    }

    public function test_the_per_node_entitlement_check_still_applies_with_the_capability(): void
    {
        // Growth (QR) holds journey_automation but not commerce, and QR cannot do catalog messages.
        $account = $this->tenant('growth');
        $payload = $this->payload();
        $payload['graph_data']['nodes'][] = ['id' => 'c', 'type' => 'catalog', 'data' => ['catalogId' => 'c1']];

        $this->actingAs($this->user($account))->postJson(self::BASE, $payload)
            ->assertStatus(403)->assertJsonPath('error_code', 'JOURNEY_NODE_NOT_ENTITLED');
        $this->assertSame(0, WhatsAppFlow::count());
    }

    // ------------------------------------------------------------------ tenant isolation / roles

    public function test_tenant_isolation_is_unchanged(): void
    {
        $a = $this->tenant('growth');
        $b = $this->tenant('growth');
        $aFlow = $this->flow($a);
        $bAdmin = $this->user($b);

        $this->actingAs($bAdmin)->getJson(self::BASE."/{$aFlow->id}")->assertNotFound();
        $this->actingAs($bAdmin)->putJson(self::BASE."/{$aFlow->id}", $this->payload('Hijack'))->assertNotFound();
        $this->actingAs($bAdmin)->getJson(self::BASE."/{$aFlow->id}?account_id={$a->id}")->assertNotFound();
        $this->assertSame([], $this->actingAs($bAdmin)->getJson(self::BASE)->assertOk()->json('data'));
        $this->assertSame('Welcome', $aFlow->fresh()->name);
    }

    public function test_an_agent_is_gated_on_the_target_sub_clients_capability(): void
    {
        $agent = $this->tenant('growth', fn ($f) => $f->agent());
        $entitled = $this->tenant('growth', fn ($f) => $f->client($agent));
        $notEntitled = $this->tenant('starter', fn ($f) => $f->client($agent));
        $stranger = $this->tenant('growth');
        $agentUser = $this->user($agent, null, ['manage-chatbot']);

        $this->actingAs($agentUser)->postJson(self::BASE."?account_id={$entitled->id}", $this->payload())->assertCreated();
        $this->actingAs($agentUser)->postJson(self::BASE."?account_id={$notEntitled->id}", $this->payload())
            ->assertStatus(403)->assertJsonPath('error_code', 'CAPABILITY_NOT_ENTITLED');
        $this->actingAs($agentUser)->getJson(self::BASE."?account_id={$stranger->id}")->assertNotFound();

        $this->assertSame([$entitled->id], WhatsAppFlow::pluck('account_id')->all());
    }

    public function test_super_admin_behaviour_is_unchanged(): void
    {
        // As for every capability gate, a Super Admin acting on a selected client is not blocked by it…
        $client = $this->tenant('starter');
        $this->assertFalse($this->holds($client));
        $this->actingAs($this->superAdmin())->postJson(self::BASE."?account_id={$client->id}", $this->payload())->assertCreated();
        $this->assertSame([$client->id], WhatsAppFlow::pluck('account_id')->all());

        // …and with no client selected still gets the existing 422 (no journey target redesign in this task).
        $this->actingAs($this->superAdmin())->getJson(self::BASE)->assertStatus(422);
    }
}
