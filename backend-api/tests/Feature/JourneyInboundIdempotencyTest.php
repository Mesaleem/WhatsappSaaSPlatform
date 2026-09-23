<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\AccountEntitlement;
use App\Models\Capability;
use App\Models\CrmLead;
use App\Models\InboundMessageEvent;
use App\Models\Invoice;
use App\Models\Lead;
use App\Models\MessageDispatchLog;
use App\Models\SocialProviderConfig;
use App\Models\Subscription;
use App\Models\WhatsAppFlow;
use App\Models\WhatsAppFlowSession;
use App\Models\WhatsAppSession;
use App\Services\Billing\InvoiceCreditService;
use App\Services\Chatbot\ChatbotEngineService;
use App\Services\Messaging\InboundEventGate;
use App\Services\WhatsApp\WhatsAppJourneyEngine;
use App\Support\PlanCatalog;
use Database\Seeders\Phase1FoundationSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Tests\TestCase;

/**
 * Phase 7 Task 3 — durable inbound idempotency and per-conversation
 * serialization (InboundEventGate, inside ChatbotEngineService).
 *
 * Identity: Meta → the WAMID ('wamid:<id>'); QR → the Baileys message key
 * id sent by qr-engine-service as message_id ('wamsg:<id>'). The claim is a
 * row in inbound_message_events under unique(account_id, provider,
 * event_key); the conversation lease is a row in
 * journey_conversation_locks. Both are the database — nothing here depends
 * on the cache.
 */
class JourneyInboundIdempotencyTest extends TestCase
{
    use RefreshDatabase;

    private const PHONE = '919812345678';

    private const PHONE_ID = '109876543210001';

    private const SECRET = 'meta_app_secret_inbound_test';

    private const INTERNAL = 'internal-secret-inbound-test';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(Phase1FoundationSeeder::class);
        config(['services.qr_engine.internal_secret' => self::INTERNAL]);
        SocialProviderConfig::create(['provider' => 'meta', 'client_id' => 'app', 'client_secret' => self::SECRET, 'is_active' => true]);
        Http::fake(['*' => Http::response(['success' => true, 'message_id' => 'OK', 'messages' => [['id' => 'wamid.OUT']]], 200)]);
    }

    // ------------------------------------------------------------------ fixtures

    private function tenant(string $planKey = 'growth', bool $meta = false): Account
    {
        $account = Account::factory()->create();
        $plan = PlanCatalog::find($planKey);
        $invoice = Invoice::create([
            'account_id' => $account->id, 'invoice_number' => 'INV-'.uniqid(), 'plan_key' => $planKey,
            'plan_label' => $plan['label'], 'amount' => $plan['price'], 'tax_amount' => 0,
            'total_amount' => $plan['price'], 'currency' => 'INR', 'payment_gateway' => 'razorpay',
            'gateway_order_id' => 'order_'.uniqid(), 'gateway_payment_id' => null, 'status' => 'pending',
            'paid_at' => null, 'gateway_raw_response' => null,
        ]);
        app(InvoiceCreditService::class)->markPaidAndCreditQuota($invoice->id, 'pay_'.uniqid());
        WhatsAppSession::create($meta
            ? ['account_id' => $account->id, 'status' => 'connected', 'meta_phone_number_id' => self::PHONE_ID, 'meta_waba_id' => '123456789012345', 'meta_access_token' => 'EAAG', 'meta_webhook_verify_token' => 'V'.uniqid()]
            : ['account_id' => $account->id, 'status' => 'connected']);

        return $account->fresh();
    }

    /** trigger(keyword join) → question(name) → save_lead("Thanks") */
    private function questionFlow(Account $account): WhatsAppFlow
    {
        return WhatsAppFlow::create([
            'account_id' => $account->id, 'name' => 'Ask', 'trigger_type' => 'keyword', 'trigger_value' => 'join', 'is_active' => true,
            'graph_data' => [
                'nodes' => [
                    ['id' => 't', 'type' => 'trigger', 'data' => []],
                    ['id' => 'q', 'type' => 'question', 'data' => ['prompt_text' => 'Your name?', 'variable_name' => 'name', 'input_type' => 'text']],
                    ['id' => 'save', 'type' => 'save_lead', 'data' => ['name_variable' => 'name', 'completion_message' => 'Thanks']],
                ],
                'edges' => [['id' => 'a', 'source' => 't', 'target' => 'q'], ['id' => 'b', 'source' => 'q', 'target' => 'save']],
            ],
        ]);
    }

    /** trigger(keyword join) → "Before" → delay 5m → "After" */
    private function delayFlow(Account $account): WhatsAppFlow
    {
        return WhatsAppFlow::create([
            'account_id' => $account->id, 'name' => 'Drip', 'trigger_type' => 'keyword', 'trigger_value' => 'join', 'is_active' => true,
            'graph_data' => [
                'nodes' => [
                    ['id' => 't', 'type' => 'trigger', 'data' => []],
                    ['id' => 'b', 'type' => 'message', 'data' => ['text' => 'Before']],
                    ['id' => 'd', 'type' => 'delay', 'data' => ['amount' => 5, 'unit' => 'minutes']],
                    ['id' => 'a', 'type' => 'message', 'data' => ['text' => 'After']],
                ],
                'edges' => [['id' => 'e1', 'source' => 't', 'target' => 'b'], ['id' => 'e2', 'source' => 'b', 'target' => 'd'], ['id' => 'e3', 'source' => 'd', 'target' => 'a']],
            ],
        ]);
    }

    /** An inbound message with its durable identity, through the real choke point. */
    private function event(Account $account, string $text, ?string $key, string $phone = self::PHONE, string $provider = InboundMessageEvent::PROVIDER_QR): void
    {
        app(ChatbotEngineService::class)->handleInboundMessage($account->id, $phone, $text, null, $provider, $key);
    }

    private function qrInbound(Account $account, string $text, ?string $messageId, string $phone = self::PHONE)
    {
        return $this->withHeader('X-Internal-Secret', self::INTERNAL)->postJson('/api/internal/whatsapp-inbound', array_filter([
            'account_id' => $account->id, 'sender_phone' => $phone, 'message' => $text, 'message_id' => $messageId,
        ], fn ($v) => $v !== null));
    }

    private function metaInbound(string $wamid, string $text, string $from = self::PHONE)
    {
        $body = json_encode(['object' => 'whatsapp_business_account', 'entry' => [[
            'id' => 'waba', 'changes' => [['field' => 'messages', 'value' => [
                'messaging_product' => 'whatsapp',
                'metadata' => ['display_phone_number' => '919999999999', 'phone_number_id' => self::PHONE_ID],
                'contacts' => [['profile' => ['name' => 'Bob'], 'wa_id' => $from]],
                'messages' => [['from' => $from, 'id' => $wamid, 'timestamp' => '1700000000', 'type' => 'text', 'text' => ['body' => $text]]],
            ]]],
        ]]]);

        return $this->call('POST', '/api/webhooks/meta', [], [], [], [
            'CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json',
            'HTTP_X_HUB_SIGNATURE_256' => 'sha256='.hash_hmac('sha256', $body, self::SECRET),
        ], $body);
    }

    /** @return list<string> */
    private function sent(Account $account, string $phone = self::PHONE): array
    {
        return MessageDispatchLog::where('account_id', $account->id)->where('source', 'journey')->where('recipient_phone', $phone)
            ->orderBy('id')->pluck('message_preview')->all();
    }

    private function used(Account $account): int
    {
        return (int) Subscription::where('account_id', $account->id)->value('used_messages');
    }

    // ------------------------------------------------------------------ one event, duplicates

    public function test_one_inbound_event_starts_exactly_one_journey_and_is_recorded(): void
    {
        $account = $this->tenant();
        $this->questionFlow($account);

        $this->event($account, 'join', 'wamsg:K1');

        $this->assertSame(1, WhatsAppFlowSession::count());
        $this->assertSame(['Your name?'], $this->sent($account));
        $claim = InboundMessageEvent::sole();
        $this->assertSame([$account->id, 'qr', 'wamsg:K1', self::PHONE], [$claim->account_id, $claim->provider, $claim->event_key, $claim->phone_number]);
        $this->assertNotNull($claim->processed_at);
    }

    public function test_an_exact_duplicate_starts_nothing_sends_nothing_and_uses_no_quota(): void
    {
        $account = $this->tenant();
        $this->questionFlow($account);
        $this->event($account, 'join', 'wamsg:K1');
        $used = $this->used($account);

        $this->event($account, 'join', 'wamsg:K1');
        $this->event($account, 'join', 'wamsg:K1');

        $this->assertSame(1, WhatsAppFlowSession::count());
        $this->assertSame(['Your name?'], $this->sent($account));
        $this->assertSame($used, $this->used($account));
        $this->assertSame(1, InboundMessageEvent::count());
    }

    public function test_a_duplicate_meta_webhook_delivery_executes_once(): void
    {
        $account = $this->tenant('business', meta: true);
        $this->questionFlow($account);

        $this->metaInbound('wamid.IN1', 'join')->assertOk();
        $this->metaInbound('wamid.IN1', 'join')->assertOk();

        $this->assertSame(1, WhatsAppFlowSession::count());
        $this->assertSame(['Your name?'], $this->sent($account));
        $this->assertSame('wamid:wamid.IN1', InboundMessageEvent::sole()->event_key);
        $this->assertSame('meta', InboundMessageEvent::sole()->provider);
    }

    public function test_the_meta_claim_survives_a_cache_flush(): void
    {
        $account = $this->tenant('business', meta: true);
        $this->questionFlow($account);
        $this->metaInbound('wamid.IN1', 'join');

        \Illuminate\Support\Facades\Cache::flush(); // a restart / cache eviction

        $this->metaInbound('wamid.IN1', 'join');
        $this->assertSame(['Your name?'], $this->sent($account));
    }

    public function test_a_duplicate_qr_inbound_event_executes_once_and_a_new_message_id_is_processed(): void
    {
        $account = $this->tenant();
        $this->questionFlow($account);

        $this->qrInbound($account, 'join', '3EB0AAAA')->assertOk();
        $this->qrInbound($account, 'join', '3EB0AAAA')->assertOk();
        $this->assertSame(1, WhatsAppFlowSession::count());
        $this->assertSame(['Your name?'], $this->sent($account));

        $this->qrInbound($account, 'Meera', '3EB0BBBB')->assertOk();
        $this->assertSame(['Your name?', 'Thanks'], $this->sent($account));
        $this->assertSame(['wamsg:3EB0AAAA', 'wamsg:3EB0BBBB'], InboundMessageEvent::orderBy('id')->pluck('event_key')->all());
    }

    // ------------------------------------------------------------------ concurrency

    public function test_a_concurrent_identical_event_that_lost_the_insert_race_does_nothing(): void
    {
        $account = $this->tenant();
        $this->questionFlow($account);
        // The concurrent twin committed its claim first.
        DB::table('inbound_message_events')->insert(['account_id' => $account->id, 'provider' => 'qr', 'event_key' => 'wamsg:K1', 'phone_number' => self::PHONE, 'created_at' => now()]);

        $this->event($account, 'join', 'wamsg:K1');

        $this->assertSame(0, WhatsAppFlowSession::count());
        $this->assertSame([], $this->sent($account));
    }

    public function test_the_database_refuses_a_second_claim_of_the_same_event(): void
    {
        $account = $this->tenant();
        $row = ['account_id' => $account->id, 'provider' => 'qr', 'event_key' => 'wamsg:K1', 'phone_number' => self::PHONE, 'created_at' => now()];
        DB::table('inbound_message_events')->insert($row);

        $this->expectException(QueryException::class);
        DB::table('inbound_message_events')->insert($row);
    }

    public function test_a_conversation_held_by_another_worker_is_not_advanced_concurrently(): void
    {
        $account = $this->tenant();
        $this->questionFlow($account);
        $this->event($account, 'join', 'wamsg:K1');
        config(['journeys.inbound_lock_wait_ms' => 0]);
        // Another request is mid-way through this customer's conversation.
        DB::table('journey_conversation_locks')->where('account_id', $account->id)->where('phone_number', self::PHONE)
            ->update(['owner' => 'other-worker', 'locked_until' => now()->addMinute()]);

        $this->event($account, 'Meera', 'wamsg:K2');

        $this->assertSame('q', WhatsAppFlowSession::sole()->current_node_id, 'not advanced under someone else\'s lease');
        $this->assertSame(['Your name?'], $this->sent($account));
        $this->assertSame(0, InboundMessageEvent::where('event_key', 'wamsg:K2')->count(), 'not claimed, so it can still be processed');

        // Once the other worker's lease is gone (released or expired), it is processed once.
        $this->travel(2)->minutes();
        $this->event($account, 'Meera', 'wamsg:K2');
        $this->event($account, 'Meera', 'wamsg:K2');
        $this->assertSame(['Your name?', 'Thanks'], $this->sent($account));
        $this->assertSame(1, Lead::count());
    }

    public function test_other_customers_are_not_blocked_by_one_conversations_lease(): void
    {
        $account = $this->tenant();
        $this->questionFlow($account);
        config(['journeys.inbound_lock_wait_ms' => 0]);
        DB::table('journey_conversation_locks')->insert(['account_id' => $account->id, 'phone_number' => self::PHONE, 'owner' => 'x', 'locked_until' => now()->addMinute(), 'created_at' => now(), 'updated_at' => now()]);

        $this->event($account, 'join', 'wamsg:OTHER', '919800000002');

        $this->assertSame(['Your name?'], $this->sent($account, '919800000002'));
    }

    public function test_the_lease_is_released_even_when_processing_throws(): void
    {
        $account = $this->tenant();

        try {
            app(InboundEventGate::class)->run($account->id, self::PHONE, 'qr', 'wamsg:BOOM', fn () => throw new RuntimeException('boom'));
        } catch (RuntimeException) {
        }

        $lock = DB::table('journey_conversation_locks')->where('account_id', $account->id)->sole();
        $this->assertNull($lock->owner);
        $claim = InboundMessageEvent::sole();
        $this->assertNull($claim->processed_at, 'claimed but not completed — at-most-once: it is not re-run');
    }

    // ------------------------------------------------------------------ duplicates vs session state

    public function test_a_duplicate_while_a_session_is_waiting_changes_nothing(): void
    {
        $account = $this->tenant();
        $this->delayFlow($account);
        $this->event($account, 'join', 'wamsg:K1');
        $before = WhatsAppFlowSession::sole()->only(['status', 'current_node_id', 'wait_until', 'attempts', 'flow_version_id']);

        $this->event($account, 'join', 'wamsg:K1');

        $this->assertSame(1, WhatsAppFlowSession::count());
        $this->assertEquals($before, WhatsAppFlowSession::sole()->only(['status', 'current_node_id', 'wait_until', 'attempts', 'flow_version_id']));
        $this->assertSame(['Before'], $this->sent($account));

        $this->travel(5)->minutes();
        app(WhatsAppJourneyEngine::class)->resumeDueSession(WhatsAppFlowSession::sole()->id);
        $this->assertSame(['Before', 'After'], $this->sent($account), 'the waiting run still resumes normally');
    }

    public function test_a_duplicate_answer_while_active_advances_once_and_saves_one_lead(): void
    {
        $account = $this->tenant();
        $this->questionFlow($account);
        $this->event($account, 'join', 'wamsg:K1');
        $this->event($account, 'join', 'wamsg:K1'); // duplicate of the trigger while active: not taken as an answer

        $this->assertSame('q', WhatsAppFlowSession::sole()->current_node_id);

        $this->event($account, 'Meera', 'wamsg:K2');
        $this->event($account, 'Meera', 'wamsg:K2');

        $this->assertSame(['Your name?', 'Thanks'], $this->sent($account));
        $this->assertSame(1, Lead::count());
        $this->assertSame(1, CrmLead::count());
        $this->assertSame('Meera', Lead::sole()->lead_name);
    }

    public function test_two_distinct_messages_with_the_same_text_are_both_handled(): void
    {
        $account = $this->tenant();
        $this->questionFlow($account);

        // Same customer, same text, two real messages: the second one is the answer.
        $this->event($account, 'join', 'wamsg:K1');
        $this->event($account, 'join', 'wamsg:K2');

        $this->assertSame(['Your name?', 'Thanks'], $this->sent($account));
        $this->assertSame(['name' => 'join'], WhatsAppFlowSession::sole()->context_data);

        // And a fresh journey from the same customer later on.
        $this->event($account, 'join', 'wamsg:K3');
        $this->assertSame(2, WhatsAppFlowSession::count());
    }

    public function test_messages_without_an_identity_are_still_processed_every_time(): void
    {
        $account = $this->tenant();
        $this->questionFlow($account);

        // An older qr-engine build that sends no message_id: no safe identity, no de-duplication.
        $this->qrInbound($account, 'join', null)->assertOk();
        $this->qrInbound($account, 'Meera', null)->assertOk();

        $this->assertSame(['Your name?', 'Thanks'], $this->sent($account));
        $this->assertSame(0, InboundMessageEvent::count());
    }

    // ------------------------------------------------------------------ tenancy, entitlement, versions

    public function test_the_same_event_key_under_two_accounts_is_two_events(): void
    {
        $a = $this->tenant();
        $b = $this->tenant();
        $this->questionFlow($a);
        $this->questionFlow($b);

        $this->event($a, 'join', 'wamsg:SAME');
        $this->event($b, 'join', 'wamsg:SAME');

        $this->assertSame(['Your name?'], $this->sent($a));
        $this->assertSame(['Your name?'], $this->sent($b));
        $this->assertSame(2, InboundMessageEvent::count());
    }

    public function test_a_duplicate_does_not_bypass_runtime_entitlement_or_replay_after_it_is_restored(): void
    {
        $account = $this->tenant();
        $this->questionFlow($account);
        $capId = Capability::where('slug', 'journey_automation')->value('id');
        AccountEntitlement::where('account_id', $account->id)->where('capability_id', $capId)->update(['revoked_at' => now()]);

        $this->event($account, 'join', 'wamsg:K1');
        $this->assertSame(0, WhatsAppFlowSession::count());

        AccountEntitlement::where('account_id', $account->id)->where('capability_id', $capId)->update(['revoked_at' => null]);
        $this->event($account, 'join', 'wamsg:K1'); // the same event, redelivered after restore
        $this->assertSame(0, WhatsAppFlowSession::count(), 'an already-handled event is not replayed');

        $this->event($account, 'join', 'wamsg:K2');
        $this->assertSame(1, WhatsAppFlowSession::count());

        // Module off: a new event is claimed but runs no journey.
        $account->forceFill(['allowed_modules' => array_values(array_diff(Account::MODULES, ['chatbot']))])->save();
        $this->event($account, 'join', 'wamsg:K3', '919800000003');
        $this->assertSame(1, WhatsAppFlowSession::count());
    }

    public function test_a_duplicate_never_moves_a_sessions_version_pin(): void
    {
        $account = $this->tenant();
        $flow = $this->questionFlow($account);
        $this->event($account, 'join', 'wamsg:K1');
        $pin = WhatsAppFlowSession::sole()->flow_version_id;

        $graph = $flow->graph_data;
        $graph['nodes'][1]['data']['prompt_text'] = 'Name please? (v2)';
        $flow->forceFill(['graph_data' => $graph])->save(); // v2 published

        $this->event($account, 'join', 'wamsg:K1');
        $this->assertSame(1, WhatsAppFlowSession::count());
        $this->assertSame($pin, WhatsAppFlowSession::sole()->flow_version_id);

        $this->event($account, 'Meera', 'wamsg:K2');
        $this->assertSame(['Your name?', 'Thanks'], $this->sent($account));
    }
}
