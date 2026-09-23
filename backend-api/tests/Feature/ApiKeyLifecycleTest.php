<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\ApiKey;
use App\Models\Invoice;
use App\Models\MessageTemplate;
use App\Models\User;
use App\Services\Billing\InvoiceCreditService;
use App\Support\PlanCatalog;
use Database\Seeders\Phase1FoundationSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Phase 3 Task 2 -- Public API Key lifecycle hardening. Inspection found
 * every requirement (hashed-only storage, one-time secret reveal, no
 * secret in listing/logs/audit trail, immediate revocation, expiry
 * enforcement, creation permanently locked to the caller's own tenant)
 * already correctly implemented by ApiKey / ApiKeyController /
 * ClientApiKeyController / AuthenticateApiKey / ApiAuthMiddleware / the
 * LogsActivity trait -- see this task's own audit report. No production
 * code changed; this file adds the missing regression coverage for the
 * lifecycle stages tests/Feature/PublicApiSecurityTest.php's external-
 * API-caller-side tests don't already exercise: key creation, the
 * admin-facing listing/show endpoints, revocation taking effect
 * mid-session, tenant-locked creation, query-string/header injection
 * (that file only covers request-body injection), and the dual-factor
 * flow's POSITIVE path (that file only covers its failure paths).
 * Authentication itself (valid/invalid/revoked/expired key, Bearer,
 * X-API-KEY) is already covered there and is re-run, not duplicated,
 * per this task's own "Also verify existing Public API tests remain
 * green" instruction.
 */
class ApiKeyLifecycleTest extends TestCase
{
    use RefreshDatabase;

    private const KEY_PREFIX = 'wasaas_live_';

    private const SECRET_PREFIX = 'wasaas_secret_';

    protected function setUp(): void
    {
        parent::setUp();
        /*
         * Phase 5 Task 11 — the `plans` table is now the runtime source of
         * truth for checkout AND fulfilment, so markPaidAndCreditQuota()
         * can no longer credit a payment on an unseeded database. Real
         * environments always have this seeded; seeding it here makes the
         * fixture match production rather than relying on the static
         * PlanCatalog the cutover removed from every runtime path.
         */
        $this->seed(Phase1FoundationSeeder::class);
        $this->seed(RolePermissionSeeder::class);
    }

    private function makeAdmin(Account $account): User
    {
        $user = User::factory()->create(['account_id' => $account->id]);
        $user->assignRole('admin');

        return $user;
    }

    /** Same convention as PublicApiSecurityTest -- a real active subscription via the actual payment-success path, not a hand-rolled Subscription row. */
    private function giveActiveSubscription(Account $account, string $planKey = 'starter'): void
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

    private function makeApprovedTemplate(?int $accountId, string $code): MessageTemplate
    {
        return MessageTemplate::create([
            'account_id' => $accountId,
            'template_code' => $code,
            'title' => $code,
            'template_body' => 'Hello, this is a test.',
            'status' => 'approved',
        ]);
    }

    /** Mirrors ApiKeyController::store()'s own key/secret minting convention exactly -- no ApiKeyFactory exists in this codebase. */
    private function issueApiKey(Account $account, bool $withSecret = true, array $overrides = []): array
    {
        $plainKey = self::KEY_PREFIX.Str::random(40);
        $plainSecret = self::SECRET_PREFIX.Str::random(40);

        $apiKey = ApiKey::create(array_merge([
            'account_id' => $account->id,
            'name' => 'Test Key',
            'key_prefix' => substr($plainKey, 0, 20),
            'key_hash' => ApiKey::hashKey($plainKey),
            'secret_prefix' => $withSecret ? substr($plainSecret, 0, 20) : null,
            'secret_hash' => $withSecret ? ApiKey::hashSecret($plainSecret) : null,
        ], $overrides));

        return ['model' => $apiKey, 'key' => $plainKey, 'secret' => $plainSecret];
    }

    // 1. API key creation: credentials are hashed-only at rest, and the
    // plaintext is revealed exactly once, in the create response itself.
    public function test_api_key_creation_generates_hashed_credentials_and_reveals_plaintext_once(): void
    {
        $account = Account::factory()->create();
        $this->giveActiveSubscription($account); // POST is mutating -> subscription.guard requires this
        $admin = $this->makeAdmin($account);

        $response = $this->actingAs($admin)->postJson('/api/developer/api-keys', ['name' => 'CRM Integration']);

        $response->assertCreated();
        $response->assertJsonStructure(['message', 'plain_text_key', 'plain_text_secret', 'api_key' => ['id', 'name', 'key_prefix']]);

        $plainKey = $response->json('plain_text_key');
        $plainSecret = $response->json('plain_text_secret');
        $this->assertStringStartsWith(self::KEY_PREFIX, $plainKey);
        $this->assertStringStartsWith(self::SECRET_PREFIX, $plainSecret);

        $row = ApiKey::where('account_id', $account->id)->firstOrFail();
        // The plaintext itself is never what's persisted -- only its
        // SHA-256 digest, matching ApiKey::hashKey()/hashSecret().
        $this->assertSame(hash('sha256', $plainKey), $row->key_hash);
        $this->assertSame(hash('sha256', $plainSecret), $row->secret_hash);
        $this->assertNotSame($plainKey, $row->key_hash);
        $this->assertNotSame($plainSecret, $row->secret_hash);
    }

    // 2. Creation is permanently locked to the caller's OWN tenant -- an
    // injected account_id/tenant_id in the request body has no effect.
    public function test_api_key_creation_ignores_injected_account_id_and_stays_scoped_to_caller_tenant(): void
    {
        $tenantA = Account::factory()->create();
        $tenantB = Account::factory()->create();
        $this->giveActiveSubscription($tenantA);
        $admin = $this->makeAdmin($tenantA);

        $response = $this->actingAs($admin)->postJson('/api/developer/api-keys', [
            'name' => 'Spoof Attempt',
            'account_id' => $tenantB->id,
            'tenant_id' => $tenantB->id,
        ]);

        $response->assertCreated();
        $row = ApiKey::findOrFail($response->json('api_key.id'));
        $this->assertSame($tenantA->id, $row->account_id);
        $this->assertNotSame($tenantB->id, $row->account_id);
    }

    // 3. Secret exposure: the Developer Portal listing endpoint never
    // returns key_hash, secret_hash, or either plaintext credential for
    // any key -- even one with a secret already provisioned.
    public function test_listing_endpoint_never_exposes_hash_or_secret(): void
    {
        $account = Account::factory()->create();
        $admin = $this->makeAdmin($account);
        $issued = $this->issueApiKey($account);

        $response = $this->actingAs($admin)->getJson('/api/developer/api-keys');

        $response->assertOk();
        $raw = $response->getContent();
        $this->assertStringNotContainsString('key_hash', $raw);
        $this->assertStringNotContainsString('secret_hash', $raw);
        $this->assertStringNotContainsString($issued['key'], $raw);
        $this->assertStringNotContainsString($issued['secret'], $raw);
        $this->assertStringNotContainsString($issued['model']->key_hash, $raw);
        $this->assertStringNotContainsString($issued['model']->secret_hash, $raw);
    }

    // 4. The simpler "Client API Key" view (Profile/Account Settings)
    // hand-picks its own response fields -- confirm neither key_hash nor
    // its value leaks through that separate code path either.
    public function test_client_api_key_show_endpoint_never_exposes_hash(): void
    {
        $account = Account::factory()->create();
        $admin = $this->makeAdmin($account);

        // Provisioned through the real endpoint rather than issueApiKey()
        // -- ClientApiKeyController::show() only ever surfaces the ONE
        // row named self::PRIMARY_KEY_NAME ('Client API Key'), which only
        // POST /api/account/api-key/regenerate creates; a generically-
        // named row (as issueApiKey() makes) is invisible to this
        // endpoint by design and show() would correctly return
        // {"data": null} for it -- not a leak, just the wrong fixture.
        $regenerate = $this->actingAs($admin)->postJson('/api/account/api-key/regenerate');
        $regenerate->assertOk();
        $plainKey = $regenerate->json('plain_text_key');
        $keyHash = ApiKey::where('account_id', $account->id)->firstOrFail()->key_hash;

        $response = $this->actingAs($admin)->getJson('/api/account/api-key');

        $response->assertOk();
        $response->assertJsonStructure(['data' => ['id', 'key_prefix', 'created_at', 'last_used_at']]);
        $raw = $response->getContent();
        $this->assertStringNotContainsString('key_hash', $raw);
        $this->assertStringNotContainsString($keyHash, $raw);
        $this->assertStringNotContainsString($plainKey, $raw);
    }

    // 5. Revocation takes effect immediately -- on the very next request
    // after a PRIOR request from the same key succeeded, not just for a
    // key that was never used. Rules out any caching/staleness window.
    public function test_revocation_denies_authentication_immediately(): void
    {
        $account = Account::factory()->create();
        $this->giveActiveSubscription($account);
        $this->makeApprovedTemplate($account->id, 'STILL_VALID');
        $issued = $this->issueApiKey($account);

        $before = $this->withHeader('X-API-KEY', $issued['key'])
            ->postJson('/api/v1/messages/send-template', ['template_code' => 'STILL_VALID', 'recipient_phone' => '919999999999']);
        $before->assertStatus(422); // authenticated fine -- 'disconnected', not 401

        $issued['model']->forceFill(['revoked_at' => now()])->save();

        $after = $this->withHeader('X-API-KEY', $issued['key'])
            ->postJson('/api/v1/messages/send-template', ['template_code' => 'STILL_VALID', 'recipient_phone' => '919999999999']);
        $after->assertStatus(401);
        $after->assertJson(['status' => false, 'message' => 'This Client API Key has been revoked.']);
    }

    // 6. Tenant resolution cannot be overridden via query string or
    // custom headers either -- PublicApiSecurityTest already proves the
    // request BODY has no effect; this closes the query-string/header
    // angle this task's requirement explicitly calls out (account_id,
    // tenant_id, agent_id).
    public function test_cross_tenant_override_via_query_string_and_header_is_rejected(): void
    {
        $tenantA = Account::factory()->create(); // deliberately no active subscription
        $tenantB = Account::factory()->create();
        $this->giveActiveSubscription($tenantB); // Tenant B DOES have quota
        $this->makeApprovedTemplate(null, 'GLOBAL_PROMO'); // global template, reachable by either tenant
        $issuedA = $this->issueApiKey($tenantA);

        $response = $this->withHeaders([
            'X-API-KEY' => $issuedA['key'],
            'X-Account-Id' => (string) $tenantB->id,
            'X-Tenant-Id' => (string) $tenantB->id,
            'X-Agent-Id' => (string) $tenantB->id,
        ])->postJson('/api/v1/messages/send-template?account_id='.$tenantB->id.'&tenant_id='.$tenantB->id.'&agent_id='.$tenantB->id, [
            'template_code' => 'GLOBAL_PROMO',
            'recipient_phone' => '919999999999',
        ]);

        // Still governed by Tenant A's own (subscription-less) state --
        // neither the query string nor these custom headers moved the
        // resolved tenant off the authenticated key's own account_id.
        $response->assertStatus(403);
        $response->assertJson(['status' => false]);
    }

    // 7. The dual-factor X-API-SECRET flow's POSITIVE path -- a
    // genuinely valid key+secret pair authenticates and reaches the
    // Message Engine boundary. PublicApiSecurityTest only exercises this
    // middleware's FAILURE paths (missing/wrong secret).
    public function test_dual_factor_valid_key_and_secret_authenticates_successfully(): void
    {
        $account = Account::factory()->create();
        $this->giveActiveSubscription($account); // 'starter' -> qr engine, no session ever connected
        $issued = $this->issueApiKey($account);

        $response = $this->withHeaders([
            'X-API-KEY' => $issued['key'],
            'X-API-SECRET' => $issued['secret'],
        ])->postJson('/api/v1/whatsapp/messages/send', [
            'recipient_type' => 'individual',
            'message_type' => 'text',
            'to' => '919999999999',
            'text' => ['body' => 'hi'],
        ]);

        // Not 401 -- both factors are valid, so this reaches the actual
        // dispatcher and fails only on the deterministic, unrelated
        // 'disconnected' condition (no WhatsApp session ever connected).
        $response->assertStatus(422);
        $response->assertJson(['success' => false, 'error_code' => 'WHATSAPP_DISCONNECTED']);
    }
}
