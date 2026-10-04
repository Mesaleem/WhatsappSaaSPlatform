<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Invoice;
use App\Models\Subscription;
use App\Models\User;
use Database\Seeders\Phase1FoundationSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Events\PreparingResponse;
use Illuminate\Routing\Route as LaravelRoute;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

/**
 * Phase 12 Task 6 — route-inventory-driven authorization contract.
 *
 * Instead of hand-picking endpoints, every registered /api route is classified from its middleware stack and each class
 * is swept: authentication, permission, role, capability, module, tenant/target-account resolution. A NEW route that is
 * added without the protection its siblings have, or an existing guard that is dropped, changes a sweep result and fails
 * here. The tests assert EXISTING behaviour only; no authorization logic is changed by this file.
 */
#[Group('release-safety')]
class CriticalRouteAuthorizationSweepTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Every /api route that does NOT sit behind auth:sanctum / auth.apikey / auth.apisecret, and the protection it
     * relies on instead. Adding an unauthenticated route means adding it here on purpose.
     *
     * @var array<string, string>
     */
    private const PUBLIC_ROUTES = [
        'POST api/auth/login' => 'throttle:login',
        'POST api/internal/whatsapp-inbound' => 'internal.secret',
        'POST api/internal/whatsapp-status' => 'internal.secret',
        'GET api/media/social/{path}' => 'public media (path is validated by the controller)',
        'GET api/social/callback/{provider}' => 'throttle:meta-webhook + signed OAuth state',
        'GET api/social/webhook/{provider}' => 'throttle:meta-webhook + verify token',
        'POST api/social/webhook/{provider}' => 'throttle:meta-webhook + HMAC signature',
        'GET api/webhooks/meta' => 'throttle:meta-webhook + verify token',
        'POST api/webhooks/meta' => 'throttle:meta-webhook + HMAC signature',
        'POST api/webhooks/razorpay' => 'gateway signature (controller)',
        'POST api/webhooks/stripe' => 'gateway signature (controller)',
    ];

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        $this->seed(Phase1FoundationSeeder::class);
    }

    /** @return list<array{route: LaravelRoute, method: string, uri: string, names: list<string>}> */
    private function apiRoutes(): array
    {
        $out = [];
        foreach (Route::getRoutes() as $r) {
            if (! str_starts_with($r->uri(), 'api/')) {
                continue;
            }
            $out[] = [
                'route' => $r,
                'method' => strtolower(array_values(array_diff($r->methods(), ['HEAD']))[0]),
                'uri' => '/'.preg_replace('/\{[^}]+\}/', '1', $r->uri()),
                'names' => array_map(fn ($m) => is_string($m) ? $m : 'closure', $r->gatherMiddleware()),
            ];
        }

        return $out;
    }

    private function tenant(array $attrs = []): Account
    {
        $a = Account::factory()->create($attrs);
        Subscription::factory()->create(['account_id' => $a->id]);

        return $a;
    }

    private function userOf(Account $a, array|string|null $roles = 'admin'): User
    {
        $u = User::factory()->create(['account_id' => $a->id, 'is_active' => true]);
        if ($roles) {
            $u->assignRole($roles);
        }

        return $u;
    }

    private function as(User $u, string $method, string $uri, array $data = [])
    {
        $this->app['auth']->forgetGuards();

        return $this->actingAs($u)->json($method, $uri, $data);
    }

    // ---- authentication ---------------------------------------------------------------------------------------

    public function test_the_set_of_unauthenticated_api_routes_is_exactly_the_documented_allowlist(): void
    {
        $found = [];
        foreach ($this->apiRoutes() as $r) {
            $authed = collect($r['names'])->contains(fn ($n) => in_array($n, ['auth:sanctum', 'auth.apikey', 'auth.apisecret'], true));
            if (! $authed) {
                $found[strtoupper($r['method']).' '.$r['route']->uri()] = $r['names'];
            }
        }

        $this->assertEqualsCanonicalizing(array_keys(self::PUBLIC_ROUTES), array_keys($found), 'an /api route is unauthenticated without being on the allowlist (or an allowlisted one became protected/removed)');

        foreach ($found as $key => $names) {
            $needs = self::PUBLIC_ROUTES[$key];
            if (str_starts_with($needs, 'throttle:')) {
                $this->assertContains(strtok($needs, ' '), $names, "{$key} lost its throttle");
            }
            if ($needs === 'internal.secret') {
                $this->assertContains('internal.secret', $names, "{$key} lost the shared-secret guard");
            }
        }
    }

    public function test_every_sanctum_route_rejects_an_unauthenticated_request(): void
    {
        $checked = 0;
        foreach ($this->apiRoutes() as $r) {
            if (! in_array('auth:sanctum', $r['names'], true)) {
                continue;
            }
            $this->app['auth']->forgetGuards();
            $res = $this->json($r['method'], $r['uri']);
            $this->assertSame(401, $res->getStatusCode(), strtoupper($r['method']).' '.$r['uri'].' answered '.$res->getStatusCode().' without a token');
            $checked++;
        }
        $this->assertGreaterThan(200, $checked);
    }

    public function test_every_api_key_route_rejects_a_missing_key_and_leaks_nothing(): void
    {
        $checked = 0;
        foreach ($this->apiRoutes() as $r) {
            if (! collect($r['names'])->contains(fn ($n) => in_array($n, ['auth.apikey', 'auth.apisecret'], true))) {
                continue;
            }
            $this->flushHeaders();
            $res = $this->json($r['method'], $r['uri']);
            $this->assertSame(401, $res->getStatusCode(), $r['method'].' '.$r['uri']);
            $this->assertFalse($res->json('status') ?? $res->json('success'), 'envelope must say it failed');
            $checked++;
        }
        $this->assertGreaterThanOrEqual(10, $checked);
    }

    public function test_unauthenticated_webhook_and_internal_endpoints_refuse_unsigned_calls_without_side_effects(): void
    {
        config(['services.qr_engine.internal_secret' => 'internal-secret-for-this-test']);
        $invoices = Invoice::count();
        $paid = Invoice::where('status', 'paid')->count();

        // Meta endpoints: a POST without a valid X-Hub-Signature-256 is rejected.
        foreach (['/api/webhooks/meta', '/api/social/webhook/meta'] as $uri) {
            $this->flushHeaders();
            $this->assertContains($this->postJson($uri, ['object' => 'whatsapp_business_account', 'entry' => []])->getStatusCode(), [400, 401, 403, 404, 422], $uri);
        }

        // Payment gateways: documented behaviour is to ACK (200) and ignore while the gateway is not configured ...
        foreach (['razorpay', 'stripe'] as $gw) {
            $this->flushHeaders();
            $this->postJson("/api/webhooks/{$gw}", ['event' => 'payment.captured'])->assertOk()->assertJson(['received' => true]);
        }
        // ... and to reject a bad or missing signature (400) once it is configured.
        \App\Models\PaymentGatewaySetting::create(['gateway' => 'razorpay', 'mode' => 'test', 'is_enabled' => true, 'test_key_id' => 'k', 'test_key_secret' => 's', 'test_webhook_secret' => 'whsec_for_this_test']);
        $this->flushHeaders();
        $this->postJson('/api/webhooks/razorpay', ['event' => 'payment.captured'])->assertStatus(400);
        $this->flushHeaders();
        $this->withHeader('X-Razorpay-Signature', 'deadbeef')->postJson('/api/webhooks/razorpay', ['event' => 'payment.captured'])->assertStatus(400);

        // Internal service endpoints need the shared secret.
        foreach (['/api/internal/whatsapp-inbound', '/api/internal/whatsapp-status'] as $uri) {
            $this->flushHeaders();
            $this->postJson($uri, ['account_id' => 1])->assertStatus(401);
            $this->flushHeaders();
            $this->withHeader('X-Internal-Secret', 'wrong')->postJson($uri, ['account_id' => 1])->assertStatus(401);
        }

        $this->assertSame($invoices, Invoice::count());
        $this->assertSame($paid, Invoice::where('status', 'paid')->count(), 'no unsigned call may fulfil an invoice');
    }

    // ---- permission / role / capability / module sweeps -------------------------------------------------------

    public function test_every_permission_gated_route_denies_a_user_without_that_permission(): void
    {
        $noRole = $this->userOf($this->tenant(), null);
        $checked = 0;
        foreach ($this->apiRoutes() as $r) {
            if (! in_array('auth:sanctum', $r['names'], true) || ! collect($r['names'])->contains(fn ($n) => str_starts_with($n, 'permission:'))) {
                continue;
            }
            $res = $this->as($noRole, $r['method'], $r['uri']);
            $this->assertSame(403, $res->getStatusCode(), strtoupper($r['method']).' '.$r['uri'].' ['.collect($r['names'])->first(fn ($n) => str_starts_with($n, 'permission:')).'] answered '.$res->getStatusCode());
            $checked++;
        }
        $this->assertGreaterThan(150, $checked);
    }

    public function test_every_super_admin_only_route_denies_a_tenant_admin(): void
    {
        $admin = $this->userOf($this->tenant());
        $checked = 0;
        foreach ($this->apiRoutes() as $r) {
            if (! in_array('role:super_admin', $r['names'], true)) {
                continue;
            }
            $this->assertSame(403, $this->as($admin, $r['method'], $r['uri'])->getStatusCode(), strtoupper($r['method']).' '.$r['uri']);
            $checked++;
        }
        $this->assertGreaterThanOrEqual(5, $checked);
    }

    public function test_every_capability_gated_route_denies_an_unentitled_tenant(): void
    {
        $admin = $this->userOf($this->tenant()); // active subscription, admin role, NO entitlements
        $checked = 0;
        foreach ($this->apiRoutes() as $r) {
            if (! in_array('auth:sanctum', $r['names'], true) || ! collect($r['names'])->contains(fn ($n) => str_starts_with($n, 'capability.guard:'))) {
                continue;
            }
            $res = $this->as($admin, $r['method'], $r['uri']);
            $this->assertSame(403, $res->getStatusCode(), strtoupper($r['method']).' '.$r['uri']);
            $this->assertSame('CAPABILITY_NOT_ENTITLED', $res->json('error_code'), strtoupper($r['method']).' '.$r['uri']);
            $checked++;
        }
        $this->assertGreaterThan(50, $checked);
    }

    public function test_every_module_gated_route_denies_a_tenant_whose_module_is_switched_off(): void
    {
        $admin = $this->userOf($this->tenant(['allowed_modules' => []]));
        $checked = 0;
        foreach ($this->apiRoutes() as $r) {
            if (! in_array('auth:sanctum', $r['names'], true) || ! collect($r['names'])->contains(fn ($n) => str_starts_with($n, 'module.guard:'))) {
                continue;
            }
            $res = $this->as($admin, $r['method'], $r['uri']);
            $this->assertSame(403, $res->getStatusCode(), strtoupper($r['method']).' '.$r['uri']);
            $this->assertContains($res->json('error_code'), ['MODULE_DISABLED', 'GROUP_MODULE_DISABLED'], strtoupper($r['method']).' '.$r['uri']);
            $checked++;
        }
        $this->assertGreaterThan(100, $checked);
    }

    // ---- tenant isolation and target-account semantics --------------------------------------------------------

    /** @return list<array{route: LaravelRoute, method: string, uri: string, names: list<string>}> */
    private function tenantGetRoutes(): array
    {
        return array_values(array_filter($this->apiRoutes(), fn ($r) => $r['method'] === 'get'
            && in_array('auth:sanctum', $r['names'], true)
            && in_array('tenant.isolation', $r['names'], true)));
    }

    /** Captures the account the request was RESOLVED to (the attribute every controller scopes by). */
    private function resolvedAccount(User $u, string $uri, ?int $requested, int &$status = 0): mixed
    {
        $seen = 'unset';
        Event::listen(PreparingResponse::class, function (PreparingResponse $e) use (&$seen) {
            $seen = $e->request->attributes->get('account_id');
        });
        $this->app['auth']->forgetGuards();
        $res = $this->actingAs($u)->getJson($uri.($requested !== null ? '?account_id='.$requested : ''));
        $status = $res->getStatusCode();

        return $seen;
    }

    public function test_a_tenant_user_can_never_select_another_account_through_the_query_string(): void
    {
        $mine = $this->tenant();
        $theirs = $this->tenant();
        $admin = $this->userOf($mine);

        $routes = $this->tenantGetRoutes();
        $this->assertGreaterThan(80, count($routes));
        foreach ($routes as $r) {
            $resolved = $this->resolvedAccount($admin, $r['uri'], $theirs->id);
            $this->assertSame($mine->id, $resolved, "GET {$r['uri']} resolved account_id to ".var_export($resolved, true).' for a tenant user who passed ?account_id=<other>');
        }
    }

    public function test_an_agent_reaches_only_its_own_subclients_on_every_tenant_route(): void
    {
        $agentAcc = Account::factory()->agent()->create();
        Subscription::factory()->create(['account_id' => $agentAcc->id]);
        $child = Account::factory()->client($agentAcc)->create();
        $stranger = $this->tenant();
        $agent = $this->userOf($agentAcc, ['admin', 'agent']);

        foreach ($this->tenantGetRoutes() as $r) {
            $status = 0;
            $this->resolvedAccount($agent, $r['uri'], $stranger->id, $status);
            $this->assertSame(404, $status, "GET {$r['uri']}: an unowned account must be 404, got {$status}");

            $resolved = $this->resolvedAccount($agent, $r['uri'], $child->id);
            $this->assertSame($child->id, $resolved, "GET {$r['uri']}: an owned sub-client must resolve to that sub-client");
        }
    }

    public function test_super_admin_target_selection_is_explicit_and_validated_on_every_tenant_route(): void
    {
        $client = $this->tenant();
        $super = User::factory()->create(['account_id' => null, 'is_active' => true]);
        $super->assignRole('super_admin');

        foreach ($this->tenantGetRoutes() as $r) {
            $status = 0;
            $this->resolvedAccount($super, $r['uri'], 999999, $status);
            $this->assertSame(404, $status, "GET {$r['uri']}: an unknown target account must be 404");

            $this->assertSame($client->id, $this->resolvedAccount($super, $r['uri'], $client->id), "GET {$r['uri']}: the selected client must be the resolved account");
        }
    }

    public function test_super_admin_acting_on_a_suspended_client_is_stopped_on_every_target_account_route(): void
    {
        $suspended = $this->tenant(['status' => 'suspended']);
        $super = User::factory()->create(['account_id' => null, 'is_active' => true]);
        $super->assignRole('super_admin');

        $checked = 0;
        foreach ($this->tenantGetRoutes() as $r) {
            if (! collect($r['names'])->contains(fn ($n) => $n === 'target.account' || str_starts_with($n, 'target.account:'))) {
                continue;
            }
            $res = $this->as($super, 'get', $r['uri'].'?account_id='.$suspended->id);
            $this->assertSame(403, $res->getStatusCode(), "GET {$r['uri']}");
            $this->assertSame('CLIENT_ACCOUNT_SUSPENDED', $res->json('error_code'), "GET {$r['uri']}");
            $checked++;
        }
        $this->assertGreaterThan(15, $checked);
    }

    public function test_super_admin_acting_on_a_client_with_the_module_off_is_stopped_where_the_route_names_a_module(): void
    {
        $noModules = $this->tenant(['allowed_modules' => []]);
        $super = User::factory()->create(['account_id' => null, 'is_active' => true]);
        $super->assignRole('super_admin');

        $checked = 0;
        foreach ($this->tenantGetRoutes() as $r) {
            if (! collect($r['names'])->contains(fn ($n) => str_starts_with($n, 'target.account:'))) {
                continue;
            }
            $res = $this->as($super, 'get', $r['uri'].'?account_id='.$noModules->id);
            $this->assertSame(403, $res->getStatusCode(), "GET {$r['uri']}");
            $this->assertSame('MODULE_DISABLED', $res->json('error_code'), "GET {$r['uri']}");
            $checked++;
        }
        $this->assertGreaterThan(10, $checked);
    }

    public function test_one_tenants_resources_are_invisible_to_another_tenant_on_representative_routes(): void
    {
        $a = $this->tenant();
        $b = $this->tenant();
        $adminA = $this->userOf($a);

        $subB = \App\Models\WebhookSubscription::create(['account_id' => $b->id, 'url' => 'https://example.test/h', 'secret' => 's', 'events' => ['message.sent'], 'is_active' => true]);
        $this->assertSame(404, $this->as($adminA, 'get', "/api/developer/webhooks/{$subB->id}/deliveries")->getStatusCode());
        $this->assertSame(404, $this->as($adminA, 'delete', "/api/developer/webhooks/{$subB->id}")->getStatusCode());
        $this->assertNotNull(\App\Models\WebhookSubscription::find($subB->id), 'the other tenant\'s subscription must survive');

        $this->assertSame(404, $this->as($adminA, 'get', '/api/billing/invoices/999999/pdf')->getStatusCode());
    }
}
