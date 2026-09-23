<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\AccountEntitlement;
use App\Models\ApiKey;
use App\Models\ApiRequestLog;
use App\Models\Capability;
use App\Models\Contact;
use App\Models\CrmLead;
use App\Models\CrmLeadTag;
use App\Models\CrmTag;
use App\Models\Invoice;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use App\Services\Access\AccessControlService;
use App\Services\Access\PlanEntitlementReconciliationService;
use App\Services\Billing\InvoiceCreditService;
use App\Services\Crm\CrmTagService;
use App\Support\PlanCatalog;
use Database\Seeders\Phase1FoundationSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Route as RouteDefinition;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * Phase 6 — CRM Task 11. CRM entitlement, RBAC and the Developer API.
 *
 * Structural (every CRM route carries the full gate set) + behavioural
 * (every route fails closed for each missing gate) + hierarchy (Super
 * Admin / Agent / Admin / user / read-only) + the /api/v1/crm/* contract.
 */
class CrmEntitlementRbacApiTest extends TestCase
{
    use RefreshDatabase;

    private const V1 = '/api/v1/crm/leads';

    private const UI = '/api/crm/leads';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        $this->seed(Phase1FoundationSeeder::class);
    }

    // =================================================================
    // Fixtures
    // =================================================================

    private function grant(Account $account, string $slug = 'crm'): void
    {
        AccountEntitlement::firstOrCreate(
            ['account_id' => $account->id, 'capability_id' => Capability::where('slug', $slug)->firstOrFail()->id],
            ['source' => 'manual_grant', 'granted_by_account_id' => null],
        );
    }

    private function account(bool $crm = true, bool $active = true, array $attributes = [], ?callable $factory = null): Account
    {
        $builder = Account::factory();
        if ($factory) {
            $builder = $factory($builder);
        }
        $account = $builder->create($attributes);
        $active
            ? Subscription::factory()->create(['account_id' => $account->id])
            : Subscription::factory()->expired()->create(['account_id' => $account->id]);
        if ($crm) {
            $this->grant($account);
        }

        return $account->fresh();
    }

    private function user(Account $account, ?string $role = 'admin', array $permissions = [], bool $active = true): User
    {
        $user = User::factory()->create(['account_id' => $account->id, 'is_active' => $active]);
        if ($role) {
            $user->assignRole($role);
        }
        foreach ($permissions as $permission) {
            $user->givePermissionTo($permission);
        }

        return $user;
    }

    private function superAdmin(): User
    {
        $platform = Account::factory()->create();
        $user = User::factory()->create(['account_id' => $platform->id, 'is_active' => true]);
        $user->assignRole('super_admin');

        return $user;
    }

    private function lead(Account $account, string $phone = '919800000001'): CrmLead
    {
        $contact = Contact::factory()->forAccount($account)->create(['phone_number' => $phone]);

        return CrmLead::factory()->forContact($contact)->create();
    }

    private function tag(Account $account, string $name = 'Hot'): CrmTag
    {
        return app(CrmTagService::class)->create($account, $name);
    }

    private function key(Account $account, array $attributes = []): string
    {
        $plain = 'sk_test_'.bin2hex(random_bytes(12));
        ApiKey::create(array_merge([
            'account_id' => $account->id,
            'name' => 'Integration',
            'key_prefix' => substr($plain, 0, 10),
            'key_hash' => ApiKey::hashKey($plain),
        ], $attributes));

        return $plain;
    }

    private function api(string $key)
    {
        return $this->withHeaders(['X-API-KEY' => $key, 'Accept' => 'application/json']);
    }

    /** @return list<array{method: string, uri: string}> */
    private function routesUnder(string $prefix): array
    {
        $out = [];
        foreach (Route::getRoutes()->getRoutes() as $route) {
            /** @var RouteDefinition $route */
            if (! str_starts_with($route->uri(), $prefix)) {
                continue;
            }
            foreach (array_diff($route->methods(), ['HEAD']) as $method) {
                $out[] = ['method' => $method, 'uri' => '/'.preg_replace('/\{[a-z]+\}/', '1', $route->uri())];
            }
        }

        return $out;
    }

    private function call_(string $method, string $uri, ?User $user = null, array $headers = [])
    {
        if ($user) {
            $this->actingAs($user);
        }

        return $this->withHeaders($headers + ['Accept' => 'application/json'])->json($method, $uri, []);
    }

    private function snapshot(): array
    {
        return [
            'leads' => CrmLead::orderBy('id')->get()->map->getAttributes()->all(),
            'contacts' => Contact::orderBy('id')->get()->map->getAttributes()->all(),
            'tags' => CrmTag::orderBy('id')->get()->map->getAttributes()->all(),
            'pivots' => DB::table('crm_lead_tags')->orderBy('crm_lead_id')->get()->map(fn ($r) => (array) $r)->all(),
        ];
    }

    // =================================================================
    // 1. Structure — every CRM entry point carries every gate
    // =================================================================

    public function test_every_tenant_crm_route_carries_the_full_gate_chain(): void
    {
        $routes = collect(Route::getRoutes()->getRoutes())->filter(fn ($r) => str_starts_with($r->uri(), 'api/crm'));
        $this->assertCount(29, $routes, 'a CRM route was added or removed — re-check its gates'); // 29 since Task 12 (analytics)

        foreach ($routes as $route) {
            $mw = $route->gatherMiddleware();
            foreach (['auth:sanctum', 'tenant.isolation', 'subscription.guard', 'module.guard:lead_crm', 'permission:manage-crm', 'capability.guard:crm'] as $required) {
                $this->assertContains($required, $mw, "{$route->uri()} is missing {$required}");
            }
        }
    }

    public function test_every_developer_api_crm_route_carries_the_full_gate_chain(): void
    {
        $routes = collect(Route::getRoutes()->getRoutes())->filter(fn ($r) => str_starts_with($r->uri(), 'api/v1/crm'));
        $this->assertCount(6, $routes);

        foreach ($routes as $route) {
            $mw = $route->gatherMiddleware();
            foreach (['log.apirequest', 'auth.apikey', 'throttle:external-api', 'idempotency', 'subscription.apikey', 'module.apikey:lead_crm', 'capability.apikey:crm'] as $required) {
                $this->assertContains($required, $mw, "{$route->uri()} is missing {$required}");
            }
        }
    }

    public function test_crm_controllers_are_reachable_only_through_the_gated_prefixes(): void
    {
        foreach (Route::getRoutes()->getRoutes() as $route) {
            $action = (string) ($route->getActionName() ?? '');
            if (preg_match('/\\\\(Crm[A-Za-z]*Controller)@/', $action)) {
                $this->assertTrue(
                    str_starts_with($route->uri(), 'api/crm') || str_starts_with($route->uri(), 'api/v1/crm'),
                    "{$action} is exposed at {$route->uri()}",
                );
            }
        }
    }

    public function test_no_bulk_delete_contact_or_tag_crud_endpoint_exists_on_the_developer_api(): void
    {
        $account = $this->account();
        $key = $this->key($account);
        $lead = $this->lead($account);

        foreach ([
            ['get', self::V1],
            ['delete', self::V1.'/'.$lead->id],
            ['put', self::V1.'/'.$lead->id],
            ['patch', self::V1.'/'.$lead->id],
            ['patch', self::V1.'/'.$lead->id.'/contact'],
            ['post', self::V1.'/bulk/status'],
            ['post', self::V1.'/bulk/assignee'],
            ['post', self::V1.'/bulk/tags/attach'],
            ['get', '/api/v1/crm/contacts'],
            ['get', '/api/v1/crm/tags'],
            ['get', '/api/v1/crm/pipeline'],
            ['get', '/api/v1/crm/assignees'],
        ] as [$method, $uri]) {
            $status = $this->api($key)->json(strtoupper($method), $uri, [])->getStatusCode();
            $this->assertContains($status, [404, 405], strtoupper($method)." {$uri} must not exist (got {$status})");
        }
        $this->assertDatabaseHas('crm_leads', ['id' => $lead->id]);
    }

    // =================================================================
    // 2. Every tenant CRM route fails closed per gate
    // =================================================================

    public function test_every_tenant_crm_route_is_401_without_a_session(): void
    {
        foreach ($this->routesUnder('api/crm') as $r) {
            $this->call_($r['method'], $r['uri'])->assertStatus(401);
        }
    }

    public function test_every_tenant_crm_route_is_403_without_the_crm_capability(): void
    {
        $user = $this->user($this->account(crm: false));
        $before = $this->snapshot();

        foreach ($this->routesUnder('api/crm') as $r) {
            $this->call_($r['method'], $r['uri'], $user)
                ->assertStatus(403)
                ->assertJsonPath('error_code', 'CAPABILITY_NOT_ENTITLED');
        }
        $this->assertSame($before, $this->snapshot());
    }

    public function test_every_tenant_crm_route_is_403_with_the_module_disabled(): void
    {
        $modules = array_values(array_diff(Account::MODULES, ['lead_crm']));
        $user = $this->user($this->account(attributes: ['allowed_modules' => $modules]));

        foreach ($this->routesUnder('api/crm') as $r) {
            $this->call_($r['method'], $r['uri'], $user)
                ->assertStatus(403)
                ->assertJsonPath('error_code', 'MODULE_DISABLED');
        }
    }

    public function test_every_tenant_crm_route_is_403_without_manage_crm(): void
    {
        $account = $this->account();
        // The default `user` role (send-messages, view-analytics, view-audit-logs) and
        // manage-social-leads alone both lack manage-crm.
        foreach ([$this->user($account, 'user'), $this->user($account, null, ['manage-social-leads']), $this->user($account, 'agent')] as $user) {
            foreach ($this->routesUnder('api/crm') as $r) {
                $this->call_($r['method'], $r['uri'], $user)->assertStatus(403);
            }
        }
    }

    public function test_a_read_only_expired_subscription_can_read_but_every_crm_mutation_is_refused(): void
    {
        $account = $this->account(active: false);
        $user = $this->user($account);
        $lead = $this->lead($account);
        $tag = $this->tag($account);
        $before = $this->snapshot();

        foreach ($this->routesUnder('api/crm') as $r) {
            if ($r['method'] === 'GET') {
                continue;
            }
            $this->call_($r['method'], $r['uri'], $user)
                ->assertStatus(403)
                ->assertJsonPath('error_code', 'SUBSCRIPTION_EXPIRED');
        }

        $this->assertSame($before, $this->snapshot());
        $this->actingAs($user)->getJson(self::UI)->assertOk()->assertJsonPath('data.0.id', $lead->id);
        $this->actingAs($user)->getJson(self::UI.'/'.$lead->id)->assertOk();
        $this->actingAs($user)->getJson('/api/crm/tags/'.$tag->id)->assertOk();
    }

    public function test_a_suspended_account_cannot_even_read(): void
    {
        $account = $this->account();
        $user = $this->user($account);
        $this->lead($account);
        $account->forceFill(['status' => 'suspended'])->save();

        $this->actingAs($user)->getJson(self::UI)->assertStatus(403)->assertJsonPath('error_code', 'CLIENT_ACCOUNT_SUSPENDED');
    }

    public function test_a_revoked_crm_entitlement_denies_immediately(): void
    {
        $account = $this->account();
        $user = $this->user($account);
        $this->actingAs($user)->getJson(self::UI)->assertOk();

        AccountEntitlement::where('account_id', $account->id)->update(['revoked_at' => now()]);

        $this->actingAs($user)->getJson(self::UI)->assertStatus(403);
    }

    // =================================================================
    // 3. Hierarchy — Super Admin / Agent / Admin / user
    // =================================================================

    public function test_admin_and_social_marketer_have_full_crm_on_their_own_account(): void
    {
        $account = $this->account();
        $lead = $this->lead($account);

        foreach (['admin', 'social_marketer'] as $role) {
            $user = $this->user($account, $role);
            $this->actingAs($user)->getJson(self::UI.'/'.$lead->id)->assertOk();
            $this->actingAs($user)->patchJson(self::UI.'/'.$lead->id.'/status', ['status' => 'contacted'])->assertOk();
        }
    }

    public function test_a_client_admin_cannot_steer_to_another_tenant_with_account_id(): void
    {
        $mine = $this->account();
        $theirs = $this->account();
        $myLead = $this->lead($mine);
        $theirLead = $this->lead($theirs, '919800000099');
        $admin = $this->user($mine);

        $this->actingAs($admin)->getJson(self::UI.'?account_id='.$theirs->id)
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $myLead->id);
        $this->actingAs($admin)->getJson(self::UI.'/'.$theirLead->id.'?account_id='.$theirs->id)->assertNotFound();
        $this->actingAs($admin)->patchJson(self::UI.'/'.$theirLead->id.'/status?account_id='.$theirs->id, ['status' => 'converted'])->assertNotFound();
        $this->assertSame(CrmLead::STATUS_NEW, $theirLead->fresh()->status);
    }

    public function test_super_admin_must_select_a_client_and_then_sees_only_that_client(): void
    {
        $a = $this->account();
        $b = $this->account();
        $leadA = $this->lead($a);
        $this->lead($b, '919800000055');
        $sa = $this->superAdmin();

        $this->actingAs($sa)->getJson(self::UI)->assertStatus(422);
        $this->actingAs($sa)->getJson(self::UI.'?account_id='.$a->id)
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $leadA->id);
        $this->actingAs($sa)->getJson(self::UI.'?account_id=999999')->assertNotFound();
    }

    /**
     * Owner decision (post Phase 6): the Task 11 Super Admin bypass no
     * longer applies to CRM — the TARGET account must hold lead_crm + crm
     * (EnsureCrmTargetAccount).
     */
    public function test_super_admin_is_held_to_the_target_accounts_crm_entitlement(): void
    {
        $client = $this->account(crm: false);
        $lead = $this->lead($client);
        $sa = $this->superAdmin();

        $this->actingAs($sa)
            ->patchJson(self::UI.'/'.$lead->id.'/status?account_id='.$client->id, ['status' => 'contacted'])
            ->assertStatus(403)->assertJsonPath('error_code', 'CAPABILITY_NOT_ENTITLED');
        $this->assertSame(CrmLead::STATUS_NEW, $lead->fresh()->status);

        $modulesOff = $this->account(attributes: ['allowed_modules' => array_values(array_diff(Account::MODULES, ['lead_crm']))]);
        $this->actingAs($sa)->getJson(self::UI.'?account_id='.$modulesOff->id)
            ->assertStatus(403)->assertJsonPath('error_code', 'MODULE_DISABLED');

        $entitled = $this->account();
        $entitledLead = $this->lead($entitled, '919800000044');
        $this->actingAs($sa)
            ->patchJson(self::UI.'/'.$entitledLead->id.'/status?account_id='.$entitled->id, ['status' => 'contacted'])
            ->assertOk();
    }

    public function test_an_agent_reaches_its_own_sub_client_crm_but_never_another_tenant(): void
    {
        $agent = $this->account(factory: fn ($f) => $f->agent());
        $sub = $this->account(factory: fn ($f) => $f->client($agent));
        $stranger = $this->account();
        $subLead = $this->lead($sub);
        $strangerLead = $this->lead($stranger, '919800000077');
        $agentUser = $this->user($agent, null, ['manage-crm']);

        $this->actingAs($agentUser)->getJson(self::UI.'?account_id='.$sub->id)
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $subLead->id);

        $this->actingAs($agentUser)->getJson(self::UI.'?account_id='.$stranger->id)->assertNotFound();
        $this->actingAs($agentUser)->patchJson(self::UI.'/'.$strangerLead->id.'/status?account_id='.$stranger->id, ['status' => 'converted'])->assertNotFound();
        // Without ?account_id the agent is on its OWN account, which holds no lead.
        $this->actingAs($agentUser)->getJson(self::UI)->assertOk()->assertJsonCount(0, 'data');
        $this->assertSame(CrmLead::STATUS_NEW, $strangerLead->fresh()->status);
    }

    public function test_an_agent_is_refused_for_a_sub_client_without_crm(): void
    {
        $agent = $this->account(factory: fn ($f) => $f->agent());
        $sub = $this->account(crm: false, factory: fn ($f) => $f->client($agent));
        $agentUser = $this->user($agent, null, ['manage-crm']);

        $this->actingAs($agentUser)->getJson(self::UI.'?account_id='.$sub->id)
            ->assertStatus(403)->assertJsonPath('error_code', 'CAPABILITY_NOT_ENTITLED');
    }

    public function test_the_default_agent_role_has_no_crm_access(): void
    {
        $agent = $this->account(factory: fn ($f) => $f->agent());
        $sub = $this->account(factory: fn ($f) => $f->client($agent));

        $this->actingAs($this->user($agent, 'agent'))->getJson(self::UI.'?account_id='.$sub->id)->assertForbidden();
    }

    public function test_an_inactive_user_cannot_be_made_an_assignee(): void
    {
        $account = $this->account();
        $lead = $this->lead($account);
        $inactive = $this->user($account, 'admin', [], false);

        $this->actingAs($this->user($account))
            ->patchJson(self::UI.'/'.$lead->id.'/assignee', ['assigned_user_id' => $inactive->id])
            ->assertStatus(422);
    }

    // =================================================================
    // 4. Plans — the capability model, not UI hiding, governs CRM
    // =================================================================

    private function subscribe(Account $account, string $planKey): void
    {
        $plan = PlanCatalog::find($planKey);
        $invoice = Invoice::create([
            'account_id' => $account->id, 'invoice_number' => 'INV-'.uniqid(), 'plan_key' => $planKey,
            'plan_label' => $plan['label'], 'amount' => $plan['price'], 'tax_amount' => 0,
            'total_amount' => $plan['price'], 'currency' => 'INR', 'payment_gateway' => 'razorpay',
            'gateway_order_id' => 'order_'.uniqid(), 'status' => 'pending',
        ]);
        app(InvoiceCreditService::class)->markPaidAndCreditQuota($invoice->id, 'pay_'.uniqid());
    }

    /** @return array<string, array{string, bool}> */
    public static function planProvider(): array
    {
        return ['starter' => ['starter', false], 'growth' => ['growth', true], 'business' => ['business', true]];
    }

    /** @dataProvider planProvider */
    public function test_each_seeded_plan_grants_crm_exactly_as_the_matrix_says(string $plan, bool $expected): void
    {
        $account = Account::factory()->create();
        $this->subscribe($account, $plan);
        $this->assertSame($expected, app(AccessControlService::class)->canTenant($account->fresh(), 'crm'));
        $this->assertSame($expected, Plan::where('slug', $plan)->firstOrFail()->capabilities->pluck('slug')->contains('crm'));

        $status = $expected ? 200 : 403;
        $this->actingAs($this->user($account->fresh()))->getJson(self::UI)->assertStatus($status);
        $this->api($this->key($account->fresh()))->getJson(self::V1.'/'.$this->lead($account)->id)->assertStatus($status);
    }

    public function test_removing_crm_from_the_plan_and_reconciling_closes_both_apis(): void
    {
        $account = Account::factory()->create();
        $this->subscribe($account, 'business');
        $user = $this->user($account->fresh());
        $key = $this->key($account);
        $lead = $this->lead($account);
        $this->actingAs($user)->getJson(self::UI)->assertOk();
        $this->api($key)->getJson(self::V1.'/'.$lead->id)->assertOk();

        $plan = Plan::where('slug', 'business')->firstOrFail();
        $plan->capabilities()->detach(Capability::where('slug', 'crm')->value('id'));
        $result = app(PlanEntitlementReconciliationService::class)->reconcile($account->fresh());
        $this->assertContains('crm', $result['revoked']);

        $this->actingAs($user)->getJson(self::UI)->assertStatus(403);
        $this->api($key)->getJson(self::V1.'/'.$lead->id)->assertStatus(403)->assertJsonPath('error_code', 'CAPABILITY_NOT_ENTITLED');
        $this->assertDatabaseHas('crm_leads', ['id' => $lead->id]); // data is kept, access is closed
    }

    // =================================================================
    // 5. Developer API — authentication & key ownership
    // =================================================================

    public function test_every_developer_api_crm_route_fails_closed_on_authentication(): void
    {
        $account = $this->account();
        $revoked = $this->key($account, ['revoked_at' => now()]);
        $expired = $this->key($account, ['expires_at' => now()->subDay()]);
        $before = $this->snapshot();

        foreach ($this->routesUnder('api/v1/crm') as $r) {
            foreach ([[], ['X-API-KEY' => 'sk_test_not_a_real_key'], ['X-API-KEY' => $revoked], ['X-API-KEY' => $expired], ['Authorization' => 'Bearer nope']] as $headers) {
                $this->call_($r['method'], $r['uri'], null, $headers)->assertStatus(401);
            }
        }
        $this->assertSame($before, $this->snapshot());
    }

    public function test_a_key_of_a_suspended_account_is_refused(): void
    {
        $account = $this->account();
        $key = $this->key($account);
        $lead = $this->lead($account);
        $account->forceFill(['status' => 'suspended'])->save();

        $this->api($key)->getJson(self::V1.'/'.$lead->id)->assertStatus(401);
        $this->api($key)->postJson(self::V1, ['phone_number' => '9811111111'])->assertStatus(401);
    }

    public function test_every_developer_api_crm_route_is_403_without_the_crm_capability(): void
    {
        $key = $this->key($this->account(crm: false));

        foreach ($this->routesUnder('api/v1/crm') as $r) {
            $this->call_($r['method'], $r['uri'], null, ['X-API-KEY' => $key])
                ->assertStatus(403)->assertJsonPath('error_code', 'CAPABILITY_NOT_ENTITLED');
        }
        $this->assertDatabaseCount('crm_leads', 0);
    }

    public function test_every_developer_api_crm_route_is_403_with_the_module_disabled(): void
    {
        $modules = array_values(array_diff(Account::MODULES, ['lead_crm']));
        $key = $this->key($this->account(attributes: ['allowed_modules' => $modules]));

        foreach ($this->routesUnder('api/v1/crm') as $r) {
            $this->call_($r['method'], $r['uri'], null, ['X-API-KEY' => $key])
                ->assertStatus(403)->assertJsonPath('error_code', 'MODULE_DISABLED');
        }
        $this->assertDatabaseCount('crm_leads', 0);
    }

    public function test_an_expired_subscription_can_read_through_the_api_but_not_write(): void
    {
        $account = $this->account(active: false);
        $key = $this->key($account);
        $lead = $this->lead($account);
        $this->tag($account);
        $before = $this->snapshot();

        foreach ($this->routesUnder('api/v1/crm') as $r) {
            if ($r['method'] === 'GET') {
                continue;
            }
            $this->call_($r['method'], $r['uri'], null, ['X-API-KEY' => $key])
                ->assertStatus(403)->assertJsonPath('error_code', 'SUBSCRIPTION_EXPIRED');
        }
        $this->assertSame($before, $this->snapshot());
        $this->api($key)->getJson(self::V1.'/'.$lead->id)->assertOk()->assertJsonPath('data.id', $lead->id);
    }

    public function test_a_key_of_another_account_sees_nothing_and_changes_nothing(): void
    {
        $mine = $this->account();
        $theirs = $this->account();
        $lead = $this->lead($mine);
        $myTag = $this->tag($mine);
        $member = $this->user($mine, 'admin');
        $theirKey = $this->key($theirs);
        $before = $this->snapshot();

        $this->api($theirKey)->getJson(self::V1.'/'.$lead->id)->assertNotFound()->assertJsonPath('message', 'Lead not found.');
        $this->api($theirKey)->patchJson(self::V1.'/'.$lead->id.'/status', ['status' => 'converted'])->assertNotFound();
        $this->api($theirKey)->patchJson(self::V1.'/'.$lead->id.'/assignee', ['assigned_user_id' => $member->id])->assertNotFound();
        $this->api($theirKey)->postJson(self::V1.'/'.$lead->id.'/tags/'.$myTag->id)->assertNotFound();
        $this->api($theirKey)->deleteJson(self::V1.'/'.$lead->id.'/tags/'.$myTag->id)->assertNotFound();
        // ?account_id= is never read on /v1.
        $this->api($theirKey)->getJson(self::V1.'/'.$lead->id.'?account_id='.$mine->id)->assertNotFound();

        $this->assertSame($before, $this->snapshot());
    }

    public function test_a_foreign_lead_and_a_missing_lead_are_indistinguishable(): void
    {
        $mine = $this->account();
        $key = $this->key($mine);
        $foreign = $this->lead($this->account(), '919800000033');

        $a = $this->api($key)->getJson(self::V1.'/'.$foreign->id);
        $b = $this->api($key)->getJson(self::V1.'/99999999');

        $a->assertNotFound();
        $b->assertNotFound();
        $this->assertSame($a->json('message'), $b->json('message'));
    }

    public function test_a_foreign_tag_is_404_on_my_lead(): void
    {
        $mine = $this->account();
        $key = $this->key($mine);
        $lead = $this->lead($mine);
        $foreignTag = $this->tag($this->account(), 'Theirs');

        $this->api($key)->postJson(self::V1.'/'.$lead->id.'/tags/'.$foreignTag->id)->assertNotFound()->assertJsonPath('message', 'Tag not found.');
        $this->assertSame(0, CrmLeadTag::count());
    }

    public function test_malformed_ids_are_404_not_500(): void
    {
        $key = $this->key($this->account());

        foreach (['abc', '1.5', '-1', '0x1', '1%20OR%201=1', "1'"] as $bad) {
            $status = $this->api($key)->getJson(self::V1.'/'.$bad)->getStatusCode();
            $this->assertContains($status, [404, 405], "GET {$bad} gave {$status}");
            $status = $this->api($key)->patchJson(self::V1.'/'.$bad.'/status', ['status' => 'new'])->getStatusCode();
            $this->assertContains($status, [404, 405], "PATCH {$bad} gave {$status}");
        }
    }

    public function test_an_agent_accounts_key_cannot_reach_its_sub_clients_crm(): void
    {
        $agent = $this->account(factory: fn ($f) => $f->agent());
        $sub = $this->account(factory: fn ($f) => $f->client($agent));
        $subLead = $this->lead($sub);
        $key = $this->key($agent);

        $this->api($key)->getJson(self::V1.'/'.$subLead->id)->assertNotFound();
        $this->api($key)->getJson(self::V1.'/'.$subLead->id.'?account_id='.$sub->id)->assertNotFound();
        $this->api($key)->patchJson(self::V1.'/'.$subLead->id.'/status?account_id='.$sub->id, ['status' => 'converted'])->assertNotFound();
        $this->assertSame(CrmLead::STATUS_NEW, $subLead->fresh()->status);
    }

    // =================================================================
    // 6. Developer API — operations
    // =================================================================

    public function test_a_lead_can_be_retrieved_with_the_tenant_api_representation(): void
    {
        $account = $this->account();
        $key = $this->key($account);
        $lead = $this->lead($account);
        app(CrmTagService::class)->attach($lead, $this->tag($account, 'Hot'));
        $user = $this->user($account);

        $v1 = $this->api($key)->getJson(self::V1.'/'.$lead->id)->assertOk()->assertJsonPath('success', true);
        $ui = $this->actingAs($user)->getJson(self::UI.'/'.$lead->id)->assertOk();

        $this->assertSame($ui->json('data'), $v1->json('data'));
        $this->assertSame('Hot', $v1->json('data.tags.0.name'));
    }

    public function test_status_changes_through_the_lifecycle_service_with_outcome_bookkeeping(): void
    {
        $account = $this->account();
        $key = $this->key($account);
        $lead = $this->lead($account);

        $this->api($key)->patchJson(self::V1.'/'.$lead->id.'/status', ['status' => 'not_converted', 'not_converted_reason' => 'Budget'])
            ->assertOk()
            ->assertJsonPath('data.status', 'not_converted')
            ->assertJsonPath('data.not_converted_reason', 'Budget');
        $this->assertNotNull($lead->fresh()->not_converted_at);

        $this->api($key)->patchJson(self::V1.'/'.$lead->id.'/status', ['status' => 'converted'])->assertOk();
        $fresh = $lead->fresh();
        $this->assertNotNull($fresh->converted_at);
        $this->assertNull($fresh->not_converted_at);
        $this->assertNull($fresh->not_converted_reason);
    }

    public function test_status_validation_fails_closed(): void
    {
        $account = $this->account();
        $key = $this->key($account);
        $lead = $this->lead($account);

        $this->api($key)->patchJson(self::V1.'/'.$lead->id.'/status', [])->assertStatus(422)->assertJsonValidationErrors(['status']);
        $this->api($key)->patchJson(self::V1.'/'.$lead->id.'/status', ['status' => 'won'])->assertStatus(422);
        $this->api($key)->patchJson(self::V1.'/'.$lead->id.'/status', ['status' => 'contacted', 'not_converted_reason' => str_repeat('x', 256)])->assertStatus(422);
        $this->assertSame(CrmLead::STATUS_NEW, $lead->fresh()->status);
    }

    public function test_assignment_uses_the_same_eligibility_rules_as_the_tenant_api(): void
    {
        $account = $this->account();
        $other = $this->account();
        $key = $this->key($account);
        $lead = $this->lead($account);
        $eligible = $this->user($account, null, ['manage-crm']);
        $noCrm = $this->user($account, 'user');
        $inactive = $this->user($account, null, ['manage-crm'], false);
        $foreign = $this->user($other, 'admin');

        $this->api($key)->patchJson(self::V1.'/'.$lead->id.'/assignee', ['assigned_user_id' => $eligible->id])
            ->assertOk()->assertJsonPath('data.assigned_user_id', $eligible->id)->assertJsonPath('message', 'Lead assigned.');

        $messages = [];
        foreach ([$noCrm->id, $inactive->id, $foreign->id, 99999999] as $target) {
            $response = $this->api($key)->patchJson(self::V1.'/'.$lead->id.'/assignee', ['assigned_user_id' => $target])->assertStatus(422);
            $messages[] = json_encode($response->json('errors'));
        }
        $this->assertCount(1, array_unique($messages), 'ineligible targets must be indistinguishable');
        $this->assertSame($eligible->id, $lead->fresh()->assigned_user_id);

        $this->api($key)->patchJson(self::V1.'/'.$lead->id.'/assignee', ['assigned_user_id' => null])
            ->assertOk()->assertJsonPath('data.assigned_user_id', null)->assertJsonPath('message', 'Lead unassigned.');
        $this->api($key)->patchJson(self::V1.'/'.$lead->id.'/assignee', [])->assertStatus(422);
    }

    public function test_tags_attach_and_detach_idempotently(): void
    {
        $account = $this->account();
        $key = $this->key($account);
        $lead = $this->lead($account);
        $tag = $this->tag($account, 'Hot');
        $status = $lead->status;

        $this->api($key)->postJson(self::V1.'/'.$lead->id.'/tags/'.$tag->id)->assertOk()->assertJsonPath('message', 'Tag attached.');
        $this->api($key)->postJson(self::V1.'/'.$lead->id.'/tags/'.$tag->id)->assertOk()->assertJsonPath('message', 'Tag already attached.');
        $this->assertSame(1, CrmLeadTag::count());

        $this->api($key)->deleteJson(self::V1.'/'.$lead->id.'/tags/'.$tag->id)->assertOk()->assertJsonPath('message', 'Tag detached.');
        $this->api($key)->deleteJson(self::V1.'/'.$lead->id.'/tags/'.$tag->id)->assertOk()->assertJsonPath('message', 'Tag was not attached.');
        $this->assertSame(0, CrmLeadTag::count());
        $this->assertSame($status, $lead->fresh()->status);
    }

    public function test_mass_assignment_attempts_are_ignored(): void
    {
        $mine = $this->account();
        $theirs = $this->account();
        $key = $this->key($mine);
        $theirContact = Contact::factory()->forAccount($theirs)->create(['phone_number' => '919811111111']);
        $theirUser = $this->user($theirs);

        $created = $this->api($key)->postJson(self::V1, [
            'phone_number' => '919811111111',
            'account_id' => $theirs->id,
            'source' => 'meta_ad',
            'assigned_user_id' => $theirUser->id,
            'contact_id' => $theirContact->id,
            'capture_lead_id' => 1,
            'converted_at' => '2020-01-01',
            'id' => 424242,
        ])->assertCreated();

        $lead = CrmLead::findOrFail($created->json('data.id'));
        $this->assertNotSame(424242, $lead->id);
        $this->assertSame($mine->id, $lead->account_id);
        $this->assertSame('api', $lead->source);
        $this->assertNull($lead->assigned_user_id);
        $this->assertNull($lead->capture_lead_id);
        $this->assertNull($lead->converted_at);
        $this->assertNotSame($theirContact->id, $lead->contact_id);
        $this->assertSame($mine->id, $lead->contact->account_id);

        $this->api($key)->patchJson(self::V1.'/'.$lead->id.'/status', [
            'status' => 'contacted', 'source' => 'journey', 'account_id' => $theirs->id, 'assigned_user_id' => $theirUser->id, 'contact_id' => $theirContact->id,
        ])->assertOk();
        $fresh = $lead->fresh();
        $this->assertSame(['contacted', 'api', $mine->id, null, $lead->contact_id], [$fresh->status, $fresh->source, $fresh->account_id, $fresh->assigned_user_id, $fresh->contact_id]);
    }

    // =================================================================
    // 7. Idempotency, contact reuse, rate limit, request log
    // =================================================================

    public function test_an_idempotency_key_prevents_a_duplicate_lead(): void
    {
        $account = $this->account();
        $key = $this->key($account);
        $body = ['phone_number' => '9811111111', 'name' => 'Ada'];

        $first = $this->api($key)->withHeader('Idempotency-Key', 'order-77')->postJson(self::V1, $body)->assertCreated();
        $second = $this->api($key)->withHeader('Idempotency-Key', 'order-77')->postJson(self::V1, $body)->assertCreated();

        $second->assertHeader('Idempotent-Replay', 'true');
        $this->assertSame($first->json('data.id'), $second->json('data.id'));
        $this->assertDatabaseCount('crm_leads', 1);

        $this->api($key)->withHeader('Idempotency-Key', 'order-77')->postJson(self::V1, ['phone_number' => '9822222222'])
            ->assertStatus(409)->assertJsonPath('error_code', 'IDEMPOTENCY_KEY_REUSED');
        $this->assertDatabaseCount('crm_leads', 1);
    }

    public function test_idempotency_keys_are_scoped_per_account(): void
    {
        $a = $this->key($this->account());
        $b = $this->key($this->account());

        $this->api($a)->withHeader('Idempotency-Key', 'same')->postJson(self::V1, ['phone_number' => '9811111111'])->assertCreated();
        $this->api($b)->withHeader('Idempotency-Key', 'same')->postJson(self::V1, ['phone_number' => '9811111111'])->assertCreated()->assertHeaderMissing('Idempotent-Replay');

        $this->assertDatabaseCount('crm_leads', 2);
        $this->assertDatabaseCount('contacts', 2);
    }

    public function test_a_refused_request_does_not_consume_the_idempotency_key(): void
    {
        $account = $this->account(crm: false);
        $key = $this->key($account);

        $this->api($key)->withHeader('Idempotency-Key', 'k1')->postJson(self::V1, ['phone_number' => '9811111111'])->assertStatus(403);
        $this->grant($account);
        $this->api($key)->withHeader('Idempotency-Key', 'k1')->postJson(self::V1, ['phone_number' => '9811111111'])->assertCreated();
        $this->assertDatabaseCount('crm_leads', 1);
    }

    public function test_without_a_key_the_existing_contact_is_reused(): void
    {
        $account = $this->account();
        $key = $this->key($account);
        $existing = Contact::factory()->forAccount($account)->create(['phone_number' => '919811111111']);

        $a = $this->api($key)->postJson(self::V1, ['phone_number' => '+91 98111 11111'])->assertCreated();
        $b = $this->api($key)->postJson(self::V1, ['phone_number' => '09811111111'])->assertCreated();

        $this->assertDatabaseCount('contacts', 1);
        $this->assertSame($existing->id, $a->json('data.contact.id'));
        $this->assertSame($existing->id, $b->json('data.contact.id'));
    }

    public function test_crm_calls_share_the_existing_per_account_rate_limit(): void
    {
        $account = $this->account(attributes: ['api_rate_limit_per_minute' => 3]);
        $key = $this->key($account);
        $lead = $this->lead($account);

        for ($i = 0; $i < 3; $i++) {
            $this->api($key)->getJson(self::V1.'/'.$lead->id)->assertOk();
        }
        $this->api($key)->getJson(self::V1.'/'.$lead->id)->assertStatus(429);
        $this->api($key)->patchJson(self::V1.'/'.$lead->id.'/status', ['status' => 'contacted'])->assertStatus(429);
        $this->assertSame(CrmLead::STATUS_NEW, $lead->fresh()->status);
    }

    public function test_every_crm_api_call_is_logged_against_its_key(): void
    {
        $account = $this->account();
        $key = $this->key($account);
        $lead = $this->lead($account);

        $this->api($key)->getJson(self::V1.'/'.$lead->id)->assertOk();
        $this->api($key)->patchJson(self::V1.'/'.$lead->id.'/status', ['status' => 'contacted'])->assertOk();
        $this->api('sk_test_bogus')->getJson(self::V1.'/'.$lead->id)->assertStatus(401);

        $logs = ApiRequestLog::orderBy('id')->get();
        $this->assertCount(3, $logs);
        $this->assertSame([$account->id, $account->id], $logs->take(2)->pluck('account_id')->map(fn ($v) => (int) $v)->all());
        $this->assertNotNull($logs[0]->api_key_id);
        $this->assertSame(401, (int) $logs[2]->status_code);
    }

    public function test_the_per_account_limit_also_applies_to_existing_v1_routes_and_429s_are_logged(): void
    {
        // Task 11 middleware-priority fix: before it, throttle ran ahead of
        // auth.apikey on every /v1 route and always used the 20/min per-IP
        // fallback; api_rate_limit_per_minute was never applied.
        $account = $this->account(attributes: ['api_rate_limit_per_minute' => 2]);
        $key = $this->key($account);

        $this->api($key)->postJson('/api/v1/send-message', [])->assertStatus(422);
        $this->api($key)->postJson('/api/v1/send-message', [])->assertStatus(422);
        $this->api($key)->postJson('/api/v1/send-message', [])->assertStatus(429);

        $this->assertSame(429, (int) ApiRequestLog::orderByDesc('id')->value('status_code'));
        $this->assertSame($account->id, (int) ApiRequestLog::orderByDesc('id')->value('account_id'));

        // A different account is not affected by this account's limit.
        $this->api($this->key($this->account()))->postJson('/api/v1/send-message', [])->assertStatus(422);
    }

    public function test_invalid_keys_are_refused_before_the_limiter(): void
    {
        for ($i = 0; $i < 25; $i++) {
            $this->api('sk_test_bogus_'.$i)->getJson(self::V1.'/1')->assertStatus(401);
        }
    }

    /**
     * LogsActivity records only when a user is authenticated, so an
     * API-key mutation writes no activity_logs row (pre-existing, same as
     * every other /v1 write and every webhook). It is attributed through
     * api_request_logs instead: account, key, method, path, status.
     */
    public function test_an_api_mutation_is_attributed_through_the_request_log(): void
    {
        $account = $this->account();
        $key = $this->key($account);
        $lead = $this->lead($account);

        $property = new \ReflectionProperty($this->app, 'isRunningInConsole');
        $property->setValue($this->app, false);
        DB::table('activity_logs')->delete();

        try {
            $this->api($key)->patchJson(self::V1.'/'.$lead->id.'/status', ['status' => 'contacted'])->assertOk();
        } finally {
            $property->setValue($this->app, true);
        }

        $this->assertSame(0, DB::table('activity_logs')->where('route_path', 'like', '%v1/crm%')->count());
        $log = ApiRequestLog::orderByDesc('id')->firstOrFail();
        $this->assertSame([$account->id, 'PATCH', 200], [(int) $log->account_id, $log->method, (int) $log->status_code]);
        $this->assertStringEndsWith('v1/crm/leads/'.$lead->id.'/status', $log->path);
        $this->assertNotNull($log->api_key_id);
    }

    // =================================================================
    // 8. Existing tenant CRM behaviour is unchanged
    // =================================================================

    public function test_the_tenant_crm_api_still_works_end_to_end(): void
    {
        $account = $this->account();
        $user = $this->user($account);
        $member = $this->user($account, null, ['manage-crm']);
        $tag = $this->tag($account);

        $id = $this->actingAs($user)->postJson(self::UI, ['phone_number' => '9811111111'])->assertCreated()->json('data.id');
        $this->actingAs($user)->patchJson(self::UI.'/'.$id.'/status', ['status' => 'contacted'])->assertOk();
        $this->actingAs($user)->patchJson(self::UI.'/'.$id.'/assignee', ['assigned_user_id' => $member->id])->assertOk();
        $this->actingAs($user)->postJson(self::UI.'/'.$id.'/tags/'.$tag->id)->assertOk();
        $this->actingAs($user)->postJson(self::UI.'/bulk/status', ['lead_ids' => [$id], 'status' => 'converted'])->assertOk();
        $this->actingAs($user)->getJson('/api/crm/pipeline')->assertOk();

        $lead = CrmLead::findOrFail($id);
        $this->assertSame(['converted', $member->id, 'manual'], [$lead->status, $lead->assigned_user_id, $lead->source]);
    }
}
