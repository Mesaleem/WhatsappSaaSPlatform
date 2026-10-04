<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\AccountEntitlement;
use App\Models\ApiIdempotencyKey;
use App\Models\ApiKey;
use App\Models\ApiKeyBinding;
use App\Models\ApiKeyChangeRequest;
use App\Models\ApiKeySecurityEvent;
use App\Models\Capability;
use App\Models\CrmLead;
use App\Models\Subscription;
use App\Models\User;
use Database\Seeders\Phase1FoundationSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

/**
 * Public API authorized-server binding. A valid key alone must not be enough: the request also has to come from the
 * key's authorized server (installation credential + IP policy). Keys are created through the real endpoints so the
 * one-time reveal, acknowledgement and registration are exercised exactly as a buyer meets them.
 */
#[Group('release-safety')]
class ApiKeyServerBindingTest extends TestCase
{
    use RefreshDatabase;

    private const SERVER_B = '203.0.113.10';
    private const SERVER_C = '198.51.100.77';
    private const SERVER_D = '203.0.113.55';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        $this->seed(Phase1FoundationSeeder::class);
    }

    private function tenant(): Account
    {
        $account = Account::factory()->create();
        Subscription::factory()->create(['account_id' => $account->id]);
        AccountEntitlement::firstOrCreate(
            ['account_id' => $account->id, 'capability_id' => Capability::where('slug', 'crm')->firstOrFail()->id],
            ['source' => 'manual_grant', 'granted_by_account_id' => null],
        );

        return $account->fresh();
    }

    private function admin(Account $account): User
    {
        $u = User::factory()->create(['account_id' => $account->id, 'is_active' => true]);
        $u->assignRole('admin');

        return $u;
    }

    private function superAdmin(): User
    {
        $u = User::factory()->create(['account_id' => null, 'is_active' => true]);
        $u->assignRole('super_admin');

        return $u;
    }

    /** Creates a key through the real endpoint. @return array{key: string, secret: string, credential: string, id: int, response: array} */
    private function createKey(User $owner, array $extra = []): array
    {
        $this->app['auth']->forgetGuards();
        $r = $this->actingAs($owner)->postJson('/api/developer/api-keys', array_merge([
            'name' => 'Production',
            'acknowledge_server_binding' => true,
            'server_label' => 'Production Server',
            'ip_policy' => 'SINGLE_IP',
            'authorized_ips' => [self::SERVER_B],
        ], $extra))->assertCreated();

        return ['key' => $r->json('plain_text_key'), 'secret' => $r->json('plain_text_secret'), 'credential' => $r->json('installation_credential'), 'id' => $r->json('api_key.id'), 'response' => $r->json()];
    }

    /** A /v1 call as a given server. */
    private function call_v1(string $method, string $uri, array $body, array $headers, string $ip)
    {
        $this->flushHeaders();
        $this->app['auth']->forgetGuards();

        return $this->withServerVariables(['REMOTE_ADDR' => $ip])->withHeaders($headers + ['Accept' => 'application/json'])->json($method, $uri, $body);
    }

    private function lead(array $k, string $ip, ?string $credential = '__use__', array $more = [])
    {
        $headers = ['X-API-KEY' => $k['key']] + $more;
        $credential = $credential === '__use__' ? $k['credential'] : $credential;
        if ($credential !== null) {
            $headers['X-Client-Installation'] = $credential;
        }

        return $this->call_v1('POST', '/api/v1/crm/leads', ['phone_number' => '919876543210'], $headers, $ip);
    }

    private function assertDenied($response): void
    {
        $response->assertStatus(403)->assertExactJson([
            'success' => false, 'status' => false, 'code' => 'API_CLIENT_NOT_AUTHORIZED', 'error_code' => 'API_CLIENT_NOT_AUTHORIZED',
            'message' => 'This API key is not authorized for this server.',
        ]);
    }

    private function events(string $event): int
    {
        return ApiKeySecurityEvent::where('event', $event)->count();
    }

    // ---- creation, acknowledgement, one-time reveal ---------------------------------------------------------------

    public function test_a_key_cannot_be_created_without_acknowledging_the_licence_warning(): void
    {
        $owner = $this->admin($this->tenant());

        foreach ([[], ['acknowledge_server_binding' => false]] as $payload) {
            $r = $this->actingAs($owner)->postJson('/api/developer/api-keys', ['name' => 'X'] + $payload);
            $r->assertStatus(422)->assertJsonValidationErrors('acknowledge_server_binding');
            $this->assertStringContainsString('licensed to your purchased account and authorized server', $r->json('errors.acknowledge_server_binding.0'));
            $this->assertStringContainsString('Changing the authorized server requires approval', $r->json('errors.acknowledge_server_binding.0'));
        }
        $this->assertSame(0, ApiKey::count());
        $this->assertSame(0, ApiKeyBinding::count());
    }

    public function test_creation_registers_the_server_reveals_secrets_once_and_stores_only_hashes(): void
    {
        $k = $this->createKey($this->admin($this->tenant()));

        $this->assertStringStartsWith('wasaas_inst_', $k['credential']);
        $this->assertSame(config('api_binding.warning'), $k['response']['warning']);
        $this->assertSame('active', $k['response']['api_key']['server_binding']['status']);

        $dump = json_encode(DB::table('api_keys')->get()).json_encode(DB::table('api_key_bindings')->get()).json_encode(DB::table('api_key_security_events')->get());
        foreach ([$k['key'], $k['secret'], $k['credential']] as $plain) {
            $this->assertStringNotContainsString($plain, $dump, 'a plaintext credential reached the database');
        }

        // never retrievable again
        $again = $this->actingAs($this->admin(Account::find(ApiKey::find($k['id'])->account_id)))->getJson('/api/developer/api-keys')->assertOk()->getContent();
        foreach ([$k['key'], $k['secret'], $k['credential'], 'installation_hash', 'key_hash', 'secret_hash'] as $needle) {
            $this->assertStringNotContainsString($needle, $again);
        }
        $this->assertSame(1, $this->events('api_key_created'));
        $this->assertSame(1, $this->events('server_registered'));
    }

    public function test_invalid_binding_input_is_rejected_before_a_key_is_created(): void
    {
        $owner = $this->admin($this->tenant());
        foreach ([
            ['ip_policy' => 'NONE'],                                            // unrestricted is not a client choice
            ['ip_policy' => 'SINGLE_IP', 'authorized_ips' => ['not-an-ip']],
            ['ip_policy' => 'SINGLE_IP', 'authorized_ips' => ['10.0.0.0/24']],  // a range is not a single IP
            ['ip_policy' => 'SINGLE_IP', 'authorized_ips' => ['10.0.0.1', '10.0.0.2']],
            ['ip_policy' => 'IP_ALLOWLIST', 'authorized_ips' => []],
            ['ip_policy' => 'IP_ALLOWLIST', 'authorized_ips' => ['10.0.0.0/40']],
        ] as $bad) {
            $this->actingAs($owner)->postJson('/api/developer/api-keys', ['name' => 'X', 'acknowledge_server_binding' => true] + $bad)->assertStatus(422);
        }
        $this->assertSame(0, ApiKey::count());
    }

    // ---- request enforcement ----------------------------------------------------------------------------------------

    public function test_the_buyer_on_the_authorized_server_and_ip_gets_normal_api_behaviour(): void
    {
        $account = $this->tenant();
        $k = $this->createKey($this->admin($account));

        $this->lead($k, self::SERVER_B)->assertCreated()->assertJsonPath('data.source', 'api');
        $this->assertSame(1, CrmLead::where('account_id', $account->id)->count());

        $b = ApiKeyBinding::sole();
        $this->assertSame(self::SERVER_B, $b->last_success_ip);
        $this->assertNotNull($b->last_success_at);
        $this->assertSame(substr($k['credential'], 0, 20), $b->last_success_client);
        $this->assertSame(1, $this->events('authorized_server_authenticated'));
    }

    public function test_the_same_key_from_another_server_is_refused_with_the_stable_envelope(): void
    {
        $k = $this->createKey($this->admin($this->tenant()));

        // Server C: right key, no credential
        $this->assertDenied($this->lead($k, self::SERVER_C, null));
        // Server C: right key, invented credential
        $this->assertDenied($this->lead($k, self::SERVER_C, 'wasaas_inst_'.str_repeat('x', 40)));
        $this->assertSame(0, CrmLead::count());
        $this->assertGreaterThanOrEqual(1, $this->events('unauthorized_server_attempt'));
        // and the buyer is unaffected
        $this->lead($k, self::SERVER_B)->assertCreated();
    }

    public function test_a_friend_with_the_copied_key_and_copied_credential_from_another_ip_is_refused_and_creates_no_second_installation(): void
    {
        $k = $this->createKey($this->admin($this->tenant()));
        $this->lead($k, self::SERVER_B)->assertCreated();

        $this->assertDenied($this->lead($k, self::SERVER_C));       // everything copied, wrong server
        $this->assertDenied($this->lead($k, self::SERVER_C));

        $this->assertSame(1, ApiKeyBinding::count(), 'a copy must not register a second installation');
        $b = ApiKeyBinding::sole();
        $this->assertSame('active', $b->status);
        $this->assertSame(self::SERVER_B, $b->registered_ip);
        $this->assertSame(self::SERVER_B, $b->last_success_ip, 'a refused attempt must not overwrite the last successful IP');
        $this->assertGreaterThanOrEqual(1, $this->events('unauthorized_ip_attempt'));
        $this->lead($k, self::SERVER_B)->assertCreated();
    }

    public function test_the_first_request_registers_the_servers_observed_ip_once_when_none_was_declared(): void
    {
        $k = $this->createKey($this->admin($this->tenant()), ['authorized_ips' => []]);
        $this->assertSame('pending_activation', ApiKeyBinding::sole()->status);

        $this->lead($k, self::SERVER_B)->assertCreated();
        $b = ApiKeyBinding::sole();
        $this->assertSame('active', $b->status);
        $this->assertSame(self::SERVER_B, $b->registered_ip);
        $this->assertSame([self::SERVER_B], $b->authorized_ips);
        $this->assertNotNull($b->registered_at);

        // a second server holding the same copied credential cannot activate again
        $this->assertDenied($this->lead($k, self::SERVER_C));
        $this->assertSame(self::SERVER_B, ApiKeyBinding::sole()->registered_ip);
        $this->assertSame(2, $this->events('server_registered'), 'creation + one activation, never a second activation');
    }

    public function test_a_declared_ip_cannot_be_hijacked_by_activating_from_somewhere_else(): void
    {
        $k = $this->createKey($this->admin($this->tenant()), ['authorized_ips' => [self::SERVER_B]]);
        $this->assertDenied($this->lead($k, self::SERVER_C));
        $this->lead($k, self::SERVER_B)->assertCreated();
    }

    public function test_ip_allowlist_supports_ranges_ipv6_and_ipv4_mapped_addresses_and_ignores_spoofed_forwarding_headers(): void
    {
        $k = $this->createKey($this->admin($this->tenant()), ['ip_policy' => 'IP_ALLOWLIST', 'authorized_ips' => ['203.0.113.0/28', '2001:db8:abcd::/48']]);

        $this->lead($k, '203.0.113.9')->assertCreated();
        $this->lead($k, '2001:db8:abcd:12::5')->assertCreated();
        $this->lead($k, '::ffff:203.0.113.9')->assertCreated();            // IPv4-mapped IPv6 is the same host
        $this->assertDenied($this->lead($k, '203.0.113.200'));
        $this->assertDenied($this->lead($k, '2001:db8:ffff::1'));

        // X-Forwarded-For from an untrusted peer must not buy an authorized address
        $this->assertDenied($this->lead($k, self::SERVER_C, '__use__', ['X-Forwarded-For' => '203.0.113.9', 'X-Real-IP' => '203.0.113.9']));
    }

    public function test_a_single_ip_binding_normalises_ipv6_spelling(): void
    {
        $k = $this->createKey($this->admin($this->tenant()), ['authorized_ips' => ['2001:0DB8:0:0:0:0:0:1']]);
        $this->assertSame(['2001:db8::1'], ApiKeyBinding::sole()->authorized_ips);
        $this->lead($k, '2001:db8::1')->assertCreated();
    }

    public function test_another_accounts_credential_never_authorizes_this_key_and_tenants_stay_isolated(): void
    {
        $a = $this->tenant();
        $b = $this->tenant();
        $ka = $this->createKey($this->admin($a));
        $kb = $this->createKey($this->admin($b));          // both servers happen to share SERVER_B (same hosting provider/NAT)

        $this->assertDenied($this->lead($ka, self::SERVER_B, $kb['credential']));
        $this->assertDenied($this->lead($kb, self::SERVER_B, $ka['credential']));

        $this->lead($ka, self::SERVER_B)->assertCreated();
        $this->lead($kb, self::SERVER_B)->assertCreated();
        $this->assertSame(1, CrmLead::where('account_id', $a->id)->count());
        $this->assertSame(1, CrmLead::where('account_id', $b->id)->count(), 'each key acts only on its own account');
    }

    public function test_an_unauthorized_server_reaches_neither_idempotency_nor_the_business_operation(): void
    {
        $account = $this->tenant();
        $k = $this->createKey($this->admin($account));

        $this->assertDenied($this->lead($k, self::SERVER_C, '__use__', ['Idempotency-Key' => 'probe-1']));
        $this->assertSame(0, ApiIdempotencyKey::count());
        $this->assertSame(0, CrmLead::count());

        // a refused server cannot exhaust the buyer's rate limit either
        $account->forceFill(['api_rate_limit_per_minute' => 2])->save();
        for ($i = 0; $i < 5; $i++) {
            $this->assertDenied($this->lead($k, self::SERVER_C));
        }
        $this->lead($k, self::SERVER_B)->assertCreated();
    }

    public function test_every_public_v1_route_enforces_the_binding_on_both_authentication_tiers(): void
    {
        $account = $this->tenant();
        $k = $this->createKey($this->admin($account));
        $headers = ['X-API-KEY' => $k['key'], 'X-API-SECRET' => $k['secret']];

        $routes = collect(Route::getRoutes()->getRoutes())->filter(fn ($r) => str_starts_with($r->uri(), 'api/v1/'));
        $this->assertCount(11, $routes);
        foreach ($routes as $route) {
            $method = array_values(array_diff($route->methods(), ['HEAD']))[0];
            $uri = '/'.str_replace(['{id}', '{tag}'], ['1', '1'], $route->uri());
            $this->assertDenied($this->call_v1($method, $uri, [], $headers, self::SERVER_C));                                   // wrong server
            $this->assertDenied($this->call_v1($method, $uri, [], $headers, self::SERVER_B));                                   // right IP, no credential
            $ok = $this->call_v1($method, $uri, [], $headers + ['X-Client-Installation' => $k['credential']], self::SERVER_B);
            $this->assertNotSame('API_CLIENT_NOT_AUTHORIZED', $ok->json('code'), "{$uri} refused the authorized server");
        }

        // the dual-factor tier works end to end for the authorized server
        $this->call_v1('POST', '/api/v1/whatsapp/groups/create', ['name' => 'Team'], $headers + ['X-Client-Installation' => $k['credential']], self::SERVER_B)->assertCreated();
        $this->assertDenied($this->call_v1('POST', '/api/v1/whatsapp/groups/create', ['name' => 'Team'], $headers, self::SERVER_C));
    }

    // ---- legacy / backward compatibility ----------------------------------------------------------------------------

    /** A key minted before binding existed: no api_key_bindings row. @return array{key: string, credential: null, model: ApiKey} */
    private function legacyKey(Account $account, string $name = 'Legacy'): array
    {
        $plain = 'wasaas_live_'.bin2hex(random_bytes(20));
        $model = ApiKey::create(['account_id' => $account->id, 'name' => $name, 'key_prefix' => substr($plain, 0, 20), 'key_hash' => ApiKey::hashKey($plain)]);

        return ['key' => $plain, 'credential' => null, 'model' => $model];
    }

    private function assertBindingRequired($response): void
    {
        $response->assertStatus(403)->assertExactJson([
            'success' => false, 'status' => false, 'code' => 'API_SERVER_BINDING_REQUIRED', 'error_code' => 'API_SERVER_BINDING_REQUIRED',
            'message' => 'This API key requires server authorization before it can be used.',
        ]);
    }

    public function test_the_production_default_does_not_allow_unbound_legacy_keys(): void
    {
        $this->assertFalse((require base_path('config/api_binding.php'))['allow_legacy_unbound'], 'unrestricted legacy access must never be the shipped default');
        $this->assertFalse(config('api_binding.allow_legacy_unbound'));
    }

    public function test_a_legacy_unbound_key_cannot_call_business_endpoints(): void
    {
        $legacy = $this->legacyKey($this->tenant());

        $this->assertBindingRequired($this->lead($legacy, self::SERVER_B, null));
        $this->assertBindingRequired($this->lead($legacy, self::SERVER_C, 'wasaas_inst_'.str_repeat('a', 40)));   // a guessed credential changes nothing
        $this->assertSame(0, CrmLead::count(), 'no business operation ran');
        $this->assertGreaterThan(0, $this->events('server_binding_required'));
    }

    public function test_a_legacy_key_cannot_claim_a_server_by_being_used(): void
    {
        $legacy = $this->legacyKey($this->tenant());

        foreach ([self::SERVER_B, self::SERVER_C, self::SERVER_D, self::SERVER_B] as $ip) {
            $this->assertBindingRequired($this->lead($legacy, $ip, null));
        }

        $this->assertSame(0, ApiKeyBinding::count(), 'no binding is ever created by API use');
        $this->assertSame(0, $legacy['model']->bindings()->count());
        $this->assertSame(0, CrmLead::count());
        // ... and a first caller cannot sneak in a registration through any /v1 route either: enrollment is dashboard-only.
        $this->call_v1('POST', '/api/developer/api-keys/'.$legacy['model']->id.'/server-binding', ['acknowledge_server_binding' => true], ['X-API-KEY' => $legacy['key']], self::SERVER_C)->assertStatus(401);
        $this->assertSame(0, ApiKeyBinding::count());
    }

    public function test_the_temporary_override_is_explicit_and_leaves_an_audit_trail(): void
    {
        $legacy = $this->legacyKey($this->tenant());
        config(['api_binding.allow_legacy_unbound' => true]);

        $this->lead($legacy, self::SERVER_C, null)->assertCreated();
        $this->assertSame(1, $this->events('legacy_unbound_override_used'));
        $this->assertSame(0, ApiKeyBinding::count(), 'even the override never binds a server');

        config(['api_binding.allow_legacy_unbound' => false]);
        $this->assertBindingRequired($this->lead($legacy, self::SERVER_C, null));
    }

    public function test_the_buyer_sees_that_a_legacy_key_needs_authorization_and_enrolls_it_end_to_end(): void
    {
        $account = $this->tenant();
        $owner = $this->admin($account);
        $legacy = $this->legacyKey($account);
        $id = $legacy['model']->id;

        // 2/3 - the owner logs in, lists keys and sees the requirement.
        $this->app['auth']->forgetGuards();
        $row = collect($this->actingAs($owner)->getJson('/api/developer/api-keys')->assertOk()->json('data'))->firstWhere('id', $id);
        $this->assertSame('unbound', $row['server_binding']['status']);
        $this->assertTrue($row['server_binding']['binding_required']);
        $this->assertTrue($row['server_binding']['enforced']);

        // 4/5 - registers the authorized server (acknowledgement required) and receives the credential once.
        $r = $this->actingAs($owner)->postJson("/api/developer/api-keys/{$id}/server-binding", ['acknowledge_server_binding' => true, 'server_label' => 'Prod', 'ip_policy' => 'SINGLE_IP', 'authorized_ips' => [self::SERVER_B]])->assertCreated();
        $credential = $r->json('installation_credential');
        $this->assertNotEmpty($credential);
        $this->assertStringNotContainsString($credential, json_encode(ApiKeyBinding::firstOrFail()->getAttributes()), 'only a hash is stored');

        // 6/7 - the SAME key, unchanged, works from the authorized server with the credential...
        $k = ['key' => $legacy['key'], 'credential' => $credential];
        $this->lead($k, self::SERVER_B)->assertCreated();
        $this->assertSame('active', ApiKeyBinding::firstOrFail()->status);
        // ...and only from there.
        $this->assertDenied($this->lead($k, self::SERVER_C));                      // right credential, wrong server
        $this->assertDenied($this->lead($legacy, self::SERVER_B, null));           // right server, no credential
        $this->assertDenied($this->lead($legacy, self::SERVER_C, null));           // the copied key alone
        $this->assertSame(1, $legacy['model']->fresh()->bindings()->count());
    }

    public function test_another_tenant_cannot_enroll_or_see_someone_elses_legacy_key(): void
    {
        $a = $this->tenant();
        $b = $this->tenant();
        $legacyA = $this->legacyKey($a);
        $intruder = $this->admin($b);
        $id = $legacyA['model']->id;

        $this->actingAs($intruder)->postJson("/api/developer/api-keys/{$id}/server-binding", ['acknowledge_server_binding' => true, 'authorized_ips' => [self::SERVER_C]])->assertNotFound();
        $this->assertSame(0, ApiKeyBinding::count());
        $this->assertNotContains($id, array_column($this->actingAs($intruder)->getJson('/api/developer/api-keys')->json('data'), 'id'));
        $this->assertBindingRequired($this->lead($legacyA, self::SERVER_C, null));
    }

    public function test_super_admin_can_rebind_a_legacy_key_and_the_owner_collects_the_credential(): void
    {
        $account = $this->tenant();
        $legacy = $this->legacyKey($account);
        $id = $legacy['model']->id;

        $this->actingAs($this->superAdmin())->postJson("/api/admin/api-access/{$id}/rebind", ['label' => 'Partner', 'ip_policy' => 'SINGLE_IP', 'authorized_ips' => [self::SERVER_D]])->assertOk();
        $this->assertDenied($this->lead($legacy, self::SERVER_D, null));            // bound now, but no credential yet

        $this->app['auth']->forgetGuards();
        $credential = $this->actingAs($this->admin($account))->postJson("/api/developer/api-keys/{$id}/installation-credential")->assertOk()->json('installation_credential');
        $k = ['key' => $legacy['key'], 'credential' => $credential];
        $this->lead($k, self::SERVER_D)->assertCreated();
        $this->assertDenied($this->lead($k, self::SERVER_C));
    }

    public function test_disabling_and_reenabling_a_legacy_key_keeps_it_unusable_until_enrolled(): void
    {
        $legacy = $this->legacyKey($this->tenant());
        $id = $legacy['model']->id;
        $super = $this->superAdmin();

        $this->actingAs($super)->postJson("/api/admin/api-access/{$id}/disable", ['reason' => 'review'])->assertOk();
        $this->lead($legacy, self::SERVER_B, null)->assertStatus(403)->assertJsonPath('code', 'API_ACCESS_DISABLED');

        $this->actingAs($super)->postJson("/api/admin/api-access/{$id}/enable")->assertOk();
        $this->assertBindingRequired($this->lead($legacy, self::SERVER_B, null));          // re-enabling never re-opens an unbound key
    }

    public function test_an_enrolled_legacy_key_follows_the_normal_server_change_flow(): void
    {
        $account = $this->tenant();
        $owner = $this->admin($account);
        $legacy = $this->legacyKey($account);
        $id = $legacy['model']->id;

        $credential = $this->actingAs($owner)->postJson("/api/developer/api-keys/{$id}/server-binding", ['acknowledge_server_binding' => true, 'authorized_ips' => [self::SERVER_B]])->assertCreated()->json('installation_credential');
        $k = ['key' => $legacy['key'], 'credential' => $credential];
        $this->lead($k, self::SERVER_B)->assertCreated();

        $reqId = $this->requestChange($owner, $id)->assertCreated()->json('data.id');
        $this->lead($k, self::SERVER_B)->assertCreated();                           // old server stays active while pending
        $this->actingAs($this->superAdmin())->postJson("/api/admin/api-access/change-requests/{$reqId}/approve")->assertOk();
        $this->assertDenied($this->lead($k, self::SERVER_B));                       // old server stops immediately
        $new = $this->actingAs($owner)->postJson("/api/developer/api-keys/{$id}/installation-credential")->assertOk()->json('installation_credential');
        $this->lead(['key' => $legacy['key'], 'credential' => $new], self::SERVER_D)->assertCreated();
    }

    public function test_a_key_that_already_has_a_binding_is_unaffected_by_the_default(): void
    {
        $k = $this->createKey($this->admin($this->tenant()));

        $this->lead($k, self::SERVER_B)->assertCreated();
        $this->assertDenied($this->lead($k, self::SERVER_C));                       // still the uniform not-authorized denial, not "binding required"
    }

    public function test_the_owner_can_register_a_server_for_a_legacy_key_exactly_once(): void
    {
        $account = $this->tenant();
        $owner = $this->admin($account);
        $plain = 'wasaas_live_'.bin2hex(random_bytes(20));
        $key = ApiKey::create(['account_id' => $account->id, 'name' => 'Legacy', 'key_prefix' => substr($plain, 0, 20), 'key_hash' => ApiKey::hashKey($plain)]);
        $url = "/api/developer/api-keys/{$key->id}/server-binding";

        $this->actingAs($owner)->postJson($url, ['ip_policy' => 'SINGLE_IP', 'authorized_ips' => [self::SERVER_B]])->assertStatus(422)->assertJsonValidationErrors('acknowledge_server_binding');
        $r = $this->actingAs($owner)->postJson($url, ['acknowledge_server_binding' => true, 'server_label' => 'Prod', 'authorized_ips' => [self::SERVER_B]])->assertCreated();
        $credential = $r->json('installation_credential');

        $this->lead(['key' => $plain, 'credential' => $credential], self::SERVER_B)->assertCreated();
        $this->assertDenied($this->lead(['key' => $plain, 'credential' => null], self::SERVER_B, null));

        // registering again is not a way to swap servers
        $this->actingAs($owner)->postJson($url, ['acknowledge_server_binding' => true, 'authorized_ips' => [self::SERVER_C]])->assertStatus(422);
        $this->assertSame(1, ApiKeyBinding::count());
    }

    public function test_regenerating_the_profile_client_key_also_binds_it(): void
    {
        $account = $this->tenant();
        $owner = $this->admin($account);

        $this->actingAs($owner)->postJson('/api/account/api-key/regenerate')->assertStatus(422)->assertJsonValidationErrors('acknowledge_server_binding');
        $r = $this->actingAs($owner)->postJson('/api/account/api-key/regenerate', ['acknowledge_server_binding' => true, 'authorized_ips' => [self::SERVER_B]])->assertOk();

        $k = ['key' => $r->json('plain_text_key'), 'credential' => $r->json('installation_credential')];
        $this->assertNotEmpty($k['credential']);
        $this->assertDenied($this->lead($k, self::SERVER_C));
        $this->lead($k, self::SERVER_B)->assertCreated();
    }

    // ---- server change: request -> pending -> Super Admin decides ----------------------------------------------------

    private function requestChange(User $owner, int $keyId, array $over = [])
    {
        return $this->actingAs($owner)->postJson("/api/developer/api-keys/{$keyId}/server-change-requests", array_merge([
            'reason' => 'Migrating to a new data centre', 'requested_label' => 'New Production', 'requested_ips' => [self::SERVER_D],
        ], $over));
    }

    public function test_server_migration_is_pending_until_a_super_admin_approves_then_the_old_server_is_revoked_and_the_new_one_active(): void
    {
        $account = $this->tenant();
        $owner = $this->admin($account);
        $super = $this->superAdmin();
        $k = $this->createKey($owner);
        $this->lead($k, self::SERVER_B)->assertCreated();

        $req = $this->requestChange($owner, $k['id'])->assertCreated();
        $req->assertJsonPath('data.status', 'pending')->assertJsonPath('data.current_ip', self::SERVER_B)->assertJsonPath('data.current_label', 'Production Server')->assertJsonPath('data.requested_ips.0', self::SERVER_D)->assertJsonPath('data.requested_label', 'New Production');
        $id = $req->json('data.id');

        // pending: the old server keeps working, the new one does not
        $this->lead($k, self::SERVER_B)->assertCreated();
        $this->assertDenied($this->lead($k, self::SERVER_D));
        $this->actingAs($owner)->getJson("/api/developer/api-keys/{$k['id']}/server-binding")->assertJsonPath('data.pending_change_request.id', $id);

        // approval
        $this->actingAs($super)->postJson("/api/admin/api-access/change-requests/{$id}/approve", ['note' => 'verified by phone'])->assertOk()->assertJsonPath('data.status', 'approved');

        $old = ApiKeyBinding::where('status', 'revoked')->sole();
        $new = ApiKeyBinding::where('status', 'active')->sole();
        $this->assertSame(self::SERVER_B, $old->registered_ip);
        $this->assertSame('server_change_approved', $old->revoked_reason);
        $this->assertSame('New Production', $new->label);
        $this->assertSame([self::SERVER_D], $new->authorized_ips);

        // the old server stops IMMEDIATELY - even with its credential and its IP
        $this->assertDenied($this->lead($k, self::SERVER_B));
        // the new server has no credential yet: still refused
        $this->assertDenied($this->lead($k, self::SERVER_D, null));
        $this->assertDenied($this->lead($k, self::SERVER_D));                         // the OLD credential is not valid for the new binding

        // the owner collects the new credential once
        $cred = $this->actingAs($owner)->postJson("/api/developer/api-keys/{$k['id']}/installation-credential")->assertOk()->json('installation_credential');
        $this->assertNotSame($k['credential'], $cred);
        $this->lead(['key' => $k['key'], 'credential' => $cred], self::SERVER_D)->assertCreated();
        $this->actingAs($owner)->postJson("/api/developer/api-keys/{$k['id']}/installation-credential")->assertStatus(422);   // in use: cannot be re-issued
        $this->assertDenied($this->lead($k, self::SERVER_B));                         // and B stays out

        foreach (['server_change_requested', 'server_change_approved', 'server_binding_revoked', 'installation_credential_issued'] as $e) {
            $this->assertGreaterThanOrEqual(1, $this->events($e), $e);
        }
    }

    public function test_a_rejected_change_leaves_the_current_server_untouched(): void
    {
        $owner = $this->admin($this->tenant());
        $k = $this->createKey($owner);
        $this->lead($k, self::SERVER_B)->assertCreated();
        $id = $this->requestChange($owner, $k['id'])->json('data.id');

        $this->actingAs($this->superAdmin())->postJson("/api/admin/api-access/change-requests/{$id}/reject", ['note' => 'not verified'])->assertOk()->assertJsonPath('data.status', 'rejected');

        $this->assertSame(1, ApiKeyBinding::count());
        $this->lead($k, self::SERVER_B)->assertCreated();
        $this->assertDenied($this->lead($k, self::SERVER_D));
        $this->assertSame(1, $this->events('server_change_rejected'));
        // a decided request cannot be decided again
        $this->actingAs($this->superAdmin())->postJson("/api/admin/api-access/change-requests/{$id}/approve")->assertStatus(409);
        $this->assertSame(1, ApiKeyBinding::count());
        // and a new request is possible afterwards
        $this->requestChange($owner, $k['id'])->assertCreated();
    }

    public function test_a_client_cannot_move_the_key_to_another_server_by_any_route(): void
    {
        $account = $this->tenant();
        $owner = $this->admin($account);
        $k = $this->createKey($owner);
        $this->lead($k, self::SERVER_B)->assertCreated();
        $base = "/api/developer/api-keys/{$k['id']}";

        // there is no edit/rebind/revoke-binding endpoint for the buyer
        foreach (['putJson', 'patchJson', 'deleteJson'] as $verb) {
            $this->assertContains($this->actingAs($owner)->{$verb}("{$base}/server-binding", ['authorized_ips' => [self::SERVER_C]])->getStatusCode(), [404, 405]);
        }
        // registering again, re-issuing an in-use credential, unrestricted policy: all refused
        $this->actingAs($owner)->postJson("{$base}/server-binding", ['acknowledge_server_binding' => true, 'authorized_ips' => [self::SERVER_C]])->assertStatus(422);
        $this->actingAs($owner)->postJson("{$base}/installation-credential")->assertStatus(422);
        $this->requestChange($owner, $k['id'], ['ip_policy' => 'NONE'])->assertStatus(422);
        $this->requestChange($owner, $k['id'], ['reason' => ''])->assertStatus(422);
        $this->requestChange($owner, $k['id'], ['requested_ips' => []])->assertStatus(422);

        // the buyer cannot approve their own request, or reach any admin action
        $id = $this->requestChange($owner, $k['id'])->assertCreated()->json('data.id');
        $this->requestChange($owner, $k['id'])->assertStatus(422);                                    // one pending request per key
        foreach ([
            ['post', "/api/admin/api-access/change-requests/{$id}/approve"], ['post', "/api/admin/api-access/{$k['id']}/rebind"],
            ['post', "/api/admin/api-access/{$k['id']}/revoke"], ['post', "/api/admin/api-access/{$k['id']}/disable"], ['get', '/api/admin/api-access'],
        ] as [$verb, $url]) {
            $this->actingAs($owner)->{$verb.'Json'}($url, ['ip_policy' => 'NONE'])->assertStatus(403);
        }

        $this->assertSame('pending', ApiKeyChangeRequest::sole()->status);
        $this->assertSame(1, ApiKeyBinding::count());
        $this->assertDenied($this->lead($k, self::SERVER_C));
        $this->lead($k, self::SERVER_B)->assertCreated();
    }

    public function test_one_tenant_cannot_touch_another_tenants_key_binding(): void
    {
        $a = $this->admin($this->tenant());
        $k = $this->createKey($this->admin($this->tenant()));
        $base = "/api/developer/api-keys/{$k['id']}";

        $this->actingAs($a)->getJson("{$base}/server-binding")->assertNotFound();
        $this->actingAs($a)->postJson("{$base}/installation-credential")->assertNotFound();
        $this->requestChange($a, $k['id'])->assertNotFound();
        $this->assertSame(0, ApiKeyChangeRequest::count());
    }

    // ---- Super Admin management --------------------------------------------------------------------------------------

    public function test_super_admin_can_revoke_rebind_disable_and_enable_and_sees_safe_fields_only(): void
    {
        $account = $this->tenant();
        $owner = $this->admin($account);
        $super = $this->superAdmin();
        $k = $this->createKey($owner);
        $this->lead($k, self::SERVER_B)->assertCreated();

        $list = $this->actingAs($super)->getJson('/api/admin/api-access')->assertOk();
        $row = collect($list->json('data'))->firstWhere('id', $k['id']);
        $this->assertSame('active', $row['server_binding']['status']);
        $this->assertSame('Production Server', $row['server_binding']['binding']['label']);
        $this->assertSame(self::SERVER_B, $row['server_binding']['binding']['registered_ip']);
        $this->assertSame(self::SERVER_B, $row['server_binding']['binding']['last_success_ip']);
        $this->assertNotNull($row['server_binding']['binding']['registered_at']);
        foreach ([$k['key'], $k['secret'], $k['credential'], 'key_hash', 'secret_hash', 'installation_hash'] as $needle) {
            $this->assertStringNotContainsString($needle, $list->getContent());
        }

        // disable / enable
        $this->actingAs($super)->postJson("/api/admin/api-access/{$k['id']}/disable", ['reason' => 'abuse review'])->assertOk()->assertJsonPath('data.status', 'disabled');
        $this->lead($k, self::SERVER_B)->assertStatus(403)->assertJsonPath('code', 'API_ACCESS_DISABLED');
        $this->actingAs($super)->postJson("/api/admin/api-access/{$k['id']}/enable")->assertOk();
        $this->lead($k, self::SERVER_B)->assertCreated();

        // revoke the server
        $this->actingAs($super)->postJson("/api/admin/api-access/{$k['id']}/revoke", ['reason' => 'key leaked'])->assertOk()->assertJsonPath('data.status', 'revoked');
        $this->assertDenied($this->lead($k, self::SERVER_B));

        // rebind to a new server; buyer collects the credential; old credential is dead
        $this->actingAs($super)->postJson("/api/admin/api-access/{$k['id']}/rebind", ['label' => 'DR Server', 'ip_policy' => 'SINGLE_IP', 'authorized_ips' => [self::SERVER_D]])->assertOk()->assertJsonPath('data.status', 'active');
        $this->assertDenied($this->lead($k, self::SERVER_D));
        $cred = $this->actingAs($owner)->postJson("/api/developer/api-keys/{$k['id']}/installation-credential")->assertOk()->json('installation_credential');
        $this->lead(['key' => $k['key'], 'credential' => $cred], self::SERVER_D)->assertCreated();
        $this->assertDenied($this->lead($k, self::SERVER_B));

        $events = $this->actingAs($super)->getJson("/api/admin/api-access/{$k['id']}/events")->assertOk()->json('data');
        $this->assertContains('server_rebound', array_column($events, 'event'));
        $this->assertContains('api_access_disabled', array_column($events, 'event'));
    }

    public function test_super_admin_may_bind_a_legacy_key_with_an_unrestricted_policy_but_a_client_may_not(): void
    {
        $account = $this->tenant();
        $plain = 'wasaas_live_'.bin2hex(random_bytes(20));
        $key = ApiKey::create(['account_id' => $account->id, 'name' => 'Legacy', 'key_prefix' => substr($plain, 0, 20), 'key_hash' => ApiKey::hashKey($plain)]);

        $this->actingAs($this->superAdmin())->postJson("/api/admin/api-access/{$key->id}/rebind", ['ip_policy' => 'NONE', 'label' => 'Partner'])->assertOk();
        $cred = $this->actingAs($this->admin($account))->postJson("/api/developer/api-keys/{$key->id}/installation-credential")->assertOk()->json('installation_credential');

        $this->lead(['key' => $plain, 'credential' => $cred], self::SERVER_C)->assertCreated();
        $this->assertDenied($this->lead(['key' => $plain, 'credential' => null], self::SERVER_C, null));
    }

    // ---- security events never carry secrets ------------------------------------------------------------------------

    public function test_security_events_record_the_whole_lifecycle_and_never_a_secret(): void
    {
        $account = $this->tenant();
        $owner = $this->admin($account);
        $super = $this->superAdmin();
        $k = $this->createKey($owner);
        $this->lead($k, self::SERVER_B)->assertCreated();                          // authorized_server_authenticated
        $this->lead($k, self::SERVER_C, null);                                     // unauthorized_server_attempt
        $this->lead($k, self::SERVER_C);                                           // unauthorized_ip_attempt
        $id = $this->requestChange($owner, $k['id'])->json('data.id');            // server_change_requested
        $this->actingAs($super)->postJson("/api/admin/api-access/change-requests/{$id}/approve");   // approved + revoked
        $this->requestChange($owner, $k['id'])->assertCreated();
        $this->actingAs($super)->postJson('/api/admin/api-access/change-requests/'.ApiKeyChangeRequest::where('status', 'pending')->value('id').'/reject');

        foreach (['api_key_created', 'server_registered', 'authorized_server_authenticated', 'unauthorized_server_attempt', 'unauthorized_ip_attempt', 'server_change_requested', 'server_change_approved', 'server_change_rejected', 'server_binding_revoked'] as $e) {
            $this->assertGreaterThanOrEqual(1, $this->events($e), "missing security event {$e}");
        }

        $dump = json_encode(ApiKeySecurityEvent::all()->toArray());
        foreach ([$k['key'], $k['secret'], $k['credential'], 'Bearer', 'Authorization', 'password'] as $secret) {
            $this->assertStringNotContainsString($secret, $dump);
        }
        $this->assertSame([], array_diff(array_keys(array_merge(...ApiKeySecurityEvent::whereNotNull('context')->get()->pluck('context')->map(fn ($c) => (array) $c)->all())), ['label', 'ip_policy', 'request_id', 'reason', 'new_binding_id', 'old_binding_id', 'requested_ip', 'current_ip', 'client']));
    }

    public function test_repeated_refused_attempts_do_not_flood_the_event_log(): void
    {
        $k = $this->createKey($this->admin($this->tenant()));
        for ($i = 0; $i < 25; $i++) {
            $this->lead($k, self::SERVER_C, null);
        }
        $this->assertSame(1, $this->events('unauthorized_server_attempt'));
    }

    public function test_the_audit_and_request_logs_never_store_the_installation_credential(): void
    {
        $k = $this->createKey($this->admin($this->tenant()));
        $this->lead($k, self::SERVER_B)->assertCreated();

        foreach (['api_request_logs', 'activity_logs'] as $table) {
            if (! \Illuminate\Support\Facades\Schema::hasTable($table)) {
                continue;
            }
            $dump = json_encode(DB::table($table)->get());
            foreach ([$k['credential'], $k['key'], $k['secret']] as $plain) {
                $this->assertStringNotContainsString($plain, $dump, "{$table} holds a plaintext credential");
            }
        }
    }

    public function test_the_binding_gate_lives_in_the_shared_authentication_boundary_not_in_controllers(): void
    {
        foreach (glob(app_path('Http/Controllers/Api/V1/*.php')) as $file) {
            $this->assertStringNotContainsString('ApiKeyBinding', (string) file_get_contents($file), basename($file).' must not duplicate the binding check');
        }
        foreach (['AuthenticateApiKey', 'ApiAuthMiddleware'] as $mw) {
            $this->assertStringContainsString('ApiKeyBindingService', (string) file_get_contents(app_path("Http/Middleware/{$mw}.php")));
        }
    }
}
