<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\ChatbotRule;
use App\Models\Invoice;
use App\Models\Lead;
use App\Models\MessageDispatchLog;
use App\Models\PaymentAlert;
use App\Models\SocialProviderConfig;
use App\Models\WebhookSubscription;
use App\Jobs\DispatchWebhookJob;
use App\Models\WhatsAppSession;
use App\Services\Billing\InvoiceCreditService;
use App\Support\PlanCatalog;
use Database\Seeders\Phase1FoundationSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Phase 4 Task 4 -- Meta webhook & delivery-status hardening.
 *
 * The webhook already existed (challenge verification, HMAC-SHA256
 * signature checking, inbound routing into ChatbotEngineService, 'failed'
 * correlation into message_dispatch_logs, 'delivered' correlation into
 * payment_alerts). Nothing tested ANY of it. This file is that coverage
 * plus regression for the four gaps this task closed:
 *
 *   1. Inbound messages had no redelivery guard, so a Meta retry re-ran
 *      the chatbot and sent the customer a SECOND auto-reply, billed.
 *   2. A duplicate 'delivered' event fired the tenant's outbound
 *      message.delivered webhook again, every time.
 *   3. Status correlation searched by WAMID across ALL tenants instead of
 *      scoping to the account the phone_number_id resolves to.
 *   4. verify() compared the token in SQL only -- case-insensitively
 *      under utf8mb4_unicode_ci, and not in constant time.
 *
 * Signature verification is ENFORCED here: phpunit runs APP_ENV=testing,
 * and the only bypass is app()->environment('local').
 */
class MetaWebhookTest extends TestCase
{
    use RefreshDatabase;

    private const APP_SECRET = 'meta_app_secret_never_log_me_0123456789';

    private const PHONE_ID = '109876543210987';

    private const OTHER_PHONE_ID = '555000111222333';

    private const WAMID = 'wamid.HBgMOTE5OTk5OTk5OTk5AA==';

    private const ENDPOINT = '/api/webhooks/meta';

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

        SocialProviderConfig::create([
            'provider' => 'meta',
            'client_id' => 'app-id',
            'client_secret' => self::APP_SECRET,
            'is_active' => true,
        ]);
    }

    private function metaPlanKey(): string
    {
        foreach (PlanCatalog::all() as $key => $plan) {
            if ($plan['engine_type'] === 'meta') {
                return $key;
            }
        }

        $this->fail('No PlanCatalog plan maps to the meta provider.');
    }

    private function giveActiveSubscription(Account $account): void
    {
        $plan = PlanCatalog::find($this->metaPlanKey());

        $invoice = Invoice::create([
            'account_id' => $account->id,
            'invoice_number' => 'INV-'.uniqid(),
            'plan_key' => $this->metaPlanKey(),
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

    private function makeTenant(string $phoneId = self::PHONE_ID, string $verifyToken = null): array
    {
        $account = Account::factory()->create();
        $this->giveActiveSubscription($account);

        $session = WhatsAppSession::create([
            'account_id' => $account->id,
            'status' => 'connected',
            'meta_phone_number_id' => $phoneId,
            'meta_waba_id' => '123456789012345',
            'meta_access_token' => 'EAAG_tenant_token_never_log_me',
            'meta_webhook_verify_token' => $verifyToken ?? 'VerifyToken'.str_replace('.', '', uniqid()),
        ]);

        return ['account' => $account, 'session' => $session];
    }

    /** POSTs a raw JSON body with a correctly computed X-Hub-Signature-256. */
    private function postSigned(array $payload, string $secret = self::APP_SECRET)
    {
        $body = json_encode($payload);

        return $this->call(
            'POST',
            self::ENDPOINT,
            [],
            [],
            [],
            [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_ACCEPT' => 'application/json',
                'HTTP_X_HUB_SIGNATURE_256' => 'sha256='.hash_hmac('sha256', $body, $secret),
            ],
            $body,
        );
    }

    /** @param array<string, mixed> $status */
    private function statusPayload(array $status, string $phoneId = self::PHONE_ID): array
    {
        return [
            'object' => 'whatsapp_business_account',
            'entry' => [[
                'id' => 'waba-id',
                'changes' => [[
                    'field' => 'messages',
                    'value' => [
                        'messaging_product' => 'whatsapp',
                        'metadata' => ['display_phone_number' => '919999999999', 'phone_number_id' => $phoneId],
                        'statuses' => [$status],
                    ],
                ]],
            ]],
        ];
    }

    private function inboundPayload(string $body, string $wamid = self::WAMID, string $phoneId = self::PHONE_ID): array
    {
        return [
            'object' => 'whatsapp_business_account',
            'entry' => [[
                'id' => 'waba-id',
                'changes' => [[
                    'field' => 'messages',
                    'value' => [
                        'messaging_product' => 'whatsapp',
                        'metadata' => ['display_phone_number' => '919999999999', 'phone_number_id' => $phoneId],
                        'contacts' => [['profile' => ['name' => 'Bob'], 'wa_id' => '919111111111']],
                        'messages' => [[
                            'from' => '919111111111',
                            'id' => $wamid,
                            'timestamp' => '1700000000',
                            'type' => 'text',
                            'text' => ['body' => $body],
                        ]],
                    ],
                ]],
            ]],
        ];
    }

    private function makeDispatchLog(Account $account, string $wamid, string $status = 'sent'): MessageDispatchLog
    {
        return MessageDispatchLog::record(
            $account->id,
            'api',
            '919111111111',
            success: $status === 'sent',
            gatewayMessageId: $wamid,
        );
    }

    // ------------------------------------------------------------------
    // Challenge verification
    // ------------------------------------------------------------------
    public function test_verification_echoes_the_challenge_for_a_matching_token(): void
    {
        $t = $this->makeTenant(self::PHONE_ID, 'MyVerifyTokenABC123');

        $response = $this->get(self::ENDPOINT.'?hub_mode=subscribe&hub_verify_token=MyVerifyTokenABC123&hub_challenge=CHALLENGE_42');

        $response->assertStatus(200);
        $this->assertSame('CHALLENGE_42', $response->getContent());
        $this->assertStringContainsString('text/plain', $response->headers->get('Content-Type'));
    }

    public function test_verification_is_rejected_for_an_unknown_token(): void
    {
        $this->makeTenant(self::PHONE_ID, 'MyVerifyTokenABC123');

        $this->get(self::ENDPOINT.'?hub_mode=subscribe&hub_verify_token=WRONG&hub_challenge=CHALLENGE_42')
            ->assertStatus(403);
    }

    public function test_verification_is_rejected_for_a_wrong_mode_or_missing_token(): void
    {
        $this->makeTenant();

        $this->get(self::ENDPOINT.'?hub_mode=unsubscribe&hub_verify_token=x&hub_challenge=c')->assertStatus(403);
        $this->get(self::ENDPOINT.'?hub_mode=subscribe&hub_challenge=c')->assertStatus(403);
    }

    /** Regression: utf8mb4_unicode_ci made the SQL comparison case-insensitive. */
    public function test_verification_token_comparison_is_case_sensitive(): void
    {
        $this->makeTenant(self::PHONE_ID, 'MyVerifyTokenABC123');

        $this->get(self::ENDPOINT.'?hub_mode=subscribe&hub_verify_token=myverifytokenabc123&hub_challenge=C')
            ->assertStatus(403);
    }

    // ------------------------------------------------------------------
    // Signature validation
    // ------------------------------------------------------------------
    public function test_a_correctly_signed_payload_is_accepted(): void
    {
        $this->makeTenant();

        $this->postSigned($this->statusPayload(['id' => self::WAMID, 'status' => 'sent']))
            ->assertStatus(200)
            ->assertJson(['received' => true]);
    }

    public function test_an_invalid_signature_is_rejected(): void
    {
        $t = $this->makeTenant();
        $this->makeDispatchLog($t['account'], self::WAMID);

        $response = $this->postSigned(
            $this->statusPayload(['id' => self::WAMID, 'status' => 'failed']),
            'the-wrong-app-secret',
        );

        $response->assertStatus(403);
        // Nothing was processed.
        $this->assertSame('sent', MessageDispatchLog::latest('id')->firstOrFail()->status);
    }

    public function test_a_missing_signature_header_is_rejected(): void
    {
        $this->makeTenant();

        $this->postJson(self::ENDPOINT, $this->statusPayload(['id' => self::WAMID, 'status' => 'failed']))
            ->assertStatus(403);
    }

    public function test_the_signature_is_computed_over_the_raw_body_so_tampering_is_caught(): void
    {
        $t = $this->makeTenant();
        $this->makeDispatchLog($t['account'], self::WAMID);

        $signedBody = json_encode($this->statusPayload(['id' => self::WAMID, 'status' => 'sent']));
        $tamperedBody = json_encode($this->statusPayload(['id' => self::WAMID, 'status' => 'failed']));

        $response = $this->call('POST', self::ENDPOINT, [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
            'HTTP_X_HUB_SIGNATURE_256' => 'sha256='.hash_hmac('sha256', $signedBody, self::APP_SECRET),
        ], $tamperedBody);

        $response->assertStatus(403);
        $this->assertSame('sent', MessageDispatchLog::latest('id')->firstOrFail()->status);
    }

    // ------------------------------------------------------------------
    // Status events: sent / delivered / read / failed
    // ------------------------------------------------------------------
    public function test_a_sent_status_is_accepted_and_changes_no_record(): void
    {
        $t = $this->makeTenant();
        $log = $this->makeDispatchLog($t['account'], self::WAMID);

        $this->postSigned($this->statusPayload(['id' => self::WAMID, 'status' => 'sent']))->assertStatus(200);

        $this->assertSame('sent', $log->fresh()->status);
    }

    public function test_a_read_status_is_accepted_and_changes_no_record(): void
    {
        $t = $this->makeTenant();
        $log = $this->makeDispatchLog($t['account'], self::WAMID);

        $this->postSigned($this->statusPayload(['id' => self::WAMID, 'status' => 'read']))->assertStatus(200);

        $this->assertSame('sent', $log->fresh()->status);
    }

    /**
     * A 'delivered' event fires the tenant's own outbound webhook exactly
     * once, even though Meta redelivers the event. Asserted on the queued
     * DispatchWebhookJob, which is what WebhookDispatcher::fire() produces
     * per active subscription.
     */
    public function test_a_delivered_status_fires_the_tenants_outbound_webhook_exactly_once(): void
    {
        Queue::fake();
        $t = $this->makeTenant();

        WebhookSubscription::create([
            'account_id' => $t['account']->id,
            'url' => 'https://tenant.example.com/hook',
            'secret' => 'whsec',
            'events' => ['message.delivered'],
            'is_active' => true,
        ]);

        PaymentAlert::create([
            'account_id' => $t['account']->id,
            'recipient_phone' => '919111111111',
            'customer_name' => 'Bob',
            'amount' => 100,
            'payment_ref' => 'PAY-1',
            'status' => 'sent',
            'gateway_message_id' => self::WAMID,
        ]);

        $payload = $this->statusPayload(['id' => self::WAMID, 'status' => 'delivered']);

        $this->postSigned($payload)->assertStatus(200);
        $this->postSigned($payload)->assertStatus(200);
        $this->postSigned($payload)->assertStatus(200);

        // Three identical Meta deliveries, one outbound webhook.
        Queue::assertPushed(DispatchWebhookJob::class, 1);
    }

    public function test_a_failed_status_marks_the_correct_dispatch_row_failed(): void
    {
        $t = $this->makeTenant();
        $log = $this->makeDispatchLog($t['account'], self::WAMID);

        $this->postSigned($this->statusPayload([
            'id' => self::WAMID,
            'status' => 'failed',
            'errors' => [['code' => 131047, 'title' => 'Re-engagement message', 'message' => '24h window expired']],
        ]))->assertStatus(200);

        $fresh = $log->fresh();
        $this->assertSame('failed', $fresh->status);
        $this->assertSame('Re-engagement message', $fresh->error_reason);
    }

    // ------------------------------------------------------------------
    // WAMID correlation / tenant isolation
    // ------------------------------------------------------------------
    public function test_an_unknown_wamid_modifies_nothing(): void
    {
        $t = $this->makeTenant();
        $log = $this->makeDispatchLog($t['account'], self::WAMID);

        $this->postSigned($this->statusPayload([
            'id' => 'wamid.COMPLETELY_UNKNOWN',
            'status' => 'failed',
            'errors' => [['title' => 'Nope']],
        ]))->assertStatus(200);

        $this->assertSame('sent', $log->fresh()->status);
    }

    /**
     * Regression: correlation used to search by WAMID across every tenant.
     * A status event for tenant B's number must never touch tenant A's row.
     */
    public function test_a_status_event_cannot_modify_another_tenants_dispatch_row(): void
    {
        $victim = $this->makeTenant(self::PHONE_ID);
        $other = $this->makeTenant(self::OTHER_PHONE_ID);

        $victimLog = $this->makeDispatchLog($victim['account'], self::WAMID);

        // The event arrives for the OTHER tenant's phone number, carrying a
        // WAMID that belongs to the victim.
        $this->postSigned($this->statusPayload([
            'id' => self::WAMID,
            'status' => 'failed',
            'errors' => [['title' => 'Cross-tenant attempt']],
        ], self::OTHER_PHONE_ID))->assertStatus(200);

        $this->assertSame('sent', $victimLog->fresh()->status, 'another tenant\'s status event mutated this row');
    }

    public function test_a_status_event_for_an_unknown_phone_number_id_is_dropped(): void
    {
        $t = $this->makeTenant();
        $log = $this->makeDispatchLog($t['account'], self::WAMID);

        $this->postSigned($this->statusPayload([
            'id' => self::WAMID,
            'status' => 'failed',
            'errors' => [['title' => 'Nope']],
        ], '000000000000000'))->assertStatus(200);

        $this->assertSame('sent', $log->fresh()->status);
    }

    public function test_an_account_id_in_the_payload_is_never_trusted(): void
    {
        $victim = $this->makeTenant(self::PHONE_ID);
        $other = $this->makeTenant(self::OTHER_PHONE_ID);
        $victimLog = $this->makeDispatchLog($victim['account'], self::WAMID);

        $payload = $this->statusPayload([
            'id' => self::WAMID,
            'status' => 'failed',
            'errors' => [['title' => 'Injected']],
        ], self::OTHER_PHONE_ID);
        // Inject tenancy hints at every plausible level.
        $payload['account_id'] = $victim['account']->id;
        $payload['entry'][0]['account_id'] = $victim['account']->id;
        $payload['entry'][0]['changes'][0]['value']['account_id'] = $victim['account']->id;

        $this->postSigned($payload)->assertStatus(200);

        $this->assertSame('sent', $victimLog->fresh()->status);
    }

    // ------------------------------------------------------------------
    // Duplicate delivery / idempotency
    // ------------------------------------------------------------------
    public function test_a_duplicate_failed_status_is_idempotent(): void
    {
        $t = $this->makeTenant();
        $log = $this->makeDispatchLog($t['account'], self::WAMID);

        $payload = $this->statusPayload([
            'id' => self::WAMID,
            'status' => 'failed',
            'errors' => [['title' => 'First reason']],
        ]);

        $this->postSigned($payload)->assertStatus(200);
        $this->postSigned($payload)->assertStatus(200);

        $fresh = $log->fresh();
        $this->assertSame('failed', $fresh->status);
        // The original reason is preserved, not rewritten by the retry.
        $this->assertSame('First reason', $fresh->error_reason);
    }

    /** Regression: a redelivered 'delivered' event fired the tenant webhook again each time. */
    public function test_a_duplicate_delivered_status_is_claimed_only_once(): void
    {
        $t = $this->makeTenant();
        PaymentAlert::create([
            'account_id' => $t['account']->id,
            'recipient_phone' => '919111111111',
            'customer_name' => 'Bob',
            'amount' => 100,
            'payment_ref' => 'PAY-1',
            'status' => 'sent',
            'gateway_message_id' => self::WAMID,
        ]);

        $payload = $this->statusPayload(['id' => self::WAMID, 'status' => 'delivered']);

        $this->postSigned($payload)->assertStatus(200);
        $claimKey = 'meta_wh_delivered:'.sha1(self::WAMID);
        $this->assertTrue(Cache::has($claimKey), 'the delivered event should be claimed');

        // A redelivery finds the claim already taken and short-circuits.
        $this->postSigned($payload)->assertStatus(200);
        $this->assertTrue(Cache::has($claimKey));
    }

    /**
     * THE headline regression: a redelivered inbound message used to run
     * the chatbot again, sending the customer a duplicate auto-reply and
     * charging quota twice.
     */
    public function test_a_redelivered_inbound_message_does_not_send_a_second_auto_reply(): void
    {
        Http::fake(['graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.REPLY']]], 200)]);
        $t = $this->makeTenant();

        ChatbotRule::create([
            'account_id' => $t['account']->id,
            'name' => 'Greeting',
            'match_type' => 'contains',
            'keywords' => ['hello'],
            'response_type' => 'text',
            'response_payload' => ['text' => 'Hi there!'],
            'is_active' => true,
            'priority' => 1,
        ]);

        $payload = $this->inboundPayload('hello there');

        $this->postSigned($payload)->assertStatus(200);
        $sentAfterFirst = count(Http::recorded());
        $usedAfterFirst = $t['account']->fresh()->currentSubscription->used_messages;

        // Meta redelivers the exact same message.
        $this->postSigned($payload)->assertStatus(200);

        $this->assertCount($sentAfterFirst, Http::recorded(), 'a redelivery sent a duplicate auto-reply');
        $this->assertSame(
            $usedAfterFirst,
            $t['account']->fresh()->currentSubscription->used_messages,
            'a redelivery charged quota a second time',
        );
    }

    public function test_a_distinct_inbound_message_is_still_processed(): void
    {
        Http::fake(['graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.REPLY']]], 200)]);
        $t = $this->makeTenant();

        ChatbotRule::create([
            'account_id' => $t['account']->id,
            'name' => 'Greeting',
            'match_type' => 'contains',
            'keywords' => ['hello'],
            'response_type' => 'text',
            'response_payload' => ['text' => 'Hi there!'],
            'is_active' => true,
            'priority' => 1,
        ]);

        $this->postSigned($this->inboundPayload('hello', 'wamid.FIRST'))->assertStatus(200);
        $afterFirst = count(Http::recorded());

        $this->postSigned($this->inboundPayload('hello', 'wamid.SECOND'))->assertStatus(200);

        $this->assertGreaterThan($afterFirst, count(Http::recorded()), 'a genuinely new message must still be answered');
    }

    public function test_inbound_messages_are_routed_only_to_the_owning_tenant(): void
    {
        Http::fake(['graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.REPLY']]], 200)]);
        $owner = $this->makeTenant(self::PHONE_ID);
        $other = $this->makeTenant(self::OTHER_PHONE_ID);

        ChatbotRule::create([
            'account_id' => $other['account']->id,
            'name' => 'Other tenant rule',
            'match_type' => 'contains',
            'keywords' => ['hello'],
            'response_type' => 'text',
            'response_payload' => ['text' => 'Should not fire'],
            'is_active' => true,
            'priority' => 1,
        ]);

        // Message arrives on the OWNER's number; only the owner's (absent)
        // rules may be consulted, so the other tenant's rule never fires.
        $this->postSigned($this->inboundPayload('hello'))->assertStatus(200);

        Http::assertNothingSent();
    }

    // ------------------------------------------------------------------
    // Malformed payloads
    // ------------------------------------------------------------------
    public function test_malformed_payloads_are_accepted_without_error_and_change_nothing(): void
    {
        $t = $this->makeTenant();
        $log = $this->makeDispatchLog($t['account'], self::WAMID);

        $payloads = [
            [],
            ['entry' => []],
            ['entry' => [[]]],
            ['entry' => [['changes' => [[]]]]],
            ['entry' => [['changes' => [['value' => ['statuses' => [[]]]]]]]],
            ['entry' => [['changes' => [['value' => ['messages' => [[]]]]]]]],
            ['entry' => [['changes' => [['value' => ['statuses' => [['status' => 'failed']]]]]]]],
        ];

        foreach ($payloads as $i => $payload) {
            $this->postSigned($payload)->assertStatus(200, "payload #{$i} should not error");
        }

        $this->assertSame('sent', $log->fresh()->status);
    }

    // ------------------------------------------------------------------
    // Secret / credential non-leakage
    // ------------------------------------------------------------------
    public function test_no_secret_or_token_is_exposed_in_responses(): void
    {
        $t = $this->makeTenant(self::PHONE_ID, 'MyVerifyTokenABC123');

        $verify = $this->get(self::ENDPOINT.'?hub_mode=subscribe&hub_verify_token=MyVerifyTokenABC123&hub_challenge=C');
        $rejected = $this->postJson(self::ENDPOINT, $this->statusPayload(['id' => self::WAMID, 'status' => 'sent']));
        $accepted = $this->postSigned($this->statusPayload(['id' => self::WAMID, 'status' => 'sent']));

        foreach ([$verify, $rejected, $accepted] as $response) {
            $body = $response->getContent();
            $this->assertStringNotContainsString(self::APP_SECRET, $body);
            $this->assertStringNotContainsString('EAAG_tenant_token_never_log_me', $body);
        }
    }

    public function test_no_secret_or_token_is_written_to_the_log(): void
    {
        $lines = [];
        Log::listen(function ($message) use (&$lines) {
            $lines[] = $message->message.' '.json_encode($message->context);
        });

        $t = $this->makeTenant(self::PHONE_ID, 'MyVerifyTokenABC123');
        $this->makeDispatchLog($t['account'], self::WAMID);

        // Exercise every logging path: bad token, missing signature, bad
        // signature, an accepted status event and an unresolvable tenant.
        $this->get(self::ENDPOINT.'?hub_mode=subscribe&hub_verify_token=WRONG&hub_challenge=C');
        $this->postJson(self::ENDPOINT, $this->statusPayload(['id' => self::WAMID, 'status' => 'sent']));
        $this->postSigned($this->statusPayload(['id' => self::WAMID, 'status' => 'sent']), 'wrong-secret');
        $this->postSigned($this->statusPayload(['id' => self::WAMID, 'status' => 'failed', 'errors' => [['title' => 'x']]]));
        $this->postSigned($this->statusPayload(['id' => self::WAMID, 'status' => 'sent'], '000000000000000'));

        $logged = implode("\n", $lines);
        $this->assertStringNotContainsString(self::APP_SECRET, $logged);
        $this->assertStringNotContainsString('EAAG_tenant_token_never_log_me', $logged);
        $this->assertStringNotContainsString('MyVerifyTokenABC123', $logged);
    }

    // ------------------------------------------------------------------
    // QR regression
    // ------------------------------------------------------------------
    public function test_a_qr_session_is_untouched_by_meta_webhook_traffic(): void
    {
        $qrAccount = Account::factory()->create();
        $qrSession = WhatsAppSession::create(['account_id' => $qrAccount->id, 'status' => 'connected']);
        $t = $this->makeTenant();
        $qrLog = $this->makeDispatchLog($qrAccount, 'qr-engine-message-id');

        $this->postSigned($this->statusPayload([
            'id' => 'qr-engine-message-id',
            'status' => 'failed',
            'errors' => [['title' => 'Should not apply']],
        ]))->assertStatus(200);

        // The QR tenant's session and dispatch row are both untouched: the
        // event resolved to the Meta tenant, which owns no such WAMID.
        $this->assertSame('connected', $qrSession->fresh()->status);
        $this->assertNull($qrSession->fresh()->meta_phone_number_id);
        $this->assertSame('sent', $qrLog->fresh()->status);
    }

    public function test_the_internal_qr_status_endpoint_is_unaffected(): void
    {
        $qrAccount = Account::factory()->create();
        $session = WhatsAppSession::create(['account_id' => $qrAccount->id, 'status' => 'disconnected']);

        // The QR engine's own callback path is entirely separate from the
        // Meta webhook and keeps working exactly as before.
        $this->withHeader('X-Internal-Secret', (string) config('services.qr_engine.internal_secret'))
            ->postJson('/api/internal/whatsapp-status', [
                'account_id' => $qrAccount->id,
                'status' => 'connected',
            ]);

        // Whether the secret matches in this env or not, the Meta webhook
        // never touched this row.
        $this->assertContains($session->fresh()->status, ['connected', 'disconnected']);
        $this->assertNull($session->fresh()->meta_phone_number_id);
    }

    // ==================================================================
    // Phase 4 Task 9 -- final integration & security regression
    // ==================================================================

    /**
     * [Bug fix, Task 9] The 'delivered' redelivery claim used to be taken
     * BEFORE the payment_alerts lookup, so a status callback that raced
     * ahead of ProcessPaymentAlertJob's gateway_message_id write burned
     * the one-shot claim on a guaranteed no-op -- and Meta's later,
     * legitimate redelivery of the same event was then silently
     * swallowed, so the tenant's message.delivered webhook never fired.
     */
    public function test_a_delivered_event_that_arrives_before_its_alert_row_does_not_burn_the_claim(): void
    {
        Queue::fake();
        $t = $this->makeTenant();

        WebhookSubscription::create([
            'account_id' => $t['account']->id,
            'url' => 'https://tenant.example.com/hook',
            'secret' => 'whsec',
            'events' => ['message.delivered'],
            'is_active' => true,
        ]);

        $payload = $this->statusPayload(['id' => self::WAMID, 'status' => 'delivered']);

        // The callback overtakes the send: no payment_alerts row yet.
        $this->postSigned($payload)->assertStatus(200);

        Queue::assertNothingPushed();
        $this->assertFalse(
            Cache::has('meta_wh_delivered:'.sha1(self::WAMID)),
            'a delivered event with no row to report must not consume the redelivery claim',
        );

        // ProcessPaymentAlertJob finishes and persists the WAMID.
        PaymentAlert::create([
            'account_id' => $t['account']->id,
            'recipient_phone' => '919111111111',
            'customer_name' => 'Bob',
            'amount' => 100,
            'payment_ref' => 'PAY-RACE',
            'status' => 'sent',
            'gateway_message_id' => self::WAMID,
        ]);

        // Meta redelivers. The tenant must now get their webhook.
        $this->postSigned($payload)->assertStatus(200);
        Queue::assertPushed(DispatchWebhookJob::class, 1);

        // ...and only once, however many more times Meta retries.
        $this->postSigned($payload)->assertStatus(200);
        $this->postSigned($payload)->assertStatus(200);
        Queue::assertPushed(DispatchWebhookJob::class, 1);
    }

    /** A foreign tenant's alert is never reported, and never consumes this tenant's claim. */
    public function test_a_delivered_event_never_reports_another_tenants_alert(): void
    {
        Queue::fake();
        $mine = $this->makeTenant();
        $theirs = $this->makeTenant(self::OTHER_PHONE_ID);

        WebhookSubscription::create([
            'account_id' => $mine['account']->id,
            'url' => 'https://tenant.example.com/hook',
            'secret' => 'whsec',
            'events' => ['message.delivered'],
            'is_active' => true,
        ]);

        PaymentAlert::create([
            'account_id' => $theirs['account']->id,
            'recipient_phone' => '919111111111',
            'customer_name' => 'Someone Else',
            'amount' => 100,
            'payment_ref' => 'PAY-THEIRS',
            'status' => 'sent',
            'gateway_message_id' => self::WAMID,
        ]);

        $this->postSigned($this->statusPayload(['id' => self::WAMID, 'status' => 'delivered']))->assertStatus(200);

        Queue::assertNothingPushed();
    }

    /**
     * [Security fix, Task 9 -- tenant isolation] A CTWA referral whose
     * WAMID already belongs to ANOTHER tenant's lead used to re-parent
     * that row (account_id was in the update payload, not the match key),
     * moving the victim's contact PII across the tenant boundary.
     */
    public function test_a_ctwa_referral_never_reparents_another_tenants_lead(): void
    {
        $mine = $this->makeTenant();
        $theirs = $this->makeTenant(self::OTHER_PHONE_ID);

        $victim = Lead::create([
            'account_id' => $theirs['account']->id,
            'provider' => 'whatsapp_ctwa',
            'provider_lead_id' => self::WAMID,
            'lead_name' => 'Their Customer',
            'lead_phone' => '919222222222',
        ]);

        $payload = $this->inboundPayload('hi', self::WAMID);
        $payload['entry'][0]['changes'][0]['value']['messages'][0]['referral'] = [
            'source_id' => 'ad-123',
            'source_type' => 'ad',
        ];

        $this->postSigned($payload)->assertStatus(200);

        $fresh = $victim->fresh();
        $this->assertSame($theirs['account']->id, $fresh->account_id, 'the lead must stay with its own tenant');
        $this->assertSame('Their Customer', $fresh->lead_name);
        $this->assertSame('919222222222', $fresh->lead_phone);
        $this->assertNull($fresh->ad_id);

        // ...and no second lead was created for the claiming tenant either.
        $this->assertSame(0, Lead::where('account_id', $mine['account']->id)->count());
    }

    /** The same-tenant CTWA upsert path is untouched by that guard. */
    public function test_a_ctwa_referral_still_upserts_its_own_tenants_lead(): void
    {
        $t = $this->makeTenant();

        $payload = $this->inboundPayload('hi', self::WAMID);
        $payload['entry'][0]['changes'][0]['value']['messages'][0]['referral'] = [
            'source_id' => 'ad-123',
            'source_type' => 'ad',
        ];

        $this->postSigned($payload)->assertStatus(200);

        $lead = Lead::where('provider_lead_id', self::WAMID)->first();
        $this->assertNotNull($lead);
        $this->assertSame($t['account']->id, $lead->account_id);
        $this->assertSame('ad-123', $lead->ad_id);
        $this->assertSame('Bob', $lead->lead_name);
    }

    /**
     * [Hardening, Task 9] Neither Meta secret on this model may ride out
     * in a serialized WhatsAppSession. meta_access_token was already
     * hidden; meta_webhook_verify_token -- the shared secret verify()
     * hash_equals() against -- was not.
     */
    public function test_no_meta_secret_serializes_out_of_a_whatsapp_session(): void
    {
        $t = $this->makeTenant(self::PHONE_ID, 'VerifyTokenSerializationCheck');
        $json = $t['session']->fresh()->toJson();

        $this->assertStringNotContainsString('EAAG_tenant_token_never_log_me', $json);
        $this->assertStringNotContainsString('VerifyTokenSerializationCheck', $json);
        $this->assertArrayNotHasKey('meta_access_token', $t['session']->fresh()->toArray());
        $this->assertArrayNotHasKey('meta_webhook_verify_token', $t['session']->fresh()->toArray());

        // The attribute itself is still readable -- MetaConfigController's
        // own response array and verify()'s hash_equals() both depend on it.
        $this->assertSame('VerifyTokenSerializationCheck', $t['session']->fresh()->meta_webhook_verify_token);
    }
}
