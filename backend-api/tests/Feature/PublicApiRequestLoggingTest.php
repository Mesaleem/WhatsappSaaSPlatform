<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\ApiKey;
use App\Models\ApiRequestLog;
use App\Models\Invoice;
use App\Models\MessageTemplate;
use App\Services\Billing\InvoiceCreditService;
use App\Support\PlanCatalog;
use Database\Seeders\Phase1FoundationSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Phase 3 Task 4 -- Public API observability/auditability. Inspection
 * (this task's own audit report) found no existing per-request log for
 * the external Developer API: `message_dispatch_logs` records only a
 * resolved outbound-message OUTCOME, written from deep inside a
 * dispatcher, and is never reached at all when a request fails
 * authentication or authorization; `activity_logs` (LogsActivity)
 * requires an authenticated Sanctum session user, which the external
 * /v1/* API never has. This was a genuine gap -- LogApiRequestMiddleware
 * (registered as the OUTERMOST middleware on both external API route
 * groups) and the api_request_logs table/ApiRequestLog model are the
 * production fix. This file is the required regression coverage for it.
 */
class PublicApiRequestLoggingTest extends TestCase
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

    /** Same convention as PublicApiSecurityTest/ApiKeyLifecycleTest/PublicApiAuthorizationTest -- a real active subscription via the actual payment-success path. */
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

    // 1. A successful, fully-authenticated API request creates exactly one
    // request log row, correctly attributed, with a 2xx status recorded.
    public function test_successful_request_creates_the_expected_request_log(): void
    {
        $account = Account::factory()->create();
        $issued = $this->issueApiKey($account);

        $response = $this->withHeaders([
            'X-API-KEY' => $issued['key'],
            'X-API-SECRET' => $issued['secret'],
        ])->postJson('/api/v1/whatsapp/groups/create', ['name' => 'Sales Team']);

        $response->assertCreated();

        $this->assertSame(1, ApiRequestLog::count());
        $log = ApiRequestLog::sole();
        $this->assertSame($account->id, $log->account_id);
        $this->assertSame($issued['model']->id, $log->api_key_id);
        $this->assertSame('POST', $log->method);
        $this->assertSame('api/v1/whatsapp/groups/create', $log->path);
        $this->assertSame(201, $log->status_code);
        $this->assertNotNull($log->request_id);
        $this->assertIsInt($log->duration_ms);
    }

    // 2. A failed authentication (unknown key -- no account/key ever
    // resolved) is still logged, with a 401 status and null tenant/key
    // attribution -- it is not silently dropped just because auth failed
    // before reaching any controller.
    public function test_failed_authentication_is_safely_logged(): void
    {
        $response = $this->withHeader('X-API-KEY', 'wasaas_live_'.Str::random(40))
            ->postJson('/api/v1/messages/send-template', ['template_code' => 'X', 'recipient_phone' => '919999999999']);

        $response->assertStatus(401);

        $this->assertSame(1, ApiRequestLog::count());
        $log = ApiRequestLog::sole();
        $this->assertNull($log->account_id);
        $this->assertNull($log->api_key_id);
        $this->assertNull($log->api_key_prefix);
        $this->assertSame(401, $log->status_code);
        $this->assertSame('api/v1/messages/send-template', $log->path);
        $this->assertNotNull($log->request_id);
    }

    // 3. An authorization failure (valid, authenticated key; account simply
    // lacks the required module) is logged with a 403 status and CORRECT
    // tenant/key attribution -- unlike an auth failure, the tenant/key
    // were genuinely resolved here and must be recorded.
    public function test_authorization_failure_is_safely_logged_with_correct_attribution(): void
    {
        $account = Account::factory()->create(['allowed_modules' => ['dashboard', 'send_alert']]); // 'contact_groups' deliberately absent
        $issued = $this->issueApiKey($account);

        $response = $this->withHeaders(['X-API-KEY' => $issued['key'], 'X-API-SECRET' => $issued['secret']])
            ->postJson('/api/v1/whatsapp/groups/create', ['name' => 'Sales Team']);

        $response->assertStatus(403);

        $log = ApiRequestLog::sole();
        $this->assertSame($account->id, $log->account_id);
        $this->assertSame($issued['model']->id, $log->api_key_id);
        $this->assertSame(403, $log->status_code);
    }

    // 4. Tenant/account attribution is correct across two DIFFERENT
    // accounts calling in sequence -- each request's log row points at
    // its own caller's account, never the other's or a stale value.
    public function test_tenant_attribution_is_correct_across_multiple_accounts(): void
    {
        $accountA = Account::factory()->create();
        $accountB = Account::factory()->create();
        $issuedA = $this->issueApiKey($accountA);
        $issuedB = $this->issueApiKey($accountB);

        $this->withHeaders(['X-API-KEY' => $issuedA['key'], 'X-API-SECRET' => $issuedA['secret']])
            ->postJson('/api/v1/whatsapp/groups/create', ['name' => 'A Group'])
            ->assertCreated();

        $this->withHeaders(['X-API-KEY' => $issuedB['key'], 'X-API-SECRET' => $issuedB['secret']])
            ->postJson('/api/v1/whatsapp/groups/create', ['name' => 'B Group'])
            ->assertCreated();

        $this->assertSame(2, ApiRequestLog::count());
        $logA = ApiRequestLog::where('api_key_id', $issuedA['model']->id)->sole();
        $logB = ApiRequestLog::where('api_key_id', $issuedB['model']->id)->sole();
        $this->assertSame($accountA->id, $logA->account_id);
        $this->assertSame($accountB->id, $logB->account_id);
        $this->assertNotSame($logA->account_id, $logB->account_id);
    }

    // 5. API key identity is represented safely -- by numeric id and the
    // existing key_prefix column only, matching the actual issued key's
    // own prefix, never the full key.
    public function test_api_key_identity_is_represented_safely_by_id_and_prefix(): void
    {
        $account = Account::factory()->create();
        $issued = $this->issueApiKey($account);

        $this->withHeaders(['X-API-KEY' => $issued['key'], 'X-API-SECRET' => $issued['secret']])
            ->postJson('/api/v1/whatsapp/groups/create', ['name' => 'Sales Team'])
            ->assertCreated();

        $log = ApiRequestLog::sole();
        $this->assertSame($issued['model']->id, $log->api_key_id);
        $this->assertSame($issued['model']->key_prefix, $log->api_key_prefix);
        $this->assertNotSame($issued['key'], $log->api_key_prefix);
    }

    // 6. The raw, plaintext API key is never persisted anywhere in the
    // request log table, across success, auth-failure, and authz-failure
    // paths.
    public function test_raw_api_key_is_never_stored(): void
    {
        $account = Account::factory()->create();
        $issued = $this->issueApiKey($account);

        $this->withHeaders(['X-API-KEY' => $issued['key'], 'X-API-SECRET' => $issued['secret']])
            ->postJson('/api/v1/whatsapp/groups/create', ['name' => 'Sales Team']);
        $this->withHeader('X-API-KEY', 'wasaas_live_'.Str::random(40))
            ->postJson('/api/v1/messages/send-template', ['template_code' => 'X', 'recipient_phone' => '919999999999']);

        $this->assertGreaterThan(0, ApiRequestLog::count());
        foreach (ApiRequestLog::all() as $log) {
            foreach ($log->getAttributes() as $value) {
                if (is_string($value)) {
                    $this->assertStringNotContainsString($issued['key'], $value);
                }
            }
        }
    }

    // 7. The raw, plaintext API secret is never persisted anywhere in the
    // request log table.
    public function test_raw_api_secret_is_never_stored(): void
    {
        $account = Account::factory()->create();
        $issued = $this->issueApiKey($account);

        $this->withHeaders(['X-API-KEY' => $issued['key'], 'X-API-SECRET' => $issued['secret']])
            ->postJson('/api/v1/whatsapp/groups/create', ['name' => 'Sales Team']);
        $this->withHeaders(['X-API-KEY' => $issued['key'], 'X-API-SECRET' => 'a-completely-wrong-secret'])
            ->postJson('/api/v1/whatsapp/groups/create', ['name' => 'Sales Team']);

        $this->assertGreaterThan(0, ApiRequestLog::count());
        foreach (ApiRequestLog::all() as $log) {
            foreach ($log->getAttributes() as $value) {
                if (is_string($value)) {
                    $this->assertStringNotContainsString($issued['secret'], $value);
                    $this->assertStringNotContainsString('a-completely-wrong-secret', $value);
                }
            }
        }
    }

    // 8. The Authorization header value is never persisted -- including
    // when a caller authenticates via the legacy `Authorization: Bearer
    // <key>` form AuthenticateApiKey still supports.
    public function test_authorization_header_is_never_stored(): void
    {
        $account = Account::factory()->create();
        $issued = $this->issueApiKey($account);

        $this->withHeader('Authorization', 'Bearer '.$issued['key'])
            ->postJson('/api/v1/messages/send-template', ['template_code' => 'X', 'recipient_phone' => '919999999999']);

        $this->assertGreaterThan(0, ApiRequestLog::count());
        $columns = ['request_id', 'api_key_prefix', 'method', 'path', 'ip', 'user_agent'];
        foreach (ApiRequestLog::all() as $log) {
            foreach ($columns as $column) {
                $value = $log->getAttribute($column);
                if (is_string($value)) {
                    $this->assertStringNotContainsString($issued['key'], $value);
                    $this->assertStringNotContainsString('Bearer', $value);
                }
            }
        }
    }

    // 9. Neither the key hash nor the secret hash is ever exposed through
    // a request log row -- the table has no such columns at all, and this
    // asserts that invariant directly against the persisted model.
    public function test_key_and_secret_hashes_are_never_exposed_through_logs(): void
    {
        $account = Account::factory()->create();
        $issued = $this->issueApiKey($account);

        $this->withHeaders(['X-API-KEY' => $issued['key'], 'X-API-SECRET' => $issued['secret']])
            ->postJson('/api/v1/whatsapp/groups/create', ['name' => 'Sales Team']);

        $log = ApiRequestLog::sole();
        $this->assertArrayNotHasKey('key_hash', $log->getAttributes());
        $this->assertArrayNotHasKey('secret_hash', $log->getAttributes());
        foreach ($log->getAttributes() as $value) {
            if (is_string($value)) {
                $this->assertStringNotContainsString($issued['model']->key_hash, $value);
                $this->assertStringNotContainsString((string) $issued['model']->secret_hash, $value);
            }
        }
    }

    // 10. The request log never persists the outbound message body or any
    // OTP/authentication-secret value that may appear in the request
    // payload -- the table simply has no column capable of holding either,
    // and this proves the actual sent content is absent from every column.
    public function test_message_body_and_otp_are_not_persisted_in_request_logs(): void
    {
        $account = Account::factory()->create();
        $this->giveActiveSubscription($account, 'business');
        $this->makeApprovedTemplate($account->id, 'OTP_TPL');
        $issued = $this->issueApiKey($account);

        $secretPayload = 'OTP-CODE-928374-DO-NOT-LOG';

        $this->withHeader('X-API-KEY', $issued['key'])
            ->postJson('/api/v1/messages/send-template', [
                'template_code' => 'OTP_TPL',
                'recipient_phone' => '919999999999',
                'variables' => ['1' => $secretPayload],
            ]);

        $this->assertGreaterThan(0, ApiRequestLog::count());
        foreach (ApiRequestLog::all() as $log) {
            foreach ($log->getAttributes() as $value) {
                if (is_string($value)) {
                    $this->assertStringNotContainsString($secretPayload, $value);
                }
            }
        }
    }

    // 11. Cross-tenant injection (an account_id in the request body
    // pointing at a different tenant) cannot change which tenant a log
    // row is attributed to -- attribution comes exclusively from the
    // authenticated key's own resolved account, never from request input.
    public function test_cross_tenant_injection_cannot_change_log_attribution(): void
    {
        $tenantA = Account::factory()->create();
        $tenantB = Account::factory()->create();
        $this->giveActiveSubscription($tenantA, 'business');
        $this->giveActiveSubscription($tenantB, 'business');
        $issuedA = $this->issueApiKey($tenantA);

        $this->withHeader('X-API-KEY', $issuedA['key'])
            ->postJson('/api/v1/messages/send-payment-alert', [
                'recipient_phone' => '919999999999',
                'customer_name' => 'Jane Doe',
                'amount' => 499.00,
                'payment_ref' => 'PAY-'.uniqid(),
                'account_id' => $tenantB->id, // injection attempt
            ]);

        $log = ApiRequestLog::sole();
        $this->assertSame($tenantA->id, $log->account_id);
        $this->assertNotSame($tenantB->id, $log->account_id);
    }

    // 12a. A caller-supplied X-Request-Id is reused verbatim: logged on
    // the request log row and echoed back to the caller on the response.
    public function test_caller_supplied_request_id_is_reused_and_echoed_back(): void
    {
        $account = Account::factory()->create();
        $issued = $this->issueApiKey($account);
        $callerRequestId = 'caller-supplied-'.Str::uuid();

        $response = $this->withHeaders([
            'X-API-KEY' => $issued['key'],
            'X-API-SECRET' => $issued['secret'],
            'X-Request-Id' => $callerRequestId,
        ])->postJson('/api/v1/whatsapp/groups/create', ['name' => 'Sales Team']);

        $response->assertCreated();
        $response->assertHeader('X-Request-Id', $callerRequestId);

        $log = ApiRequestLog::sole();
        $this->assertSame($callerRequestId, $log->request_id);
    }

    // 12b. When the caller supplies no X-Request-Id, one is minted
    // automatically -- logged, non-empty, and echoed back on the
    // response -- so every request remains traceable even without the
    // caller's cooperation.
    public function test_request_id_is_generated_when_caller_supplies_none(): void
    {
        $account = Account::factory()->create();
        $issued = $this->issueApiKey($account);

        $response = $this->withHeaders(['X-API-KEY' => $issued['key'], 'X-API-SECRET' => $issued['secret']])
            ->postJson('/api/v1/whatsapp/groups/create', ['name' => 'Sales Team']);

        $response->assertCreated();
        $log = ApiRequestLog::sole();
        $this->assertNotEmpty($log->request_id);
        $response->assertHeader('X-Request-Id', $log->request_id);
    }
}
