<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\ActivityLog;
use App\Models\ApiIdempotencyKey;
use App\Models\ApiKey;
use App\Models\MessageDispatchLog;
use App\Models\MessageTemplate;
use App\Models\Subscription;
use App\Models\User;
use App\Models\WhatsAppSession;
use App\Services\Access\EntitlementAuditLogger;
use Database\Seeders\Phase1FoundationSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Phase 5 P5-B — the `developer_api` module is enforced on the whole
 * Public API (/api/v1, both the single-factor and the dual-factor tier)
 * and on the Client API Key endpoints (/api/account/api-key), through the
 * existing `module.apikey` / `module.guard` middlewares.
 */
class PublicApiDeveloperModuleTest extends TestCase
{
    use RefreshDatabase;

    private const RECIPIENT = '919999999999';

    /** Every module except developer_api. */
    private const WITHOUT_DEVELOPER_API = ['dashboard', 'send_alert', 'contact_groups', 'lead_crm', 'templates', 'billing'];

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        $this->seed(Phase1FoundationSeeder::class);
    }

    // ---------------------------------------------------------------
    // Fixtures
    // ---------------------------------------------------------------

    /** @param list<string>|null $modules null = default (every module) */
    private function tenant(?array $modules = null, bool $subscribed = true, bool $connected = true): Account
    {
        $account = Account::factory()->create(['allowed_modules' => $modules]);
        if ($subscribed) {
            Subscription::factory()->create(['account_id' => $account->id]); // QR engine
        }
        if ($connected) {
            WhatsAppSession::create(['account_id' => $account->id, 'status' => 'connected']);
        }
        MessageTemplate::create([
            'account_id' => $account->id, 'template_code' => 'WELCOME', 'title' => 'Welcome',
            'template_body' => 'Hello there.', 'status' => 'approved',
        ]);

        return $account->fresh();
    }

    /** @return array{model: ApiKey, key: string, secret: string} */
    private function issueApiKey(Account $account): array
    {
        $plainKey = 'wasaas_live_'.Str::random(40);
        $plainSecret = 'wasaas_secret_'.Str::random(40);

        $model = ApiKey::create([
            'account_id' => $account->id,
            'name' => 'Test Key',
            'key_prefix' => substr($plainKey, 0, 20),
            'key_hash' => ApiKey::hashKey($plainKey),
            'secret_prefix' => substr($plainSecret, 0, 20),
            'secret_hash' => ApiKey::hashSecret($plainSecret),
        ]);

        return ['model' => $model, 'key' => $plainKey, 'secret' => $plainSecret];
    }

    private function user(Account $account, string $role = 'admin'): User
    {
        $user = User::factory()->create(['account_id' => $account->id]);
        $user->assignRole($role);

        return $user;
    }

    private function fakeQrEngine(): void
    {
        Http::fake(['*' => Http::response(['success' => true, 'message_id' => 'QR_OK'], 200)]);
    }

    private function sendTemplate(string $key, array $headers = [])
    {
        return $this->withHeaders(['X-API-KEY' => $key] + $headers)
            ->postJson('/api/v1/messages/send-template', ['template_code' => 'WELCOME', 'recipient_phone' => self::RECIPIENT]);
    }

    private function moduleDeniedAudits(Account $account): int
    {
        return ActivityLog::where('module_name', EntitlementAuditLogger::MODULE)
            ->where('account_id', $account->id)
            ->where('action_type', 'denied')
            ->get()
            ->filter(fn ($row) => ($row->new_values['module'] ?? null) === 'developer_api')
            ->count();
    }

    // ---------------------------------------------------------------
    // /api/v1 — single-factor tier
    // ---------------------------------------------------------------

    public function test_v1_messaging_succeeds_with_the_developer_api_module(): void
    {
        $this->fakeQrEngine();
        $account = $this->tenant();
        $issued = $this->issueApiKey($account);

        $this->sendTemplate($issued['key'])->assertOk()->assertJson(['status' => true]);

        $this->assertSame(1, MessageDispatchLog::where('account_id', $account->id)->where('status', 'sent')->count());
    }

    public function test_v1_messaging_is_denied_without_the_developer_api_module(): void
    {
        $this->fakeQrEngine();
        $account = $this->tenant(self::WITHOUT_DEVELOPER_API);
        $issued = $this->issueApiKey($account);

        $this->sendTemplate($issued['key'])
            ->assertStatus(403)
            ->assertJson(['success' => false, 'error_code' => 'MODULE_DISABLED']);

        Http::assertNothingSent();
        $this->assertSame(0, MessageDispatchLog::where('account_id', $account->id)->count());
        $this->assertSame(0, (int) Subscription::where('account_id', $account->id)->value('used_messages'));
        $this->assertSame(1, $this->moduleDeniedAudits($account));
    }

    public function test_every_v1_route_honours_the_module_switch(): void
    {
        $account = $this->tenant(self::WITHOUT_DEVELOPER_API);
        $issued = $this->issueApiKey($account);
        $single = ['X-API-KEY' => $issued['key']];
        $dual = ['X-API-KEY' => $issued['key'], 'X-API-SECRET' => $issued['secret']];

        $calls = [
            ['POST', '/api/v1/messages/send-payment-alert', $single, ['recipient_phone' => self::RECIPIENT, 'customer_name' => 'A', 'amount' => 10, 'payment_ref' => 'R1']],
            ['POST', '/api/v1/send-message', $single, ['recipient_type' => 'individual', 'template_code' => 'WELCOME', 'recipient_phone' => self::RECIPIENT]],
            ['POST', '/api/v1/crm/leads', $single, ['name' => 'Lead', 'phone' => self::RECIPIENT]],
            ['GET', '/api/v1/crm/leads/1', $single, []],
            ['POST', '/api/v1/whatsapp/groups/create', $dual, ['name' => 'Crew']],
            ['POST', '/api/v1/whatsapp/messages/send', $dual, ['recipient_type' => 'individual', 'message_type' => 'text', 'to' => self::RECIPIENT, 'text' => ['body' => 'Hi']]],
        ];

        foreach ($calls as [$method, $uri, $headers, $body]) {
            $this->withHeaders($headers)->json($method, $uri, $body)
                ->assertStatus(403)
                ->assertJsonPath('error_code', 'MODULE_DISABLED');
        }

        $this->assertSame(0, MessageDispatchLog::where('account_id', $account->id)->count());
    }

    public function test_the_dual_factor_tier_still_works_with_the_module(): void
    {
        $this->fakeQrEngine();
        $account = $this->tenant();
        $issued = $this->issueApiKey($account);

        $this->withHeaders(['X-API-KEY' => $issued['key'], 'X-API-SECRET' => $issued['secret']])
            ->postJson('/api/v1/whatsapp/messages/send', [
                'recipient_type' => 'individual', 'message_type' => 'text',
                'to' => self::RECIPIENT, 'text' => ['body' => 'Hi'],
            ])
            ->assertOk()->assertJsonPath('success', true);
    }

    public function test_api_key_authentication_is_unchanged(): void
    {
        $account = $this->tenant(self::WITHOUT_DEVELOPER_API);
        $issued = $this->issueApiKey($account);

        // A bad key is still refused by authentication (401), before the module gate.
        $this->sendTemplate('wasaas_live_totally_bogus_key')->assertStatus(401);

        // A valid key of a module-disabled account is authenticated, then refused (403, not 401).
        $this->sendTemplate($issued['key'])->assertStatus(403);

        // The key itself is untouched: switching the module back on restores access.
        $this->fakeQrEngine();
        $account->forceFill(['allowed_modules' => null])->save();
        $this->sendTemplate($issued['key'])->assertOk();
        $this->assertNull($issued['model']->fresh()->revoked_at ?? null);
    }

    public function test_a_denied_call_stores_no_idempotency_record(): void
    {
        $account = $this->tenant(self::WITHOUT_DEVELOPER_API);
        $issued = $this->issueApiKey($account);

        $this->sendTemplate($issued['key'], ['Idempotency-Key' => 'p5b-denied'])->assertStatus(403);

        $this->assertSame(0, ApiIdempotencyKey::where('idempotency_key', 'p5b-denied')->count());
    }

    // ---------------------------------------------------------------
    // /api/account/api-key
    // ---------------------------------------------------------------

    public function test_api_key_regeneration_succeeds_with_module_permission_and_subscription(): void
    {
        $account = $this->tenant();
        $admin = $this->user($account);

        $response = $this->actingAs($admin)->postJson('/api/account/api-key/regenerate')->assertOk();

        $this->assertStringStartsWith('wasaas_live_', $response->json('plain_text_key'));
        $this->assertSame(1, ApiKey::where('account_id', $account->id)->count());
        $this->actingAs($admin)->getJson('/api/account/api-key')->assertOk()->assertJsonPath('data.key_prefix', $response->json('data.key_prefix'));
    }

    public function test_api_key_regeneration_is_denied_without_the_module(): void
    {
        $account = $this->tenant(self::WITHOUT_DEVELOPER_API);
        $admin = $this->user($account);

        $this->actingAs($admin)->postJson('/api/account/api-key/regenerate')
            ->assertStatus(403)->assertJsonPath('error_code', 'MODULE_DISABLED');
        $this->actingAs($admin)->getJson('/api/account/api-key')
            ->assertStatus(403)->assertJsonPath('error_code', 'MODULE_DISABLED');

        $this->assertSame(0, ApiKey::where('account_id', $account->id)->count());
    }

    public function test_api_key_regeneration_is_denied_without_the_permission(): void
    {
        $account = $this->tenant();
        $plainUser = $this->user($account, 'user'); // no manage-developer-settings

        $this->actingAs($plainUser)->postJson('/api/account/api-key/regenerate')->assertStatus(403);

        $this->assertSame(0, ApiKey::where('account_id', $account->id)->count());
    }

    public function test_api_key_regeneration_is_denied_without_an_active_subscription(): void
    {
        $account = $this->tenant(subscribed: false);
        $admin = $this->user($account);

        $this->actingAs($admin)->postJson('/api/account/api-key/regenerate')
            ->assertStatus(403)->assertJsonPath('error_code', 'SUBSCRIPTION_EXPIRED');

        $this->assertSame(0, ApiKey::where('account_id', $account->id)->count());
        // Reading the existing key stays possible for an expired account (module still required).
        $this->actingAs($admin)->getJson('/api/account/api-key')->assertOk();
    }

    public function test_an_agent_cannot_mint_a_key_for_a_module_disabled_sub_client(): void
    {
        $agent = Account::factory()->agent()->create();
        Subscription::factory()->create(['account_id' => $agent->id]);
        $client = Account::factory()->client($agent)->create(['allowed_modules' => self::WITHOUT_DEVELOPER_API]);
        Subscription::factory()->create(['account_id' => $client->id]);
        $agentAdmin = User::factory()->create(['account_id' => $agent->id]);
        $agentAdmin->assignRole(['admin', 'agent']);

        $this->actingAs($agentAdmin)->postJson("/api/account/api-key/regenerate?account_id={$client->id}")
            ->assertStatus(403)->assertJsonPath('error_code', 'MODULE_DISABLED');

        $this->assertSame(0, ApiKey::where('account_id', $client->id)->count());
    }
}
