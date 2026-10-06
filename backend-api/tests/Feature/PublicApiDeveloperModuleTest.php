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
    use \Tests\Concerns\AllowsUnboundApiKeys;

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
    /** The Developer API is part of the plan: the account gets the external_api entitlement a paid plan carries. */
    private function grantApiPlan(Account $account): void
    {
        \App\Models\AccountEntitlement::firstOrCreate(
            ['account_id' => $account->id, 'capability_id' => \App\Models\Capability::where('slug', 'external_api')->firstOrFail()->id],
            ['source' => 'plan', 'granted_by_account_id' => null],
        );
    }

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

        $this->grantApiPlan($account);

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

        // A valid key is authenticated: the module list does not refuse it. The send then stops at the WhatsApp session.
        $this->sendTemplate($issued['key'])->assertStatus(422)->assertJsonPath('message', 'Session disconnected');

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

        // No plan means no key capability: the call is refused before any idempotency record is written.
        \App\Models\AccountEntitlement::where('account_id', $account->id)->delete();
        Subscription::query()->where('account_id', $account->id)->delete();
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

        $account->forceFill(['authorized_server_ip' => '203.0.113.10'])->save();
        $response = $this->actingAs($admin)->postJson('/api/account/api-key/regenerate', ['acknowledge_ip_restriction' => true])->assertOk();

        $this->assertStringStartsWith('wasaas_live_', $response->json('plain_text_key'));
        $this->assertSame(1, ApiKey::where('account_id', $account->id)->count());
        $this->actingAs($admin)->getJson('/api/account/api-key')->assertOk()->assertJsonPath('data.key_prefix', $response->json('data.key_prefix'));
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
        // A lapsed plan keeps the key visible but refuses a new one until renewal.
        $account = $this->tenant();
        Subscription::query()->where('account_id', $account->id)->update(['status' => 'expired', 'expires_at' => now()->subDay()]);
        $admin = $this->user($account);

        $this->actingAs($admin)->postJson('/api/account/api-key/regenerate')
            ->assertStatus(403)->assertJsonPath('error_code', 'SUBSCRIPTION_EXPIRED');

        $this->assertSame(0, ApiKey::where('account_id', $account->id)->count());
        // Reading the existing key stays possible for an expired account (module still required).
        $this->actingAs($admin)->getJson('/api/account/api-key')->assertOk();
    }

    public function test_the_module_list_does_not_gate_the_key_the_plan_does(): void
    {
        // The API key comes with the plan. A module list without developer_api does not refuse it.
        $account = $this->tenant(self::WITHOUT_DEVELOPER_API);
        $issued = $this->issueApiKey($account);

        $this->withHeaders(['X-API-KEY' => $issued['key'], 'Idempotency-Key' => 'module-'.Str::random(8)])
            ->postJson('/api/v1/messages/send-template', ['template_code' => 'WELCOME', 'recipient_phone' => self::RECIPIENT])
            ->assertStatus(422)->assertJsonPath('message', 'Session disconnected');
    }
}
