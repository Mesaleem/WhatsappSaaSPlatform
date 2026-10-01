<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\AccountEntitlement;
use App\Models\AdAttribution;
use App\Models\AdCampaign;
use App\Models\Capability;
use App\Models\Contact;
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
use Tests\TestCase;

/**
 * Phase 10 Task 4 — attribution hardening: strongest-identifier journey
 * linking, ambiguity left unresolved, tenant-scoped matching, idempotent
 * capture / linking / conversion, single-credit conversion, NULL value.
 * (Authorization gates are covered by AdAttributionTest, which this task
 * leaves in force.)
 */
class AdAttributionHardeningTest extends TestCase
{
    use RefreshDatabase;

    private const SECRET = 'meta_app_secret_attribution_hardening';

    private const PHONE_ID = '109800000000101';

    private const OTHER_PHONE_ID = '109800000000202';

    private const CUSTOMER = '919876543210';

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

    private function tenant(string $phoneId = self::PHONE_ID): Account
    {
        $account = Account::factory()->create();
        Subscription::factory()->create(['account_id' => $account->id]);
        foreach (['crm', 'ads'] as $slug) {
            $this->grant($account, $slug);
        }
        WhatsAppSession::create([
            'account_id' => $account->id, 'status' => 'connected', 'meta_phone_number_id' => $phoneId,
            'meta_waba_id' => '123456789012345', 'meta_access_token' => 'EAAG', 'meta_webhook_verify_token' => 'V'.uniqid(),
        ]);

        return $account->fresh();
    }

    private function user(Account $account): User
    {
        $user = User::factory()->create(['account_id' => $account->id, 'is_active' => true]);
        $user->assignRole('admin');

        return $user;
    }

    private function campaign(Account $account, string $adId): AdCampaign
    {
        return AdCampaign::create([
            'account_id' => $account->id, 'meta_campaign_id' => 'CMP-'.uniqid(), 'meta_adset_id' => 'SET-'.uniqid(), 'meta_ad_id' => $adId,
            'name' => 'C-'.$adId, 'objective' => 'OUTCOME_LEADS', 'status' => 'ACTIVE', 'daily_budget' => 10,
        ]);
    }

    private function ctwa(string $wamid, string $phoneId = self::PHONE_ID, string $from = self::CUSTOMER, string $adId = 'AD-777', ?string $clid = null)
    {
        $body = json_encode(['object' => 'whatsapp_business_account', 'entry' => [[
            'id' => 'waba', 'changes' => [['field' => 'messages', 'value' => [
                'messaging_product' => 'whatsapp',
                'metadata' => ['display_phone_number' => '919999999999', 'phone_number_id' => $phoneId],
                'contacts' => [['profile' => ['name' => 'Bob'], 'wa_id' => $from]],
                'messages' => [['from' => $from, 'id' => $wamid, 'timestamp' => '1700000000', 'type' => 'text', 'text' => ['body' => 'Hi'],
                    'referral' => ['source_id' => $adId, 'source_type' => 'ad', 'ctwa_clid' => $clid ?? 'CLID-'.$wamid]]],
            ]]],
        ]]]);

        return $this->call('POST', '/api/webhooks/meta', [], [], [], [
            'CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json',
            'HTTP_X_HUB_SIGNATURE_256' => 'sha256='.hash_hmac('sha256', $body, self::SECRET),
        ], $body);
    }

    private function flow(Account $account): WhatsAppFlow
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

    private function flowSession(Account $account, ?WhatsAppFlow $flow = null, string $phone = self::CUSTOMER): WhatsAppFlowSession
    {
        return WhatsAppFlowSession::create([
            'account_id' => $account->id, 'flow_id' => ($flow ?? $this->flow($account))->id, 'phone_number' => $phone, 'status' => 'active', 'context_data' => [],
        ]);
    }

    private function row(Account $account, string $wamid, array $extra = []): AdAttribution
    {
        return AdAttribution::create($extra + [
            'account_id' => $account->id, 'provider' => 'meta', 'channel' => 'whatsapp_ctwa', 'referral_message_id' => $wamid,
            'source_type' => 'ad', 'source_id' => 'AD-777', 'contact_phone' => self::CUSTOMER, 'referral_received_at' => now(),
        ]);
    }

    private function start(Account $account, WhatsAppFlowSession $session, ?string $messageId, array $referral = ['source_id' => 'AD-777', 'source_type' => 'ad'], string $phone = self::CUSTOMER): void
    {
        app(AdAttributionService::class)->journeyStarted($account->id, $phone, $referral, $session, $messageId);
    }

    private function crmLead(Account $account, ?string $phone = null): CrmLead
    {
        $phone ??= '9198'.random_int(10000000, 99999999);

        return CrmLead::factory()->forContact(Contact::factory()->forAccount($account)->create(['phone_number' => $phone]))->create();
    }

    // ================================================================== journey linking rules

    public function test_the_referral_message_id_is_the_strongest_identifier_even_with_several_open_referrals(): void
    {
        $account = $this->tenant();
        $a = $this->row($account, 'wamid.A');
        $b = $this->row($account, 'wamid.B');
        $session = $this->flowSession($account);

        $this->start($account, $session, 'wamid.A');

        $this->assertSame($session->id, $a->fresh()->flow_session_id);
        $this->assertNull($b->fresh()->flow_session_id, 'the newer referral is not guessed');
        $this->assertSame('referral_message_id', $a->fresh()->metadata['match']['rule']);
    }

    public function test_several_equally_plausible_referrals_stay_unresolved_and_are_marked_ambiguous(): void
    {
        $account = $this->tenant();
        $a = $this->row($account, 'wamid.A');
        $b = $this->row($account, 'wamid.B');

        $this->start($account, $this->flowSession($account), null);

        foreach ([$a, $b] as $row) {
            $row = $row->fresh();
            $this->assertNull($row->flow_session_id);
            $this->assertNull($row->journey_started_at);
            $this->assertSame('ambiguous', $row->metadata['match']['status']);
            $this->assertSame('ambiguous', $row->diagnosticStatus());
        }

        $user = $this->user($account);
        $this->actingAs($user)->getJson('/api/social/ads/attribution')->assertOk()->assertJsonPath('data.0.status', 'ambiguous')->assertJsonPath('data.0.match.reason', 'multiple_referrals_in_window');
    }

    public function test_the_click_id_resolves_otherwise_ambiguous_referrals(): void
    {
        $account = $this->tenant();
        $a = $this->row($account, 'wamid.A', ['click_id' => 'CLID-1']);
        $b = $this->row($account, 'wamid.B', ['click_id' => 'CLID-2']);

        $this->start($account, $this->flowSession($account), null, ['source_id' => 'AD-777', 'source_type' => 'ad', 'ctwa_clid' => 'CLID-1']);

        $this->assertNotNull($a->fresh()->flow_session_id);
        $this->assertNull($b->fresh()->flow_session_id);
        $this->assertSame('click_id', $a->fresh()->metadata['match']['rule']);
    }

    public function test_controlled_fallback_links_a_single_candidate_and_records_why(): void
    {
        $account = $this->tenant();
        $a = $this->row($account, 'wamid.A');

        $this->start($account, $this->flowSession($account), null);

        $this->assertNotNull($a->fresh()->flow_session_id);
        $this->assertSame('phone_source_window', $a->fresh()->metadata['match']['rule']);
        $this->assertSame('attributed', $a->fresh()->diagnosticStatus());
    }

    public function test_the_fallback_ignores_a_referral_outside_the_window_or_for_another_ad(): void
    {
        $account = $this->tenant();
        $old = $this->row($account, 'wamid.OLD', ['referral_received_at' => now()->subHours(30)]);
        $other = $this->row($account, 'wamid.OTHER', ['source_id' => 'AD-OTHER']);

        $this->start($account, $this->flowSession($account), null);

        $this->assertNull($old->fresh()->flow_session_id);
        $this->assertNull($other->fresh()->flow_session_id);
    }

    public function test_journey_start_is_idempotent_and_a_linked_referral_is_never_reattached(): void
    {
        $account = $this->tenant();
        $a = $this->row($account, 'wamid.A');
        $first = $this->flowSession($account);
        $second = $this->flowSession($account);

        $this->start($account, $first, 'wamid.A');
        $stamp = $a->fresh()->journey_started_at;
        $this->start($account, $first, 'wamid.A');
        $this->start($account, $second, 'wamid.A');
        $this->start($account, $second, null);

        $this->assertSame($first->id, $a->fresh()->flow_session_id);
        $this->assertEquals($stamp, $a->fresh()->journey_started_at);
        $this->assertSame(1, AdAttribution::whereNotNull('flow_session_id')->count());
    }

    public function test_journey_linking_never_crosses_tenants_for_the_same_phone_message_or_ad(): void
    {
        $a = $this->tenant(self::PHONE_ID);
        $b = $this->tenant(self::OTHER_PHONE_ID);
        $rowA = $this->row($a, 'wamid.SAME');
        $rowB = $this->row($b, 'wamid.SAME');

        // Tenant A's session, tenant B's identifiers only exist on B.
        $sessionA = $this->flowSession($a);
        $this->start($a, $sessionA, 'wamid.SAME');
        $this->assertSame($sessionA->id, $rowA->fresh()->flow_session_id);
        $this->assertNull($rowB->fresh()->flow_session_id);

        // A message id that exists only on B is never linked from A.
        $rowB->update(['referral_message_id' => 'wamid.B-ONLY']);
        $this->start($a, $this->flowSession($a), 'wamid.B-ONLY');
        $this->assertNull($rowB->fresh()->flow_session_id);

        // A session that belongs to another account is refused outright.
        $this->start($b, $this->flowSession($a), null);
        $this->assertNull($rowB->fresh()->flow_session_id);
    }

    public function test_webhook_redelivery_with_a_journey_leaves_one_consistent_graph(): void
    {
        $account = $this->tenant();
        $this->campaign($account, 'AD-777');
        $flow = $this->flow($account);

        foreach (range(1, 3) as $_) {
            $this->ctwa('wamid.R')->assertOk();
        }

        $attribution = AdAttribution::sole();
        $this->assertSame(1, Lead::where('provider', 'whatsapp_ctwa')->count());
        $this->assertSame(1, CrmLead::where('source', CrmLead::SOURCE_META_AD)->count());
        $this->assertSame(1, WhatsAppFlowSession::where('flow_id', $flow->id)->count());
        $this->assertSame(WhatsAppFlowSession::where('flow_id', $flow->id)->value('id'), $attribution->flow_session_id);
        $this->assertSame(CrmLead::where('source', CrmLead::SOURCE_META_AD)->value('id'), $attribution->crm_lead_id);
        $this->assertSame('referral_message_id', $attribution->metadata['match']['rule']);
    }

    public function test_a_second_referral_from_the_same_phone_links_its_own_journey_not_the_earlier_one(): void
    {
        $account = $this->tenant();
        $this->ctwa('wamid.1')->assertOk(); // no journey exists yet → unlinked
        $this->flow($account);

        $this->ctwa('wamid.2')->assertOk();

        $this->assertNull(AdAttribution::where('referral_message_id', 'wamid.1')->value('flow_session_id'));
        $this->assertNotNull(AdAttribution::where('referral_message_id', 'wamid.2')->value('flow_session_id'));
    }

    // ================================================================== tenant-scoped capture

    public function test_cross_tenant_phone_and_ad_collisions_keep_each_graph_separate(): void
    {
        $a = $this->tenant(self::PHONE_ID);
        $b = $this->tenant(self::OTHER_PHONE_ID);
        $campaignA = $this->campaign($a, 'AD-SHARED');

        $this->ctwa('wamid.X1', self::PHONE_ID, adId: 'AD-SHARED')->assertOk();
        $this->ctwa('wamid.X2', self::OTHER_PHONE_ID, adId: 'AD-SHARED')->assertOk();

        $this->assertSame($campaignA->id, AdAttribution::where('account_id', $a->id)->value('ad_campaign_id'));
        $rowB = AdAttribution::where('account_id', $b->id)->sole();
        $this->assertNull($rowB->ad_campaign_id, 'another tenant\'s campaign is never matched');
        $this->assertSame('no_launcher_campaign', $rowB->metadata['campaign_match']);
        $this->assertSame($b->id, CrmLead::find($rowB->crm_lead_id)->account_id);
    }

    public function test_ambiguous_launcher_campaigns_are_recorded_with_a_reason(): void
    {
        $account = $this->tenant();
        $this->campaign($account, 'AD-777');
        $this->campaign($account, 'AD-777');

        $this->ctwa('wamid.A')->assertOk();

        $row = AdAttribution::sole();
        $this->assertNull($row->ad_campaign_id);
        $this->assertSame('ambiguous_launcher_campaigns', $row->metadata['campaign_match']);
    }

    public function test_a_referral_is_never_linked_to_a_crm_lead_from_another_capture(): void
    {
        $account = $this->tenant();
        $capture = Lead::create(['account_id' => $account->id, 'provider' => 'whatsapp_ctwa', 'provider_lead_id' => 'wamid.C', 'lead_phone' => self::CUSTOMER]);
        $unrelated = $this->crmLead($account); // same phone, different origin

        app(AdAttributionService::class)->recordReferral($account->id, 'meta', 'whatsapp_ctwa', 'wamid.C', self::CUSTOMER, ['source_id' => 'AD-777', 'source_type' => 'ad'], null, $capture, $unrelated);

        $row = AdAttribution::sole();
        $this->assertSame($capture->id, $row->capture_lead_id);
        $this->assertNull($row->crm_lead_id);
    }

    public function test_repeated_capture_creates_no_duplicate_attribution_and_keeps_sources(): void
    {
        $account = $this->tenant();
        $this->ctwa('wamid.A')->assertOk();
        $this->ctwa('wamid.A')->assertOk();
        $this->actingAs($this->user($account))->postJson('/api/crm/leads', ['phone_number' => '919000000001', 'name' => 'Manual'])->assertCreated();

        $this->assertSame(1, AdAttribution::count());
        $this->assertSame(CrmLead::SOURCE_META_AD, CrmLead::find(AdAttribution::value('crm_lead_id'))->source);
        $this->assertSame(CrmLead::SOURCE_MANUAL, CrmLead::whereHas('contact', fn ($q) => $q->where('phone_number', '919000000001'))->value('source'));
    }

    public function test_a_journey_lead_links_only_when_the_session_has_exactly_one_attribution(): void
    {
        $account = $this->tenant();
        $session = $this->flowSession($account);
        $a = $this->row($account, 'wamid.A', ['flow_session_id' => $session->id]);
        $lead = $this->crmLead($account);

        app(AdAttributionService::class)->linkJourneyLead($session, $lead);
        app(AdAttributionService::class)->linkJourneyLead($session, $this->crmLead($account));

        $this->assertSame($lead->id, $a->fresh()->crm_lead_id, 'a repeated link never replaces the first lead');

        // Two rows on one session (corrupt state) → nothing is guessed.
        $s2 = $this->flowSession($account);
        $x = $this->row($account, 'wamid.X', ['flow_session_id' => $s2->id]);
        $y = $this->row($account, 'wamid.Y', ['flow_session_id' => $s2->id]);
        app(AdAttributionService::class)->linkJourneyLead($s2, $this->crmLead($account));
        $this->assertNull($x->fresh()->crm_lead_id);
        $this->assertNull($y->fresh()->crm_lead_id);
    }

    // ================================================================== conversion

    public function test_conversion_is_idempotent_across_repeated_status_changes_and_never_invents_a_value(): void
    {
        $account = $this->tenant();
        $lead = $this->crmLead($account);
        $row = $this->row($account, 'wamid.A', ['crm_lead_id' => $lead->id]);

        $lead->update(['status' => CrmLead::STATUS_CONVERTED, 'converted_at' => now()]);
        $stamp = $row->fresh()->converted_at;
        $this->assertNotNull($stamp);

        $this->travel(5)->minutes();
        $lead->update(['status' => CrmLead::STATUS_CONVERTED, 'converted_at' => now()->addMinutes(random_int(1, 50))]);
        $lead->update(['status' => CrmLead::STATUS_CONVERTED, 'converted_at' => now()->addMinutes(random_int(1, 50))]);
        $this->assertEquals($stamp, $row->fresh()->converted_at, 'repeated saves do not move the conversion');
        $this->assertSame(1, AdAttribution::whereNotNull('converted_at')->count());
        $this->assertNull($row->fresh()->conversion_value);
        $this->assertNull($row->fresh()->conversion_currency);
        $this->assertSame('converted', $row->fresh()->diagnosticStatus());

        $lead->update(['converted_at' => null, 'status' => CrmLead::STATUS_CONTACTED]);
        $this->assertNull($row->fresh()->converted_at);
        $lead->update(['converted_at' => null, 'status' => CrmLead::STATUS_CONTACTED]);
        $this->assertNull($row->fresh()->converted_at);

        $lead->update(['status' => CrmLead::STATUS_CONVERTED, 'converted_at' => now()]);
        $this->assertNotNull($row->fresh()->converted_at);
    }

    public function test_a_lead_without_attribution_converts_without_error(): void
    {
        $account = $this->tenant();
        $lead = $this->crmLead($account);

        $lead->update(['status' => CrmLead::STATUS_CONVERTED, 'converted_at' => now()]);
        $lead->update(['converted_at' => null, 'status' => CrmLead::STATUS_CONTACTED]);

        $this->assertSame(0, AdAttribution::count());
        $this->assertSame(CrmLead::STATUS_CONTACTED, $lead->fresh()->status);
    }

    public function test_several_attribution_rows_for_one_lead_credit_a_single_conversion(): void
    {
        $account = $this->tenant();
        $lead = $this->crmLead($account);
        $first = $this->row($account, 'wamid.1', ['crm_lead_id' => $lead->id, 'referral_received_at' => now()->subDay()]);
        $second = $this->row($account, 'wamid.2', ['crm_lead_id' => $lead->id]);

        $lead->update(['status' => CrmLead::STATUS_CONVERTED, 'converted_at' => now()]);

        $this->assertNotNull($first->fresh()->converted_at, 'first touch is credited');
        $this->assertNull($second->fresh()->converted_at, 'no double counting');
        $this->assertSame(1, AdAttribution::whereNotNull('converted_at')->count());

        $lead->update(['converted_at' => null, 'status' => CrmLead::STATUS_CONTACTED]);
        $this->assertSame(0, AdAttribution::whereNotNull('converted_at')->count());
    }

    public function test_conversion_never_touches_another_tenants_attribution(): void
    {
        $a = $this->tenant(self::PHONE_ID);
        $b = $this->tenant(self::OTHER_PHONE_ID);
        $leadA = $this->crmLead($a);
        $rowB = $this->row($b, 'wamid.B');
        // A foreign link is refused by the model's tenant guard, so the row stays unlinked.
        try {
            $rowB->update(['crm_lead_id' => $leadA->id]);
        } catch (\LogicException) {
        }

        $leadA->update(['status' => CrmLead::STATUS_CONVERTED, 'converted_at' => now()]);

        $this->assertNull($rowB->fresh()->converted_at);
        $this->assertNull($rowB->fresh()->crm_lead_id);
    }

    public function test_a_lead_created_already_converted_credits_one_attribution_only(): void
    {
        $account = $this->tenant();
        $capture = Lead::create(['account_id' => $account->id, 'provider' => 'whatsapp_ctwa', 'provider_lead_id' => 'wamid.C', 'lead_phone' => self::CUSTOMER]);
        $lead = CrmLead::factory()->forContact(Contact::factory()->forAccount($account)->create(['phone_number' => self::CUSTOMER]))
            ->create(['status' => CrmLead::STATUS_CONVERTED, 'converted_at' => now(), 'capture_lead_id' => $capture->id]);
        $service = app(AdAttributionService::class);

        $service->recordReferral($account->id, 'meta', 'whatsapp_ctwa', 'wamid.C', self::CUSTOMER, ['source_id' => 'AD-777', 'source_type' => 'ad'], null, $capture, $lead);
        $service->recordReferral($account->id, 'meta', 'whatsapp_ctwa', 'wamid.C', self::CUSTOMER, ['source_id' => 'AD-777', 'source_type' => 'ad'], null, $capture, $lead);

        $row = AdAttribution::sole();
        $this->assertSame($lead->id, $row->crm_lead_id);
        $this->assertNotNull($row->converted_at);
        $this->assertNull($row->conversion_value);
    }

    // ================================================================== Task 4 follow-up: policy / lifecycle / limit

    public function test_multi_attribution_conversion_policy_is_deterministic_earliest_first_and_tenant_safe(): void
    {
        $a = $this->tenant(self::PHONE_ID);
        $b = $this->tenant(self::OTHER_PHONE_ID);
        $lead = $this->crmLead($a);
        // Insert out of order on purpose: the earliest REFERRAL (not the lowest id) must win.
        $late = $this->row($a, 'wamid.LATE', ['crm_lead_id' => $lead->id, 'referral_received_at' => now()->subHour()]);
        $earliest = $this->row($a, 'wamid.EARLY', ['crm_lead_id' => $lead->id, 'referral_received_at' => now()->subDays(3)]);
        $middle = $this->row($a, 'wamid.MID', ['crm_lead_id' => $lead->id, 'referral_received_at' => now()->subDay()]);
        // Another tenant's row with the same lead id value can never be linked or selected.
        $foreign = $this->row($b, 'wamid.FOREIGN', ['referral_received_at' => now()->subDays(10)]);
        try {
            $foreign->update(['crm_lead_id' => $lead->id]);
        } catch (\LogicException) {
        }
        AdAttribution::query()->whereKey($foreign->id)->update(['crm_lead_id' => $lead->id]); // even a forged raw link is ignored (scoped by account)

        $lead->update(['status' => CrmLead::STATUS_CONVERTED, 'converted_at' => now()]);

        $this->assertNotNull($earliest->fresh()->converted_at);
        $this->assertNull($middle->fresh()->converted_at);
        $this->assertNull($late->fresh()->converted_at);
        $this->assertNull($foreign->fresh()->converted_at, 'another tenant\'s attribution is never selected');
        $this->assertSame(1, AdAttribution::whereNotNull('converted_at')->count());

        $stamp = $earliest->fresh()->converted_at;
        $this->travel(2)->minutes();
        $lead->update(['status' => CrmLead::STATUS_CONVERTED, 'converted_at' => now()->addMinutes(7)]);
        $lead->update(['converted_at' => now()->addMinutes(9)]);
        $this->assertEquals($stamp, $earliest->fresh()->converted_at);
        $this->assertSame(1, AdAttribution::whereNotNull('converted_at')->count());
        $this->assertNull(AdAttribution::whereNotNull('converted_at')->value('conversion_value'));

        $lead->update(['status' => CrmLead::STATUS_CONTACTED, 'converted_at' => null]);
        $this->assertSame(0, AdAttribution::whereNotNull('converted_at')->count());
        $this->assertNull($foreign->fresh()->converted_at);

        // Converting again credits the same earliest row again (deterministic).
        $lead->update(['status' => CrmLead::STATUS_CONVERTED, 'converted_at' => now()]);
        $this->assertNotNull($earliest->fresh()->converted_at);
        $this->assertNull($middle->fresh()->converted_at);
        $this->assertNull($late->fresh()->converted_at);
    }

    public function test_the_saved_hook_syncs_a_status_change_on_the_same_instance_that_was_just_created(): void
    {
        $account = $this->tenant();
        $contact = Contact::factory()->forAccount($account)->create(['phone_number' => self::CUSTOMER]);

        // Real lifecycle: one model instance, created (initial status), then changed and saved.
        $lead = CrmLead::factory()->forContact($contact)->create(['status' => CrmLead::STATUS_NEW]);
        $this->assertTrue($lead->wasRecentlyCreated, 'the instance still reports "recently created" — the case the old hook mishandled');
        $row = $this->row($account, 'wamid.A', ['crm_lead_id' => $lead->id]);
        $this->assertNull($row->fresh()->converted_at, 'initial status does not convert');

        $lead->status = CrmLead::STATUS_CONVERTED;
        $lead->converted_at = now();
        $lead->save();

        $this->assertTrue($lead->wasRecentlyCreated);
        $this->assertTrue($lead->wasChanged('status'));
        $this->assertFalse($lead->wasChanged('id'));
        $this->assertNotNull($row->fresh()->converted_at);
        $this->assertSame(1, AdAttribution::whereNotNull('converted_at')->count());

        $stamp = $row->fresh()->converted_at;
        $this->travel(3)->minutes();
        $lead->converted_at = now()->addMinutes(11);
        $lead->save();
        $this->assertEquals($stamp, $row->fresh()->converted_at, 'a save without a status change does not touch the conversion');

        $lead->status = CrmLead::STATUS_CONTACTED;
        $lead->converted_at = null;
        $lead->save();
        $this->assertNull($row->fresh()->converted_at);
    }

    public function test_the_hook_does_not_query_attribution_on_a_plain_non_converted_creation(): void
    {
        $account = $this->tenant();
        $queries = 0;
        DB::listen(function ($q) use (&$queries) {
            if (str_contains($q->sql, 'ad_attributions')) {
                $queries++;
            }
        });

        $this->crmLead($account);

        $this->assertSame(0, $queries);
    }

    public function test_more_candidates_than_any_row_limit_stay_ambiguous_never_an_arbitrary_match(): void
    {
        $account = $this->tenant();
        $rows = [];
        foreach (range(1, 14) as $i) {
            $rows[] = $this->row($account, "wamid.N{$i}", ['referral_received_at' => now()->subMinutes($i), 'click_id' => 'CLID-'.$i]);
        }
        $session = $this->flowSession($account);

        $this->start($account, $session, null);

        $this->assertSame(0, AdAttribution::whereNotNull('flow_session_id')->count());
        $this->assertSame(10, AdAttribution::query()->get()->filter(fn ($r) => ($r->metadata['match']['status'] ?? null) === 'ambiguous')->count(), 'diagnostics are bounded');
        $this->assertSame(14, $rows[0]->fresh()->metadata['match']['candidates'], 'the true candidate count is reported');
    }

    public function test_a_click_id_shared_by_a_row_beyond_the_top_candidates_is_not_unique(): void
    {
        $account = $this->tenant();
        // Oldest two rows share the click id; 11 newer rows would push one of them out of any top-10 list.
        $this->row($account, 'wamid.OLD1', ['referral_received_at' => now()->subHours(5), 'click_id' => 'CLID-DUP']);
        $this->row($account, 'wamid.OLD2', ['referral_received_at' => now()->subHours(6), 'click_id' => 'CLID-DUP']);
        foreach (range(1, 11) as $i) {
            $this->row($account, "wamid.NEW{$i}", ['referral_received_at' => now()->subMinutes($i), 'click_id' => 'CLID-NEW'.$i]);
        }

        $this->start($account, $this->flowSession($account), null, ['source_id' => 'AD-777', 'source_type' => 'ad', 'ctwa_clid' => 'CLID-DUP']);

        $this->assertSame(0, AdAttribution::whereNotNull('flow_session_id')->count());
    }

    public function test_a_unique_click_id_still_resolves_among_many_candidates(): void
    {
        $account = $this->tenant();
        foreach (range(1, 12) as $i) {
            $this->row($account, "wamid.M{$i}", ['referral_received_at' => now()->subMinutes($i), 'click_id' => 'CLID-'.$i]);
        }
        $oldest = AdAttribution::where('referral_message_id', 'wamid.M12')->first();

        $this->start($account, $this->flowSession($account), null, ['source_id' => 'AD-777', 'source_type' => 'ad', 'ctwa_clid' => 'CLID-12']);

        $this->assertNotNull($oldest->fresh()->flow_session_id);
        $this->assertSame(1, AdAttribution::whereNotNull('flow_session_id')->count());
    }
}
