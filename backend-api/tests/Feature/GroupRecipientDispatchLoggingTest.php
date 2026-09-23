<?php

namespace Tests\Feature;

use App\Jobs\ProcessGroupDirectMessageJob;
use App\Jobs\ProcessGroupDispatchJob;
use App\Models\Account;
use App\Models\ContactGroup;
use App\Models\ContactGroupMember;
use App\Models\Invoice;
use App\Models\MessageDispatchLog;
use App\Models\MessageTemplate;
use App\Models\SocialProviderConfig;
use App\Models\Subscription;
use App\Models\WhatsAppSession;
use App\Services\Billing\InvoiceCreditService;
use App\Services\Groups\GroupDirectMessageDispatcher;
use App\Services\Groups\GroupMessageDispatcher;
use App\Support\PlanCatalog;
use Database\Seeders\Phase1FoundationSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\TestCase;

require_once __DIR__.'/../Support/disable_job_sleep.php';

/**
 * Phase 5 Task 5 -- per-recipient group dispatch logging and gateway
 * correlation.
 *
 * A group batch used to write ONE aggregate row, which meant no
 * per-recipient gateway_message_id, no way for a Meta status callback to
 * reach a group send, has_media permanently false, and no recipient-level
 * audit at all. Each batch now also writes one row per actual attempt,
 * hanging off the aggregate via parent_dispatch_id.
 *
 * What this file must prove, beyond the rows existing:
 *   - the aggregate row still owns the batch, and the reservation/refund
 *     arithmetic from Task 4 is untouched -- no second quota operation;
 *   - provider ids are stored verbatim and never fabricated;
 *   - Meta's failed-status correlation now reaches a group recipient,
 *     tenant-scoped, without touching normal-message correlation;
 *   - re-running anything converges on the same rows, guarded by the
 *     database rather than by a cache.
 */
class GroupRecipientDispatchLoggingTest extends TestCase
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

    private const META_TOKEN = 'EAAG_tenant_token_never_leak_me_0123456789';

    private const APP_SECRET = 'meta_app_secret_never_log_me_0123456789';

    private const WAMID = 'wamid.GROUP_RECIPIENT_ONE';

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

    private function metaAccount(string $phoneId = null): Account
    {
        $account = Account::factory()->create();
        $this->giveActiveSubscription($account, 'meta');
        WhatsAppSession::create([
            'account_id' => $account->id,
            'status' => 'connected',
            'meta_phone_number_id' => $phoneId ?? ('1098'.random_int(100000000, 999999999)),
            'meta_waba_id' => '123456789012345',
            'meta_access_token' => self::META_TOKEN,
        ]);

        return $account;
    }

    private function segmentGroup(Account $account, int $memberCount): ContactGroup
    {
        $group = ContactGroup::create([
            'account_id' => $account->id,
            'name' => 'Segment',
            'group_type' => ContactGroup::GROUP_TYPE_INTERNAL,
        ]);

        for ($i = 0; $i < $memberCount; $i++) {
            ContactGroupMember::create([
                'group_id' => $group->id,
                'phone_number' => '9190000000'.str_pad((string) $i, 2, '0', STR_PAD_LEFT),
                'name' => "Member {$i}",
            ]);
        }

        return $group;
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
            'phone_number' => '919000000001',
            'name' => 'Bob',
        ]);

        return $group;
    }

    private function template(Account $account): MessageTemplate
    {
        return MessageTemplate::create([
            'account_id' => $account->id,
            'template_code' => 'TPL_'.strtoupper(Str::random(6)),
            'title' => 'Blast',
            'template_body' => 'Hello {{name}}.',
            'status' => 'approved',
        ]);
    }

    private function qrSendsSucceed(): void
    {
        Http::fake(['*' => Http::response(['success' => true, 'message_id' => 'QR_OK'], 200)]);
    }

    private function qrSendsFail(): void
    {
        Http::fake(['*' => Http::response(['success' => false, 'error' => 'Engine said no'], 200)]);
    }

    /** QR engine that returns no id at all — the nullable case. */
    private function qrSendsSucceedWithoutId(): void
    {
        Http::fake(['*' => Http::response(['success' => true], 200)]);
    }

    private function metaSendsSucceed(string $wamid = self::WAMID): void
    {
        Http::fake([
            'graph.facebook.com/*' => Http::response([
                'messaging_product' => 'whatsapp',
                'messages' => [['id' => $wamid]],
            ], 200),
        ]);
    }

    /** @return \Illuminate\Database\Eloquent\Collection<int, MessageDispatchLog> */
    private function recipientRowsOf(int $parentId)
    {
        return MessageDispatchLog::where('parent_dispatch_id', $parentId)->orderBy('id')->get();
    }

    private function aggregate(int $id): MessageDispatchLog
    {
        return MessageDispatchLog::findOrFail($id);
    }

    private function used(Account $account): int
    {
        return (int) Subscription::where('account_id', $account->id)->firstOrFail()->used_messages;
    }

    // ==================================================================
    // 1. Recipient rows exist, with the right shape
    // ==================================================================
    public function test_one_recipient_produces_one_recipient_row(): void
    {
        $this->qrSendsSucceed();
        $account = $this->qrAccount();
        $group = $this->segmentGroup($account, 1);
        $template = $this->template($account);

        $result = GroupMessageDispatcher::dispatch($account->id, $group->id, $template->id, []);

        $rows = $this->recipientRowsOf($result['dispatch_id']);
        $this->assertCount(1, $rows);
        $this->assertSame('919000000000', $rows[0]->recipient_phone);
    }

    public function test_n_recipients_produce_n_recipient_rows(): void
    {
        $this->qrSendsSucceed();
        $account = $this->qrAccount();
        $group = $this->segmentGroup($account, 5);
        $template = $this->template($account);

        $result = GroupMessageDispatcher::dispatch($account->id, $group->id, $template->id, []);

        $this->assertCount(5, $this->recipientRowsOf($result['dispatch_id']));
    }

    public function test_a_recipient_row_carries_the_full_expected_shape(): void
    {
        $this->qrSendsSucceed();
        $account = $this->qrAccount();
        $group = $this->segmentGroup($account, 1);
        $template = $this->template($account);

        $result = GroupMessageDispatcher::dispatch($account->id, $group->id, $template->id, []);

        $row = $this->recipientRowsOf($result['dispatch_id'])->first();
        $aggregate = $this->aggregate($result['dispatch_id']);

        $this->assertSame($account->id, $row->account_id, 'tenant');
        $this->assertSame($aggregate->id, $row->parent_dispatch_id, 'parent reference');
        $this->assertSame(MessageDispatchLog::RECIPIENT_TYPE_GROUP_RECIPIENT, $row->recipient_type);
        $this->assertSame(MessageDispatchLog::REFERENCE_TYPE_GROUP_MEMBER, $row->reference_type);
        $this->assertSame($group->id, $row->group_id);
        $this->assertSame('qr', $row->engine_type, 'provider');
        $this->assertSame('sent', $row->status);
        // Inherited from the batch, never re-derived: GroupMessageDispatcher's
        // own $source default is 'api'.
        $this->assertSame($aggregate->source, $row->source);
        $this->assertSame('api', $row->source);
        $this->assertSame($aggregate->api_key_id, $row->api_key_id);
        $this->assertSame($aggregate->group_name, $row->group_name);
        $this->assertNotNull($row->sent_at, 'timestamps');
        $this->assertNotNull($row->created_at);
        $this->assertSame(1, (int) $row->recipient_count);
    }

    public function test_the_parent_relationship_resolves_both_ways(): void
    {
        $this->qrSendsSucceed();
        $account = $this->qrAccount();
        $group = $this->segmentGroup($account, 3);
        $template = $this->template($account);

        $result = GroupMessageDispatcher::dispatch($account->id, $group->id, $template->id, []);
        $aggregate = $this->aggregate($result['dispatch_id']);

        $this->assertCount(3, $aggregate->recipientDispatches);
        $this->assertSame($aggregate->id, $aggregate->recipientDispatches->first()->parentDispatch->id);
        $this->assertNull($aggregate->parent_dispatch_id, 'the aggregate itself has no parent');
    }

    public function test_the_recipient_row_points_at_its_own_member(): void
    {
        $this->qrSendsSucceed();
        $account = $this->qrAccount();
        $group = $this->segmentGroup($account, 3);
        $template = $this->template($account);

        $result = GroupMessageDispatcher::dispatch($account->id, $group->id, $template->id, []);

        $memberIds = ContactGroupMember::where('group_id', $group->id)->orderBy('id')->pluck('id')->all();
        $referenceIds = $this->recipientRowsOf($result['dispatch_id'])->pluck('reference_id')->map(fn ($v) => (int) $v)->all();

        $this->assertSame($memberIds, $referenceIds);
    }

    public function test_the_direct_group_path_logs_recipients_too(): void
    {
        $this->qrSendsSucceed();
        $account = $this->qrAccount();
        $group = $this->segmentGroup($account, 4);

        $result = GroupDirectMessageDispatcher::dispatch($account->id, $group->id, 'text', ['body' => 'Hi']);

        $rows = $this->recipientRowsOf($result['dispatch_id']);
        $this->assertCount(4, $rows);
        $this->assertSame('qr', $rows[0]->engine_type);
    }

    public function test_a_native_group_logs_exactly_one_recipient_row_for_the_jid(): void
    {
        $this->qrSendsSucceed();
        $account = $this->qrAccount();
        $group = $this->nativeGroup($account);
        $template = $this->template($account);

        $result = GroupMessageDispatcher::dispatch($account->id, $group->id, $template->id, []);

        $rows = $this->recipientRowsOf($result['dispatch_id']);
        $this->assertCount(1, $rows);
        $this->assertSame('120363000000000000@g.us', $rows[0]->recipient_phone);
        $this->assertSame(MessageDispatchLog::REFERENCE_TYPE_NATIVE_GROUP, $rows[0]->reference_type);
        $this->assertSame($group->id, (int) $rows[0]->reference_id);
    }

    public function test_recipient_rows_do_not_disturb_the_aggregate_row(): void
    {
        $this->qrSendsSucceed();
        $account = $this->qrAccount();
        $group = $this->segmentGroup($account, 3);
        $template = $this->template($account);

        $result = GroupMessageDispatcher::dispatch($account->id, $group->id, $template->id, []);
        $aggregate = $this->aggregate($result['dispatch_id']);

        $this->assertSame('group', $aggregate->recipient_type);
        $this->assertSame('sent', $aggregate->status);
        $this->assertSame(3, (int) $aggregate->recipient_count);
        $this->assertSame(3, (int) $aggregate->success_count);
        $this->assertSame(0, (int) $aggregate->failure_count);
    }

    // ==================================================================
    // 2. Gateway ids
    // ==================================================================
    public function test_a_successful_meta_recipient_stores_the_wamid_verbatim(): void
    {
        $this->metaSendsSucceed('wamid.EXACTLY_THIS');
        $account = $this->metaAccount();
        $group = $this->segmentGroup($account, 2);
        $template = $this->template($account);

        $result = GroupMessageDispatcher::dispatch($account->id, $group->id, $template->id, []);

        $rows = $this->recipientRowsOf($result['dispatch_id']);
        $this->assertCount(2, $rows);

        foreach ($rows as $row) {
            $this->assertSame('wamid.EXACTLY_THIS', $row->gateway_message_id);
            $this->assertSame('meta', $row->engine_type);
        }
    }

    public function test_a_qr_gateway_id_is_preserved_when_the_engine_returns_one(): void
    {
        $this->qrSendsSucceed();
        $account = $this->qrAccount();
        $group = $this->segmentGroup($account, 1);
        $template = $this->template($account);

        $result = GroupMessageDispatcher::dispatch($account->id, $group->id, $template->id, []);

        $this->assertSame('QR_OK', $this->recipientRowsOf($result['dispatch_id'])->first()->gateway_message_id);
    }

    public function test_a_missing_gateway_id_stays_null_and_is_never_fabricated(): void
    {
        $this->qrSendsSucceedWithoutId();
        $account = $this->qrAccount();
        $group = $this->segmentGroup($account, 2);
        $template = $this->template($account);

        $result = GroupMessageDispatcher::dispatch($account->id, $group->id, $template->id, []);

        foreach ($this->recipientRowsOf($result['dispatch_id']) as $row) {
            $this->assertSame('sent', $row->status, 'the send still succeeded');
            $this->assertNull($row->gateway_message_id, 'no id must ever be invented');
        }
    }

    public function test_a_failed_recipient_stores_no_gateway_id(): void
    {
        $this->qrSendsFail();
        $account = $this->qrAccount();
        $group = $this->segmentGroup($account, 2);
        $template = $this->template($account);

        $result = GroupMessageDispatcher::dispatch($account->id, $group->id, $template->id, []);

        foreach ($this->recipientRowsOf($result['dispatch_id']) as $row) {
            $this->assertSame('failed', $row->status);
            $this->assertNull($row->gateway_message_id);
        }
    }

    // ==================================================================
    // 3. has_media
    // ==================================================================
    public function test_a_group_text_send_records_no_media(): void
    {
        $this->qrSendsSucceed();
        $account = $this->qrAccount();
        $group = $this->segmentGroup($account, 2);

        $result = GroupDirectMessageDispatcher::dispatch($account->id, $group->id, 'text', ['body' => 'Hi']);

        $this->assertFalse((bool) $this->aggregate($result['dispatch_id'])->has_media);

        foreach ($this->recipientRowsOf($result['dispatch_id']) as $row) {
            $this->assertFalse((bool) $row->has_media);
            $this->assertNull($row->media_url);
        }
    }

    public function test_a_group_media_send_records_media_on_the_batch_and_every_recipient(): void
    {
        $this->qrSendsSucceed();
        $account = $this->qrAccount();
        $group = $this->segmentGroup($account, 2);

        $result = GroupDirectMessageDispatcher::dispatch($account->id, $group->id, 'media', [
            'media_type' => 'image',
            'url' => 'https://cdn.example.com/blast.jpg',
            'caption' => 'Look',
        ]);

        $aggregate = $this->aggregate($result['dispatch_id']);
        $this->assertTrue((bool) $aggregate->has_media, 'the aggregate used to hardcode false');
        $this->assertSame('https://cdn.example.com/blast.jpg', $aggregate->media_url);

        $rows = $this->recipientRowsOf($result['dispatch_id']);
        $this->assertCount(2, $rows);

        foreach ($rows as $row) {
            $this->assertTrue((bool) $row->has_media);
            $this->assertSame('https://cdn.example.com/blast.jpg', $row->media_url);
        }
    }

    public function test_a_group_template_send_records_no_media(): void
    {
        $this->qrSendsSucceed();
        $account = $this->qrAccount();
        $group = $this->segmentGroup($account, 2);
        $template = $this->template($account);

        $result = GroupMessageDispatcher::dispatch($account->id, $group->id, $template->id, []);

        $this->assertFalse((bool) $this->aggregate($result['dispatch_id'])->has_media);

        foreach ($this->recipientRowsOf($result['dispatch_id']) as $row) {
            $this->assertFalse((bool) $row->has_media);
        }
    }

    // ==================================================================
    // 4. Failure detail, and the untouched reservation/refund
    // ==================================================================
    public function test_a_failed_recipient_keeps_the_engines_own_error(): void
    {
        $this->qrSendsFail();
        $account = $this->qrAccount();
        $group = $this->segmentGroup($account, 2);
        $template = $this->template($account);

        $result = GroupMessageDispatcher::dispatch($account->id, $group->id, $template->id, []);

        foreach ($this->recipientRowsOf($result['dispatch_id']) as $row) {
            $this->assertSame('failed', $row->status);
            $this->assertSame('Engine said no', $row->error_reason);
            $this->assertNull($row->sent_at);
        }
    }

    public function test_an_unsendable_number_still_gets_its_own_audit_row(): void
    {
        $this->qrSendsSucceed();
        $account = $this->qrAccount();
        $group = $this->segmentGroup($account, 1);
        ContactGroupMember::create(['group_id' => $group->id, 'phone_number' => 'not-a-number', 'name' => 'Broken']);
        $template = $this->template($account);

        $result = GroupMessageDispatcher::dispatch($account->id, $group->id, $template->id, []);

        $rows = $this->recipientRowsOf($result['dispatch_id']);
        $this->assertCount(2, $rows, 'the invalid number is still a charged attempt');

        $broken = $rows->firstWhere('recipient_phone', 'not-a-number');
        $this->assertNotNull($broken);
        $this->assertSame('failed', $broken->status);
        $this->assertStringContainsString('not a valid number', (string) $broken->error_reason);
    }

    public function test_the_aggregate_counts_and_the_refund_are_unchanged_by_recipient_logging(): void
    {
        $account = $this->qrAccount();
        $group = $this->segmentGroup($account, 4);
        $template = $this->template($account);

        // Registered after the fixtures: a sequence is consumed by every
        // matching request, and markPaidAndCreditQuota() fires a webhook.
        $sequence = Http::fakeSequence();
        $sequence->push(['success' => true, 'message_id' => 'QR_1'], 200);
        $sequence->push(['success' => true, 'message_id' => 'QR_2'], 200);
        $sequence->push(['success' => false, 'error' => 'Engine said no'], 200);
        $sequence->push(['success' => false, 'error' => 'Engine said no'], 200);
        $sequence->whenEmpty(Http::response(['success' => false, 'error' => 'sequence exhausted'], 200));

        $result = GroupMessageDispatcher::dispatch($account->id, $group->id, $template->id, []);

        $aggregate = $this->aggregate($result['dispatch_id']);
        $this->assertSame(2, (int) $aggregate->success_count);
        $this->assertSame(2, (int) $aggregate->failure_count);

        // Reserved 4, delivered 2, so 2 come back — exactly Task 4's rule,
        // and the refund still equals the aggregate failure count.
        $this->assertSame(2, $this->used($account));

        $rows = $this->recipientRowsOf($result['dispatch_id']);
        $this->assertSame(2, $rows->where('status', 'sent')->count());
        $this->assertSame(2, $rows->where('status', 'failed')->count());
    }

    public function test_recipient_logging_performs_no_quota_operation_of_its_own(): void
    {
        $this->qrSendsSucceed();
        $account = $this->qrAccount();
        $group = $this->segmentGroup($account, 6);
        $template = $this->template($account);

        GroupMessageDispatcher::dispatch($account->id, $group->id, $template->id, []);

        // 6 reserved, 6 delivered, 0 released. If a recipient row had
        // consumed or released anything this would not be 6.
        $this->assertSame(6, $this->used($account));
    }

    // ==================================================================
    // 5. Meta webhook correlation
    // ==================================================================
    private function seedMetaAppSecret(): void
    {
        SocialProviderConfig::create([
            'provider' => 'meta',
            'client_id' => 'app-id',
            'client_secret' => self::APP_SECRET,
            'is_active' => true,
        ]);
    }

    /** @param array<string, mixed> $status */
    private function postStatus(array $status, string $phoneId)
    {
        $payload = [
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

        $body = json_encode($payload);

        return $this->call('POST', '/api/webhooks/meta', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
            'HTTP_X_HUB_SIGNATURE_256' => 'sha256='.hash_hmac('sha256', $body, self::APP_SECRET),
        ], $body);
    }

    public function test_a_meta_failed_status_marks_the_right_group_recipient_row(): void
    {
        $this->seedMetaAppSecret();
        $this->metaSendsSucceed('wamid.TARGET');
        $account = $this->metaAccount('109876543210987');
        $group = $this->segmentGroup($account, 1);
        $template = $this->template($account);

        $result = GroupMessageDispatcher::dispatch($account->id, $group->id, $template->id, []);
        $row = $this->recipientRowsOf($result['dispatch_id'])->first();
        $this->assertSame('sent', $row->status);

        $this->postStatus([
            'id' => 'wamid.TARGET',
            'status' => 'failed',
            'errors' => [['code' => 131047, 'title' => 'Re-engagement message']],
        ], '109876543210987')->assertStatus(200);

        $fresh = MessageDispatchLog::findOrFail($row->id);
        $this->assertSame('failed', $fresh->status);
        $this->assertSame('Re-engagement message', $fresh->error_reason);
    }

    public function test_a_failed_status_never_touches_another_tenants_group_recipient(): void
    {
        $this->seedMetaAppSecret();
        $this->metaSendsSucceed('wamid.SHARED');

        $victim = $this->metaAccount('555000111222333');
        $victimGroup = $this->segmentGroup($victim, 1);
        $victimTemplate = $this->template($victim);
        $victimResult = GroupMessageDispatcher::dispatch($victim->id, $victimGroup->id, $victimTemplate->id, []);
        $victimRow = $this->recipientRowsOf($victimResult['dispatch_id'])->first();

        // A different tenant's webhook, carrying the SAME wamid.
        $attacker = $this->metaAccount('109876543210987');

        $this->postStatus([
            'id' => 'wamid.SHARED',
            'status' => 'failed',
            'errors' => [['title' => 'Nope']],
        ], '109876543210987')->assertStatus(200);

        $this->assertSame('sent', MessageDispatchLog::findOrFail($victimRow->id)->status, 'cross-tenant correlation must be impossible');
        $this->assertSame(0, MessageDispatchLog::where('account_id', $attacker->id)->where('status', 'failed')->count());
    }

    public function test_a_duplicate_failed_status_is_still_idempotent(): void
    {
        $this->seedMetaAppSecret();
        $this->metaSendsSucceed('wamid.DUPE');
        $account = $this->metaAccount('109876543210987');
        $group = $this->segmentGroup($account, 1);
        $template = $this->template($account);

        $result = GroupMessageDispatcher::dispatch($account->id, $group->id, $template->id, []);
        $row = $this->recipientRowsOf($result['dispatch_id'])->first();

        $status = ['id' => 'wamid.DUPE', 'status' => 'failed', 'errors' => [['title' => 'First reason']]];
        $this->postStatus($status, '109876543210987')->assertStatus(200);
        $this->postStatus(['id' => 'wamid.DUPE', 'status' => 'failed', 'errors' => [['title' => 'Second reason']]], '109876543210987')->assertStatus(200);

        $fresh = MessageDispatchLog::findOrFail($row->id);
        $this->assertSame('failed', $fresh->status);
        $this->assertSame('First reason', $fresh->error_reason, 'the first verdict stands');
        $this->assertSame(1, MessageDispatchLog::where('parent_dispatch_id', $result['dispatch_id'])->count());
    }

    /**
     * 'delivered' has no column to land in on a dispatch row -- the
     * documented Phase 4 Task 4 limitation (no delivered_at/read_at) --
     * so it must behave for a group recipient exactly as it already does
     * for a normal dispatch row: correlate nothing, change nothing, and
     * leave the redelivery claim behaviour untouched.
     */
    public function test_a_delivered_status_treats_a_group_recipient_exactly_like_a_normal_dispatch_row(): void
    {
        $this->seedMetaAppSecret();
        $this->metaSendsSucceed('wamid.DELIVERED');
        $account = $this->metaAccount('109876543210987');
        $group = $this->segmentGroup($account, 1);
        $template = $this->template($account);

        $result = GroupMessageDispatcher::dispatch($account->id, $group->id, $template->id, []);
        $row = $this->recipientRowsOf($result['dispatch_id'])->first();

        $this->postStatus(['id' => 'wamid.DELIVERED', 'status' => 'delivered'], '109876543210987')->assertStatus(200);
        $this->postStatus(['id' => 'wamid.DELIVERED', 'status' => 'delivered'], '109876543210987')->assertStatus(200);

        $fresh = MessageDispatchLog::findOrFail($row->id);
        $this->assertSame('sent', $fresh->status);
        $this->assertSame('wamid.DELIVERED', $fresh->gateway_message_id);
    }

    public function test_normal_non_group_correlation_still_works(): void
    {
        $this->seedMetaAppSecret();
        $account = $this->metaAccount('109876543210987');

        $individual = MessageDispatchLog::record(
            $account->id,
            'api',
            '919111111111',
            success: true,
            gatewayMessageId: 'wamid.INDIVIDUAL',
        );

        $this->postStatus([
            'id' => 'wamid.INDIVIDUAL',
            'status' => 'failed',
            'errors' => [['title' => 'Still works']],
        ], '109876543210987')->assertStatus(200);

        $fresh = MessageDispatchLog::findOrFail($individual->id);
        $this->assertSame('failed', $fresh->status);
        $this->assertSame('Still works', $fresh->error_reason);
        $this->assertNull($fresh->parent_dispatch_id, 'an individual row is untouched by this task');
    }

    // ==================================================================
    // 6. Duplicate execution
    // ==================================================================
    public function test_rerunning_the_job_creates_no_duplicate_recipient_rows(): void
    {
        Queue::fake();
        $account = $this->qrAccount();
        $group = $this->segmentGroup($account, 3);
        $template = $this->template($account);

        $result = GroupMessageDispatcher::dispatch($account->id, $group->id, $template->id, []);

        $this->qrSendsSucceed();
        (new ProcessGroupDispatchJob($result['dispatch_id'], $template->id, [], null))->handle();
        $this->assertCount(3, $this->recipientRowsOf($result['dispatch_id']));

        (new ProcessGroupDispatchJob($result['dispatch_id'], $template->id, [], null))->handle();
        $this->assertCount(3, $this->recipientRowsOf($result['dispatch_id']), 'a re-run must not duplicate the audit');
        $this->assertSame(3, $this->used($account), 'and must not re-touch quota');
    }

    public function test_rerunning_the_direct_group_job_creates_no_duplicate_rows(): void
    {
        Queue::fake();
        $account = $this->qrAccount();
        $group = $this->segmentGroup($account, 2);

        $result = GroupDirectMessageDispatcher::dispatch($account->id, $group->id, 'text', ['body' => 'Hi']);

        $this->qrSendsSucceed();
        (new ProcessGroupDirectMessageJob($result['dispatch_id'], 'text', ['body' => 'Hi'], null))->handle();
        (new ProcessGroupDirectMessageJob($result['dispatch_id'], 'text', ['body' => 'Hi'], null))->handle();

        $this->assertCount(2, $this->recipientRowsOf($result['dispatch_id']));
    }

    /**
     * The database is the guard, not the job's status check: writing the
     * same recipient twice, directly, must converge on one row and keep
     * the FIRST outcome.
     */
    public function test_the_unique_index_is_what_prevents_a_duplicate_recipient_row(): void
    {
        $this->qrSendsSucceed();
        $account = $this->qrAccount();
        $group = $this->segmentGroup($account, 1);
        $template = $this->template($account);

        $result = GroupMessageDispatcher::dispatch($account->id, $group->id, $template->id, []);
        $parent = $this->aggregate($result['dispatch_id']);
        $memberId = (int) ContactGroupMember::where('group_id', $group->id)->value('id');

        $again = MessageDispatchLog::recordGroupRecipient(
            $parent,
            '919000000000',
            MessageDispatchLog::REFERENCE_TYPE_GROUP_MEMBER,
            $memberId,
            success: false,
            engineType: 'qr',
            errorReason: 'a later, different outcome',
        );

        $this->assertCount(1, $this->recipientRowsOf($parent->id));
        $this->assertSame('sent', $again->status, 'the first recorded outcome is authoritative');
        $this->assertNull($again->error_reason);
    }

    public function test_repeated_resolution_neither_duplicates_rows_nor_refunds_again(): void
    {
        $this->qrSendsFail();
        $account = $this->qrAccount();
        $group = $this->segmentGroup($account, 3);
        $template = $this->template($account);

        $result = GroupMessageDispatcher::dispatch($account->id, $group->id, $template->id, []);
        $this->assertSame(0, $this->used($account));
        $this->assertCount(3, $this->recipientRowsOf($result['dispatch_id']));

        $this->aggregate($result['dispatch_id'])->resolveGroupDispatch(0, 3);

        $this->assertSame(0, $this->used($account));
        $this->assertCount(3, $this->recipientRowsOf($result['dispatch_id']));
    }

    // ==================================================================
    // 7. Invariants this task must not break
    // ==================================================================
    public function test_analytics_recipient_type_buckets_are_untouched(): void
    {
        $this->qrSendsSucceed();
        $account = $this->qrAccount();
        $group = $this->segmentGroup($account, 3);
        $template = $this->template($account);

        GroupMessageDispatcher::dispatch($account->id, $group->id, $template->id, []);

        // AnalyticsController counts 'individual' per row and sums
        // 'group' via success_count/failure_count. A recipient row must
        // land in NEITHER bucket, or every group message is double
        // counted.
        $this->assertSame(0, MessageDispatchLog::where('account_id', $account->id)->where('recipient_type', 'individual')->count());
        $this->assertSame(1, MessageDispatchLog::where('account_id', $account->id)->where('recipient_type', 'group')->count());
        $this->assertSame(3, MessageDispatchLog::where('account_id', $account->id)->groupRecipients()->count());
    }

    public function test_a_meta_native_group_is_still_rejected_and_logs_nothing(): void
    {
        Queue::fake();
        $account = $this->metaAccount();
        $group = $this->nativeGroup($account);
        $template = $this->template($account);

        $result = GroupMessageDispatcher::dispatch($account->id, $group->id, $template->id, []);

        $this->assertSame('unsupported_engine', $result['status']);
        $this->assertSame(0, MessageDispatchLog::count());
        $this->assertSame(0, $this->used($account));
    }

    public function test_no_driver_is_instantiated_outside_the_factory(): void
    {
        foreach (['Jobs/ProcessGroupDispatchJob.php',
                  'Jobs/ProcessGroupDirectMessageJob.php',
                  'Models/MessageDispatchLog.php'] as $path) {
            $source = file_get_contents(app_path($path));
            $this->assertStringNotContainsString('new BaileysDriver', $source);
            $this->assertStringNotContainsString('new MetaCloudApiDriver', $source);
        }
    }

    public function test_no_credential_is_stored_on_a_recipient_row(): void
    {
        $this->metaSendsSucceed();
        $account = $this->metaAccount();
        $group = $this->segmentGroup($account, 2);
        $template = $this->template($account);

        $result = GroupMessageDispatcher::dispatch($account->id, $group->id, $template->id, []);

        foreach ($this->recipientRowsOf($result['dispatch_id']) as $row) {
            $serialized = json_encode($row->getAttributes());
            $this->assertStringNotContainsString(self::META_TOKEN, $serialized);
            $this->assertStringNotContainsString('EAAG', $serialized);
        }
    }
}
