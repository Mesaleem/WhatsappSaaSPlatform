<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\ApiKey;
use App\Models\ContactGroup;
use App\Models\Invoice;
use App\Models\MessageTemplate;
use App\Models\PaymentAlert;
use App\Services\Billing\InvoiceCreditService;
use App\Support\PlanCatalog;
use Database\Seeders\Phase1FoundationSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Phase 3 Task 3 -- Public API authorization hardening. Inspection found
 * the authorization decision already implemented exactly as this task's
 * own conceptual chain describes:
 *
 *   Authenticated API Key -> Owning Account (api_account_id, resolved
 *   ONLY from the key, never request input) -> Account Status
 *   (AuthenticateApiKey/ApiAuthMiddleware's isAdministrativelyActive()
 *   check) -> Required Entitlement/Module (Account::hasModuleEnabled(),
 *   checked inline in GroupController/GroupMessageDispatcher) ->
 *   Provider/Engine Capability (engine_type checks inline in
 *   GroupController/GroupMessageDispatcher) -> Allow/Deny.
 *
 * No new scope/permission system exists or is introduced here -- see
 * this task's own audit report. No production code changed; this file
 * adds the missing regression coverage for authorization scenarios not
 * already exercised by PublicApiSecurityTest.php / ApiKeyLifecycleTest.php:
 * inactive/suspended ACCOUNT (as opposed to a revoked KEY), the
 * dedicated GROUP_MODULE_DISABLED and NATIVE_GROUP_REQUIRES_QR_ENGINE
 * code paths on POST /api/v1/whatsapp/groups/create, the completely
 * untested POST /api/v1/messages/send-payment-alert endpoint, and the
 * auth.apikey/auth.apisecret tier-separation edge case of a completely
 * MISSING X-API-SECRET header (as opposed to a present-but-wrong one).
 *
 * [Disclosed finding]: this codebase has no Meta-only operation on the
 * external API surface -- every message-send path (template, direct
 * text/media, payment alert) resolves its engine per-account via
 * WhatsAppEngineFactory::make() and works identically on either engine;
 * the ONLY provider-restricted operation anywhere is Native WhatsApp
 * Groups, which requires 'qr' and rejects 'meta' (already covered by
 * test_meta_engine_account_cannot_create_native_group below and by
 * PublicApiSecurityTest::test_provider_incompatible_operation_is_rejected).
 * Confirmed by grepping the entire app/ tree for any engine_type check
 * that requires 'meta' and rejects 'qr' -- none exists. "QR-only account
 * cannot invoke Meta-only functionality" is therefore not testable
 * against real code without inventing a feature that doesn't exist,
 * which this task's own rules forbid ("Do NOT redesign API
 * authentication" / "No unrelated refactoring").
 *
 * The internal, Sanctum-authenticated bulk-send endpoint
 * (POST /api/alerts/send-template-bulk) is NOT part of the external
 * Developer API surface this task scopes (no /v1 prefix, no
 * auth.apikey/auth.apisecret middleware) -- same disclosed finding as
 * Phase 3 Task 1's audit report -- so it is out of scope for new
 * authorization tests here; its own regression test
 * (PublicApiSecurityTest::test_existing_bulk_recipients_array_behavior_remains_supported)
 * is re-run, not duplicated, per this task's "verify ... bulk-send
 * remain green" instruction.
 */
class PublicApiAuthorizationTest extends TestCase
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

    /** Same convention as PublicApiSecurityTest/ApiKeyLifecycleTest -- a real active subscription via the actual payment-success path. */
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

    // 1. Authenticated API key from an active account, with the required
    // entitlement, can perform the operation.
    public function test_valid_key_with_active_account_and_entitlement_is_allowed(): void
    {
        $account = Account::factory()->create(); // default allowed_modules -> every module enabled, including contact_groups
        $issued = $this->issueApiKey($account);

        $response = $this->withHeaders([
            'X-API-KEY' => $issued['key'],
            'X-API-SECRET' => $issued['secret'],
        ])->postJson('/api/v1/whatsapp/groups/create', ['name' => 'Sales Team']);

        $response->assertCreated();
        $response->assertJson(['success' => true]);
    }

    // 2. An inactive/suspended ACCOUNT is denied even though the API key
    // itself is perfectly valid (not revoked, not expired) -- distinct
    // from PublicApiSecurityTest's revoked-KEY case.
    public function test_inactive_account_is_denied_even_with_a_valid_unrevoked_key(): void
    {
        $account = Account::factory()->create(['status' => 'suspended']);
        $issued = $this->issueApiKey($account);

        $singleFactor = $this->withHeader('X-API-KEY', $issued['key'])
            ->postJson('/api/v1/messages/send-template', ['template_code' => 'X', 'recipient_phone' => '919999999999']);
        $singleFactor->assertStatus(401);
        $singleFactor->assertJson(['status' => false, 'message' => 'The account associated with this Client API Key is not active.']);

        $dualFactor = $this->withHeaders(['X-API-KEY' => $issued['key'], 'X-API-SECRET' => $issued['secret']])
            ->postJson('/api/v1/whatsapp/groups/create', ['name' => 'Sales Team']);
        $dualFactor->assertStatus(401);
        $dualFactor->assertJson(['success' => false, 'error_code' => 'UNAUTHORIZED', 'message' => 'The account associated with this API key is not active.']);
    }

    // 3. Missing required entitlement/module is denied -- the dedicated
    // GROUP_MODULE_DISABLED code path on the group CREATE endpoint
    // (distinct from PublicApiSecurityTest's coverage of the same gate
    // on the group MESSAGE-SEND endpoint).
    public function test_missing_entitlement_module_is_denied_on_group_creation(): void
    {
        $account = Account::factory()->create(['allowed_modules' => ['dashboard', 'send_alert']]); // 'contact_groups' deliberately absent
        $issued = $this->issueApiKey($account);

        $response = $this->withHeaders(['X-API-KEY' => $issued['key'], 'X-API-SECRET' => $issued['secret']])
            ->postJson('/api/v1/whatsapp/groups/create', ['name' => 'Sales Team']);

        $response->assertStatus(403);
        $response->assertJson(['success' => false, 'error_code' => 'GROUP_MODULE_DISABLED']);
    }

    // 4. Provider/engine capability: a Meta Cloud API account cannot
    // create a Native WhatsApp Group (the one real provider-restricted
    // operation on this API surface -- see this file's own docblock for
    // why the reverse, "QR-only account blocked from Meta-only
    // functionality", has no corresponding real feature to test).
    public function test_meta_engine_account_cannot_create_native_group(): void
    {
        $account = Account::factory()->create();
        $this->giveActiveSubscription($account, 'business'); // Meta Cloud API engine
        $issued = $this->issueApiKey($account);

        $response = $this->withHeaders(['X-API-KEY' => $issued['key'], 'X-API-SECRET' => $issued['secret']])
            ->postJson('/api/v1/whatsapp/groups/create', [
                'name' => 'Native Group',
                'group_type' => ContactGroup::GROUP_TYPE_NATIVE,
                'contacts' => [['phone_number' => '919999999999']],
            ]);

        $response->assertStatus(422);
        $response->assertJson(['success' => false, 'error_code' => 'NATIVE_GROUP_REQUIRES_QR_ENGINE']);
    }

    // 5. Cross-tenant isolation on the previously untested payment-alert
    // endpoint -- an injected account_id in the body has no effect; the
    // alert is always created under the KEY's own tenant.
    public function test_payment_alert_endpoint_is_scoped_to_key_owning_tenant(): void
    {
        $tenantA = Account::factory()->create();
        $tenantB = Account::factory()->create();
        // 'business' (Meta Cloud API engine) -- PaymentAlertDispatcher::
        // isWhatsAppDisconnected() is deliberately scoped to the 'qr'
        // engine only (see its own docblock), so a Meta-engine account
        // reaches 'queued' deterministically without a real WhatsApp
        // session, exactly like PublicApiSecurityTest's own convention
        // for reaching a clean, deterministic outcome without a driver.
        $this->giveActiveSubscription($tenantA, 'business');
        $this->giveActiveSubscription($tenantB, 'business');
        $issuedA = $this->issueApiKey($tenantA);

        $response = $this->withHeader('X-API-KEY', $issuedA['key'])
            ->postJson('/api/v1/messages/send-payment-alert', [
                'recipient_phone' => '919999999999',
                'customer_name' => 'Jane Doe',
                'amount' => 499.00,
                'payment_ref' => 'PAY-'.uniqid(),
                'account_id' => $tenantB->id, // injection attempt
            ]);

        $response->assertStatus(202);
        $alert = PaymentAlert::where('account_id', $tenantA->id)->firstOrFail();
        $this->assertSame($tenantA->id, $alert->account_id);
        $this->assertNotSame($tenantB->id, $alert->account_id);
    }

    // 6. auth.apikey routes cannot accidentally gain access to
    // auth.apisecret-protected routes -- a request carrying ONLY
    // X-API-KEY (no X-API-SECRET header AT ALL, not merely a wrong one)
    // is rejected with the middleware's own distinct "both headers
    // required" message, not silently authorized.
    public function test_apikey_only_request_cannot_reach_an_apisecret_protected_route(): void
    {
        $account = Account::factory()->create();
        $issued = $this->issueApiKey($account);

        $response = $this->withHeader('X-API-KEY', $issued['key'])
            ->postJson('/api/v1/whatsapp/groups/create', ['name' => 'Sales Team']);

        $response->assertStatus(401);
        $response->assertJson(['success' => false, 'error_code' => 'UNAUTHORIZED', 'message' => 'Both X-API-KEY and X-API-SECRET headers are required.']);
    }

    // 7. auth.apisecret routes still require a genuinely VALID secret --
    // the correct key with an incorrect secret is denied, using the
    // exact existing error envelope (re-asserted here, self-contained,
    // alongside this file's own tier-separation tests above).
    public function test_apisecret_route_denies_a_valid_key_with_an_incorrect_secret(): void
    {
        $account = Account::factory()->create();
        $issued = $this->issueApiKey($account);

        $response = $this->withHeaders(['X-API-KEY' => $issued['key'], 'X-API-SECRET' => 'not-the-real-secret'])
            ->postJson('/api/v1/whatsapp/groups/create', ['name' => 'Sales Team']);

        $response->assertStatus(401);
        $response->assertJson(['success' => false, 'error_code' => 'UNAUTHORIZED', 'message' => 'Invalid API key or secret.']);
        $this->assertStringNotContainsString($issued['secret'], $response->getContent());
    }

    // 8. Authentication success must NOT automatically imply
    // authorization for every operation -- the SAME valid, authenticated
    // key succeeds on an entitled operation but is denied on one its
    // account isn't entitled to, proving auth and authorization are
    // separately enforced rather than auth alone gating everything.
    public function test_authentication_success_does_not_imply_blanket_authorization(): void
    {
        $account = Account::factory()->create(['allowed_modules' => ['dashboard', 'send_alert', 'developer_api']]); // no 'contact_groups'
        $this->giveActiveSubscription($account);
        $this->makeApprovedTemplate($account->id, 'ALLOWED_TPL');
        $issued = $this->issueApiKey($account);

        // Entitled operation: allowed (reaches the Message Engine boundary).
        $allowed = $this->withHeader('X-API-KEY', $issued['key'])
            ->postJson('/api/v1/messages/send-template', ['template_code' => 'ALLOWED_TPL', 'recipient_phone' => '919999999999']);
        $allowed->assertStatus(422); // 'disconnected' -- authenticated AND authorized, just no WhatsApp session
        $allowed->assertJson(['status' => false, 'error_code' => 'WHATSAPP_DISCONNECTED']);

        // Same key, same account, same authentication -- but an
        // operation this account is NOT entitled to is still denied.
        $denied = $this->withHeaders(['X-API-KEY' => $issued['key'], 'X-API-SECRET' => $issued['secret']])
            ->postJson('/api/v1/whatsapp/groups/create', ['name' => 'Sales Team']);
        $denied->assertStatus(403);
        $denied->assertJson(['success' => false, 'error_code' => 'GROUP_MODULE_DISABLED']);
    }

    // 9. Authorization failures never leak internal details (account id,
    // key hash, stack traces) in the response body, and use the existing
    // API error envelope shape.
    public function test_authorization_failure_response_leaks_no_internal_details(): void
    {
        $account = Account::factory()->create(['allowed_modules' => ['dashboard']]);
        $issued = $this->issueApiKey($account);

        $response = $this->withHeaders(['X-API-KEY' => $issued['key'], 'X-API-SECRET' => $issued['secret']])
            ->postJson('/api/v1/whatsapp/groups/create', ['name' => 'Sales Team']);

        $response->assertStatus(403);
        $response->assertJsonStructure(['success', 'error_code', 'message']);
        $raw = $response->getContent();
        $this->assertStringNotContainsString((string) $account->id, $raw);
        $this->assertStringNotContainsString($issued['model']->key_hash, $raw);
        $this->assertStringNotContainsString($issued['model']->secret_hash, $raw);
        $this->assertStringNotContainsString($issued['secret'], $raw);
    }
}
