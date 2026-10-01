<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\AccountEntitlement;
use App\Models\Capability;
use App\Models\Lead;
use App\Models\MessageDispatchLog;
use App\Models\SocialAccount;
use App\Models\Subscription;
use App\Models\WhatsAppSession;
use App\Services\Leads\MetaLeadWebhookHandler;
use Database\Seeders\Phase1FoundationSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Phase 5 P5-A — the Meta Lead Ads tenant notice and lead welcome go
 * through the unified individual-recipient send path
 * (DirectMessageDispatcher): subscription/quota gate, one quota
 * consumption per confirmed send, and a message_dispatch_logs row on
 * every terminal branch — instead of calling the engine driver directly.
 */
class MetaLeadAdsDispatchTest extends TestCase
{
    use RefreshDatabase;

    private const PAGE_ID = '888000111';

    private const PHONE_ID = '109876500000001';

    private const LEAD_PHONE = '919876543210';

    private const TENANT_PHONE = '919812345678';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        $this->seed(Phase1FoundationSeeder::class);
    }

    // ---------------------------------------------------------------
    // Fixtures
    // ---------------------------------------------------------------

    /**
     * A Meta-engine tenant owning the Lead Ads Page.
     *
     * @param array<string, mixed> $subscription
     */
    private function tenant(array $subscription = [], ?string $primaryPhone = null, string $pageId = self::PAGE_ID, string $phoneId = self::PHONE_ID): Account
    {
        $account = Account::factory()->create(['primary_phone' => $primaryPhone]);
        Subscription::factory()->meta()->create(['account_id' => $account->id, ...$subscription]);

        AccountEntitlement::firstOrCreate(
            ['account_id' => $account->id, 'capability_id' => Capability::where('slug', 'crm')->firstOrFail()->id],
            ['source' => 'manual_grant', 'granted_by_account_id' => null],
        );

        WhatsAppSession::create([
            'account_id' => $account->id,
            'status' => 'connected',
            'meta_phone_number_id' => $phoneId,
            'meta_waba_id' => '123456789012345',
            'meta_access_token' => 'EAAG_tenant_token_'.$account->id,
            'meta_webhook_verify_token' => 'Verify'.str_replace('.', '', uniqid('', true)),
        ]);

        SocialAccount::create([
            'account_id' => $account->id,
            'provider' => 'meta',
            'asset_type' => 'facebook_page',
            'provider_id' => $pageId,
            'name' => 'Page '.$pageId,
            'access_token' => 'PAGE_TOKEN_'.$pageId,
        ]);

        return $account->fresh();
    }

    private function fakeMeta(int $sendStatus = 200, string $phoneId = self::PHONE_ID): void
    {
        $counter = 0;

        Http::fake([
            'graph.facebook.com/v18.0/LG-*' => Http::response(['field_data' => [
                ['name' => 'full_name', 'values' => ['Ada Lovelace']],
                ['name' => 'phone_number', 'values' => ['+91 98765 43210']],
                ['name' => 'email', 'values' => ['ada@example.com']],
            ]], 200),
            "graph.facebook.com/v18.0/{$phoneId}/messages" => function () use ($sendStatus, &$counter) {
                $counter++;

                return $sendStatus === 200
                    ? Http::response(['messages' => [['id' => "wamid.LEAD{$counter}"]]], 200)
                    : Http::response(['error' => ['message' => 'Recipient phone number not in allowed list']], $sendStatus);
            },
        ]);
    }

    private function deliverLeadgen(string $leadgenId = 'LG-1', string $pageId = self::PAGE_ID): void
    {
        app(MetaLeadWebhookHandler::class)->handle([
            'changes' => [[
                'field' => 'leadgen',
                'value' => ['leadgen_id' => $leadgenId, 'page_id' => $pageId, 'form_id' => 'F1', 'ad_id' => 'A1'],
            ]],
        ]);
    }

    private function sendRequests(string $phoneId = self::PHONE_ID): int
    {
        return Http::recorded(fn (Request $r) => str_contains($r->url(), "/{$phoneId}/messages"))->count();
    }

    private function usedMessages(Account $account): int
    {
        return (int) Subscription::where('account_id', $account->id)->value('used_messages');
    }

    // ---------------------------------------------------------------
    // Tests
    // ---------------------------------------------------------------

    public function test_the_welcome_consumes_one_quota_unit_and_writes_a_sent_dispatch_log(): void
    {
        $account = $this->tenant();
        $this->fakeMeta();

        $this->deliverLeadgen();

        $this->assertSame(1, $this->sendRequests());
        $this->assertSame(1, $this->usedMessages($account));

        $log = MessageDispatchLog::where('source', MetaLeadWebhookHandler::DISPATCH_SOURCE)->sole();
        $this->assertSame($account->id, $log->account_id);
        $this->assertSame(self::LEAD_PHONE, $log->recipient_phone);
        $this->assertSame('sent', $log->status);
        $this->assertNotNull($log->sent_at);
        $this->assertSame('wamid.LEAD1', $log->gateway_message_id);
        $this->assertStringStartsWith('Hi Ada Lovelace, thanks for your interest!', $log->message_preview);

        $lead = Lead::where('provider_lead_id', 'LG-1')->sole();
        $this->assertNotNull($lead->lead_welcomed_at);
        $this->assertNull($lead->lead_welcome_error);
    }

    public function test_the_tenant_notice_and_the_welcome_each_go_through_the_centralized_path(): void
    {
        $account = $this->tenant(primaryPhone: '+91 98123 45678');
        $this->fakeMeta();

        $this->deliverLeadgen();

        $this->assertSame(2, $this->sendRequests());
        $this->assertSame(2, $this->usedMessages($account));

        $logs = MessageDispatchLog::where('source', MetaLeadWebhookHandler::DISPATCH_SOURCE)->orderBy('id')->get();
        $this->assertCount(2, $logs);
        $this->assertSame([self::TENANT_PHONE, self::LEAD_PHONE], $logs->pluck('recipient_phone')->all());
        $this->assertSame(['sent', 'sent'], $logs->pluck('status')->all());
        $this->assertSame([$account->id, $account->id], $logs->pluck('account_id')->all());
        $this->assertStringStartsWith('New lead from your Meta Ads campaign!', $logs[0]->message_preview);

        $lead = Lead::where('provider_lead_id', 'LG-1')->sole();
        $this->assertNotNull($lead->tenant_notified_at);
        $this->assertNotNull($lead->lead_welcomed_at);
    }

    public function test_exhausted_quota_prevents_the_external_send(): void
    {
        $account = $this->tenant(['total_allocated_messages' => 10, 'used_messages' => 10], primaryPhone: '+91 98123 45678');
        $this->fakeMeta();

        $this->deliverLeadgen();

        $this->assertSame(0, $this->sendRequests(), 'No WhatsApp send may happen once quota is exhausted.');
        $this->assertSame(10, $this->usedMessages($account));

        $logs = MessageDispatchLog::where('source', MetaLeadWebhookHandler::DISPATCH_SOURCE)->get();
        $this->assertCount(2, $logs);
        $this->assertSame(['failed', 'failed'], $logs->pluck('status')->all());
        $this->assertTrue($logs->every(fn ($l) => $l->sent_at === null && $l->gateway_message_id === null && $l->account_id === $account->id));

        // The capture itself is unaffected: the lead row exists, only the sends are refused.
        $lead = Lead::where('provider_lead_id', 'LG-1')->sole();
        $this->assertNull($lead->lead_welcomed_at);
        $this->assertNull($lead->tenant_notified_at);
        $this->assertNotNull($lead->lead_welcome_error);
        $this->assertNotNull($lead->tenant_notify_error);
    }

    public function test_a_provider_rejection_consumes_no_quota_and_is_logged_as_failed(): void
    {
        $account = $this->tenant();
        $this->fakeMeta(sendStatus: 400);

        $this->deliverLeadgen();

        $this->assertSame(1, $this->sendRequests());
        $this->assertSame(0, $this->usedMessages($account), 'A failed send must not be charged.');

        $log = MessageDispatchLog::where('source', MetaLeadWebhookHandler::DISPATCH_SOURCE)->sole();
        $this->assertSame('failed', $log->status);
        $this->assertNull($log->sent_at);
        $this->assertNull($log->gateway_message_id);
        $this->assertSame('Recipient phone number not in allowed list', $log->error_reason);

        $lead = Lead::where('provider_lead_id', 'LG-1')->sole();
        $this->assertNull($lead->lead_welcomed_at);
        $this->assertSame('Recipient phone number not in allowed list', $lead->lead_welcome_error);
    }

    public function test_a_disconnected_qr_tenant_is_refused_by_the_centralized_gate(): void
    {
        $account = $this->tenant();
        Subscription::where('account_id', $account->id)->update(['engine_type' => 'qr']);
        WhatsAppSession::where('account_id', $account->id)->update(['status' => 'disconnected']);
        $this->fakeMeta();

        $this->deliverLeadgen();

        $this->assertSame(0, $this->usedMessages($account));
        $log = MessageDispatchLog::where('source', MetaLeadWebhookHandler::DISPATCH_SOURCE)->sole();
        $this->assertSame('failed', $log->status);
        $this->assertSame('WhatsApp account is disconnected.', $log->error_reason);
        $this->assertNotNull(Lead::where('provider_lead_id', 'LG-1')->sole()->lead_welcome_error);
    }

    public function test_only_the_page_owning_tenant_is_charged_and_logged(): void
    {
        $owner = $this->tenant();
        $other = $this->tenant(pageId: '888000222', phoneId: '109876500000002');
        $this->fakeMeta();

        $this->deliverLeadgen();

        $this->assertSame(1, $this->usedMessages($owner));
        $this->assertSame(0, $this->usedMessages($other));
        $this->assertSame(0, MessageDispatchLog::where('account_id', $other->id)->count());
        $this->assertSame(1, MessageDispatchLog::where('account_id', $owner->id)->where('source', MetaLeadWebhookHandler::DISPATCH_SOURCE)->count());
        $this->assertSame(0, $this->sendRequests('109876500000002'), "The other tenant's WhatsApp number was never used.");
    }

    public function test_capture_behaviour_is_unchanged(): void
    {
        $account = $this->tenant();
        $this->fakeMeta();

        $this->deliverLeadgen();

        $this->assertDatabaseHas('leads', [
            'provider_lead_id' => 'LG-1',
            'account_id' => $account->id,
            'provider' => 'meta',
            'form_id' => 'F1',
            'ad_id' => 'A1',
            'lead_name' => 'Ada Lovelace',
            'lead_phone' => self::LEAD_PHONE,
            'lead_email' => 'ada@example.com',
        ]);
        $lead = Lead::where('provider_lead_id', 'LG-1')->sole();
        $this->assertNotNull($lead->crmLead, 'The capture is still promoted into the CRM.');

        // Meta redelivery of the same leadgen stays a no-op: no second capture, send, charge or log.
        $this->deliverLeadgen();
        $this->assertSame(1, Lead::where('provider_lead_id', 'LG-1')->count());
        $this->assertSame(1, $this->sendRequests());
        $this->assertSame(1, $this->usedMessages($account));
        $this->assertSame(1, MessageDispatchLog::where('source', MetaLeadWebhookHandler::DISPATCH_SOURCE)->count());
    }

    public function test_no_direct_driver_send_remains_in_the_lead_ads_handler(): void
    {
        $source = file_get_contents(app_path('Services/Leads/MetaLeadWebhookHandler.php'));

        $this->assertStringNotContainsString('WhatsAppEngineFactory', $source);
        $this->assertStringNotContainsString('->sendMessage(', $source);
        $this->assertStringNotContainsString('MessageQuotaService', $source, 'No quota logic may live in the handler.');
        $this->assertStringContainsString('DirectMessageDispatcher::dispatch(', $source);
    }

    public function test_the_new_source_is_filterable_in_the_message_logs_api(): void
    {
        $reflection = new \ReflectionClassConstant(\App\Http\Controllers\Api\MessageDispatchLogController::class, 'VALID_SOURCES');

        $this->assertContains(MetaLeadWebhookHandler::DISPATCH_SOURCE, $reflection->getValue());
    }
}
