<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\ChatbotRule;
use App\Models\Invoice;
use App\Models\MessageDispatchLog;
use App\Models\MessageTemplate;
use App\Models\PaymentAlert;
use App\Models\SocialProviderConfig;
use App\Models\Subscription;
use App\Models\WhatsAppFlow;
use App\Models\WhatsAppSession;
use App\Services\Billing\InvoiceCreditService;
use App\Services\Chatbot\ChatbotEngineService;
use App\Services\PaymentAlerts\PaymentAlertDispatcher;
use App\Services\Templates\TemplateMessageDispatcher;
use App\Services\WhatsApp\WhatsAppJourneyEngine;
use App\Support\PlanCatalog;
use Database\Seeders\Phase1FoundationSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Phase 5 Task 3 -- the four remaining consume-after quota paths were
 * migrated onto MessageQuotaService:
 *
 *   TemplateMessageDispatcher, ProcessPaymentAlertJob,
 *   ChatbotEngineService, WhatsAppJourneyEngine
 *
 * (DirectMessageDispatcher moved in Task 2.) Three of the four also
 * carried a hand-inlined copy of Subscription::computeStatus()'s
 * exhaustion condition, which is now hasQuotaFor(1).
 *
 * This file proves the migration is behaviour-preserving, not that
 * MessageQuotaService works -- MessageQuotaServiceTest owns that. Every
 * assertion here is about the PATH: that the semantic is still strictly
 * check -> send -> consume, that exactly one credit moves per confirmed
 * send, that a refused or failed send moves none, and that nothing else
 * about these paths changed.
 */
class ConsumeAfterQuotaMigrationTest extends TestCase
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

    private const RECIPIENT = '919999999999';

    private const META_TOKEN = 'EAAG_tenant_token_never_leak_me_0123456789';

    private const APP_SECRET = 'meta_app_secret_never_log_me_0123456789';

    // ------------------------------------------------------------------
    // Fixtures
    // ------------------------------------------------------------------
    private function planKeyFor(string $engine): string
    {
        foreach (PlanCatalog::all() as $key => $plan) {
            if ($plan['engine_type'] === $engine) {
                return $key;
            }
        }

        $this->fail("No PlanCatalog plan maps to the {$engine} provider.");
    }

    private function giveActiveSubscription(Account $account, string $engine): void
    {
        $planKey = $this->planKeyFor($engine);
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

    private function qrAccount(): Account
    {
        $account = Account::factory()->create();
        $this->giveActiveSubscription($account, 'qr');
        WhatsAppSession::create(['account_id' => $account->id, 'status' => 'connected']);

        return $account;
    }

    private function metaAccount(): Account
    {
        $account = Account::factory()->create();
        $this->giveActiveSubscription($account, 'meta');
        WhatsAppSession::create([
            'account_id' => $account->id,
            'status' => 'connected',
            'meta_phone_number_id' => '1098'.random_int(100000000, 999999999),
            'meta_waba_id' => '123456789012345',
            'meta_access_token' => self::META_TOKEN,
        ]);

        return $account;
    }

    private function fakeSendsSucceed(): void
    {
        Http::fake([
            'graph.facebook.com/*' => Http::response([
                'messaging_product' => 'whatsapp',
                'messages' => [['id' => 'wamid.OK']],
            ], 200),
            '*' => Http::response(['success' => true, 'message_id' => 'QR_OK'], 200),
        ]);
    }

    private function fakeSendsFail(): void
    {
        Http::fake(['*' => Http::response(['success' => false, 'error' => 'Engine said no'], 200)]);
    }

    private function subscriptionOf(Account $account): Subscription
    {
        return Subscription::where('account_id', $account->id)->firstOrFail();
    }

    private function used(Account $account): int
    {
        return (int) $this->subscriptionOf($account)->used_messages;
    }

    private function exhaust(Account $account): void
    {
        $s = $this->subscriptionOf($account);
        Subscription::whereKey($s->id)->update(['used_messages' => $s->total_allocated_messages]);
    }

    /** @param array<string, mixed> $overrides */
    private function template(Account $account, array $overrides = []): MessageTemplate
    {
        return MessageTemplate::create(array_merge([
            'account_id' => $account->id,
            'template_code' => 'TPL_'.strtoupper(Str::random(6)),
            'title' => 'Order Update',
            'template_body' => 'Hello {{name}}.',
            'status' => 'approved',
        ], $overrides));
    }

    // ==================================================================
    // 1. TemplateMessageDispatcher
    // ==================================================================
    public function test_template_a_successful_qr_send_consumes_exactly_one(): void
    {
        $this->fakeSendsSucceed();
        $account = $this->qrAccount();
        $template = $this->template($account);

        $result = TemplateMessageDispatcher::dispatch($account->id, $template->id, self::RECIPIENT, ['name' => 'Bob']);

        $this->assertSame('sent', $result['status']);
        $this->assertSame('Hello Bob.', $result['rendered_message']);
        $this->assertSame(1, $this->used($account));
    }

    public function test_template_a_successful_meta_template_send_consumes_exactly_one(): void
    {
        $this->fakeSendsSucceed();
        $account = $this->metaAccount();
        $template = $this->template($account, [
            'language' => 'en_US',
            'category' => 'UTILITY',
            'meta_template_name' => 'order_update',
            'meta_template_status' => 'APPROVED',
        ]);

        $result = TemplateMessageDispatcher::dispatch($account->id, $template->id, self::RECIPIENT, ['name' => 'Bob']);

        $this->assertSame('sent', $result['status']);
        $this->assertSame(1, $this->used($account));

        // ...and it still went out as a Graph template payload.
        $body = null;
        foreach (Http::recorded() as [$request]) {
            if (str_contains($request->url(), 'graph.facebook.com')) {
                $body = $request->data();
            }
        }
        $this->assertSame('template', $body['type'] ?? null);
    }

    public function test_template_a_rejected_send_consumes_nothing(): void
    {
        $this->fakeSendsFail();
        $account = $this->qrAccount();
        $template = $this->template($account);

        $result = TemplateMessageDispatcher::dispatch($account->id, $template->id, self::RECIPIENT, ['name' => 'Bob']);

        $this->assertSame('failed', $result['status']);
        $this->assertSame(0, $this->used($account), 'consume() must sit AFTER the driver call');
    }

    public function test_template_an_exhausted_subscription_is_still_refused_before_sending(): void
    {
        $this->fakeSendsSucceed();
        $account = $this->qrAccount();
        $template = $this->template($account);
        $this->exhaust($account);
        $before = $this->used($account);

        $result = TemplateMessageDispatcher::dispatch($account->id, $template->id, self::RECIPIENT, ['name' => 'Bob']);

        $this->assertSame('quota_exhausted', $result['status']);
        Http::assertNothingSent();
        $this->assertSame($before, $this->used($account));
    }

    public function test_template_a_meta_template_on_a_qr_account_is_still_refused_and_costs_nothing(): void
    {
        $this->fakeSendsSucceed();
        $account = $this->qrAccount();
        $template = $this->template($account, [
            'language' => 'en_US',
            'meta_template_name' => 'order_update',
        ]);

        $result = TemplateMessageDispatcher::dispatch($account->id, $template->id, self::RECIPIENT, ['name' => 'Bob']);

        $this->assertSame('failed', $result['status']);
        $this->assertStringContainsString('Meta Cloud API provider', $result['message']);
        $this->assertSame(0, $this->used($account));
    }

    public function test_template_repeated_sends_consume_one_each(): void
    {
        $this->fakeSendsSucceed();
        $account = $this->qrAccount();
        $template = $this->template($account);

        for ($i = 0; $i < 4; $i++) {
            TemplateMessageDispatcher::dispatch($account->id, $template->id, self::RECIPIENT, ['name' => "B{$i}"]);
        }

        $this->assertSame(4, $this->used($account));
    }

    public function test_template_the_dispatch_log_is_unchanged(): void
    {
        $this->fakeSendsSucceed();
        $account = $this->qrAccount();
        $template = $this->template($account);

        TemplateMessageDispatcher::dispatch($account->id, $template->id, self::RECIPIENT, ['name' => 'Bob']);

        $log = MessageDispatchLog::where('account_id', $account->id)->latest('id')->firstOrFail();
        $this->assertSame('sent', $log->status);
        $this->assertSame('web_template', $log->source);
        $this->assertSame('template', $log->reference_type);
        $this->assertSame($template->id, $log->reference_id);
        $this->assertSame('Order Update', $log->template_name);
    }

    // ==================================================================
    // 2. ProcessPaymentAlertJob
    // ==================================================================
    /** @param array<string, mixed> $overrides */
    private function dispatchAlert(Account $account, array $overrides = []): array
    {
        return PaymentAlertDispatcher::dispatch($account->id, array_merge([
            'recipient_phone' => self::RECIPIENT,
            'customer_name' => 'Bob',
            'amount' => 250.00,
            'payment_ref' => 'PAY-'.Str::random(8),
        ], $overrides));
    }

    public function test_payment_alert_a_successful_send_consumes_exactly_one(): void
    {
        $this->fakeSendsSucceed();
        $account = $this->qrAccount();

        $result = $this->dispatchAlert($account);

        $this->assertSame('queued', $result['status']);
        $this->assertSame('sent', PaymentAlert::findOrFail($result['alert']->id)->status);
        $this->assertSame(1, $this->used($account));
    }

    /**
     * An exhausted plan still blocks the alert and still costs nothing.
     *
     * [Pre-existing behaviour, unchanged by this migration]: the block
     * comes from hasActiveSubscription() one line ABOVE the quota gate,
     * not from the gate itself -- isActive() calls refreshStatus(), which
     * recomputes 'exhausted' from the same condition, so a plan that is
     * out of credits is never "active". The dedicated quota gate below it
     * is therefore defence-in-depth that exhaustion alone cannot reach.
     * That was equally true of the inline arithmetic this task replaced;
     * asserting the real message here keeps the ordering documented
     * rather than papered over.
     */
    public function test_payment_alert_an_exhausted_plan_still_blocks_and_costs_nothing(): void
    {
        $this->fakeSendsSucceed();
        $account = $this->qrAccount();

        $s = $this->subscriptionOf($account);
        Subscription::whereKey($s->id)->update(['used_messages' => $s->total_allocated_messages - 1]);

        $first = $this->dispatchAlert($account);
        $this->assertSame('sent', PaymentAlert::findOrFail($first['alert']->id)->status);
        $this->assertSame((int) $s->total_allocated_messages, $this->used($account));

        $second = $this->dispatchAlert($account);
        $alert = PaymentAlert::findOrFail($second['alert']->id);

        $this->assertSame('failed', $alert->status);
        $this->assertSame('Account has no active subscription.', $alert->error_reason);
        $this->assertSame((int) $s->total_allocated_messages, $this->used($account), 'a blocked alert must consume nothing');
    }

    /**
     * The quota gate itself, proved directly rather than through the job:
     * hasQuotaFor(1) must give the same verdict the hand-inlined
     * arithmetic gave at every boundary, including the two uncapped
     * shapes that made the old expression long.
     */
    public function test_payment_alert_the_replaced_predicate_is_exactly_equivalent(): void
    {
        $quota = app(\App\Services\Messaging\MessageQuotaService::class);
        $account = $this->qrAccount();
        $s = $this->subscriptionOf($account);
        $cap = (int) $s->total_allocated_messages;

        foreach ([0, 1, $cap - 1, $cap, $cap + 5] as $used) {
            Subscription::whereKey($s->id)->update(['used_messages' => $used]);
            $fresh = Subscription::findOrFail($s->id);

            // The exact expression this task deleted from three files.
            $inlineExhausted = $fresh->billing_model !== 'unlimited'
                && $fresh->total_allocated_messages !== null
                && $fresh->used_messages >= $fresh->total_allocated_messages;

            $this->assertSame(
                $inlineExhausted,
                ! $quota->hasQuotaFor($fresh, 1),
                "verdicts diverged at used_messages={$used}",
            );
        }

        foreach ([['billing_model' => 'unlimited'], ['total_allocated_messages' => null]] as $uncapped) {
            Subscription::whereKey($s->id)->update($uncapped + ['used_messages' => 999999]);
            $fresh = Subscription::findOrFail($s->id);

            $this->assertTrue($quota->hasQuotaFor($fresh, 1), 'an uncapped plan was never exhausted by the old expression either');
        }
    }

    public function test_payment_alert_a_rejected_send_consumes_nothing(): void
    {
        $this->fakeSendsFail();
        $account = $this->qrAccount();

        $result = $this->dispatchAlert($account);

        $this->assertSame('failed', PaymentAlert::findOrFail($result['alert']->id)->status);
        $this->assertSame(0, $this->used($account));
    }

    public function test_payment_alert_duplicate_payment_ref_behaviour_is_unchanged(): void
    {
        $this->fakeSendsSucceed();
        $account = $this->qrAccount();

        $first = $this->dispatchAlert($account, ['payment_ref' => 'PAY-DUP-1']);
        $second = $this->dispatchAlert($account, ['payment_ref' => 'PAY-DUP-1']);

        $this->assertSame('queued', $first['status']);
        $this->assertSame('duplicate', $second['status']);
        $this->assertSame(1, PaymentAlert::where('account_id', $account->id)->count());
        $this->assertSame(1, $this->used($account), 'a duplicate must not be billed twice');
    }

    public function test_payment_alert_the_quota_increment_and_status_write_stay_atomic(): void
    {
        $this->fakeSendsSucceed();
        $account = $this->qrAccount();

        $result = $this->dispatchAlert($account);
        $alert = PaymentAlert::findOrFail($result['alert']->id);

        // Both halves of the original single transaction must have landed.
        $this->assertSame('sent', $alert->status);
        $this->assertNotNull($alert->sent_at);
        $this->assertSame('QR_OK', $alert->gateway_message_id);
        $this->assertSame(1, $this->used($account));
    }

    // ==================================================================
    // 3. ChatbotEngineService
    // ==================================================================
    private function chatbotRule(Account $account): ChatbotRule
    {
        return ChatbotRule::create([
            'account_id' => $account->id,
            'name' => 'Greeting',
            'match_type' => 'contains',
            'keywords' => ['hello'],
            'response_type' => 'text',
            'response_payload' => ['text' => 'Hi there!'],
            'priority' => 1,
            'is_active' => true,
        ]);
    }

    public function test_chatbot_a_successful_auto_reply_consumes_exactly_one(): void
    {
        $this->fakeSendsSucceed();
        $account = $this->qrAccount();
        $this->chatbotRule($account);

        app(ChatbotEngineService::class)->handleInboundMessage($account->id, self::RECIPIENT, 'hello there');

        $this->assertSame(1, $this->used($account));

        $log = MessageDispatchLog::where('account_id', $account->id)->latest('id')->firstOrFail();
        $this->assertSame('sent', $log->status);
        $this->assertSame('chatbot', $log->source);
    }

    public function test_chatbot_a_failed_send_consumes_nothing(): void
    {
        $this->fakeSendsFail();
        $account = $this->qrAccount();
        $this->chatbotRule($account);

        app(ChatbotEngineService::class)->handleInboundMessage($account->id, self::RECIPIENT, 'hello there');

        $this->assertSame(0, $this->used($account));

        $log = MessageDispatchLog::where('account_id', $account->id)->latest('id')->firstOrFail();
        $this->assertSame('failed', $log->status);
    }

    public function test_chatbot_an_exhausted_subscription_is_refused_before_sending(): void
    {
        $this->fakeSendsSucceed();
        $account = $this->qrAccount();
        $this->chatbotRule($account);
        $this->exhaust($account);
        $before = $this->used($account);

        app(ChatbotEngineService::class)->handleInboundMessage($account->id, self::RECIPIENT, 'hello there');

        Http::assertNothingSent();
        $this->assertSame($before, $this->used($account));
    }

    public function test_chatbot_a_non_matching_message_costs_nothing(): void
    {
        $this->fakeSendsSucceed();
        $account = $this->qrAccount();
        $this->chatbotRule($account);

        app(ChatbotEngineService::class)->handleInboundMessage($account->id, self::RECIPIENT, 'completely unrelated');

        Http::assertNothingSent();
        $this->assertSame(0, $this->used($account));
    }

    /**
     * The Meta webhook's inbound redelivery guard (Phase 4 Task 4) sits
     * OUTSIDE this service and must be unaffected by the quota change: a
     * redelivered message must still not produce a second billed reply.
     */
    public function test_chatbot_inbound_redelivery_deduplication_is_unchanged(): void
    {
        $this->fakeSendsSucceed();

        SocialProviderConfig::create([
            'provider' => 'meta',
            'client_id' => 'app-id',
            'client_secret' => self::APP_SECRET,
            'is_active' => true,
        ]);

        $account = $this->metaAccount();
        $phoneId = WhatsAppSession::where('account_id', $account->id)->value('meta_phone_number_id');
        $this->chatbotRule($account);

        $payload = [
            'object' => 'whatsapp_business_account',
            'entry' => [[
                'id' => 'waba-id',
                'changes' => [[
                    'field' => 'messages',
                    'value' => [
                        'messaging_product' => 'whatsapp',
                        'metadata' => ['display_phone_number' => '919999999999', 'phone_number_id' => $phoneId],
                        'contacts' => [['profile' => ['name' => 'Bob'], 'wa_id' => self::RECIPIENT]],
                        'messages' => [[
                            'from' => self::RECIPIENT,
                            'id' => 'wamid.DEDUPE_CHECK',
                            'timestamp' => '1700000000',
                            'type' => 'text',
                            'text' => ['body' => 'hello'],
                        ]],
                    ],
                ]],
            ]],
        ];

        $body = json_encode($payload);
        $headers = [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
            'HTTP_X_HUB_SIGNATURE_256' => 'sha256='.hash_hmac('sha256', $body, self::APP_SECRET),
        ];

        $this->call('POST', '/api/webhooks/meta', [], [], [], $headers, $body)->assertStatus(200);
        $this->call('POST', '/api/webhooks/meta', [], [], [], $headers, $body)->assertStatus(200);
        $this->call('POST', '/api/webhooks/meta', [], [], [], $headers, $body)->assertStatus(200);

        $this->assertSame(1, $this->used($account), 'three deliveries of one message must bill once');
    }

    // ==================================================================
    // 4. WhatsAppJourneyEngine
    // ==================================================================
    private function twoMessageFlow(Account $account): WhatsAppFlow
    {
        return WhatsAppFlow::create([
            'account_id' => $account->id,
            'name' => 'Welcome',
            'trigger_type' => 'keyword',
            'trigger_value' => 'start',
            'is_active' => true,
            'graph_data' => [
                'nodes' => [
                    ['id' => 'n1', 'type' => 'trigger', 'data' => []],
                    ['id' => 'n2', 'type' => 'message', 'data' => ['text' => 'Welcome aboard!']],
                ],
                'edges' => [
                    ['id' => 'e1', 'source' => 'n1', 'target' => 'n2'],
                ],
            ],
        ]);
    }

    public function test_journey_a_successful_send_consumes_exactly_one(): void
    {
        $this->fakeSendsSucceed();
        $account = $this->qrAccount();
        $flow = $this->twoMessageFlow($account);

        app(WhatsAppJourneyEngine::class)->testFlow($account, $flow, self::RECIPIENT);

        $this->assertSame(1, $this->used($account));

        $log = MessageDispatchLog::where('account_id', $account->id)->latest('id')->firstOrFail();
        $this->assertSame('sent', $log->status);
        $this->assertSame('journey', $log->source);
        $this->assertSame('whatsapp_flow', $log->reference_type);
        $this->assertSame($flow->id, $log->reference_id);
        $this->assertSame('Welcome aboard!', $log->message_preview);
    }

    public function test_journey_a_failed_send_consumes_nothing(): void
    {
        $this->fakeSendsFail();
        $account = $this->qrAccount();
        $flow = $this->twoMessageFlow($account);

        app(WhatsAppJourneyEngine::class)->testFlow($account, $flow, self::RECIPIENT);

        $this->assertSame(0, $this->used($account));

        $log = MessageDispatchLog::where('account_id', $account->id)->latest('id')->firstOrFail();
        $this->assertSame('failed', $log->status);
        $this->assertSame('journey', $log->source);
    }

    public function test_journey_an_exhausted_subscription_is_refused_before_sending(): void
    {
        $this->fakeSendsSucceed();
        $account = $this->qrAccount();
        $flow = $this->twoMessageFlow($account);
        $this->exhaust($account);
        $before = $this->used($account);

        app(WhatsAppJourneyEngine::class)->testFlow($account, $flow, self::RECIPIENT);

        Http::assertNothingSent();
        $this->assertSame($before, $this->used($account));

        // [Pre-existing ordering, unchanged]: send()'s
        // hasActiveSubscription() check sits above its quota gate and
        // already reports exhaustion, for the same refreshStatus() reason
        // documented on the payment-alert test above.
        $log = MessageDispatchLog::where('account_id', $account->id)->latest('id')->firstOrFail();
        $this->assertSame('failed', $log->status);
        $this->assertSame('journey', $log->source);
        $this->assertSame('No active subscription.', $log->error_reason);
    }

    public function test_journey_a_meta_account_still_routes_through_graph(): void
    {
        $this->fakeSendsSucceed();
        $account = $this->metaAccount();
        $flow = $this->twoMessageFlow($account);

        app(WhatsAppJourneyEngine::class)->testFlow($account, $flow, self::RECIPIENT);

        $this->assertSame(1, $this->used($account));

        $sawGraph = false;
        foreach (Http::recorded() as [$request]) {
            $sawGraph = $sawGraph || str_contains($request->url(), 'graph.facebook.com');
        }
        $this->assertTrue($sawGraph, 'provider routing must be unchanged');
    }

    // ==================================================================
    // 5. No quota arithmetic left in any consume-after path
    // ==================================================================
    /**
     * Asserts on CODE, not on prose: the docblocks in these files legitimately
     * discuss used_messages/total_allocated_messages history, and deleting
     * that rationale to satisfy a grep would be the wrong trade. Comments
     * and docblocks are stripped with the PHP tokenizer first.
     */
    private function codeWithoutComments(string $relativePath): string
    {
        $tokens = token_get_all(file_get_contents(app_path($relativePath)));
        $code = '';

        foreach ($tokens as $token) {
            if (is_array($token) && in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }

            $code .= is_array($token) ? $token[1] : $token;
        }

        return $code;
    }

    public static function consumeAfterPathProvider(): array
    {
        return [
            'TemplateMessageDispatcher' => ['Services/Templates/TemplateMessageDispatcher.php'],
            'ProcessPaymentAlertJob' => ['Jobs/ProcessPaymentAlertJob.php'],
            'ChatbotEngineService' => ['Services/Chatbot/ChatbotEngineService.php'],
            'WhatsAppJourneyEngine' => ['Services/WhatsApp/WhatsAppJourneyEngine.php'],
            'DirectMessageDispatcher' => ['Services/WhatsApp/DirectMessageDispatcher.php'],
        ];
    }

    /**
     * @dataProvider consumeAfterPathProvider
     */
    public function test_no_consume_after_path_holds_its_own_quota_arithmetic(string $relativePath): void
    {
        $code = $this->codeWithoutComments($relativePath);

        $this->assertStringContainsString('MessageQuotaService', $code, "{$relativePath} must delegate quota to the service");
        $this->assertStringNotContainsString("increment('used_messages'", $code);
        $this->assertStringNotContainsString('total_allocated_messages', $code);
        $this->assertStringNotContainsString("billing_model !== 'unlimited'", $code);
    }

    /**
     * ProcessPaymentAlertJob keeps ONE lockForUpdate-free transaction of
     * its own -- the alert status write -- and that is deliberate (see its
     * comment). The other four must hold no row lock at all any more.
     */
    public function test_only_the_payment_alert_job_still_opens_its_own_transaction(): void
    {
        foreach (['Services/Templates/TemplateMessageDispatcher.php',
                  'Services/Chatbot/ChatbotEngineService.php',
                  'Services/WhatsApp/WhatsAppJourneyEngine.php',
                  'Services/WhatsApp/DirectMessageDispatcher.php'] as $path) {
            $code = $this->codeWithoutComments($path);
            $this->assertStringNotContainsString('lockForUpdate', $code, "{$path} must not lock rows for quota");
        }

        $job = $this->codeWithoutComments('Jobs/ProcessPaymentAlertJob.php');
        $this->assertStringNotContainsString('lockForUpdate', $job, 'the job must not lock the subscription itself any more');
        $this->assertStringContainsString('DB::transaction', $job, 'but it must keep its own atomic alert-status write');
    }

    /**
     * [Updated by Phase 5 Task 4] This assertion used to pin the opposite:
     * that the two group dispatchers still owned their own reserve-ahead
     * block, guarding Task 4's starting point. Task 4 migrated them onto
     * MessageQuotaService::reserve(), so the invariant inverts -- they
     * must now hold no quota arithmetic of their own either, which makes
     * the service the single quota primitive across ALL SEVEN send paths.
     * The reserve-vs-consume distinction is asserted in
     * GroupQuotaReservationAndRefundTest.
     */
    public function test_the_group_reservation_paths_also_delegate_to_the_service(): void
    {
        foreach (['Services/Groups/GroupMessageDispatcher.php',
                  'Services/Groups/GroupDirectMessageDispatcher.php'] as $path) {
            $code = $this->codeWithoutComments($path);
            $this->assertStringContainsString('MessageQuotaService', $code);
            $this->assertStringNotContainsString("increment('used_messages'", $code);
            $this->assertStringNotContainsString('lockForUpdate', $code);
            // reserve(), never consume(), on a group path.
            $this->assertStringContainsString('->reserve(', $code);
            $this->assertStringNotContainsString('->consume(', $code);
        }
    }
}
