<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\ApiKey;
use App\Models\Invoice;
use App\Models\MessageDispatchLog;
use App\Models\WhatsAppSession;
use App\Services\Billing\InvoiceCreditService;
use App\Services\WhatsApp\MetaCloudApiDriver;
use App\Services\WhatsApp\WhatsAppEngineFactory;
use App\Support\PlanCatalog;
use Database\Seeders\Phase1FoundationSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Phase 4 Task 3 -- Meta WhatsApp text send.
 *
 * The send path itself already existed and needed no new architecture:
 * DirectMessageDispatcher is the common flow (authorization ->
 * provider resolution -> validation -> quota -> dispatch -> provider send
 * -> result) and MetaCloudApiDriver already implements
 * WhatsAppDriverInterface against the Graph API. Nothing covered the Meta
 * half of it, so this file is that coverage: it asserts the outbound
 * Graph request itself (URL, payload shape, bearer token), the quota and
 * MessageDispatchLog side effects, the failure mappings onto the existing
 * error contract, and that a QR account is completely unaffected.
 *
 * Every Graph and qr-engine call is faked -- no test here reaches Meta.
 */
class MetaSendMessageTest extends TestCase
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

    private const PHONE_ID = '109876543210987';

    private const TOKEN = 'EAAG_permanent_meta_token_do_not_leak_0123456789';

    private const RECIPIENT = '919999999999';

    private const ENDPOINT = '/api/v1/whatsapp/messages/send';

    /** Mirrors ApiKeyController::store()'s own minting convention. */
    private function issueApiKey(Account $account): array
    {
        $plainKey = 'wasaas_live_'.Str::random(40);
        $plainSecret = 'wasaas_secret_'.Str::random(40);

        ApiKey::create([
            'account_id' => $account->id,
            'name' => 'Test Key',
            'key_prefix' => substr($plainKey, 0, 20),
            'key_hash' => ApiKey::hashKey($plainKey),
            'secret_prefix' => substr($plainSecret, 0, 20),
            'secret_hash' => ApiKey::hashSecret($plainSecret),
        ]);

        return ['key' => $plainKey, 'secret' => $plainSecret];
    }

    private function giveActiveSubscription(Account $account, string $planKey): void
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

    /**
     * A tenant on the Meta provider. The plan key is resolved from
     * PlanCatalog by engine_type rather than named here, so no plan name
     * is pinned to a capability (rule 11).
     */
    private function metaPlanKey(): string
    {
        foreach (PlanCatalog::all() as $key => $plan) {
            if ($plan['engine_type'] === 'meta') {
                return $key;
            }
        }

        $this->fail('No PlanCatalog plan maps to the meta provider.');
    }

    private function qrPlanKey(): string
    {
        foreach (PlanCatalog::all() as $key => $plan) {
            if ($plan['engine_type'] === 'qr') {
                return $key;
            }
        }

        $this->fail('No PlanCatalog plan maps to the qr provider.');
    }

    private function makeMetaTenant(string $phoneId = self::PHONE_ID, array $accountOverrides = []): array
    {
        $account = Account::factory()->create($accountOverrides);
        $this->giveActiveSubscription($account, $this->metaPlanKey());

        WhatsAppSession::create([
            'account_id' => $account->id,
            'status' => 'connected',
            'meta_phone_number_id' => $phoneId,
            'meta_waba_id' => '123456789012345',
            'meta_access_token' => self::TOKEN,
        ]);

        return ['account' => $account, 'issued' => $this->issueApiKey($account)];
    }

    private function makeQrTenant(): array
    {
        $account = Account::factory()->create();
        $this->giveActiveSubscription($account, $this->qrPlanKey());
        WhatsAppSession::create(['account_id' => $account->id, 'status' => 'connected']);

        return ['account' => $account, 'issued' => $this->issueApiKey($account)];
    }

    private function fakeGraphSuccess(): void
    {
        Http::fake([
            'graph.facebook.com/*' => Http::response([
                'messaging_product' => 'whatsapp',
                'contacts' => [['input' => self::RECIPIENT, 'wa_id' => self::RECIPIENT]],
                'messages' => [['id' => 'wamid.HBgMOTE5OTk5OTk5OTk5']],
            ], 200),
            // Any qr-engine call would be a provider-resolution bug.
            '*' => Http::response(['success' => true, 'message_id' => 'QR_SHOULD_NOT_BE_USED'], 200),
        ]);
    }

    /** @param array<string, mixed> $payload */
    private function send(array $issued, array $payload = null)
    {
        return $this->withHeaders([
            'X-API-KEY' => $issued['key'],
            'X-API-SECRET' => $issued['secret'],
        ])->postJson(self::ENDPOINT, $payload ?? $this->validPayload());
    }

    /** @return array<string, mixed> */
    private function validPayload(string $body = 'Hello from the Meta provider.'): array
    {
        return [
            'recipient_type' => 'individual',
            'message_type' => 'text',
            'to' => self::RECIPIENT,
            'text' => ['body' => $body],
        ];
    }

    // ------------------------------------------------------------------
    // Successful Meta text send
    // ------------------------------------------------------------------
    public function test_a_meta_text_message_is_sent_and_returns_the_existing_success_contract(): void
    {
        $this->fakeGraphSuccess();
        $t = $this->makeMetaTenant();

        $response = $this->send($t['issued']);

        $response->assertStatus(200);
        $response->assertJson(['success' => true, 'queued_recipients_count' => 1]);
        $this->assertArrayHasKey('dispatch_id', $response->json());
    }

    public function test_the_outbound_graph_request_uses_the_configured_phone_number_id_and_token(): void
    {
        $this->fakeGraphSuccess();
        $t = $this->makeMetaTenant();

        $this->send($t['issued'])->assertStatus(200);

        Http::assertSent(function (ClientRequest $request) {
            if (! str_contains($request->url(), 'graph.facebook.com')) {
                return false;
            }

            // Endpoint: /{version}/{phone_number_id}/messages
            $this->assertStringContainsString('/'.self::PHONE_ID.'/messages', $request->url());
            // Credentials travel in the Authorization header, never the URL.
            $this->assertSame('Bearer '.self::TOKEN, $request->header('Authorization')[0]);
            $this->assertStringNotContainsString(self::TOKEN, $request->url());

            // Exactly Meta's documented text-message shape.
            $body = $request->data();
            $this->assertSame('whatsapp', $body['messaging_product']);
            $this->assertSame('text', $body['type']);
            $this->assertSame('Hello from the Meta provider.', $body['text']['body']);
            $this->assertArrayNotHasKey('access_token', $body);

            return true;
        });
    }

    public function test_a_successful_meta_send_consumes_quota_and_writes_a_dispatch_log(): void
    {
        $this->fakeGraphSuccess();
        $t = $this->makeMetaTenant();
        $before = $t['account']->fresh()->currentSubscription->used_messages;

        $this->send($t['issued'])->assertStatus(200);

        $this->assertSame($before + 1, $t['account']->fresh()->currentSubscription->used_messages);

        $log = MessageDispatchLog::latest('id')->firstOrFail();
        $this->assertSame($t['account']->id, $log->account_id);
        $this->assertSame('sent', $log->status);
        $this->assertSame('wamid.HBgMOTE5OTk5OTk5OTk5', $log->gateway_message_id);
    }

    // ------------------------------------------------------------------
    // Provider resolution
    // ------------------------------------------------------------------
    public function test_a_meta_account_resolves_to_the_meta_driver_and_never_calls_the_qr_engine(): void
    {
        $this->fakeGraphSuccess();
        $t = $this->makeMetaTenant();

        $account = Account::with(['currentSubscription', 'whatsAppSession'])->find($t['account']->id);
        $this->assertInstanceOf(MetaCloudApiDriver::class, WhatsAppEngineFactory::make($account));

        $this->send($t['issued'])->assertStatus(200);

        Http::assertSent(fn (ClientRequest $r) => str_contains($r->url(), 'graph.facebook.com'));
        Http::assertNotSent(fn (ClientRequest $r) => str_contains($r->url(), '/api/message/send'));
    }

    // ------------------------------------------------------------------
    // Tenant isolation
    // ------------------------------------------------------------------
    public function test_a_send_always_uses_the_authenticated_tenants_own_meta_number(): void
    {
        $this->fakeGraphSuccess();
        $mine = $this->makeMetaTenant('111111111111111');
        $theirs = $this->makeMetaTenant('222222222222222');

        $this->send($mine['issued'])->assertStatus(200);

        // My key sent through MY phone number id, never the other tenant's.
        Http::assertSent(fn (ClientRequest $r) => str_contains($r->url(), '/111111111111111/messages'));
        Http::assertNotSent(fn (ClientRequest $r) => str_contains($r->url(), '/222222222222222/messages'));

        $log = MessageDispatchLog::latest('id')->firstOrFail();
        $this->assertSame($mine['account']->id, $log->account_id);
        $this->assertSame(0, MessageDispatchLog::where('account_id', $theirs['account']->id)->count());
    }

    public function test_an_injected_account_id_in_the_body_is_ignored(): void
    {
        $this->fakeGraphSuccess();
        $mine = $this->makeMetaTenant('111111111111111');
        $theirs = $this->makeMetaTenant('222222222222222');

        $this->send($mine['issued'], [
            ...$this->validPayload(),
            'account_id' => $theirs['account']->id,
        ])->assertStatus(200);

        Http::assertSent(fn (ClientRequest $r) => str_contains($r->url(), '/111111111111111/messages'));
        $this->assertSame(0, MessageDispatchLog::where('account_id', $theirs['account']->id)->count());
    }

    public function test_provider_credentials_are_never_accepted_from_request_input(): void
    {
        $this->fakeGraphSuccess();
        $t = $this->makeMetaTenant();

        $this->send($t['issued'], [
            ...$this->validPayload(),
            'meta_access_token' => 'ATTACKER_SUPPLIED_TOKEN',
            'meta_phone_number_id' => '999999999999999',
        ])->assertStatus(200);

        // The stored credentials were used; the supplied ones were ignored.
        Http::assertSent(function (ClientRequest $r) {
            $this->assertStringContainsString('/'.self::PHONE_ID.'/messages', $r->url());
            $this->assertSame('Bearer '.self::TOKEN, $r->header('Authorization')[0]);
            $this->assertStringNotContainsString('999999999999999', $r->url());

            return true;
        });
        Http::assertNotSent(fn (ClientRequest $r) => $r->hasHeader('Authorization', 'Bearer ATTACKER_SUPPLIED_TOKEN'));
    }

    // ------------------------------------------------------------------
    // Missing Meta configuration
    // ------------------------------------------------------------------
    public function test_a_meta_account_without_credentials_fails_without_calling_graph(): void
    {
        $this->fakeGraphSuccess();
        $account = Account::factory()->create();
        $this->giveActiveSubscription($account, $this->metaPlanKey());
        // Deliberately no WhatsAppSession / no Meta credentials.
        $issued = $this->issueApiKey($account);

        $response = $this->send(['key' => $issued['key'], 'secret' => $issued['secret']]);

        $response->assertStatus(422);
        $response->assertJson(['success' => false, 'error_code' => 'SEND_FAILED']);
        Http::assertNotSent(fn (ClientRequest $r) => str_contains($r->url(), 'graph.facebook.com'));

        $log = MessageDispatchLog::latest('id')->firstOrFail();
        $this->assertSame('failed', $log->status);
    }

    // ------------------------------------------------------------------
    // Meta API failures -> existing error contract
    // ------------------------------------------------------------------
    public function test_invalid_or_expired_credentials_are_reported_through_the_existing_contract(): void
    {
        Http::fake([
            'graph.facebook.com/*' => Http::response([
                'error' => [
                    'message' => 'Error validating access token: Session has expired.',
                    'type' => 'OAuthException',
                    'code' => 190,
                ],
            ], 401),
        ]);
        $t = $this->makeMetaTenant();
        $before = $t['account']->fresh()->currentSubscription->used_messages;

        $response = $this->send($t['issued']);

        $response->assertStatus(422);
        $response->assertJson([
            'success' => false,
            'error_code' => 'SEND_FAILED',
            'message' => 'Error validating access token: Session has expired.',
        ]);
        // A failed send must never consume quota.
        $this->assertSame($before, $t['account']->fresh()->currentSubscription->used_messages);
    }

    public function test_a_meta_server_error_is_mapped_safely(): void
    {
        Http::fake([
            'graph.facebook.com/*' => Http::response(['error' => ['message' => 'Internal server error.']], 500),
        ]);
        $t = $this->makeMetaTenant();

        $response = $this->send($t['issued']);

        $response->assertStatus(422);
        $response->assertJson(['success' => false, 'error_code' => 'SEND_FAILED']);
        $this->assertSame('failed', MessageDispatchLog::latest('id')->firstOrFail()->status);
    }

    public function test_an_unreachable_graph_api_does_not_leak_transport_detail(): void
    {
        Http::fake(function () {
            throw new \Illuminate\Http\Client\ConnectionException(
                'cURL error 6: Could not resolve host: graph.facebook.com for https://graph.facebook.com/v18.0/'.self::PHONE_ID.'/messages'
            );
        });
        $t = $this->makeMetaTenant();

        $response = $this->send($t['issued']);

        $response->assertStatus(422);
        $response->assertJson(['message' => 'Meta Graph API is unreachable.']);
        // The raw cURL string (which embeds the full endpoint) stays internal.
        $this->assertStringNotContainsString('cURL error', $response->getContent());
    }

    // ------------------------------------------------------------------
    // Validation
    // ------------------------------------------------------------------
    public function test_a_missing_recipient_is_rejected_before_any_graph_call(): void
    {
        $this->fakeGraphSuccess();
        $t = $this->makeMetaTenant();

        $response = $this->send($t['issued'], [
            'recipient_type' => 'individual',
            'message_type' => 'text',
            'text' => ['body' => 'Hi'],
        ]);

        $response->assertStatus(422);
        $response->assertJson(['success' => false, 'error_code' => 'VALIDATION_ERROR']);
        Http::assertNothingSent();
    }

    public function test_a_missing_text_body_is_rejected_before_any_graph_call(): void
    {
        $this->fakeGraphSuccess();
        $t = $this->makeMetaTenant();

        $response = $this->send($t['issued'], [
            'recipient_type' => 'individual',
            'message_type' => 'text',
            'to' => self::RECIPIENT,
        ]);

        $response->assertStatus(422);
        $response->assertJson(['success' => false, 'error_code' => 'VALIDATION_ERROR']);
        Http::assertNothingSent();
    }

    public function test_an_unnormalizable_recipient_is_rejected(): void
    {
        $this->fakeGraphSuccess();
        $t = $this->makeMetaTenant();

        $response = $this->send($t['issued'], [
            'recipient_type' => 'individual',
            'message_type' => 'text',
            'to' => 'not-a-number',
            'text' => ['body' => 'Hi'],
        ]);

        $response->assertStatus(422);
        Http::assertNotSent(fn (ClientRequest $r) => str_contains($r->url(), 'graph.facebook.com'));
    }

    // ------------------------------------------------------------------
    // Authorization / quota
    // ------------------------------------------------------------------
    public function test_an_unauthenticated_send_is_rejected(): void
    {
        $this->fakeGraphSuccess();

        $this->postJson(self::ENDPOINT, $this->validPayload())->assertStatus(401);
        Http::assertNothingSent();
    }

    public function test_a_key_without_its_secret_is_rejected_on_this_tier(): void
    {
        $this->fakeGraphSuccess();
        $t = $this->makeMetaTenant();

        $this->withHeaders(['X-API-KEY' => $t['issued']['key']])
            ->postJson(self::ENDPOINT, $this->validPayload())
            ->assertStatus(401);

        Http::assertNothingSent();
    }

    public function test_an_inactive_account_cannot_send(): void
    {
        $this->fakeGraphSuccess();
        $t = $this->makeMetaTenant(self::PHONE_ID, ['status' => 'inactive']);

        $this->send($t['issued'])->assertStatus(401);
        Http::assertNothingSent();
    }

    public function test_a_revoked_key_cannot_send(): void
    {
        $this->fakeGraphSuccess();
        $t = $this->makeMetaTenant();
        ApiKey::where('account_id', $t['account']->id)->update(['revoked_at' => now()]);

        $this->send($t['issued'])->assertStatus(401);
        Http::assertNothingSent();
    }

    /**
     * The entitlement gate that actually applies to this endpoint is the
     * subscription/quota one -- there is no module.guard on the
     * /v1/whatsapp/* route group (see routes/api.php), so this asserts the
     * real gate rather than inventing one.
     */
    public function test_an_exhausted_quota_is_denied_with_the_existing_contract(): void
    {
        $this->fakeGraphSuccess();
        $t = $this->makeMetaTenant();

        $subscription = $t['account']->fresh()->currentSubscription;
        $subscription->forceFill(['used_messages' => $subscription->total_allocated_messages])->save();
        $subscription->refreshStatus();

        $response = $this->send($t['issued']);

        $response->assertStatus(402);
        $response->assertJson(['success' => false, 'error_code' => 'INSUFFICIENT_QUOTA']);
        Http::assertNotSent(fn (ClientRequest $r) => str_contains($r->url(), 'graph.facebook.com'));
    }

    // ------------------------------------------------------------------
    // QR regression
    // ------------------------------------------------------------------
    public function test_a_qr_account_still_sends_through_the_qr_engine_not_graph(): void
    {
        Http::fake([
            '*/api/message/send' => Http::response(['success' => true, 'message_id' => 'QR_OK'], 200),
            'graph.facebook.com/*' => Http::response(['error' => ['message' => 'should not be called']], 500),
        ]);
        $t = $this->makeQrTenant();

        $response = $this->send($t['issued']);

        $response->assertStatus(200);
        $response->assertJson(['success' => true]);

        Http::assertSent(fn (ClientRequest $r) => str_contains($r->url(), '/api/message/send'));
        Http::assertNotSent(fn (ClientRequest $r) => str_contains($r->url(), 'graph.facebook.com'));
        $this->assertSame('QR_OK', MessageDispatchLog::latest('id')->firstOrFail()->gateway_message_id);
    }

    public function test_a_meta_send_by_one_tenant_does_not_disturb_a_qr_tenant(): void
    {
        Http::fake([
            'graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.X']]], 200),
            '*/api/message/send' => Http::response(['success' => true, 'message_id' => 'QR_OK'], 200),
        ]);
        $meta = $this->makeMetaTenant();
        $qr = $this->makeQrTenant();

        $this->send($meta['issued'])->assertStatus(200);
        $this->send($qr['issued'])->assertStatus(200);

        $this->assertSame(
            'wamid.X',
            MessageDispatchLog::where('account_id', $meta['account']->id)->latest('id')->firstOrFail()->gateway_message_id,
        );
        $this->assertSame(
            'QR_OK',
            MessageDispatchLog::where('account_id', $qr['account']->id)->latest('id')->firstOrFail()->gateway_message_id,
        );
    }

    // ------------------------------------------------------------------
    // Token hygiene
    // ------------------------------------------------------------------
    public function test_the_access_token_never_appears_in_the_response_on_success_or_failure(): void
    {
        $this->fakeGraphSuccess();
        $t = $this->makeMetaTenant();
        $ok = $this->send($t['issued']);

        Http::fake(['graph.facebook.com/*' => Http::response(['error' => ['message' => 'Bad token.']], 401)]);
        $failed = $this->send($t['issued']);

        foreach ([$ok, $failed] as $response) {
            $this->assertStringNotContainsString(self::TOKEN, $response->getContent());
            $this->assertStringNotContainsString('EAAG', $response->getContent());
        }
    }

    public function test_the_access_token_is_never_written_to_the_log(): void
    {
        $lines = [];
        Log::listen(function ($message) use (&$lines) {
            $lines[] = $message->message.' '.json_encode($message->context);
        });

        // A success, a Meta rejection and a transport failure — the three
        // paths that could plausibly log request detail.
        $this->fakeGraphSuccess();
        $t = $this->makeMetaTenant();
        $this->send($t['issued']);

        Http::fake(['graph.facebook.com/*' => Http::response(['error' => ['message' => 'Bad token.']], 401)]);
        $this->send($t['issued']);

        Http::fake(function () {
            throw new \Illuminate\Http\Client\ConnectionException('cURL error 6');
        });
        $this->send($t['issued']);

        $logged = implode("\n", $lines);
        $this->assertStringNotContainsString(self::TOKEN, $logged);
        $this->assertStringNotContainsString('EAAG', $logged);
    }

    public function test_the_access_token_never_lands_in_the_dispatch_log(): void
    {
        Http::fake(['graph.facebook.com/*' => Http::response(['error' => ['message' => 'Bad token.']], 401)]);
        $t = $this->makeMetaTenant();

        $this->send($t['issued']);

        $log = MessageDispatchLog::latest('id')->firstOrFail();
        $this->assertStringNotContainsString(self::TOKEN, json_encode($log->getAttributes()));
        $this->assertStringNotContainsString('EAAG', json_encode($log->getAttributes()));
    }

    /**
     * Regression for the defect this task found: gateway_message_id was
     * passed into MessageDispatchLog::create() but missing from $fillable,
     * so it was silently dropped and the column was never written.
     *
     * This asserts the exact lookup MetaWebhookController::
     * correlateFailedStatus() performs, so a post-send rejection from Meta
     * can actually be matched back to the send it belongs to.
     */
    public function test_the_meta_wamid_is_persisted_so_a_status_callback_can_correlate(): void
    {
        $this->fakeGraphSuccess();
        $t = $this->makeMetaTenant();

        $this->send($t['issued'])->assertStatus(200);

        $wamid = 'wamid.HBgMOTE5OTk5OTk5OTk5';

        // The literal query MetaWebhookController runs against an inbound
        // 'failed' status event.
        $correlated = MessageDispatchLog::where('gateway_message_id', $wamid)->first();

        $this->assertNotNull($correlated, 'a Meta status callback could not be correlated to its dispatch log');
        $this->assertSame($t['account']->id, $correlated->account_id);
        $this->assertSame('sent', $correlated->status);
    }

    public function test_a_failed_send_records_no_gateway_message_id(): void
    {
        Http::fake(['graph.facebook.com/*' => Http::response(['error' => ['message' => 'Bad token.']], 401)]);
        $t = $this->makeMetaTenant();

        $this->send($t['issued'])->assertStatus(422);

        $log = MessageDispatchLog::latest('id')->firstOrFail();
        $this->assertSame('failed', $log->status);
        $this->assertNull($log->gateway_message_id);
    }
}
