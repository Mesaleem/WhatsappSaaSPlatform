<?php

namespace Tests\Feature;

use App\Jobs\DispatchWebhookJob;
use App\Models\Account;
use App\Models\ChatbotRule;
use App\Models\Invoice;
use App\Models\Lead;
use App\Models\MessageDispatchLog;
use App\Models\PaymentAlert;
use App\Models\SocialProviderConfig;
use App\Models\WebhookSubscription;
use App\Models\WhatsAppSession;
use App\Services\Billing\InvoiceCreditService;
use App\Support\PlanCatalog;
use Database\Seeders\Phase1FoundationSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

/**
 * Phase 12 Task 6 — Meta duplicate-delivery contract. Meta delivers at-least-once. Whatever the existing
 * idempotency (inbound_message_events unique key, delivered-status cache claim, failed-status guard) decides,
 * a duplicate delivery must never create a second BUSINESS EFFECT: no second auto-reply, no second quota charge,
 * no second lead, no second tenant webhook. The wire contract (always 200 to Meta) is pinned too.
 * Complements MetaWebhookTest; adds effect-counting across tenants, mixed batches and distinct-event controls.
 */
#[Group('release-safety')]
class MetaDuplicateDeliveryContractTest extends TestCase
{
    use RefreshDatabase;

    private const APP_SECRET = 'meta_app_secret_task6_0123456789';
    private const PHONE_ID = '109876543210987';
    private const OTHER_PHONE_ID = '555000111222333';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(Phase1FoundationSeeder::class);
        SocialProviderConfig::create(['provider' => 'meta', 'client_id' => 'app-id', 'client_secret' => self::APP_SECRET, 'is_active' => true]);
        Http::fake(['graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.REPLY']]], 200)]);
    }

    /** @return array{account: Account, session: WhatsAppSession} */
    private function tenant(string $phoneId = self::PHONE_ID, bool $withGreeting = true): array
    {
        $account = Account::factory()->create();
        $key = collect(PlanCatalog::all())->filter(fn ($p) => $p['engine_type'] === 'meta')->keys()->first();
        $plan = PlanCatalog::find($key);
        $invoice = Invoice::create([
            'account_id' => $account->id, 'invoice_number' => 'INV-'.uniqid(), 'plan_key' => $key, 'plan_label' => $plan['label'],
            'amount' => $plan['price'], 'tax_amount' => 0, 'total_amount' => $plan['price'], 'currency' => 'INR',
            'payment_gateway' => 'razorpay', 'gateway_order_id' => 'order_'.uniqid(), 'status' => 'pending',
        ]);
        app(InvoiceCreditService::class)->markPaidAndCreditQuota($invoice->id, 'pay_'.uniqid());

        $session = WhatsAppSession::create([
            'account_id' => $account->id, 'status' => 'connected', 'meta_phone_number_id' => $phoneId, 'meta_waba_id' => '123456789012345',
            'meta_access_token' => 'EAAG_tenant_token_task6', 'meta_webhook_verify_token' => 'Verify'.uniqid(),
        ]);
        if ($withGreeting) {
            ChatbotRule::create(['account_id' => $account->id, 'name' => 'Greeting', 'match_type' => 'contains', 'keywords' => ['hello'], 'response_type' => 'text', 'response_payload' => ['text' => 'Hi there!'], 'is_active' => true, 'priority' => 1]);
        }

        return ['account' => $account, 'session' => $session];
    }

    private function deliver(array $payload, ?string $secret = self::APP_SECRET)
    {
        $body = json_encode($payload);
        $server = ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'];
        if ($secret !== null) {
            $server['HTTP_X_HUB_SIGNATURE_256'] = 'sha256='.hash_hmac('sha256', $body, $secret);
        }

        return $this->call('POST', '/api/webhooks/meta', [], [], [], $server, $body);
    }

    private function message(string $id, string $text = 'hello', array $extra = []): array
    {
        return ['from' => '919111111111', 'id' => $id, 'timestamp' => '1700000000', 'type' => 'text', 'text' => ['body' => $text]] + $extra;
    }

    private function inbound(array $messages, string $phoneId = self::PHONE_ID): array
    {
        return ['object' => 'whatsapp_business_account', 'entry' => [['id' => 'waba', 'changes' => [['field' => 'messages', 'value' => [
            'messaging_product' => 'whatsapp',
            'metadata' => ['display_phone_number' => '919999999999', 'phone_number_id' => $phoneId],
            'contacts' => [['profile' => ['name' => 'Bob'], 'wa_id' => '919111111111']],
            'messages' => $messages,
        ]]]]]];
    }

    private function statusEvent(string $wamid, string $status, array $extra = [], string $phoneId = self::PHONE_ID): array
    {
        return ['object' => 'whatsapp_business_account', 'entry' => [['id' => 'waba', 'changes' => [['field' => 'messages', 'value' => [
            'messaging_product' => 'whatsapp',
            'metadata' => ['display_phone_number' => '919999999999', 'phone_number_id' => $phoneId],
            'statuses' => [['id' => $wamid, 'status' => $status] + $extra],
        ]]]]]];
    }

    private function graphSends(): int
    {
        return Http::recorded()->count();
    }

    private function used(Account $a): int
    {
        return (int) $a->fresh()->currentSubscription->used_messages;
    }

    public function test_a_duplicate_inbound_delivery_is_acknowledged_and_creates_no_second_effect(): void
    {
        $t = $this->tenant();
        $payload = $this->inbound([$this->message('wamid.IN1')]);

        $this->deliver($payload)->assertOk();
        $sends = $this->graphSends();
        $used = $this->used($t['account']);
        $events = DB::table('inbound_message_events')->count();
        $this->assertGreaterThanOrEqual(1, $sends, 'control: the first delivery is answered');
        $this->assertSame(1, $events);

        foreach (range(1, 4) as $_) {
            $this->deliver($payload)->assertOk();
        }

        $this->assertSame($sends, $this->graphSends(), 'duplicate deliveries sent more replies');
        $this->assertSame($used, $this->used($t['account']), 'duplicate deliveries charged quota again');
        $this->assertSame($events, DB::table('inbound_message_events')->count(), 'duplicate deliveries recorded more events');
    }

    public function test_a_duplicate_id_inside_one_batch_is_processed_once(): void
    {
        $t = $this->tenant();
        $this->deliver($this->inbound([$this->message('wamid.SAME'), $this->message('wamid.SAME')]))->assertOk();
        $afterBatch = $this->graphSends();
        $usedBatch = $this->used($t['account']);

        $solo = $this->tenant('222000111000222');
        $this->deliver($this->inbound([$this->message('wamid.SAME')], '222000111000222'))->assertOk();

        $this->assertSame(1, DB::table('inbound_message_events')->where('account_id', $t['account']->id)->count());
        $this->assertSame($this->used($solo['account']), $usedBatch, 'a batch with a repeated id must cost the same as a single message');
        $this->assertGreaterThanOrEqual($afterBatch, $this->graphSends());
    }

    public function test_a_mixed_batch_processes_only_the_new_message(): void
    {
        $t = $this->tenant();
        $this->deliver($this->inbound([$this->message('wamid.OLD')]))->assertOk();
        $usedAfterOld = $this->used($t['account']);
        $this->assertGreaterThan(0, $usedAfterOld, 'control: a message is billed');

        $this->deliver($this->inbound([$this->message('wamid.OLD'), $this->message('wamid.NEW')]))->assertOk();

        $this->assertSame(2, DB::table('inbound_message_events')->where('account_id', $t['account']->id)->count());
        $this->assertSame($usedAfterOld * 2, $this->used($t['account']), 'exactly one more message may be billed (the new one)');
    }

    public function test_the_same_message_id_for_two_tenants_is_two_independent_events(): void
    {
        $a = $this->tenant();
        $b = $this->tenant(self::OTHER_PHONE_ID);

        $this->deliver($this->inbound([$this->message('wamid.COLLIDE')]))->assertOk();
        $this->deliver($this->inbound([$this->message('wamid.COLLIDE')], self::OTHER_PHONE_ID))->assertOk();

        $this->assertSame(1, DB::table('inbound_message_events')->where('account_id', $a['account']->id)->count());
        $this->assertSame(1, DB::table('inbound_message_events')->where('account_id', $b['account']->id)->count(), 'tenant B was suppressed by tenant A\'s identical id');
        $this->assertSame($this->used($a['account']), $this->used($b['account']));
    }

    public function test_a_distinct_message_is_never_swallowed_by_the_duplicate_guard(): void
    {
        $t = $this->tenant();
        $this->deliver($this->inbound([$this->message('wamid.A')]))->assertOk();
        $one = $this->used($t['account']);
        $this->assertGreaterThan(0, $one, 'control: a message is billed');
        $this->deliver($this->inbound([$this->message('wamid.B')]))->assertOk();
        $this->deliver($this->inbound([$this->message('wamid.C')]))->assertOk();

        $this->assertSame($one * 3, $this->used($t['account']));
        $this->assertSame(3, DB::table('inbound_message_events')->count());
    }

    public function test_a_duplicate_ctwa_referral_creates_one_lead(): void
    {
        $t = $this->tenant(withGreeting: false);
        $payload = $this->inbound([$this->message('wamid.AD1', 'hi', ['referral' => ['source_id' => 'ad-9', 'source_type' => 'ad']])]);

        foreach (range(1, 3) as $_) {
            $this->deliver($payload)->assertOk();
        }

        $this->assertSame(1, Lead::where('account_id', $t['account']->id)->where('provider_lead_id', 'wamid.AD1')->count());
    }

    public function test_a_duplicate_delivered_status_fires_one_tenant_webhook(): void
    {
        Queue::fake();
        $t = $this->tenant(withGreeting: false);
        WebhookSubscription::create(['account_id' => $t['account']->id, 'url' => 'https://tenant.example.test/hook', 'secret' => 'whsec', 'events' => ['message.delivered'], 'is_active' => true]);
        PaymentAlert::create(['account_id' => $t['account']->id, 'recipient_phone' => '919111111111', 'customer_name' => 'Bob', 'amount' => 100, 'payment_ref' => 'PAY-T6', 'status' => 'sent', 'gateway_message_id' => 'wamid.DLV']);

        foreach (range(1, 4) as $_) {
            $this->deliver($this->statusEvent('wamid.DLV', 'delivered'))->assertOk();
        }

        Queue::assertPushed(DispatchWebhookJob::class, 1);
    }

    public function test_a_duplicate_failed_status_keeps_the_first_reason_and_a_late_different_status_does_not_resurrect_it(): void
    {
        $t = $this->tenant(withGreeting: false);
        $log = MessageDispatchLog::record($t['account']->id, 'api', '919111111111', success: true, gatewayMessageId: 'wamid.FAIL');

        $this->deliver($this->statusEvent('wamid.FAIL', 'failed', ['errors' => [['title' => 'First reason']]]))->assertOk();
        $this->deliver($this->statusEvent('wamid.FAIL', 'failed', ['errors' => [['title' => 'Second reason']]]))->assertOk();
        $this->deliver($this->statusEvent('wamid.FAIL', 'sent'))->assertOk();

        $fresh = $log->fresh();
        $this->assertSame('failed', $fresh->status);
        $this->assertSame('First reason', $fresh->error_reason);
        $this->assertSame(1, MessageDispatchLog::where('gateway_message_id', 'wamid.FAIL')->count(), 'a status event must never create a dispatch row');
    }

    public function test_a_status_for_one_tenant_never_touches_another_tenants_row_with_the_same_wamid(): void
    {
        $a = $this->tenant(withGreeting: false);
        $b = $this->tenant(self::OTHER_PHONE_ID, withGreeting: false);
        $logB = MessageDispatchLog::record($b['account']->id, 'api', '919111111111', success: true, gatewayMessageId: 'wamid.X');

        $this->deliver($this->statusEvent('wamid.X', 'failed', ['errors' => [['title' => 'nope']]]))->assertOk();

        $this->assertSame('sent', $logB->fresh()->status);
    }

    public function test_duplicates_are_always_acknowledged_with_200_and_an_empty_error_free_body(): void
    {
        $this->tenant();
        $payload = $this->inbound([$this->message('wamid.ACK')]);
        foreach (range(1, 3) as $_) {
            $r = $this->deliver($payload);
            $r->assertOk();
            $this->assertStringNotContainsString('error', strtolower($r->getContent()));
        }
    }

    public function test_an_unsigned_or_wrongly_signed_duplicate_is_rejected_and_has_no_effect(): void
    {
        $t = $this->tenant();
        $payload = $this->inbound([$this->message('wamid.SIG')]);

        $this->deliver($payload, null)->assertStatus(403);
        $this->deliver($payload, 'wrong-secret')->assertStatus(403);

        $this->assertSame(0, DB::table('inbound_message_events')->count());
        $this->assertSame(0, $this->graphSends());

        // the same message, now validly signed, is then processed (rejection must not have "burned" the event id)
        $this->deliver($payload)->assertOk();
        $this->assertSame(1, DB::table('inbound_message_events')->count());
    }
}
