<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\ApiKey;
use App\Models\ContactGroup;
use App\Models\ContactGroupMember;
use App\Models\Invoice;
use App\Models\MessageDispatchLog;
use App\Models\Subscription;
use App\Models\WhatsAppSession;
use App\Services\Access\ProviderCapabilityService;
use App\Services\Billing\InvoiceCreditService;
use App\Services\WhatsApp\DirectMessageDispatcher;
use App\Support\PlanCatalog;
use Database\Seeders\Phase1FoundationSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Phase 5 Task 2 -- the two behaviour-preservation proofs for this task.
 *
 *   PART A  DirectMessageDispatcher now consumes quota through
 *           MessageQuotaService instead of its own inline
 *           lock-then-increment block. Every observable thing about that
 *           path -- the send, the counter, the dispatch log, the return
 *           envelope, the driver chosen -- must be exactly what it was.
 *
 *   PART B  Nine `engine_type !== 'qr'` literals guarding Native
 *           WhatsApp Groups now go through
 *           ProviderCapabilityService::supportsNativeWhatsAppGroups().
 *           QR must stay allowed and Meta must stay denied, on a SEEDED
 *           database (catalog authoritative) and on an UNSEEDED one
 *           (fallback) -- the second case is the one a naive
 *           supports() call would fail open on.
 *
 * Phase1FoundationSeeder is deliberately NOT run in setUp(): most of this
 * project's suites don't run it either, so the default state here is the
 * unseeded one, and the seeded case is opted into explicitly.
 */
class UnifiedQuotaAndCapabilityTest extends TestCase
{
    use RefreshDatabase;

    private const RECIPIENT = '919999999999';

    private const ENDPOINT = '/api/v1/whatsapp/messages/send';

    private const TOKEN = 'EAAG_permanent_meta_token_do_not_leak_0123456789';

    // ------------------------------------------------------------------
    // Fixtures (same conventions as MetaSendMessageTest)
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

        /*
         * Phase 5 Task 11 — fulfilment reads the `plans` table now, so a
         * payment cannot be credited without the row. Only the PLAN row
         * is created here, NOT Phase1FoundationSeeder: this suite's whole
         * premise is an UNSEEDED provider_capabilities catalog (see the
         * class docblock), and running the full seeder would destroy the
         * fail-open case it exists to prove.
         */
        \App\Models\Plan::updateOrCreate(['slug' => $planKey], [
            'label' => $plan['label'],
            'price' => $plan['price'],
            'duration_days' => $plan['duration_days'],
            'description' => $plan['description'],
            'engine_type' => $plan['engine_type'],
            'billing_model' => $plan['billing_model'],
            'rate_per_message' => $plan['rate_per_message'],
            'total_allocated_messages' => $plan['total_allocated_messages'],
        ]);

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

    /** @return array{key: string, secret: string} */
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
            'meta_access_token' => self::TOKEN,
        ]);

        return $account;
    }

    private function fakeBothEngines(): void
    {
        Http::fake([
            'graph.facebook.com/*' => Http::response([
                'messaging_product' => 'whatsapp',
                'messages' => [['id' => 'wamid.META_OK']],
            ], 200),
            '*' => Http::response(['success' => true, 'message_id' => 'QR_OK'], 200),
        ]);
    }

    private function usedMessages(Account $account): int
    {
        return (int) Subscription::where('account_id', $account->id)->firstOrFail()->used_messages;
    }

    private function nativeGroup(Account $account): ContactGroup
    {
        $group = ContactGroup::create([
            'account_id' => $account->id,
            'name' => 'Native Crew',
            'group_type' => ContactGroup::GROUP_TYPE_NATIVE,
            'wa_group_jid' => '120363000000000000@g.us',
            'sync_status' => ContactGroup::SYNC_STATUS_SYNCED,
        ]);

        ContactGroupMember::create([
            'group_id' => $group->id,
            'phone_number' => self::RECIPIENT,
            'name' => 'Bob',
        ]);

        return $group;
    }

    // ==================================================================
    // PART A -- DirectMessageDispatcher behaviour preservation
    // ==================================================================
    public function test_a_qr_text_send_still_succeeds_and_consumes_exactly_one_credit(): void
    {
        $this->fakeBothEngines();
        $account = $this->qrAccount();
        $before = $this->usedMessages($account);

        $result = DirectMessageDispatcher::dispatch($account->id, self::RECIPIENT, 'text', ['body' => 'Hello']);

        $this->assertSame('sent', $result['status']);
        $this->assertArrayHasKey('dispatch_log_id', $result);
        $this->assertSame($before + 1, $this->usedMessages($account));
    }

    public function test_a_meta_text_send_still_succeeds_and_consumes_exactly_one_credit(): void
    {
        $this->fakeBothEngines();
        $account = $this->metaAccount();
        $before = $this->usedMessages($account);

        $result = DirectMessageDispatcher::dispatch($account->id, self::RECIPIENT, 'text', ['body' => 'Hello']);

        $this->assertSame('sent', $result['status']);
        $this->assertSame($before + 1, $this->usedMessages($account));
    }

    public function test_the_dispatch_log_row_is_unchanged(): void
    {
        $this->fakeBothEngines();
        $account = $this->qrAccount();

        DirectMessageDispatcher::dispatch($account->id, self::RECIPIENT, 'text', ['body' => 'Hello'], source: 'api', apiKeyId: null);

        $log = MessageDispatchLog::where('account_id', $account->id)->latest('id')->firstOrFail();

        $this->assertSame('sent', $log->status);
        $this->assertSame('api', $log->source);
        $this->assertSame('text', $log->reference_type);
        $this->assertSame(self::RECIPIENT, $log->recipient_phone);
        $this->assertFalse((bool) $log->has_media);
        $this->assertNull($log->media_url);
        $this->assertSame('Hello', $log->message_preview);
    }

    public function test_a_media_send_still_records_its_attachment(): void
    {
        $this->fakeBothEngines();
        $account = $this->qrAccount();

        $result = DirectMessageDispatcher::dispatch($account->id, self::RECIPIENT, 'media', [
            'media_type' => 'image',
            'url' => 'https://cdn.example.com/a.jpg',
            'caption' => 'Look',
        ]);

        $this->assertSame('sent', $result['status']);

        $log = MessageDispatchLog::where('account_id', $account->id)->latest('id')->firstOrFail();
        $this->assertTrue((bool) $log->has_media);
        $this->assertSame('https://cdn.example.com/a.jpg', $log->media_url);
    }

    /**
     * The consume() call sits AFTER the driver call, exactly where the
     * inline block used to. A send the engine rejects must still cost
     * nothing.
     */
    public function test_a_rejected_send_consumes_no_quota(): void
    {
        Http::fake(['*' => Http::response(['success' => false, 'error' => 'Engine said no'], 200)]);
        $account = $this->qrAccount();
        $before = $this->usedMessages($account);

        $result = DirectMessageDispatcher::dispatch($account->id, self::RECIPIENT, 'text', ['body' => 'Hello']);

        $this->assertSame('failed', $result['status']);
        $this->assertSame($before, $this->usedMessages($account), 'a rejected send must not be billed');
    }

    public function test_an_exhausted_subscription_is_still_refused_before_any_send(): void
    {
        $this->fakeBothEngines();
        $account = $this->qrAccount();
        $subscription = Subscription::where('account_id', $account->id)->firstOrFail();
        Subscription::whereKey($subscription->id)->update([
            'used_messages' => $subscription->total_allocated_messages,
        ]);

        $result = DirectMessageDispatcher::dispatch($account->id, self::RECIPIENT, 'text', ['body' => 'Hello']);

        $this->assertSame('quota_exhausted', $result['status']);
        Http::assertNothingSent();

        $log = MessageDispatchLog::where('account_id', $account->id)->latest('id')->firstOrFail();
        $this->assertSame('failed', $log->status);
        $this->assertSame('No active subscription or quota exhausted.', $log->error_reason);
    }

    public function test_a_disconnected_qr_account_is_still_refused(): void
    {
        $this->fakeBothEngines();
        $account = $this->qrAccount();
        WhatsAppSession::where('account_id', $account->id)->update(['status' => 'disconnected']);

        $result = DirectMessageDispatcher::dispatch($account->id, self::RECIPIENT, 'text', ['body' => 'Hi']);

        $this->assertSame('disconnected', $result['status']);
        $this->assertSame(0, $this->usedMessages($account));
    }

    public function test_an_unknown_account_still_returns_not_found_and_logs_nothing(): void
    {
        $result = DirectMessageDispatcher::dispatch(999999, self::RECIPIENT, 'text', ['body' => 'Hi']);

        $this->assertSame('not_found', $result['status']);
        $this->assertSame(0, MessageDispatchLog::count());
    }

    /** The counter must move once per send, not once per call to the service. */
    public function test_repeated_sends_consume_one_credit_each(): void
    {
        $this->fakeBothEngines();
        $account = $this->qrAccount();

        for ($i = 0; $i < 3; $i++) {
            DirectMessageDispatcher::dispatch($account->id, self::RECIPIENT, 'text', ['body' => "m{$i}"]);
        }

        $this->assertSame(3, $this->usedMessages($account));
    }

    public function test_the_public_api_contract_for_a_direct_send_is_unchanged(): void
    {
        $this->fakeBothEngines();
        $account = $this->qrAccount();
        $issued = $this->issueApiKey($account);

        $response = $this->withHeaders([
            'X-API-KEY' => $issued['key'],
            'X-API-SECRET' => $issued['secret'],
        ])->postJson(self::ENDPOINT, [
            'recipient_type' => 'individual',
            'message_type' => 'text',
            'to' => self::RECIPIENT,
            'text' => ['body' => 'Hello'],
        ]);

        $response->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonPath('queued_recipients_count', 1)
            ->assertJsonStructure(['success', 'dispatch_id', 'queued_recipients_count']);

        $this->assertSame(1, $this->usedMessages($account));
    }

    public function test_no_quota_logic_remains_inline_in_the_direct_message_path(): void
    {
        $source = file_get_contents(app_path('Services/WhatsApp/DirectMessageDispatcher.php'));

        $this->assertStringContainsString('MessageQuotaService', $source);
        $this->assertStringNotContainsString("increment('used_messages'", $source);
        $this->assertStringNotContainsString('lockForUpdate', $source);
        $this->assertStringNotContainsString('total_allocated_messages', $source);
    }

    // ==================================================================
    // PART B -- provider capability wiring
    // ==================================================================
    public function test_the_catalog_allows_qr_groups_and_denies_meta_groups_when_seeded(): void
    {
        $this->seed(Phase1FoundationSeeder::class);
        $capabilities = app(ProviderCapabilityService::class);

        $this->assertTrue($capabilities->supportsNativeWhatsAppGroups('qr'));
        $this->assertFalse($capabilities->supportsNativeWhatsAppGroups('meta'));
    }

    public function test_meta_normal_sending_remains_allowed_in_the_catalog(): void
    {
        $this->seed(Phase1FoundationSeeder::class);
        $capabilities = app(ProviderCapabilityService::class);

        $this->assertTrue($capabilities->supports('meta', 'whatsapp_send'));
        $this->assertTrue($capabilities->supports('qr', 'whatsapp_send'));
    }

    /**
     * The fail-safe. On an UNSEEDED database a bare supports() call
     * returns true for everything ("no row stated = no restriction"),
     * which for a send-time gate would mean Meta suddenly supports
     * WhatsApp groups. supportsNativeWhatsAppGroups() must not.
     */
    public function test_the_gate_does_not_fail_open_on_an_unseeded_catalog(): void
    {
        $capabilities = app(ProviderCapabilityService::class);

        $this->assertTrue($capabilities->supports('meta', 'whatsapp_groups'), 'precondition: the bare lookup fails open');

        $this->assertTrue($capabilities->supportsNativeWhatsAppGroups('qr'));
        $this->assertFalse($capabilities->supportsNativeWhatsAppGroups('meta'), 'the gate must stay closed for Meta');
    }

    public function test_the_gate_denies_an_account_with_no_engine_configured(): void
    {
        $capabilities = app(ProviderCapabilityService::class);

        $this->assertFalse($capabilities->supportsNativeWhatsAppGroups(null));
        $this->assertFalse($capabilities->supportsNativeWhatsAppGroups(''));
    }

    public function test_supports_or_null_distinguishes_unknown_from_denied(): void
    {
        $capabilities = app(ProviderCapabilityService::class);

        $this->assertNull($capabilities->supportsOrNull('meta', 'whatsapp_groups'), 'unseeded = unknown');

        $this->seed(Phase1FoundationSeeder::class);
        $fresh = new ProviderCapabilityService();

        $this->assertFalse($fresh->supportsOrNull('meta', 'whatsapp_groups'));
        $this->assertTrue($fresh->supportsOrNull('qr', 'whatsapp_groups'));
    }

    public function test_a_meta_account_still_cannot_create_a_native_group(): void
    {
        $account = $this->metaAccount();
        $issued = $this->issueApiKey($account);

        $this->withHeaders([
            'X-API-KEY' => $issued['key'],
            'X-API-SECRET' => $issued['secret'],
        ])->postJson('/api/v1/whatsapp/groups/create', [
            'name' => 'Crew',
            'group_type' => ContactGroup::GROUP_TYPE_NATIVE,
            'contacts' => [['phone_number' => self::RECIPIENT, 'name' => 'Bob']],
        ])->assertStatus(422)->assertJsonPath('error_code', 'NATIVE_GROUP_REQUIRES_QR_ENGINE');
    }

    public function test_a_qr_account_can_still_create_a_native_group(): void
    {
        $this->fakeBothEngines();
        $account = $this->qrAccount();
        $issued = $this->issueApiKey($account);

        $this->withHeaders([
            'X-API-KEY' => $issued['key'],
            'X-API-SECRET' => $issued['secret'],
        ])->postJson('/api/v1/whatsapp/groups/create', [
            'name' => 'Crew',
            'group_type' => ContactGroup::GROUP_TYPE_NATIVE,
            'contacts' => [['phone_number' => self::RECIPIENT, 'name' => 'Bob']],
        ])->assertStatus(201)->assertJsonPath('success', true);
    }

    public function test_the_same_denial_holds_with_the_catalog_seeded(): void
    {
        $this->seed(Phase1FoundationSeeder::class);
        $account = $this->metaAccount();
        $issued = $this->issueApiKey($account);

        $this->withHeaders([
            'X-API-KEY' => $issued['key'],
            'X-API-SECRET' => $issued['secret'],
        ])->postJson('/api/v1/whatsapp/groups/create', [
            'name' => 'Crew',
            'group_type' => ContactGroup::GROUP_TYPE_NATIVE,
            'contacts' => [['phone_number' => self::RECIPIENT, 'name' => 'Bob']],
        ])->assertStatus(422)->assertJsonPath('error_code', 'NATIVE_GROUP_REQUIRES_QR_ENGINE');
    }

    /**
     * The group DISPATCH gate (a different call site from creation): an
     * account whose engine moved to Meta after a native group already
     * existed must still be refused.
     */
    public function test_a_native_group_send_is_refused_once_the_account_moves_to_meta(): void
    {
        $this->fakeBothEngines();
        $account = $this->qrAccount();
        $group = $this->nativeGroup($account);

        // The engine changes under an already-created native group.
        Subscription::where('account_id', $account->id)->update(['engine_type' => 'meta']);

        $result = \App\Services\Groups\GroupDirectMessageDispatcher::dispatch(
            $account->id,
            $group->id,
            'text',
            ['body' => 'Hello crew'],
        );

        $this->assertSame('unsupported_engine', $result['status']);
        $this->assertSame(0, $this->usedMessages($account), 'a refused group send must reserve nothing');
    }

    public function test_a_native_group_send_still_works_on_qr(): void
    {
        $this->fakeBothEngines();
        $account = $this->qrAccount();
        $group = $this->nativeGroup($account);

        $result = \App\Services\Groups\GroupDirectMessageDispatcher::dispatch(
            $account->id,
            $group->id,
            'text',
            ['body' => 'Hello crew'],
        );

        $this->assertSame('queued', $result['status']);
    }

    // ==================================================================
    // Invariants this task must not break
    // ==================================================================
    public function test_no_driver_is_instantiated_outside_the_engine_factory(): void
    {
        $offenders = [];

        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(app_path()));

        foreach ($files as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }

            $path = $file->getPathname();

            if (str_ends_with($path, 'WhatsAppEngineFactory.php')) {
                continue;
            }

            $source = file_get_contents($path);

            if (str_contains($source, 'new BaileysDriver') || str_contains($source, 'new MetaCloudApiDriver')) {
                $offenders[] = $path;
            }
        }

        $this->assertSame([], $offenders, 'provider selection must stay inside WhatsAppEngineFactory');
    }

    public function test_no_send_endpoint_accepts_a_caller_supplied_engine_type(): void
    {
        $requests = glob(app_path('Http/Requests/*.php'));
        $this->assertNotEmpty($requests);

        foreach ($requests as $path) {
            $source = file_get_contents($path);

            foreach (["'engine_type'", "'provider'", "'tenant_id'"] as $forbidden) {
                $this->assertStringNotContainsString(
                    $forbidden,
                    $source,
                    basename($path).' must not accept '.$forbidden.' from request input',
                );
            }
        }
    }

    /** A caller cannot pick the provider by sending one: engine comes from the subscription. */
    public function test_a_caller_cannot_force_provider_selection_through_the_public_api(): void
    {
        $this->fakeBothEngines();
        $account = $this->qrAccount();
        $issued = $this->issueApiKey($account);

        $this->withHeaders([
            'X-API-KEY' => $issued['key'],
            'X-API-SECRET' => $issued['secret'],
        ])->postJson(self::ENDPOINT, [
            'recipient_type' => 'individual',
            'message_type' => 'text',
            'to' => self::RECIPIENT,
            'text' => ['body' => 'Hello'],
            // All ignored.
            'engine_type' => 'meta',
            'provider' => 'meta',
            'account_id' => 999999,
        ])->assertStatus(200);

        // The QR engine was used; Graph was never called.
        foreach (Http::recorded() as [$request]) {
            $this->assertStringNotContainsString('graph.facebook.com', $request->url());
        }

        $this->assertSame('qr', Subscription::where('account_id', $account->id)->firstOrFail()->engine_type);
        $this->assertSame(1, $this->usedMessages($account));
    }
}
