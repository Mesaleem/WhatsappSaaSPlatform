<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\ApiIdempotencyKey;
use App\Models\ApiKey;
use App\Models\ApiRequestLog;
use App\Models\Invoice;
use App\Models\MessageDispatchLog;
use App\Models\MessageTemplate;
use App\Models\WhatsAppSession;
use App\Services\Billing\InvoiceCreditService;
use App\Support\PlanCatalog;
use Database\Seeders\Phase1FoundationSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Phase 3 Task 5 -- regression coverage for the OPTIONAL Idempotency-Key
 * protection on the external Developer API's send operations
 * (EnsureIdempotentApiRequest + ApiIdempotencyKey against the
 * pre-existing api_idempotency_keys table).
 *
 * Helper conventions (issueApiKey / giveActiveSubscription /
 * makeApprovedTemplate) are copied deliberately from
 * PublicApiSecurityTest rather than extracted into a shared base class:
 * that file established them, no ApiKeyFactory exists in this codebase,
 * and refactoring another task's passing test file is out of scope here.
 *
 * "One outbound message" is asserted two independent ways throughout --
 * Http::assertSentCount() (the actual qr-engine-service call
 * BaileysDriver makes) AND the MessageDispatchLog row count (this app's
 * own send ledger) -- so a regression that suppressed one signal but not
 * the other still fails.
 */
class PublicApiIdempotencyTest extends TestCase
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

    /** Mirrors ApiKeyController::store()'s own key/secret minting convention exactly. */
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

    /** Real payment-success path (InvoiceCreditService), same convention PublicApiSecurityTest uses. */
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

    /**
     * The 'starter' plan is the `qr` engine (PlanCatalog), so a send goes
     * through BaileysDriver -> POST {qr_engine}/api/message/send, which
     * Http::fake() below intercepts. A 'connected' session is required or
     * PaymentAlertDispatcher::isWhatsAppDisconnected() short-circuits the
     * dispatcher before the engine is ever reached.
     */
    private function connectSession(Account $account): void
    {
        WhatsAppSession::create([
            'account_id' => $account->id,
            'status' => 'connected',
        ]);
    }

    /**
     * A fully send-capable tenant: active qr subscription, connected
     * session, one approved template, one API key.
     *
     * $templateCode = null skips template creation, for the cross-tenant
     * test below which deliberately shares ONE global (account_id = null)
     * template between two tenants -- message_templates.template_code is
     * globally unique (its creating migration adds $table->unique(
     * 'template_code')), so two tenants cannot each own a template with
     * the same code, and a global template is the only way to send a
     * byte-identical payload as two different tenants.
     */
    private function makeSendableTenant(?string $templateCode = 'WELCOME'): array
    {
        $account = Account::factory()->create();
        $this->giveActiveSubscription($account);
        $this->connectSession($account);

        if ($templateCode !== null) {
            $this->makeApprovedTemplate($account->id, $templateCode);
        }

        return ['account' => $account, 'issued' => $this->issueApiKey($account)];
    }

    private function fakeEngineSuccess(): void
    {
        Http::fake(['*' => Http::response(['success' => true, 'message_id' => 'wamid.TEST'], 200)]);
    }

    private function fakeEngineFailure(): void
    {
        Http::fake(['*' => Http::response(['success' => false, 'error' => 'device offline'], 500)]);
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function sendTemplate(string $apiKey, array $payload, ?string $idempotencyKey = null)
    {
        $headers = ['X-API-KEY' => $apiKey];

        if ($idempotencyKey !== null) {
            $headers['Idempotency-Key'] = $idempotencyKey;
        }

        return $this->withHeaders($headers)->postJson('/api/v1/messages/send-template', $payload);
    }

    /** @return array<string, mixed> */
    private function validPayload(string $templateCode = 'WELCOME', string $phone = '919999999999'): array
    {
        return ['template_code' => $templateCode, 'recipient_phone' => $phone];
    }

    // ------------------------------------------------------------------
    // 1. First request succeeds normally.
    // ------------------------------------------------------------------
    public function test_first_request_with_an_idempotency_key_succeeds_normally(): void
    {
        $this->fakeEngineSuccess();
        $tenant = $this->makeSendableTenant();

        $response = $this->sendTemplate($tenant['issued']['key'], $this->validPayload(), 'idem-key-001');

        $response->assertStatus(200);
        $response->assertExactJson(['status' => true, 'message' => 'Message sent.']);
        Http::assertSentCount(1);
        $this->assertSame(1, MessageDispatchLog::count());

        $record = ApiIdempotencyKey::query()
            ->where('account_id', $tenant['account']->id)
            ->where('idempotency_key', 'idem-key-001')
            ->firstOrFail();

        $this->assertSame(ApiIdempotencyKey::STATUS_COMPLETED, $record->status);
        $this->assertSame(200, $record->response_status);
    }

    // ------------------------------------------------------------------
    // 2. Same key + same payload does not send twice.
    // ------------------------------------------------------------------
    public function test_same_key_and_same_payload_does_not_send_twice(): void
    {
        $this->fakeEngineSuccess();
        $tenant = $this->makeSendableTenant();

        $first = $this->sendTemplate($tenant['issued']['key'], $this->validPayload(), 'idem-key-002');
        $second = $this->sendTemplate($tenant['issued']['key'], $this->validPayload(), 'idem-key-002');

        $first->assertStatus(200);
        $second->assertStatus(200);

        // The whole point: exactly ONE outbound WhatsApp message and ONE
        // ledger row, despite two accepted HTTP requests.
        Http::assertSentCount(1);
        $this->assertSame(1, MessageDispatchLog::count());
        $this->assertSame(1, ApiIdempotencyKey::query()->where('idempotency_key', 'idem-key-002')->count());
    }

    // ------------------------------------------------------------------
    // 3. Same key returns the original result.
    // ------------------------------------------------------------------
    public function test_same_key_returns_the_original_stored_result(): void
    {
        $this->fakeEngineSuccess();
        $tenant = $this->makeSendableTenant();

        $first = $this->sendTemplate($tenant['issued']['key'], $this->validPayload(), 'idem-key-003');
        $second = $this->sendTemplate($tenant['issued']['key'], $this->validPayload(), 'idem-key-003');

        // Byte-identical body and identical status code -- replayed, not
        // re-derived.
        $this->assertSame($first->getStatusCode(), $second->getStatusCode());
        $this->assertSame($first->getContent(), $second->getContent());

        // The replay is distinguishable by header only; the BODY is
        // untouched (this feature redesigns no response envelope).
        $this->assertNull($first->headers->get('Idempotent-Replay'));
        $this->assertSame('true', $second->headers->get('Idempotent-Replay'));
    }

    // ------------------------------------------------------------------
    // 4. Same key + different payload is rejected.
    // ------------------------------------------------------------------
    public function test_same_key_with_a_different_payload_is_rejected(): void
    {
        $this->fakeEngineSuccess();
        $tenant = $this->makeSendableTenant();

        $this->sendTemplate($tenant['issued']['key'], $this->validPayload(), 'idem-key-004')->assertStatus(200);

        // Same key, genuinely different request (different recipient).
        $conflict = $this->sendTemplate(
            $tenant['issued']['key'],
            $this->validPayload('WELCOME', '918888888888'),
            'idem-key-004',
        );

        $conflict->assertStatus(409);
        $conflict->assertJson(['status' => false, 'error_code' => 'IDEMPOTENCY_KEY_REUSED']);

        // Critically: the different payload was NOT silently treated as
        // the original, and it also did not send.
        Http::assertSentCount(1);
        $this->assertSame(1, MessageDispatchLog::count());
    }

    // ------------------------------------------------------------------
    // 5. Same key on a different tenant is independent.
    // ------------------------------------------------------------------
    public function test_same_key_on_a_different_tenant_is_independent(): void
    {
        $this->fakeEngineSuccess();
        $tenantA = $this->makeSendableTenant(null);
        $tenantB = $this->makeSendableTenant(null);
        // One global template both tenants may send, so the two requests
        // below are byte-identical apart from the API key.
        $this->makeApprovedTemplate(null, 'GLOBAL_WELCOME');

        $payload = $this->validPayload('GLOBAL_WELCOME');

        $a = $this->sendTemplate($tenantA['issued']['key'], $payload, 'shared-key');
        $b = $this->sendTemplate($tenantB['issued']['key'], $payload, 'shared-key');

        $a->assertStatus(200);
        // Tenant B must NOT be served tenant A's stored response, and must
        // not be blocked by tenant A's key.
        $b->assertStatus(200);
        $this->assertNull($b->headers->get('Idempotent-Replay'));

        Http::assertSentCount(2);
        $this->assertSame(2, MessageDispatchLog::count());
        $this->assertSame(2, ApiIdempotencyKey::query()->where('idempotency_key', 'shared-key')->count());

        // Each row is scoped to its own account.
        $this->assertSame(1, ApiIdempotencyKey::query()
            ->where('idempotency_key', 'shared-key')
            ->where('account_id', $tenantA['account']->id)
            ->count());
        $this->assertSame(1, ApiIdempotencyKey::query()
            ->where('idempotency_key', 'shared-key')
            ->where('account_id', $tenantB['account']->id)
            ->count());
    }

    // ------------------------------------------------------------------
    // 6. Missing Idempotency-Key preserves existing behavior.
    // ------------------------------------------------------------------
    public function test_missing_idempotency_key_preserves_existing_behavior(): void
    {
        $this->fakeEngineSuccess();
        $tenant = $this->makeSendableTenant();

        $first = $this->sendTemplate($tenant['issued']['key'], $this->validPayload());
        $second = $this->sendTemplate($tenant['issued']['key'], $this->validPayload());

        $first->assertStatus(200);
        $second->assertStatus(200);

        // Pre-existing behaviour is exactly preserved: no header means no
        // deduplication, so both requests really send.
        Http::assertSentCount(2);
        $this->assertSame(2, MessageDispatchLog::count());

        // And nothing is recorded for a caller who never opted in.
        $this->assertSame(0, ApiIdempotencyKey::count());
    }

    public function test_blank_idempotency_key_header_is_treated_as_absent(): void
    {
        $this->fakeEngineSuccess();
        $tenant = $this->makeSendableTenant();

        $this->sendTemplate($tenant['issued']['key'], $this->validPayload(), '   ')->assertStatus(200);

        $this->assertSame(0, ApiIdempotencyKey::count());
    }

    // ------------------------------------------------------------------
    // 7. Concurrent/simulated duplicate requests cannot create two sends.
    // ------------------------------------------------------------------
    public function test_concurrent_duplicate_requests_cannot_create_two_sends(): void
    {
        $this->fakeEngineSuccess();
        $tenant = $this->makeSendableTenant();

        // Request A: completes, which stores the genuine fingerprint for
        // this exact payload.
        $this->sendTemplate($tenant['issued']['key'], $this->validPayload(), 'idem-key-007')->assertStatus(200);

        // Now rewind that row to 'processing' to model the real race
        // window: request A has claimed the key and is still inside the
        // Message Engine, with no response recorded yet, at the instant
        // request B arrives. (Simulated deterministically rather than with
        // real threads -- PHPUnit is single-process.)
        ApiIdempotencyKey::query()
            ->where('account_id', $tenant['account']->id)
            ->where('idempotency_key', 'idem-key-007')
            ->update([
                'status' => ApiIdempotencyKey::STATUS_PROCESSING,
                'response_status' => null,
                'response_body' => null,
            ]);

        $b = $this->sendTemplate($tenant['issued']['key'], $this->validPayload(), 'idem-key-007');

        // B is refused rather than being allowed to send a second message
        // or to replay a response that does not exist yet.
        $b->assertStatus(409);
        $b->assertJson(['status' => false, 'error_code' => 'IDEMPOTENCY_REQUEST_IN_PROGRESS']);

        // Still exactly one outbound message (A's) and one claim row --
        // proving the unique(['account_id','idempotency_key']) index, not
        // an exists() pre-check, is what stopped B.
        Http::assertSentCount(1);
        $this->assertSame(1, MessageDispatchLog::count());
        $this->assertSame(1, ApiIdempotencyKey::query()->where('idempotency_key', 'idem-key-007')->count());
    }

    // ------------------------------------------------------------------
    // 8. Failed validation does not permanently consume the key.
    // ------------------------------------------------------------------
    public function test_failed_validation_does_not_permanently_consume_the_key(): void
    {
        $this->fakeEngineSuccess();
        $tenant = $this->makeSendableTenant();

        // recipient_phone omitted -> SendTemplateByCodeRequest's own 422.
        $invalid = $this->sendTemplate($tenant['issued']['key'], ['template_code' => 'WELCOME'], 'idem-key-008');
        $invalid->assertStatus(422);

        // Nothing was sent, so the key must be released, not burned.
        Http::assertSentCount(0);
        $this->assertSame(0, ApiIdempotencyKey::query()->where('idempotency_key', 'idem-key-008')->count());

        // The same key now works for a corrected request.
        $this->sendTemplate($tenant['issued']['key'], $this->validPayload(), 'idem-key-008')->assertStatus(200);
        Http::assertSentCount(1);
    }

    // ------------------------------------------------------------------
    // 9. Authorization failure does not create a successful record.
    // ------------------------------------------------------------------
    public function test_authorization_failure_does_not_create_a_successful_idempotency_record(): void
    {
        $this->fakeEngineSuccess();
        $tenant = $this->makeSendableTenant();

        // A real template code that belongs to a DIFFERENT tenant: the
        // controller's account-scoped lookup returns the generic 404.
        $other = Account::factory()->create();
        $this->makeApprovedTemplate($other->id, 'OTHER_TENANT_TEMPLATE');

        $denied = $this->sendTemplate(
            $tenant['issued']['key'],
            $this->validPayload('OTHER_TENANT_TEMPLATE'),
            'idem-key-009',
        );

        $denied->assertStatus(404);
        Http::assertSentCount(0);

        // No record at all -- and in particular no 'completed' record that
        // a later retry could replay as if the send had succeeded.
        $this->assertSame(0, ApiIdempotencyKey::query()->where('idempotency_key', 'idem-key-009')->count());
    }

    public function test_authentication_failure_creates_no_idempotency_record(): void
    {
        $response = $this->sendTemplate('wasaas_live_totally_bogus_key', $this->validPayload(), 'idem-key-009b');

        $response->assertStatus(401);
        $this->assertSame(0, ApiIdempotencyKey::count());
    }

    // ------------------------------------------------------------------
    // 10. Provider/send failure can be retried safely.
    // ------------------------------------------------------------------
    public function test_provider_send_failure_can_be_retried_safely_with_the_same_key(): void
    {
        $tenant = $this->makeSendableTenant();

        // A sequence, not two Http::fake() calls: Laravel evaluates fake
        // stubs in registration order and the FIRST match wins, so a
        // second Http::fake(['*' => ...]) would never take effect.
        Http::fakeSequence()
            ->push(['success' => false, 'error' => 'device offline'], 500)
            ->push(['success' => true, 'message_id' => 'wamid.TEST'], 200);

        // qr-engine-service reports failure -> dispatcher returns 'failed'
        // -> this endpoint's existing 422.
        $failed = $this->sendTemplate($tenant['issued']['key'], $this->validPayload(), 'idem-key-010');
        $failed->assertStatus(422);

        // A transient provider failure must not be cached as a success.
        $this->assertSame(0, ApiIdempotencyKey::query()->where('idempotency_key', 'idem-key-010')->count());

        // Retry with the SAME key once the provider recovers.
        $retried = $this->sendTemplate($tenant['issued']['key'], $this->validPayload(), 'idem-key-010');
        $retried->assertStatus(200);
        $retried->assertJson(['status' => true]);
        $this->assertNull($retried->headers->get('Idempotent-Replay'));

        $record = ApiIdempotencyKey::query()->where('idempotency_key', 'idem-key-010')->firstOrFail();
        $this->assertSame(ApiIdempotencyKey::STATUS_COMPLETED, $record->status);
    }

    // ------------------------------------------------------------------
    // 11. Existing API response envelopes remain unchanged.
    // ------------------------------------------------------------------
    public function test_send_template_envelope_is_unchanged_on_first_call_and_on_replay(): void
    {
        $this->fakeEngineSuccess();
        $tenant = $this->makeSendableTenant();

        $first = $this->sendTemplate($tenant['issued']['key'], $this->validPayload(), 'idem-env-1');
        // The pre-existing {status: bool, message: string} contract.
        $first->assertExactJson(['status' => true, 'message' => 'Message sent.']);

        $replay = $this->sendTemplate($tenant['issued']['key'], $this->validPayload(), 'idem-env-1');
        $replay->assertExactJson(['status' => true, 'message' => 'Message sent.']);
    }

    public function test_send_message_envelope_is_unchanged_on_first_call_and_on_replay(): void
    {
        $this->fakeEngineSuccess();
        $tenant = $this->makeSendableTenant();

        $payload = [
            'recipient_type' => 'individual',
            'template_code' => 'WELCOME',
            'recipient_phone' => '919999999999',
        ];

        $first = $this->withHeaders(['X-API-KEY' => $tenant['issued']['key'], 'Idempotency-Key' => 'idem-env-2'])
            ->postJson('/api/v1/send-message', $payload);

        // This endpoint's own, deliberately different {success: ...}
        // envelope -- preserved, not normalised to the other one.
        $first->assertStatus(200);
        $first->assertJson(['success' => true, 'queued_recipients_count' => 1]);
        $this->assertArrayHasKey('dispatch_id', $first->json());

        $replay = $this->withHeaders(['X-API-KEY' => $tenant['issued']['key'], 'Idempotency-Key' => 'idem-env-2'])
            ->postJson('/api/v1/send-message', $payload);

        $this->assertSame($first->getContent(), $replay->getContent());
        Http::assertSentCount(1);
        $this->assertSame(1, MessageDispatchLog::count());
    }

    public function test_payment_alert_envelope_is_unchanged_and_is_not_queued_twice(): void
    {
        // Queue::fake() keeps ProcessPaymentAlertJob from running inline
        // (QUEUE_CONNECTION=sync in phpunit.xml) -- the 202 "queued"
        // response is this endpoint's completion point, which is what is
        // under test here.
        Queue::fake();
        $account = Account::factory()->create();
        $this->giveActiveSubscription($account);
        $this->connectSession($account);
        $issued = $this->issueApiKey($account);

        $payload = [
            'recipient_phone' => '919999999999',
            'customer_name' => 'Test Customer',
            'amount' => 499.50,
            'payment_ref' => 'PAY-REF-001',
        ];

        $first = $this->withHeaders(['X-API-KEY' => $issued['key'], 'Idempotency-Key' => 'idem-env-3'])
            ->postJson('/api/v1/messages/send-payment-alert', $payload);

        $first->assertStatus(202);
        // The pre-existing {message, alert:{...}} envelope.
        $first->assertJson([
            'message' => 'Payment alert queued.',
            'alert' => ['status' => 'queued', 'payment_ref' => 'PAY-REF-001'],
        ]);

        $replay = $this->withHeaders(['X-API-KEY' => $issued['key'], 'Idempotency-Key' => 'idem-env-3'])
            ->postJson('/api/v1/messages/send-payment-alert', $payload);

        // Replayed 202 verbatim -- and NOT the 409 the payment_ref dedup
        // would otherwise have produced, because the operation never ran
        // a second time at all.
        $replay->assertStatus(202);
        $this->assertSame($first->getContent(), $replay->getContent());
        Queue::assertPushed(\App\Jobs\ProcessPaymentAlertJob::class, 1);
    }

    // ------------------------------------------------------------------
    // 12. Existing authentication remains unchanged.
    // ------------------------------------------------------------------
    public function test_existing_authentication_remains_unchanged_with_an_idempotency_key(): void
    {
        // Invalid key -> the same 401 envelope as before this feature.
        $invalid = $this->sendTemplate('wasaas_live_totally_bogus_key', $this->validPayload(), 'idem-key-012');
        $invalid->assertStatus(401);
        $invalid->assertJson(['status' => false, 'message' => 'Invalid or missing Client API Key.']);

        // Revoked key -> unchanged 401.
        $account = Account::factory()->create();
        $issued = $this->issueApiKey($account);
        $issued['model']->forceFill(['revoked_at' => now()])->save();

        $revoked = $this->sendTemplate($issued['key'], $this->validPayload(), 'idem-key-012b');
        $revoked->assertStatus(401);
        $revoked->assertJson(['status' => false, 'message' => 'This Client API Key has been revoked.']);

        $this->assertSame(0, ApiIdempotencyKey::count());
    }

    public function test_dual_factor_route_still_requires_the_secret_with_an_idempotency_key(): void
    {
        $tenant = $this->makeSendableTenant();

        // X-API-KEY only, no X-API-SECRET, on the dual-factor tier.
        $response = $this->withHeaders([
            'X-API-KEY' => $tenant['issued']['key'],
            'Idempotency-Key' => 'idem-key-012c',
        ])->postJson('/api/v1/whatsapp/messages/send', [
            'recipient_type' => 'individual',
            'message_type' => 'text',
            'to' => '919999999999',
            'text' => ['body' => 'hello'],
        ]);

        $response->assertStatus(401);
        $this->assertSame(0, ApiIdempotencyKey::count());
    }

    public function test_dual_factor_route_is_idempotent_when_authenticated(): void
    {
        $this->fakeEngineSuccess();
        $tenant = $this->makeSendableTenant();

        $headers = [
            'X-API-KEY' => $tenant['issued']['key'],
            'X-API-SECRET' => $tenant['issued']['secret'],
            'Idempotency-Key' => 'idem-key-012d',
        ];
        $payload = [
            'recipient_type' => 'individual',
            'message_type' => 'text',
            'to' => '919999999999',
            'text' => ['body' => 'hello'],
        ];

        $first = $this->withHeaders($headers)->postJson('/api/v1/whatsapp/messages/send', $payload);
        $second = $this->withHeaders($headers)->postJson('/api/v1/whatsapp/messages/send', $payload);

        $first->assertStatus(200);
        $second->assertStatus(200);
        $this->assertSame($first->getContent(), $second->getContent());
        Http::assertSentCount(1);
        $this->assertSame(1, MessageDispatchLog::count());
    }

    // ------------------------------------------------------------------
    // Security: a key never leaks payload content or credentials.
    // ------------------------------------------------------------------
    public function test_stored_record_holds_no_message_content_or_credentials(): void
    {
        $this->fakeEngineSuccess();
        $tenant = $this->makeSendableTenant();

        $this->sendTemplate($tenant['issued']['key'], $this->validPayload('WELCOME', '919123456789'), 'idem-sec-1')
            ->assertStatus(200);

        $record = ApiIdempotencyKey::query()->where('idempotency_key', 'idem-sec-1')->firstOrFail();

        // The fingerprint is a one-way hash, not the payload.
        $this->assertSame(64, strlen((string) $record->request_fingerprint));
        $this->assertStringNotContainsString('919123456789', (string) $record->request_fingerprint);

        // The API key/secret never appear anywhere on the row.
        $serialized = json_encode($record->getAttributes());
        $this->assertStringNotContainsString($tenant['issued']['key'], $serialized);
        $this->assertStringNotContainsString($tenant['issued']['secret'], $serialized);
    }

    // ------------------------------------------------------------------
    // Task 7 audit — gaps the Task 5 suite left unpinned.
    // ------------------------------------------------------------------

    /**
     * A key is scoped to (account, key) — NOT to an endpoint — so the
     * fingerprint is what stops one endpoint's stored response being
     * replayed for a different endpoint. Without this, a caller reusing a
     * key across two operations could be handed the wrong operation's
     * result as a "success".
     */
    public function test_same_key_on_a_different_endpoint_is_rejected_and_does_not_send(): void
    {
        $this->fakeEngineSuccess();
        $tenant = $this->makeSendableTenant();

        $this->sendTemplate($tenant['issued']['key'], $this->validPayload(), 'cross-endpoint-key')
            ->assertStatus(200);

        $second = $this->withHeaders([
            'X-API-KEY' => $tenant['issued']['key'],
            'Idempotency-Key' => 'cross-endpoint-key',
        ])->postJson('/api/v1/send-message', [
            'recipient_type' => 'individual',
            'template_code' => 'WELCOME',
            'recipient_phone' => '919999999999',
        ]);

        $second->assertStatus(409);
        $second->assertJson(['success' => false, 'error_code' => 'IDEMPOTENCY_KEY_REUSED']);

        Http::assertSentCount(1);
        $this->assertSame(1, MessageDispatchLog::count());
    }

    /**
     * LogApiRequestMiddleware sits OUTSIDE the idempotency guard, so the
     * 409 it returns must still be observable in api_request_logs with
     * the real status and the resolved account — otherwise a caller's
     * duplicate-key problem would be invisible to support.
     */
    public function test_idempotency_conflict_is_request_logged_with_its_real_status(): void
    {
        $this->fakeEngineSuccess();
        $tenant = $this->makeSendableTenant();

        $this->sendTemplate($tenant['issued']['key'], $this->validPayload(), 'logged-conflict')->assertStatus(200);
        $this->sendTemplate($tenant['issued']['key'], $this->validPayload('WELCOME', '918888888888'), 'logged-conflict')
            ->assertStatus(409);

        $conflictLog = ApiRequestLog::query()->where('status_code', 409)->latest('id')->first();

        $this->assertNotNull($conflictLog);
        $this->assertSame($tenant['account']->id, $conflictLog->account_id);
        $this->assertSame('api/v1/messages/send-template', $conflictLog->path);
        $this->assertNotEmpty($conflictLog->request_id);
    }

    /**
     * An entitlement/module denial is a non-2xx like any other failure:
     * nothing was sent, so the key must be released for a genuine retry
     * once the module is enabled. Ties the module gate (Task 2/3 scope)
     * to the idempotency cleanup contract.
     */
    public function test_module_entitlement_denial_does_not_consume_the_key(): void
    {
        $account = Account::factory()->create([
            'allowed_modules' => ['dashboard', 'send_alert'], // contact_groups deliberately absent
        ]);
        $this->giveActiveSubscription($account);
        $this->connectSession($account);
        $issued = $this->issueApiKey($account);

        $denied = $this->withHeaders([
            'X-API-KEY' => $issued['key'],
            'X-API-SECRET' => $issued['secret'],
            'Idempotency-Key' => 'module-denied-key',
        ])->postJson('/api/v1/whatsapp/groups/create', [
            'name' => 'Test group',
            'group_type' => 'internal_segment',
        ]);

        $denied->assertStatus(403);
        $denied->assertJson(['error_code' => 'GROUP_MODULE_DISABLED']);

        $this->assertSame(0, ApiIdempotencyKey::query()->where('idempotency_key', 'module-denied-key')->count());
    }
}
