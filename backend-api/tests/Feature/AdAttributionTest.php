<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\AccountEntitlement;
use App\Models\AdAttribution;
use App\Models\AdCampaign;
use App\Models\ApiKey;
use App\Models\Capability;
use App\Models\CrmLead;
use App\Models\Lead;
use App\Models\SocialProviderConfig;
use App\Models\Subscription;
use App\Models\User;
use App\Models\WhatsAppFlow;
use App\Models\WhatsAppFlowSession;
use App\Models\WhatsAppSession;
use App\Services\Ads\AdAttributionService;
use Database\Seeders\Phase1FoundationSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use LogicException;
use Tests\TestCase;

/**
 * Phase 10 Task 1 — Ads & Attribution foundation: a click-to-WhatsApp
 * referral is attributed to the tenant that owns the receiving number,
 * linked to its CTWA capture / CRM lead, to the journey it started and to
 * the CRM conversion — per tenant, idempotently, never fabricated — and
 * the read endpoints follow Ads' own gates (meta_ads module + `ads`
 * capability + target account checks).
 */
class AdAttributionTest extends TestCase
{
    use RefreshDatabase;

    private const SECRET = 'meta_app_secret_attribution_test';

    private const PHONE_ID = '109800000000101';

    private const OTHER_PHONE_ID = '109800000000202';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        $this->seed(Phase1FoundationSeeder::class);
        SocialProviderConfig::create(['provider' => 'meta', 'client_id' => 'app', 'client_secret' => self::SECRET, 'is_active' => true]);
        Http::fake();
    }

    // ------------------------------------------------------------------ fixtures

    private function grant(Account $account, string $slug): void
    {
        AccountEntitlement::firstOrCreate(
            ['account_id' => $account->id, 'capability_id' => Capability::where('slug', $slug)->firstOrFail()->id],
            ['source' => 'manual_grant', 'granted_by_account_id' => null],
        );
    }

    /** A Meta-engine tenant with the CTWA number, CRM and Ads. */
    private function tenant(string $phoneId = self::PHONE_ID, array $grants = ['crm', 'ads']): Account
    {
        $account = Account::factory()->create();
        Subscription::factory()->create(['account_id' => $account->id]);
        foreach ($grants as $slug) {
            $this->grant($account, $slug);
        }
        WhatsAppSession::create([
            'account_id' => $account->id, 'status' => 'connected', 'meta_phone_number_id' => $phoneId,
            'meta_waba_id' => '123456789012345', 'meta_access_token' => 'EAAG', 'meta_webhook_verify_token' => 'V'.uniqid(),
        ]);

        return $account->fresh();
    }

    private function user(Account $account, string $role = 'admin'): User
    {
        $user = User::factory()->create(['account_id' => $account->id, 'is_active' => true]);
        $user->assignRole($role);

        return $user;
    }

    private function superAdmin(): User
    {
        $user = User::factory()->create(['account_id' => null, 'is_active' => true]);
        $user->assignRole('super_admin');

        return $user;
    }

    private function campaign(Account $account, string $adId, string $name = 'Monsoon'): AdCampaign
    {
        return AdCampaign::create([
            'account_id' => $account->id, 'meta_campaign_id' => 'CMP-'.uniqid(), 'meta_adset_id' => 'SET-'.uniqid(), 'meta_ad_id' => $adId,
            'name' => $name, 'objective' => 'OUTCOME_LEADS', 'status' => 'ACTIVE', 'daily_budget' => 10,
        ]);
    }

    private function referralMessage(string $wamid, string $from, string $phoneId, array $referral, string $text = 'Hi, I saw your ad')
    {
        $body = json_encode(['object' => 'whatsapp_business_account', 'entry' => [[
            'id' => 'waba', 'changes' => [['field' => 'messages', 'value' => [
                'messaging_product' => 'whatsapp',
                'metadata' => ['display_phone_number' => '919999999999', 'phone_number_id' => $phoneId],
                'contacts' => [['profile' => ['name' => 'Bob'], 'wa_id' => $from]],
                'messages' => [['from' => $from, 'id' => $wamid, 'timestamp' => '1700000000', 'type' => 'text', 'text' => ['body' => $text], 'referral' => $referral]],
            ]]],
        ]]]);

        return $this->call('POST', '/api/webhooks/meta', [], [], [], [
            'CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json',
            'HTTP_X_HUB_SIGNATURE_256' => 'sha256='.hash_hmac('sha256', $body, self::SECRET),
        ], $body);
    }

    private function ctwa(string $wamid = 'wamid.A1', string $from = '919876543210', string $phoneId = self::PHONE_ID, string $adId = 'AD-777')
    {
        return $this->referralMessage($wamid, $from, $phoneId, [
            'source_id' => $adId, 'source_type' => 'ad', 'source_url' => 'https://fb.me/ad', 'headline' => 'Monsoon offer', 'ctwa_clid' => 'CLID-'.$wamid,
        ]);
    }

    private function ctwaJourney(Account $account): WhatsAppFlow
    {
        $this->grant($account, 'journey_automation');

        return WhatsAppFlow::create([
            'account_id' => $account->id, 'name' => 'Ad journey', 'trigger_type' => 'ctwa_referral', 'trigger_value' => '', 'is_active' => true,
            'graph_data' => [
                'nodes' => [
                    ['id' => 't', 'type' => 'trigger', 'position' => ['x' => 0, 'y' => 0], 'data' => []],
                    ['id' => 's', 'type' => 'save_lead', 'position' => ['x' => 200, 'y' => 0], 'data' => []],
                ],
                'edges' => [['id' => 'e', 'source' => 't', 'target' => 's']],
            ],
        ]);
    }

    // ================================================================== referral → CRM lead

    public function test_a_ctwa_referral_is_attributed_to_the_receiving_tenant_with_its_capture_and_crm_lead(): void
    {
        $account = $this->tenant();
        $campaign = $this->campaign($account, 'AD-777');

        $this->ctwa()->assertOk();

        $attribution = AdAttribution::sole();
        $capture = Lead::sole();
        $crmLead = CrmLead::sole();
        $this->assertSame($account->id, $attribution->account_id);
        $this->assertSame(['meta', 'whatsapp_ctwa', 'ad', 'AD-777', 'CLID-wamid.A1', 'wamid.A1', '919876543210'], [
            $attribution->provider, $attribution->channel, $attribution->source_type, $attribution->source_id,
            $attribution->click_id, $attribution->referral_message_id, $attribution->contact_phone,
        ]);
        $this->assertSame($campaign->id, $attribution->ad_campaign_id);
        $this->assertSame(WhatsAppSession::where('account_id', $account->id)->value('id'), $attribution->whatsapp_session_id);
        $this->assertSame([$capture->id, $crmLead->id], [$attribution->capture_lead_id, $attribution->crm_lead_id]);
        $this->assertNotNull($attribution->lead_linked_at);
        $this->assertNull($attribution->converted_at);
        $this->assertNull($attribution->conversion_value, 'no conversion value is fabricated');
        $this->assertSame(['headline' => 'Monsoon offer', 'source_url' => 'https://fb.me/ad'], $attribution->metadata);

        // Existing CTWA behaviour is unchanged: the meta_ad CRM source is kept.
        $this->assertSame(CrmLead::SOURCE_META_AD, $crmLead->source);
        $this->assertSame('whatsapp_ctwa', $capture->provider);
    }

    public function test_a_redelivered_referral_updates_nothing_and_creates_no_second_row(): void
    {
        $this->tenant();

        $this->ctwa('wamid.R')->assertOk();
        $this->ctwa('wamid.R')->assertOk();

        $this->assertSame(1, AdAttribution::count());
        $this->assertSame(1, CrmLead::count());
    }

    public function test_without_crm_the_referral_is_still_attributed_but_no_lead_is_invented(): void
    {
        $this->tenant(grants: ['ads']);

        $this->ctwa()->assertOk();

        $attribution = AdAttribution::sole();
        $this->assertNotNull($attribution->capture_lead_id);
        $this->assertNull($attribution->crm_lead_id);
        $this->assertNull($attribution->lead_linked_at);
    }

    public function test_an_organic_post_referral_is_kept_but_never_matched_to_a_campaign(): void
    {
        $account = $this->tenant();
        $this->campaign($account, 'POST-1');

        $this->referralMessage('wamid.P', '919876543210', self::PHONE_ID, ['source_id' => 'POST-1', 'source_type' => 'post'])->assertOk();

        $attribution = AdAttribution::sole();
        $this->assertSame('post', $attribution->source_type);
        $this->assertNull($attribution->ad_campaign_id);
        $this->assertNull($attribution->click_id, 'no click id was sent, none is stored');
    }

    // ================================================================== tenant isolation

    public function test_the_same_external_ids_on_two_tenants_never_cross(): void
    {
        $a = $this->tenant(self::PHONE_ID);
        $b = $this->tenant(self::OTHER_PHONE_ID);
        $campaignA = $this->campaign($a, 'AD-SHARED');
        $campaignB = $this->campaign($b, 'AD-SHARED');

        // Same ad id, same click id, same customer phone, on both tenants' numbers.
        $this->referralMessage('wamid.X1', '919876543210', self::PHONE_ID, ['source_id' => 'AD-SHARED', 'source_type' => 'ad', 'ctwa_clid' => 'CLID-SAME'])->assertOk();
        $this->referralMessage('wamid.X2', '919876543210', self::OTHER_PHONE_ID, ['source_id' => 'AD-SHARED', 'source_type' => 'ad', 'ctwa_clid' => 'CLID-SAME'])->assertOk();

        $rowA = AdAttribution::where('account_id', $a->id)->sole();
        $rowB = AdAttribution::where('account_id', $b->id)->sole();
        $this->assertSame($campaignA->id, $rowA->ad_campaign_id);
        $this->assertSame($campaignB->id, $rowB->ad_campaign_id);
        $this->assertSame($a->id, CrmLead::find($rowA->crm_lead_id)->account_id);
        $this->assertSame($b->id, CrmLead::find($rowB->crm_lead_id)->account_id);
    }

    public function test_a_provider_message_id_already_used_by_another_tenant_is_attributed_only_to_the_receiver(): void
    {
        $other = $this->tenant(self::OTHER_PHONE_ID);
        $mine = $this->tenant(self::PHONE_ID);
        // The other tenant already holds a capture row for this WAMID (globally unique column).
        Lead::create(['account_id' => $other->id, 'provider' => 'whatsapp_ctwa', 'provider_lead_id' => 'wamid.DUP', 'lead_phone' => '919000000000']);

        $this->ctwa('wamid.DUP')->assertOk();

        $attribution = AdAttribution::sole();
        $this->assertSame($mine->id, $attribution->account_id);
        $this->assertNull($attribution->capture_lead_id, 'the other tenant\'s capture row is never linked');
        $this->assertNull($attribution->crm_lead_id);
        $this->assertSame($other->id, Lead::where('provider_lead_id', 'wamid.DUP')->value('account_id'));
    }

    public function test_an_ad_id_ambiguous_within_the_tenant_is_not_guessed(): void
    {
        $account = $this->tenant();
        $this->campaign($account, 'AD-TWICE', 'First');
        $this->campaign($account, 'AD-TWICE', 'Second');

        $this->ctwa(adId: 'AD-TWICE')->assertOk();

        $this->assertNull(AdAttribution::sole()->ad_campaign_id);
    }

    public function test_an_unknown_receiving_number_records_nothing(): void
    {
        $this->tenant(self::PHONE_ID);

        $this->ctwa('wamid.U', phoneId: '000000000000000')->assertOk();

        $this->assertSame(0, AdAttribution::count());
    }

    public function test_links_to_another_tenants_rows_are_refused_by_the_model(): void
    {
        $a = $this->tenant(self::PHONE_ID);
        $b = $this->tenant(self::OTHER_PHONE_ID);
        $foreignCampaign = $this->campaign($b, 'AD-B');

        $this->expectException(LogicException::class);
        AdAttribution::create([
            'account_id' => $a->id, 'provider' => 'meta', 'channel' => 'whatsapp_ctwa', 'referral_message_id' => 'wamid.F',
            'referral_received_at' => now(), 'ad_campaign_id' => $foreignCampaign->id,
        ]);
    }

    // ================================================================== journey / conversion

    public function test_a_ctwa_journey_start_is_linked_to_the_referral(): void
    {
        $account = $this->tenant();
        $flow = $this->ctwaJourney($account);

        $this->ctwa('wamid.J')->assertOk();

        $attribution = AdAttribution::sole();
        $session = WhatsAppFlowSession::where('flow_id', $flow->id)->sole();
        $this->assertSame($session->id, $attribution->flow_session_id);
        $this->assertNotNull($attribution->journey_started_at);
        $this->assertNotNull($attribution->crm_lead_id, 'the CTWA capture\'s CRM lead');
    }

    public function test_a_lead_saved_inside_an_attributed_journey_session_is_linked(): void
    {
        $account = $this->tenant();
        $flow = $this->ctwaJourney($account);
        $session = WhatsAppFlowSession::create([
            'account_id' => $account->id, 'flow_id' => $flow->id, 'phone_number' => '919876543210', 'status' => 'active', 'context_data' => [],
        ]);
        $attribution = AdAttribution::create([
            'account_id' => $account->id, 'provider' => 'meta', 'channel' => 'whatsapp_ctwa', 'referral_message_id' => 'wamid.S',
            'contact_phone' => '919876543210', 'referral_received_at' => now(), 'flow_session_id' => $session->id, 'journey_started_at' => now(),
        ]);
        $crmLead = CrmLead::factory()->forContact(\App\Models\Contact::factory()->forAccount($account)->create(['phone_number' => '919876543210']))->create();

        app(AdAttributionService::class)->linkJourneyLead($session, $crmLead);

        $this->assertSame($crmLead->id, $attribution->fresh()->crm_lead_id);
    }

    public function test_conversion_follows_the_attributed_crm_lead_status_through_any_path(): void
    {
        $account = $this->tenant();
        $this->ctwa()->assertOk();
        $lead = CrmLead::sole();

        $this->actingAs($this->user($account))->patchJson("/api/crm/leads/{$lead->id}/status", ['status' => 'converted'])->assertOk();
        $this->assertNotNull(AdAttribution::sole()->converted_at);

        $this->actingAs($this->user($account))->patchJson("/api/crm/leads/{$lead->id}/status", ['status' => 'contacted'])->assertOk();
        $this->assertNull(AdAttribution::sole()->converted_at, 'the row reflects the lead\'s current state');
        $this->assertNull(AdAttribution::sole()->conversion_value);
    }

    // ================================================================== coexistence

    public function test_manual_and_api_created_leads_coexist_and_are_never_attributed_by_guesswork(): void
    {
        $account = $this->tenant();
        $this->grant($account, 'journey_automation');
        $this->ctwa()->assertOk();

        // Manual lead for the same phone.
        $this->actingAs($this->user($account))->postJson('/api/crm/leads', ['phone_number' => '919876543210', 'name' => 'Manual'])->assertCreated();

        // API-created lead for the same phone.
        $plain = 'sk_test_'.bin2hex(random_bytes(12));
        ApiKey::create(['account_id' => $account->id, 'name' => 'Integration', 'key_prefix' => substr($plain, 0, 10), 'key_hash' => ApiKey::hashKey($plain)]);
        $this->withHeaders(['X-API-KEY' => $plain, 'Accept' => 'application/json'])
            ->postJson('/api/v1/crm/leads', ['phone_number' => '919876543210', 'name' => 'From API'])->assertCreated();

        $sources = CrmLead::where('account_id', $account->id)->pluck('source')->sort()->values()->all();
        $this->assertSame([CrmLead::SOURCE_API, CrmLead::SOURCE_MANUAL, CrmLead::SOURCE_META_AD], $sources);

        $attributed = AdAttribution::sole();
        $this->assertSame(CrmLead::SOURCE_META_AD, CrmLead::find($attributed->crm_lead_id)->source, 'only the ad-captured lead is attributed; manual and API leads are never linked by guesswork');
    }

    public function test_an_ordinary_message_creates_no_attribution(): void
    {
        $this->tenant();
        $body = json_encode(['object' => 'whatsapp_business_account', 'entry' => [[
            'id' => 'waba', 'changes' => [['field' => 'messages', 'value' => [
                'messaging_product' => 'whatsapp', 'metadata' => ['phone_number_id' => self::PHONE_ID],
                'messages' => [['from' => '919876543210', 'id' => 'wamid.O', 'type' => 'text', 'text' => ['body' => 'hello']]],
            ]]],
        ]]]);

        $this->call('POST', '/api/webhooks/meta', [], [], [], [
            'CONTENT_TYPE' => 'application/json', 'HTTP_X_HUB_SIGNATURE_256' => 'sha256='.hash_hmac('sha256', $body, self::SECRET),
        ], $body)->assertOk();

        $this->assertSame(0, AdAttribution::count());
    }

    // ================================================================== read API + authorization

    public function test_a_tenant_reads_only_its_own_attribution_and_the_summary(): void
    {
        $a = $this->tenant(self::PHONE_ID);
        $b = $this->tenant(self::OTHER_PHONE_ID);
        $campaign = $this->campaign($a, 'AD-777');
        DB::table('ad_campaign_daily_metrics')->insert(['account_id' => $a->id, 'ad_campaign_id' => $campaign->id, 'metric_date' => now()->toDateString(), 'spend' => 20, 'impressions' => 1000, 'leads' => 2, 'created_at' => now(), 'updated_at' => now()]);
        $this->ctwa('wamid.1', '919876543210')->assertOk();
        $this->ctwa('wamid.2', '919876543211')->assertOk();
        $this->ctwa('wamid.3', '919876543212', self::OTHER_PHONE_ID)->assertOk();

        $user = $this->user($a);
        $this->actingAs($user)->getJson('/api/social/ads/attribution')->assertOk()
            ->assertJsonCount(2, 'data')->assertJsonPath('data.0.campaign.id', $campaign->id)->assertJsonPath('meta.total', 2);
        $this->actingAs($user)->getJson("/api/social/ads/attribution?account_id={$b->id}")->assertOk()->assertJsonPath('meta.total', 2);

        $summary = $this->actingAs($user)->getJson('/api/social/ads/attribution/summary')->assertOk()->json('data');
        $this->assertSame(['referrals' => 2, 'conversations' => 2, 'leads' => 2, 'journeys' => 0, 'conversions' => 0], array_intersect_key($summary['totals'], array_flip(['referrals', 'conversations', 'leads', 'journeys', 'conversions'])));
        $this->assertSame(0.0, (float) $summary['totals']['conversion_rate']);
        $this->assertSame(20.0, (float) $summary['totals']['spend']);
        $this->assertSame(10.0, (float) $summary['totals']['cost_per_lead']);
        $this->assertNull($summary['totals']['conversion_value']);
        $this->assertNull($summary['totals']['roas'], 'no ROAS without an attributable conversion value');
        $this->assertSame($campaign->id, $summary['ads'][0]['campaign']['id']);
    }

    public function test_super_admin_must_select_a_client_and_the_client_must_pass_the_ads_checks(): void
    {
        $admin = $this->superAdmin();
        $client = $this->tenant();
        $this->ctwa()->assertOk();

        $this->actingAs($admin)->getJson('/api/social/ads/attribution')->assertStatus(422);
        $this->app['env'] = 'local';
        $this->actingAs($admin)->getJson('/api/social/ads/attribution/summary')->assertStatus(422);
        $this->app['env'] = 'testing';

        $this->actingAs($admin)->getJson("/api/social/ads/attribution?account_id={$client->id}")->assertOk()->assertJsonPath('meta.total', 1);

        $suspended = $this->tenant(self::OTHER_PHONE_ID);
        $suspended->forceFill(['status' => 'suspended'])->save();
        $this->actingAs($admin)->getJson("/api/social/ads/attribution?account_id={$suspended->id}")->assertForbidden()->assertJsonPath('error_code', 'CLIENT_ACCOUNT_SUSPENDED');
    }

    public function test_expired_subscription_missing_ads_capability_and_disabled_module_are_denied(): void
    {
        $admin = $this->superAdmin();

        $expired = $this->tenant(self::PHONE_ID);
        Subscription::where('account_id', $expired->id)->update(['expires_at' => now()->subDay()]);
        // Phase 10 Task 6: reads follow the launcher list / dashboard contract — a lapsed subscription still reads (writes stay blocked, see AdCampaignLifecycleTest).
        $this->actingAs($this->user($expired))->getJson('/api/social/ads/attribution')->assertOk();
        // Owner decision (2026-09-30): a lapsed subscription does not stop a Super Admin (suspension and module switches still do).
        $this->actingAs($admin)->getJson("/api/social/ads/attribution?account_id={$expired->id}")->assertOk();

        // `social` does not grant Ads: a tenant with social but without ads is refused.
        $noAds = $this->tenant(self::OTHER_PHONE_ID, ['crm', 'social']);
        $this->actingAs($this->user($noAds))->getJson('/api/social/ads/attribution')->assertForbidden()->assertJsonPath('error_code', 'CAPABILITY_NOT_ENTITLED');
        // Owner decision (2026-09-30): a Super Admin is not held to the client's plan.
        $this->actingAs($admin)->getJson("/api/social/ads/attribution?account_id={$noAds->id}")->assertOk();

        $noModule = $this->tenant('109800000000303');
        $noModule->forceFill(['allowed_modules' => ['campaigns']])->save();
        $this->actingAs($this->user($noModule))->getJson('/api/social/ads/attribution/summary')->assertForbidden();
        $this->actingAs($admin)->getJson("/api/social/ads/attribution/summary?account_id={$noModule->id}")->assertForbidden()->assertJsonPath('error_code', 'MODULE_DISABLED');
    }

    public function test_without_an_ads_read_permission_access_is_refused(): void
    {
        $account = $this->tenant();

        $this->actingAs($this->user($account, 'user'))->getJson('/api/social/ads/attribution')->assertForbidden();
    }

    public function test_the_attribution_code_makes_no_provider_calls(): void
    {
        foreach (['Services/Ads/AdAttributionService.php', 'Http/Controllers/Api/AdAttributionController.php', 'Models/AdAttribution.php'] as $relative) {
            $source = file_get_contents(app_path($relative));
            $this->assertStringNotContainsString('graph.facebook.com', $source);
            $this->assertStringNotContainsString('Facades\\Http', $source);
        }
    }
}
