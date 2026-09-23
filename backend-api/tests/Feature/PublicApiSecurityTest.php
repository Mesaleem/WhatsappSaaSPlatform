<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\ApiKey;
use App\Models\ContactGroup;
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
 * Phase 3 -- Public API Hardening audit. No Feature test previously
 * existed for the external Developer API at all (AuthenticateApiKey /
 * ApiAuthMiddleware / Api\V1\* controllers, POST /api/v1/messages/
 * send-template, POST /api/v1/send-message, POST /api/v1/whatsapp/
 * messages/send) -- this file is that missing regression coverage, not
 * a rewrite of any of it. Every check below exercises EXISTING,
 * unmodified logic (see this task's own audit report for the full
 * inspection); no production code changed as part of this task.
 */
class PublicApiSecurityTest extends TestCase
{
    use RefreshDatabase;


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
    }

    private const KEY_PREFIX = 'wasaas_live_';

    private const SECRET_PREFIX = 'wasaas_secret_';

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

    /** Gives $account a real, active subscription via the actual payment-success path (InvoiceCreditService) -- same convention AgentCommissionPayoutTest already established, reused rather than hand-rolling a Subscription row. */
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

    private function makeApprovedTemplate(?int $accountId, string $code, string $body = 'Hello, this is a test.'): MessageTemplate
    {
        return MessageTemplate::create([
            'account_id' => $accountId,
            'template_code' => $code,
            'title' => $code,
            'template_body' => $body,
            'status' => 'approved',
        ]);
    }

    // 1. Valid API key can access its own tenant.
    public function test_valid_api_key_can_access_its_own_tenant(): void
    {
        $account = Account::factory()->create();
        $this->giveActiveSubscription($account);
        $this->makeApprovedTemplate($account->id, 'WELCOME');
        $issued = $this->issueApiKey($account);

        $response = $this->withHeader('X-API-KEY', $issued['key'])
            ->postJson('/api/v1/messages/send-template', [
                'template_code' => 'WELCOME',
                'recipient_phone' => '919999999999',
            ]);

        // No WhatsApp session was ever connected for this account -- the
        // request must still traverse auth -> tenant resolution -> scope
        // -> entitlement -> provider-capability and reach the Message
        // Engine boundary, proven by the deterministic 'disconnected'
        // response, not by any earlier 401/403/404 layer.
        $response->assertStatus(422);
        $response->assertJson(['status' => false, 'error_code' => 'WHATSAPP_DISCONNECTED']);
    }

    // 2. Invalid API key is rejected.
    public function test_invalid_api_key_is_rejected(): void
    {
        $response = $this->withHeader('X-API-KEY', 'wasaas_live_totally_bogus_key')
            ->postJson('/api/v1/messages/send-template', ['template_code' => 'X', 'recipient_phone' => '919999999999']);

        $response->assertStatus(401);
        $response->assertJson(['status' => false, 'message' => 'Invalid or missing Client API Key.']);
    }

    // 3. Revoked API key is rejected.
    public function test_revoked_api_key_is_rejected(): void
    {
        $account = Account::factory()->create();
        $issued = $this->issueApiKey($account);
        $issued['model']->forceFill(['revoked_at' => now()])->save();

        $response = $this->withHeader('X-API-KEY', $issued['key'])
            ->postJson('/api/v1/messages/send-template', ['template_code' => 'X', 'recipient_phone' => '919999999999']);

        $response->assertStatus(401);
        $response->assertJson(['status' => false, 'message' => 'This Client API Key has been revoked.']);
    }

    // 3b. Expired (inactive) API key is rejected.
    public function test_expired_api_key_is_rejected(): void
    {
        $account = Account::factory()->create();
        $issued = $this->issueApiKey($account, overrides: ['expires_at' => now()->subDay()]);

        $response = $this->withHeader('X-API-KEY', $issued['key'])
            ->postJson('/api/v1/messages/send-template', ['template_code' => 'X', 'recipient_phone' => '919999999999']);

        $response->assertStatus(401);
        $response->assertJson(['status' => false, 'message' => 'This Client API Key has expired.']);
    }

    // 4. API key from Tenant A cannot access Tenant B.
    public function test_api_key_from_tenant_a_cannot_access_tenant_b(): void
    {
        $tenantA = Account::factory()->create();
        $tenantB = Account::factory()->create();
        $this->giveActiveSubscription($tenantA);
        // A template scoped ONLY to Tenant B -- reachable through
        // Tenant A's key only if account scoping were broken.
        $this->makeApprovedTemplate($tenantB->id, 'TENANT_B_ONLY');
        $issuedA = $this->issueApiKey($tenantA);

        $response = $this->withHeader('X-API-KEY', $issuedA['key'])
            ->postJson('/api/v1/messages/send-template', [
                'template_code' => 'TENANT_B_ONLY',
                'recipient_phone' => '919999999999',
            ]);

        // Identical generic 404 to "code doesn't exist at all" -- this
        // response can never be used to enumerate another tenant's
        // template codes (TemplateMessageController::send()'s own
        // stated design).
        $response->assertStatus(404);
        $response->assertJson(['status' => false, 'message' => 'Invalid template_code for this account']);
    }

    // 5. API key cannot override tenant/account ID through request parameters.
    public function test_api_key_cannot_override_tenant_via_request_parameter(): void
    {
        $tenantA = Account::factory()->create(); // deliberately NO active subscription
        $tenantB = Account::factory()->create();
        $this->giveActiveSubscription($tenantB); // Tenant B DOES have quota
        $this->makeApprovedTemplate(null, 'GLOBAL_PROMO'); // global template, reachable by either tenant
        $issuedA = $this->issueApiKey($tenantA);

        $response = $this->withHeader('X-API-KEY', $issuedA['key'])
            ->postJson('/api/v1/messages/send-template', [
                'template_code' => 'GLOBAL_PROMO',
                'recipient_phone' => '919999999999',
                // Injection attempt: neither field is part of this
                // endpoint's validated() payload or ever read by the
                // controller/dispatcher -- tenant resolution comes
                // exclusively from api_account_id (the middleware
                // attribute derived from the presented key), never the
                // request body.
                'account_id' => $tenantB->id,
                'api_account_id' => $tenantB->id,
            ]);

        // If the injected account_id/api_account_id had ANY effect, this
        // would resolve against Tenant B's real, active subscription and
        // proceed further (to the 'disconnected' check, a 422). Instead
        // it must fail exactly as TENANT A's own (subscription-less)
        // state dictates.
        $response->assertStatus(403);
        $response->assertJson(['status' => false]);
    }

    // 6. API key without required scope is rejected (the dual-factor
    // secret tier -- a single-factor key has no secret provisioned and
    // cannot reach the auth.apisecret-gated endpoints).
    public function test_api_key_without_secret_scope_is_rejected_from_dual_factor_endpoint(): void
    {
        $account = Account::factory()->create();
        $issued = $this->issueApiKey($account, withSecret: false);

        $response = $this->withHeaders([
            'X-API-KEY' => $issued['key'],
            'X-API-SECRET' => 'whatever-the-caller-guesses',
        ])->postJson('/api/v1/whatsapp/messages/send', [
            'recipient_type' => 'individual',
            'message_type' => 'text',
            'to' => '919999999999',
            'text' => 'hi',
        ]);

        $response->assertStatus(401);
        $response->assertJson(['success' => false, 'error_code' => 'UNAUTHORIZED']);

        // The SAME key still works perfectly on its original (single-
        // factor) tier -- proving this is a narrower-scope rejection,
        // not a broken or revoked key.
        $this->giveActiveSubscription($account);
        $this->makeApprovedTemplate($account->id, 'STILL_WORKS');
        $stillWorks = $this->withHeader('X-API-KEY', $issued['key'])
            ->postJson('/api/v1/messages/send-template', ['template_code' => 'STILL_WORKS', 'recipient_phone' => '919999999999']);
        $stillWorks->assertStatus(422); // 'disconnected', not 401 -- the key itself is valid.
    }

    // 7. Missing required entitlement is rejected.
    public function test_missing_entitlement_is_rejected(): void
    {
        $account = Account::factory()->create(['allowed_modules' => ['dashboard', 'send_alert']]); // 'contact_groups' deliberately NOT included
        $this->giveActiveSubscription($account);
        $this->makeApprovedTemplate($account->id, 'GROUP_TPL');
        ContactGroup::create(['account_id' => $account->id, 'name' => 'VIP', 'group_code' => 'VIP', 'group_type' => ContactGroup::GROUP_TYPE_INTERNAL]);
        $issued = $this->issueApiKey($account);

        $response = $this->withHeader('X-API-KEY', $issued['key'])
            ->postJson('/api/v1/send-message', [
                'recipient_type' => 'group',
                'template_code' => 'GROUP_TPL',
                'group_code' => 'VIP',
            ]);

        $response->assertStatus(403);
        $response->assertJson(['success' => false, 'error_code' => 'GROUP_ACCESS_DENIED']);
    }

    // 8. Provider-incompatible operation is rejected.
    public function test_provider_incompatible_operation_is_rejected(): void
    {
        $account = Account::factory()->create();
        $this->giveActiveSubscription($account, 'business'); // Meta Cloud API engine
        $this->makeApprovedTemplate($account->id, 'NATIVE_TPL');
        // A Native WhatsApp Group can only ever be reached over the QR
        // (Baileys) engine -- Meta Cloud API has no concept of it.
        ContactGroup::create([
            'account_id' => $account->id,
            'name' => 'Native Group',
            'group_code' => 'NATIVEG',
            'group_type' => ContactGroup::GROUP_TYPE_NATIVE,
            'wa_group_jid' => '120363xxx@g.us',
            'sync_status' => ContactGroup::SYNC_STATUS_SYNCED,
        ]);
        $issued = $this->issueApiKey($account);

        $response = $this->withHeader('X-API-KEY', $issued['key'])
            ->postJson('/api/v1/send-message', [
                'recipient_type' => 'group',
                'template_code' => 'NATIVE_TPL',
                'group_code' => 'NATIVEG',
            ]);

        $response->assertStatus(422);
        // TemplateMessageController::sendToGroup() (the /v1/send-message
        // group path) has no dedicated match arm for 'unsupported_engine'
        // -- unlike UnifiedMessageController's /v1/whatsapp endpoints,
        // which do map it to 'UNSUPPORTED_ENGINE' -- so it falls through
        // to that method's own generic default arm. The operation is
        // still correctly rejected (422, success:false, non-leaking
        // message); this asserts the actual existing contract rather
        // than one borrowed from a different controller.
        $response->assertJson(['success' => false, 'error_code' => 'SEND_FAILED']);
    }

    // 9a. Validation errors return the existing expected API error
    // format -- the {"status": false, ...} envelope
    // /v1/messages/send-template has always used.
    public function test_validation_error_returns_existing_status_envelope_format(): void
    {
        $account = Account::factory()->create();
        $issued = $this->issueApiKey($account);

        $response = $this->withHeader('X-API-KEY', $issued['key'])
            ->postJson('/api/v1/messages/send-template', ['recipient_phone' => '919999999999']); // missing template_code

        $response->assertStatus(422);
        $response->assertJsonStructure(['status', 'message', 'errors']);
        $response->assertJson(['status' => false]);
    }

    // 9b. Validation errors return the existing expected API error
    // format -- the {"success": false, ...} envelope /v1/send-message
    // has always used (a deliberately different, disclosed contract
    // from send-template above -- see SendMessageRequest's docblock).
    public function test_validation_error_returns_existing_success_envelope_format(): void
    {
        $account = Account::factory()->create();
        $issued = $this->issueApiKey($account);

        $response = $this->withHeader('X-API-KEY', $issued['key'])
            ->postJson('/api/v1/send-message', ['recipient_type' => 'individual']); // missing template_code/recipient_phone

        $response->assertStatus(422);
        $response->assertJsonStructure(['success', 'message', 'errors']);
        $response->assertJson(['success' => false]);
    }

    // 10. Authentication/authorization failures do not leak secrets.
    public function test_authentication_failures_do_not_leak_secrets(): void
    {
        $account = Account::factory()->create();
        $issued = $this->issueApiKey($account);
        $payload = ['recipient_type' => 'individual', 'message_type' => 'text', 'to' => '919999999999', 'text' => 'hi'];

        // Cause A: the key doesn't exist at all.
        $unknownKeyResponse = $this->withHeaders([
            'X-API-KEY' => 'wasaas_live_'.Str::random(40),
            'X-API-SECRET' => 'wasaas_secret_'.Str::random(40),
        ])->postJson('/api/v1/whatsapp/messages/send', $payload);

        // Cause B: the key IS real, but the secret is wrong.
        $wrongSecretResponse = $this->withHeaders([
            'X-API-KEY' => $issued['key'],
            'X-API-SECRET' => 'definitely-the-wrong-secret',
        ])->postJson('/api/v1/whatsapp/messages/send', $payload);

        // Both causes must be indistinguishable to the caller -- an
        // attacker probing for which keys exist, or whether a secret has
        // been provisioned yet, gets the exact same response either way
        // (ApiAuthMiddleware's own disclosed design).
        $unknownKeyResponse->assertStatus(401);
        $wrongSecretResponse->assertStatus(401);
        $this->assertSame($unknownKeyResponse->json(), $wrongSecretResponse->json());

        // Neither plaintext credential is ever echoed back in the response.
        $this->assertStringNotContainsString($issued['key'], $wrongSecretResponse->getContent());
        $this->assertStringNotContainsString($issued['secret'], $wrongSecretResponse->getContent());
    }

    // 11. Existing API endpoints remain backward compatible -- the
    // original Authorization: Bearer <key> auth form (pre-dating the
    // X-API-KEY header) must keep authenticating identically.
    public function test_legacy_bearer_token_auth_form_remains_supported(): void
    {
        $account = Account::factory()->create();
        $this->giveActiveSubscription($account);
        $this->makeApprovedTemplate($account->id, 'BEARER_TPL');
        $issued = $this->issueApiKey($account);

        $response = $this->withHeader('Authorization', 'Bearer '.$issued['key'])
            ->postJson('/api/v1/messages/send-template', [
                'template_code' => 'BEARER_TPL',
                'recipient_phone' => '919999999999',
            ]);

        // Same outcome as the X-API-KEY form used throughout this file --
        // proves the pre-existing Authorization: Bearer contract still
        // authenticates identically, unchanged.
        $response->assertStatus(422);
        $response->assertJson(['status' => false, 'error_code' => 'WHATSAPP_DISCONNECTED']);
    }

    // 12. Existing bulk recipients behavior remains supported -- the
    // Send Alert page's comma-separated textarea is split into this
    // array client-side; this array IS that existing, unchanged wire
    // contract (POST /api/alerts/send-template-bulk, internal/Sanctum,
    // untouched by this audit).
    public function test_existing_bulk_recipients_array_behavior_remains_supported(): void
    {
        $this->seed(RolePermissionSeeder::class);
        $account = Account::factory()->create();
        $this->giveActiveSubscription($account);
        $template = $this->makeApprovedTemplate($account->id, 'BULK_TPL', 'Hello there.');
        $admin = User::factory()->create(['account_id' => $account->id]);
        $admin->assignRole('admin');

        $response = $this->actingAs($admin)->postJson('/api/alerts/send-template-bulk', [
            // sendBulk() looks up by the raw template_id, not
            // template_code -- see SendBulkTemplateMessageRequest's own
            // docblock for why this internal contract deliberately
            // differs from the external /v1 endpoints.
            'template_id' => $template->id,
            'recipient_phones' => ['919999999901', '919999999902', '919999999903'],
        ]);

        $response->assertOk();
        $response->assertJsonStructure(['message', 'queued_count', 'batch_count']);
        $response->assertJson(['queued_count' => 3]);
    }
}
