<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\AccountEntitlement;
use App\Models\ApiKey;
use App\Models\Capability;
use App\Models\Contact;
use App\Models\CrmLead;
use App\Models\MessageTemplate;
use App\Models\Subscription;
use Database\Seeders\Phase1FoundationSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

/**
 * Phase 12 Task 6 — public /api/v1 contract. Pins what the existing implementation does today (route and
 * middleware inventory, per-tier authentication envelopes, validation envelopes, quota gates, the CRM v1
 * response shape, tenant isolation, rate limit) so a later change cannot alter the public contract silently.
 * It asserts the CURRENT contract, including its deliberate inconsistencies (status vs success envelopes).
 */
#[Group('release-safety')]
class PublicApiV1ContractTest extends TestCase
{
    use \Tests\Concerns\AllowsUnboundApiKeys;

    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        $this->seed(Phase1FoundationSeeder::class);
    }

    private function account(bool $crm = true, bool $subscribed = true, array $attributes = []): Account
    {
        $account = Account::factory()->create($attributes);
        if ($subscribed) {
            Subscription::factory()->create(['account_id' => $account->id]);
        }
        if ($crm) {
            AccountEntitlement::firstOrCreate(
                ['account_id' => $account->id, 'capability_id' => Capability::where('slug', 'crm')->firstOrFail()->id],
                ['source' => 'manual_grant', 'granted_by_account_id' => null],
            );
        }

        return $account->fresh();
    }

    /** @return array{key: string, secret: string, model: ApiKey} */
    private function issue(Account $account, bool $secret = true, array $overrides = []): array
    {
        $key = 'wasaas_live_'.Str::random(40);
        $sec = 'wasaas_secret_'.Str::random(40);
        $model = ApiKey::create(array_merge([
            'account_id' => $account->id, 'name' => 'Contract Key', 'key_prefix' => substr($key, 0, 20), 'key_hash' => ApiKey::hashKey($key),
            'secret_prefix' => $secret ? substr($sec, 0, 20) : null, 'secret_hash' => $secret ? ApiKey::hashSecret($sec) : null,
        ], $overrides));

        return ['key' => $key, 'secret' => $sec, 'model' => $model];
    }

    /** One request, with no header carry-over from previous requests in the same test. */
    private function send(string $method, string $uri, array $body = [], array $headers = [])
    {
        $this->flushHeaders();
        $this->app['auth']->forgetGuards();

        return $this->withHeaders($headers + ['Accept' => 'application/json'])->json($method, $uri, $body);
    }

    private function template(Account $account, string $code = 'T6_TPL'): MessageTemplate
    {
        return MessageTemplate::create(['account_id' => $account->id, 'template_code' => $code, 'title' => $code, 'template_body' => 'Hello, this is a test.', 'status' => 'approved']);
    }

    // ---- inventory --------------------------------------------------------------------------------------------

    public function test_the_v1_route_surface_and_its_gates_are_exactly_the_documented_contract(): void
    {
        $routes = collect(Route::getRoutes()->getRoutes())->filter(fn ($r) => str_starts_with($r->uri(), 'api/v1/'));

        $actual = $routes->map(fn ($r) => implode('|', array_diff($r->methods(), ['HEAD'])).' '.$r->uri())->sort()->values()->all();
        $this->assertSame([
            'DELETE api/v1/crm/leads/{id}/tags/{tag}',
            'DELETE api/v1/scheduled-messages/{id}',
            'GET api/v1/crm/leads/{id}',
            'PATCH api/v1/crm/leads/{id}/assignee',
            'PATCH api/v1/crm/leads/{id}/status',
            'POST api/v1/crm/leads',
            'POST api/v1/crm/leads/{id}/tags/{tag}',
            'POST api/v1/messages/send-payment-alert',
            'POST api/v1/messages/send-template',
            'POST api/v1/send-message',
            'POST api/v1/whatsapp/groups/create',
            'POST api/v1/whatsapp/messages/send',
        ], $actual, 'the public /v1 surface changed - this is a contract change that needs an explicit decision');

        $tierOf = fn ($r) => collect($r->gatherMiddleware())->first(fn ($m) => in_array($m, ['auth.apikey', 'auth.apisecret'], true));
        foreach ($routes as $r) {
            $mw = $r->gatherMiddleware();
            $this->assertNotNull($tierOf($r), $r->uri().' has no API-key authentication');
            foreach (['log.apirequest', 'throttle:external-api', 'api.key.access', 'idempotency'] as $required) {
                $this->assertContains($required, $mw, "{$r->uri()} lacks {$required}");
            }
            $this->assertNotContains('auth:sanctum', $mw, "{$r->uri()} must not accept session tokens");
            $dual = str_starts_with($r->uri(), 'api/v1/whatsapp/');
            $this->assertSame($dual ? 'auth.apisecret' : 'auth.apikey', $tierOf($r), "{$r->uri()}: authentication tier changed");
        }
        foreach ($routes->filter(fn ($r) => str_starts_with($r->uri(), 'api/v1/crm/')) as $r) {
            foreach (['subscription.apikey', 'module.apikey:lead_crm', 'capability.apikey:crm'] as $gate) {
                $this->assertContains($gate, $r->gatherMiddleware(), "{$r->uri()} lacks {$gate}");
            }
        }
    }

    // ---- authentication ---------------------------------------------------------------------------------------

    public function test_every_v1_route_rejects_a_missing_unknown_or_revoked_key_with_401(): void
    {
        $account = $this->account();
        $revoked = $this->issue($account, overrides: ['revoked_at' => now()]);

        foreach (Route::getRoutes()->getRoutes() as $route) {
            if (! str_starts_with($route->uri(), 'api/v1/')) {
                continue;
            }
            $method = array_values(array_diff($route->methods(), ['HEAD']))[0];
            $uri = str_replace(['{id}', '{tag}'], ['1', '1'], $route->uri());
            foreach ([[], ['X-API-KEY' => 'wasaas_live_unknown', 'X-API-SECRET' => 'x'], ['X-API-KEY' => $revoked['key'], 'X-API-SECRET' => $revoked['secret']]] as $headers) {
                $this->send($method, '/'.$uri, [], $headers)->assertStatus(401);
            }
        }
    }

    public function test_the_two_authentication_tiers_use_their_own_error_envelopes(): void
    {
        $this->send('POST', '/api/v1/messages/send-template', [])
            ->assertStatus(401)->assertJsonStructure(['status', 'message'])->assertJson(['status' => false]);

        $this->send('POST', '/api/v1/whatsapp/groups/create', ['name' => 'x'])
            ->assertStatus(401)->assertJson(['success' => false, 'error_code' => 'UNAUTHORIZED']);
    }

    public function test_the_dual_factor_tier_requires_a_secret_the_key_actually_has(): void
    {
        $account = $this->account();
        $noSecret = $this->issue($account, secret: false);
        $withSecret = $this->issue($account);

        $this->send('POST', '/api/v1/whatsapp/groups/create', ['name' => 'G'], ['X-API-KEY' => $noSecret['key'], 'X-API-SECRET' => 'guess'])
            ->assertStatus(401)->assertJson(['error_code' => 'UNAUTHORIZED']);
        $this->send('POST', '/api/v1/whatsapp/groups/create', ['name' => 'G'], ['X-API-KEY' => $withSecret['key']])
            ->assertStatus(401);
        $this->send('POST', '/api/v1/whatsapp/groups/create', ['name' => 'G'], ['X-API-KEY' => $withSecret['key'], 'X-API-SECRET' => 'wrong'])
            ->assertStatus(401);
        $this->send('POST', '/api/v1/whatsapp/groups/create', ['name' => 'G'], ['X-API-KEY' => $withSecret['key'], 'X-API-SECRET' => $withSecret['secret']])
            ->assertCreated()->assertJson(['success' => true]);
    }

    public function test_a_suspended_account_and_a_disabled_developer_api_module_are_refused(): void
    {
        $suspended = $this->issue($this->account(attributes: ['status' => 'suspended']));
        $this->send('POST', '/api/v1/messages/send-template', ['template_code' => 'X', 'recipient_phone' => '919999999999'], ['X-API-KEY' => $suspended['key']])
            ->assertStatus(401);

        $noDev = $this->issue($this->account(subscribed: false, attributes: ['allowed_modules' => ['dashboard', 'send_alert']]));
        $r = $this->send('POST', '/api/v1/messages/send-template', ['template_code' => 'X', 'recipient_phone' => '919999999999'], ['X-API-KEY' => $noDev['key']]);
        $this->assertContains($r->getStatusCode(), [402, 403], 'an account that never bought a plan must be refused');
        $this->assertStringNotContainsString($noDev['key'], $r->getContent());
    }

    public function test_authentication_failures_never_echo_credentials(): void
    {
        $key = 'wasaas_live_LEAKCHECK_'.Str::random(20);
        $secret = 'wasaas_secret_LEAKCHECK_'.Str::random(20);
        foreach (['/api/v1/messages/send-template', '/api/v1/whatsapp/messages/send'] as $uri) {
            $body = $this->send('POST', $uri, [], ['X-API-KEY' => $key, 'X-API-SECRET' => $secret])->assertStatus(401)->getContent();
            $this->assertStringNotContainsString('LEAKCHECK', $body);
        }
    }

    // ---- validation -------------------------------------------------------------------------------------------

    public function test_validation_errors_keep_each_endpoints_existing_envelope(): void
    {
        $account = $this->account();
        $k = $this->issue($account);
        $single = ['X-API-KEY' => $k['key']];
        $dual = ['X-API-KEY' => $k['key'], 'X-API-SECRET' => $k['secret']];

        $this->send('POST', '/api/v1/messages/send-template', ['recipient_phone' => '919999999999'], $single)
            ->assertStatus(422)->assertJsonStructure(['status', 'message', 'errors'])->assertJson(['status' => false])->assertJsonStructure(['errors' => ['template_code']]);

        $this->send('POST', '/api/v1/send-message', ['recipient_type' => 'individual'], $single)
            ->assertStatus(422)->assertJsonStructure(['success', 'message', 'errors'])->assertJson(['success' => false]);

        $this->send('POST', '/api/v1/whatsapp/groups/create', [], $dual)
            ->assertStatus(422)->assertJsonStructure(['message', 'errors']);

        $this->send('POST', '/api/v1/whatsapp/messages/send', [], $dual)
            ->assertStatus(422)->assertJsonStructure(['message', 'errors']);

        $this->send('POST', '/api/v1/crm/leads', [], $single)
            ->assertStatus(422)->assertJsonStructure(['message', 'errors' => ['phone_number']]);
        $this->send('POST', '/api/v1/crm/leads', ['phone_number' => '919876543210', 'status' => 'nonsense', 'email' => 'not-an-email'], $single)
            ->assertStatus(422)->assertJsonValidationErrors(['status', 'email']);
    }

    // ---- quota ------------------------------------------------------------------------------------------------

    public function test_quota_exhaustion_is_refused_with_each_endpoints_existing_status_and_code(): void
    {
        $account = $this->account();
        Subscription::query()->where('account_id', $account->id)->update(['status' => 'expired', 'expires_at' => now()->subDay()]);
        $this->template($account);
        $k = $this->issue($account);

        $this->send('POST', '/api/v1/messages/send-template', ['template_code' => 'T6_TPL', 'recipient_phone' => '919999999999'], ['X-API-KEY' => $k['key']])
            ->assertStatus(403)->assertJson(['status' => false]);

        $this->send('POST', '/api/v1/send-message', ['recipient_type' => 'individual', 'template_code' => 'T6_TPL', 'recipient_phone' => '919999999999'], ['X-API-KEY' => $k['key']])
            ->assertStatus(402)->assertJson(['success' => false, 'error_code' => 'INSUFFICIENT_QUOTA']);

        $this->send('POST', '/api/v1/whatsapp/messages/send', ['recipient_type' => 'individual', 'message_type' => 'text', 'to' => '919999999999', 'text' => ['body' => 'hi']], ['X-API-KEY' => $k['key'], 'X-API-SECRET' => $k['secret']])
            ->assertStatus(402)->assertJson(['success' => false, 'error_code' => 'INSUFFICIENT_QUOTA']);
    }

    public function test_an_exhausted_message_quota_is_not_consumed_by_a_refused_request(): void
    {
        $account = $this->account();
        $sub = $account->currentSubscription;
        $sub->forceFill(['total_allocated_messages' => 5, 'used_messages' => 5])->save();
        $this->template($account);
        $k = $this->issue($account);
        $before = $sub->fresh()->used_messages;

        $r = $this->send('POST', '/api/v1/send-message', ['recipient_type' => 'individual', 'template_code' => 'T6_TPL', 'recipient_phone' => '919999999999'], ['X-API-KEY' => $k['key']]);

        $r->assertStatus(402)->assertJson(['error_code' => 'INSUFFICIENT_QUOTA']);
        $this->assertSame($before, $sub->fresh()->used_messages);
    }

    public function test_crm_writes_need_an_active_subscription_but_reads_do_not(): void
    {
        $account = $this->account();
        Subscription::query()->where('account_id', $account->id)->update(['status' => 'expired', 'expires_at' => now()->subDay()]);
        $lead = CrmLead::factory()->forContact(Contact::factory()->forAccount($account)->create())->create();
        $k = $this->issue($account);
        $h = ['X-API-KEY' => $k['key']];

        $this->send('GET', "/api/v1/crm/leads/{$lead->id}", [], $h)->assertOk();
        $this->send('POST', '/api/v1/crm/leads', ['phone_number' => '919876543210'], $h)
            ->assertStatus(403)->assertJson(['error_code' => 'SUBSCRIPTION_EXPIRED']);
        $this->send('PATCH', "/api/v1/crm/leads/{$lead->id}/status", ['status' => 'contacted'], $h)
            ->assertStatus(403)->assertJson(['error_code' => 'SUBSCRIPTION_EXPIRED']);
        $this->assertSame(1, CrmLead::where('account_id', $account->id)->count());
    }

    // ---- CRM v1 contract --------------------------------------------------------------------------------------

    public function test_creating_a_crm_lead_returns_the_documented_201_structure_and_forces_source_and_tenant(): void
    {
        $account = $this->account();
        $other = $this->account();
        $k = $this->issue($account);

        $r = $this->send('POST', '/api/v1/crm/leads', [
            'phone_number' => '+91 98765 43210', 'name' => 'Bob', 'email' => 'bob@example.test',
            'source' => 'meta_ad', 'account_id' => $other->id,
        ], ['X-API-KEY' => $k['key']]);

        $r->assertCreated()->assertJson(['success' => true])
            ->assertJsonStructure(['success', 'message', 'data' => ['id', 'status', 'source', 'contact', 'created_at']])
            ->assertJsonPath('data.source', CrmLead::SOURCE_API)
            ->assertJsonPath('data.status', 'new');
        $lead = CrmLead::findOrFail($r->json('data.id'));
        $this->assertSame($account->id, $lead->account_id, 'the tenant comes from the key, never the body');
        $this->assertSame(0, CrmLead::where('account_id', $other->id)->count());
    }

    public function test_crm_routes_enforce_capability_and_module_gates_with_stable_codes(): void
    {
        $noCrm = $this->issue($this->account(crm: false));
        $this->send('POST', '/api/v1/crm/leads', ['phone_number' => '919876543210'], ['X-API-KEY' => $noCrm['key']])
            ->assertStatus(403)->assertJson(['error_code' => 'CAPABILITY_NOT_ENTITLED']);

        $noModule = $this->issue($this->account(attributes: ['allowed_modules' => ['dashboard', 'developer_api']]));
        $this->send('POST', '/api/v1/crm/leads', ['phone_number' => '919876543210'], ['X-API-KEY' => $noModule['key']])
            ->assertStatus(403)->assertJson(['error_code' => 'MODULE_DISABLED']);
    }

    public function test_crm_lead_lookups_have_a_uniform_404_for_bad_unknown_and_foreign_ids(): void
    {
        $a = $this->account();
        $b = $this->account();
        $foreign = CrmLead::factory()->forContact(Contact::factory()->forAccount($b)->create())->create();
        $k = $this->issue($a);
        $h = ['X-API-KEY' => $k['key']];

        $responses = [
            $this->send('GET', '/api/v1/crm/leads/abc', [], $h),
            $this->send('GET', '/api/v1/crm/leads/999999', [], $h),
            $this->send('GET', "/api/v1/crm/leads/{$foreign->id}", [], $h),
        ];
        foreach ($responses as $r) {
            $r->assertStatus(404);
        }
        $this->assertSame($responses[1]->json('message'), $responses[2]->json('message'), 'a foreign lead must be indistinguishable from a missing one');

        $this->send('PATCH', "/api/v1/crm/leads/{$foreign->id}/status", ['status' => 'contacted'], $h)->assertStatus(404);
        $this->assertSame('new', $foreign->fresh()->status);
    }

    public function test_an_idempotency_key_makes_a_retried_lead_creation_create_one_lead_and_replay_the_response(): void
    {
        $account = $this->account();
        $k = $this->issue($account);
        $h = ['X-API-KEY' => $k['key'], 'Idempotency-Key' => 'task6-'.Str::random(12)];
        $body = ['phone_number' => '919876543210', 'name' => 'Once'];

        $first = $this->send('POST', '/api/v1/crm/leads', $body, $h)->assertCreated();
        $second = $this->send('POST', '/api/v1/crm/leads', $body, $h);

        $this->assertSame(1, CrmLead::where('account_id', $account->id)->count());
        $this->assertSame($first->json('data.id'), $second->json('data.id'));
        $this->assertSame(201, $second->getStatusCode());
    }

    public function test_reusing_an_idempotency_key_with_a_different_body_is_refused_not_executed(): void
    {
        $account = $this->account();
        $k = $this->issue($account);
        $h = ['X-API-KEY' => $k['key'], 'Idempotency-Key' => 'task6-reuse-1'];

        $this->send('POST', '/api/v1/crm/leads', ['phone_number' => '919876543210'], $h)->assertCreated();
        $r = $this->send('POST', '/api/v1/crm/leads', ['phone_number' => '919876500000'], $h);

        $this->assertSame(409, $r->getStatusCode());
        $this->assertSame(1, CrmLead::where('account_id', $account->id)->count());
    }

    // ---- rate limit -------------------------------------------------------------------------------------------

    public function test_the_per_account_rate_limit_returns_the_existing_429_body(): void
    {
        $account = $this->account(attributes: ['api_rate_limit_per_minute' => 2]);
        $k = $this->issue($account);
        $h = ['X-API-KEY' => $k['key']];

        foreach ([1, 2] as $_) {
            $this->assertNotSame(429, $this->send('POST', '/api/v1/messages/send-template', [], $h)->getStatusCode());
        }
        $this->send('POST', '/api/v1/messages/send-template', [], $h)
            ->assertStatus(429)->assertExactJson(['message' => 'Rate limit exceeded. Please slow down your requests.']);

        // another tenant is unaffected
        $other = $this->issue($this->account());
        $this->assertNotSame(429, $this->send('POST', '/api/v1/messages/send-template', [], ['X-API-KEY' => $other['key']])->getStatusCode());
    }
}
