<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\AccountEntitlement;
use App\Models\AdAttribution;
use App\Models\AdCampaign;
use App\Models\AdCampaignDailyMetric;
use App\Models\Capability;
use App\Models\Contact;
use App\Models\CrmLead;
use App\Models\SocialProviderConfig;
use App\Models\Subscription;
use App\Models\User;
use App\Models\WhatsAppFlow;
use App\Models\WhatsAppSession;
use Database\Seeders\Phase1FoundationSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Phase 10 Task 6 — Ads end-to-end hardening: one authorization matrix over every
 * Ads endpoint, tenant / Agent / Super Admin isolation with forged ids, and the full
 * CTWA webhook → attribution → CRM lead → Journey → conversion → value → dashboard
 * flow reconciled against the canonical rows.
 */
class AdsEndToEndHardeningTest extends TestCase
{
    use RefreshDatabase;

    private const SECRET = 'meta_app_secret_e2e_hardening';

    private const PHONE_ID = '109800000000303';

    private int $seq = 0;

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
            ['source' => 'manual_grant'],
        );
    }

    private function tenant(array $grants = ['crm', 'ads'], ?string $phoneId = null, array $attributes = []): Account
    {
        $account = Account::factory()->create($attributes);
        Subscription::factory()->create(['account_id' => $account->id]);
        foreach ($grants as $slug) {
            $this->grant($account, $slug);
        }
        if ($phoneId !== null) {
            WhatsAppSession::create([
                'account_id' => $account->id, 'status' => 'connected', 'meta_phone_number_id' => $phoneId,
                'meta_waba_id' => '123456789012345', 'meta_access_token' => 'EAAG', 'meta_webhook_verify_token' => 'V'.uniqid(),
            ]);
        }

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

    private function campaign(Account $account, string $name = 'C', array $attributes = []): AdCampaign
    {
        $n = ++$this->seq;

        return AdCampaign::create($attributes + [
            'account_id' => $account->id, 'meta_campaign_id' => "E2E-CMP-{$n}", 'meta_adset_id' => "E2E-SET-{$n}", 'meta_ad_id' => "E2E-AD-{$n}",
            'name' => $name, 'objective' => 'CLICK_TO_WHATSAPP', 'status' => 'ACTIVE', 'daily_budget' => 10, 'currency' => 'INR',
        ]);
    }

    private function spend(AdCampaign $campaign, float $spend, ?string $date = null): void
    {
        AdCampaignDailyMetric::create([
            'account_id' => $campaign->account_id, 'ad_campaign_id' => $campaign->id, 'metric_date' => $date ?? now()->subDay()->toDateString(),
            'spend' => $spend, 'impressions' => 1000, 'leads' => 0, 'cpl' => null,
        ]);
    }

    private function ctwa(string $wamid, string $from, string $adId, string $phoneId = self::PHONE_ID)
    {
        $body = json_encode(['object' => 'whatsapp_business_account', 'entry' => [[
            'id' => 'waba', 'changes' => [['field' => 'messages', 'value' => [
                'messaging_product' => 'whatsapp',
                'metadata' => ['display_phone_number' => '919999999999', 'phone_number_id' => $phoneId],
                'contacts' => [['profile' => ['name' => 'Bob'], 'wa_id' => $from]],
                'messages' => [['from' => $from, 'id' => $wamid, 'timestamp' => '1700000000', 'type' => 'text', 'text' => ['body' => 'Hi'],
                    'referral' => ['source_id' => $adId, 'source_type' => 'ad', 'source_url' => 'https://fb.me/ad', 'headline' => 'Offer', 'ctwa_clid' => 'CLID-'.$wamid]]],
            ]]],
        ]]]);

        return $this->call('POST', '/api/webhooks/meta', [], [], [], [
            'CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json',
            'HTTP_X_HUB_SIGNATURE_256' => 'sha256='.hash_hmac('sha256', $body, self::SECRET),
        ], $body);
    }

    private function journey(Account $account): WhatsAppFlow
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

    // ================================================================== authorization matrix

    /** @return array<string, array{0: string, 1: string, 2: bool}> label => [method, uri, isRead] */
    private function endpoints(AdCampaign $campaign): array
    {
        $id = $campaign->id;

        return [
            'list' => ['GET', '/api/social/ads', true],
            'dashboard' => ['GET', '/api/social/ads/dashboard', true],
            'attribution' => ['GET', '/api/social/ads/attribution', true],
            'attribution summary' => ['GET', '/api/social/ads/attribution/summary', true],
            'launch' => ['POST', '/api/social/ads/launch', false],
            'pause' => ['POST', "/api/social/ads/{$id}/pause", false],
            'resume' => ['POST', "/api/social/ads/{$id}/resume", false],
            'cpl threshold' => ['PATCH', "/api/social/ads/{$id}/cpl-threshold", false],
        ];
    }

    private function hit(?User $actor, string $method, string $uri)
    {
        $request = $actor ? $this->actingAs($actor) : $this;

        return $request->json($method, $uri, $method === 'GET' ? [] : ['cpl_threshold' => 5]);
    }

    public function test_every_ads_endpoint_requires_authentication(): void
    {
        $campaign = $this->campaign($this->tenant());

        foreach ($this->endpoints($campaign) as $label => [$method, $uri]) {
            $this->assertContains($this->hit(null, $method, $uri)->status(), [401, 403], "{$label} must not be reachable unauthenticated");
        }
        $this->assertContains($this->getJson("/api/crm/leads/1/conversion-value")->status(), [401, 403]);
        Http::assertNothingSent();
    }

    public function test_every_ads_endpoint_is_denied_for_each_missing_gate_and_sends_nothing_to_meta(): void
    {
        $noCapability = $this->tenant(['crm', 'social']);
        $moduleOff = $this->tenant();
        $moduleOff->forceFill(['allowed_modules' => ['campaigns']])->save();
        $suspended = $this->tenant();
        $suspended->forceFill(['status' => 'suspended'])->save();
        $noPermission = $this->tenant();
        Role::firstOrCreate(['name' => 'no_ads', 'guard_name' => 'web'])->syncPermissions(['view-social-analytics']);

        $scenarios = [
            'no ads capability (social is not enough)' => $this->user($noCapability),
            'meta_ads module disabled' => $this->user($moduleOff),
            'suspended account' => $this->user($suspended),
            'no ads permission' => $this->user($noPermission, 'no_ads'),
        ];

        foreach ($scenarios as $scenario => $actor) {
            $campaign = $this->campaign($actor->account);
            foreach ($this->endpoints($campaign) as $label => [$method, $uri]) {
                $this->assertContains($this->hit($actor, $method, $uri)->status(), [403, 401], "{$scenario}: {$label}");
            }
            $this->assertSame('ACTIVE', $campaign->fresh()->status, "{$scenario}: a denied pause must not change the campaign");
        }
        Http::assertNothingSent();
    }

    public function test_an_expired_subscription_keeps_every_read_and_blocks_every_write(): void
    {
        $account = $this->tenant();
        Subscription::where('account_id', $account->id)->update(['expires_at' => now()->subDay()]);
        $actor = $this->user($account);
        $campaign = $this->campaign($account);

        foreach ($this->endpoints($campaign) as $label => [$method, $uri, $isRead]) {
            $status = $this->hit($actor, $method, $uri)->status();
            $isRead ? $this->assertSame(200, $status, "{$label} read must work") : $this->assertSame(403, $status, "{$label} write must be refused");
        }
        $this->assertSame('ACTIVE', $campaign->fresh()->status);
        Http::assertNothingSent();
    }

    public function test_a_view_only_user_reads_but_never_writes(): void
    {
        $account = $this->tenant();
        Role::firstOrCreate(['name' => 'ads_viewer', 'guard_name' => 'web'])->syncPermissions(['social_ads.view']);
        $viewer = $this->user($account, 'ads_viewer');
        $campaign = $this->campaign($account);

        foreach ($this->endpoints($campaign) as $label => [$method, $uri, $isRead]) {
            $status = $this->hit($viewer, $method, $uri)->status();
            $isRead ? $this->assertSame(200, $status, $label) : $this->assertSame(403, $status, $label);
        }
        $this->assertSame('ACTIVE', $campaign->fresh()->status);
    }

    public function test_super_admin_needs_an_explicit_client_for_every_endpoint_and_never_gets_the_first_account(): void
    {
        $first = $this->tenant();
        $campaign = $this->campaign($first, 'First tenant campaign');
        $admin = $this->superAdmin();
        $this->app['env'] = 'local';

        foreach ($this->endpoints($campaign) as $label => [$method, $uri]) {
            $response = $this->hit($admin, $method, $uri);
            $this->assertSame(422, $response->status(), "{$label}: no client selected");
            $this->assertStringNotContainsString('First tenant campaign', $response->getContent());
        }
        $this->assertSame('ACTIVE', $campaign->fresh()->status);
    }

    public function test_super_admin_switching_clients_sees_exactly_the_selected_client_and_a_forged_account_is_404(): void
    {
        $admin = $this->superAdmin();
        $a = $this->tenant();
        $b = $this->tenant();
        $this->campaign($a, 'Alpha');
        $this->campaign($b, 'Beta');

        $this->actingAs($admin)->getJson("/api/social/ads/dashboard?account_id={$a->id}")->assertOk()
            ->assertJsonCount(1, 'data.campaigns')->assertJsonPath('data.campaigns.0.name', 'Alpha');
        $this->actingAs($admin)->getJson("/api/social/ads/dashboard?account_id={$b->id}")->assertOk()
            ->assertJsonCount(1, 'data.campaigns')->assertJsonPath('data.campaigns.0.name', 'Beta');
        $this->actingAs($admin)->getJson('/api/social/ads/dashboard?account_id=99999999')->assertNotFound();
        $this->actingAs($admin)->getJson('/api/social/ads?account_id=99999999')->assertNotFound();
    }

    public function test_a_client_cannot_reach_another_clients_data_by_forging_account_or_row_ids(): void
    {
        $mine = $this->tenant();
        $theirs = $this->tenant();
        $theirCampaign = $this->campaign($theirs, 'Theirs');
        $this->campaign($mine, 'Mine');
        $actor = $this->user($mine);

        // A forged account_id never changes the target of a client user.
        $response = $this->actingAs($actor)->getJson("/api/social/ads/dashboard?account_id={$theirs->id}");
        $this->assertStringNotContainsString('Theirs', $response->getContent());
        $this->assertStringNotContainsString('Theirs', $this->actingAs($actor)->getJson("/api/social/ads?account_id={$theirs->id}")->getContent());

        // Foreign campaign ids are 404 for every write, and nothing leaves for Meta.
        foreach (['pause', 'resume'] as $action) {
            $this->actingAs($actor)->postJson("/api/social/ads/{$theirCampaign->id}/{$action}")->assertNotFound();
        }
        $this->actingAs($actor)->patchJson("/api/social/ads/{$theirCampaign->id}/cpl-threshold", ['cpl_threshold' => 5])->assertNotFound();
        $this->assertSame('ACTIVE', $theirCampaign->fresh()->status);
        $this->assertNull($theirCampaign->fresh()->cpl_threshold);

        // Foreign CRM lead / attribution rows.
        $theirLead = CrmLead::factory()->forContact(Contact::factory()->forAccount($theirs)->create())->create();
        AdAttribution::create([
            'account_id' => $theirs->id, 'provider' => 'meta', 'channel' => 'whatsapp_ctwa', 'referral_message_id' => 'wamid.THEIRS',
            'ad_campaign_id' => $theirCampaign->id, 'crm_lead_id' => $theirLead->id, 'referral_received_at' => now()->subDay(),
        ]);
        $this->actingAs($actor)->getJson("/api/crm/leads/{$theirLead->id}/conversion-value")->assertNotFound();
        $this->actingAs($actor)->patchJson("/api/crm/leads/{$theirLead->id}/conversion-value", ['value' => 10, 'currency' => 'INR'])->assertNotFound();
        $this->assertNull(AdAttribution::sole()->conversion_value);
        $this->assertSame(0, $this->actingAs($actor)->getJson('/api/social/ads/attribution')->json('meta.total') ?? 0);
        $this->assertStringNotContainsString('wamid.THEIRS', $this->actingAs($actor)->getJson('/api/social/ads/attribution')->getContent());
        Http::assertNothingSent();
    }

    public function test_an_agent_reaches_its_own_clients_only(): void
    {
        $agent = $this->tenant(['crm', 'ads'], null, ['account_type' => 'agent']);
        $mine = $this->tenant();
        $mine->forceFill(['agent_id' => $agent->id])->save();
        $stranger = $this->tenant();
        $this->campaign($agent, 'Agent own');
        $this->campaign($mine, 'Agent client');
        $this->campaign($stranger, 'Stranger');
        $actor = $this->user($agent);

        $this->actingAs($actor)->getJson('/api/social/ads/dashboard')->assertOk()->assertJsonPath('data.campaigns.0.name', 'Agent own');
        $this->actingAs($actor)->getJson("/api/social/ads/dashboard?account_id={$mine->id}")->assertOk()->assertJsonPath('data.campaigns.0.name', 'Agent client');
        $this->actingAs($actor)->getJson("/api/social/ads/dashboard?account_id={$stranger->id}")->assertNotFound();
        $this->actingAs($actor)->getJson("/api/social/ads?account_id={$stranger->id}")->assertNotFound();
        $this->actingAs($actor)->postJson('/api/social/ads/'.AdCampaign::where('name', 'Stranger')->value('id').'/pause?account_id='.$stranger->id)->assertNotFound();
    }

    // ================================================================== end to end

    public function test_ctwa_webhook_to_dashboard_uses_one_set_of_canonical_records_and_survives_replays(): void
    {
        $account = $this->tenant(['crm', 'ads'], self::PHONE_ID);
        $this->journey($account);
        $campaign = $this->campaign($account, 'Monsoon', ['meta_ad_id' => 'AD-E2E']);
        $this->spend($campaign, 50.0);
        $actor = $this->user($account);

        // Referral + conversation + CRM lead + Journey, delivered twice (Meta redelivers).
        $this->ctwa('wamid.E1', '919876543210', 'AD-E2E')->assertOk();
        $this->ctwa('wamid.E1', '919876543210', 'AD-E2E')->assertOk();
        // A second, different person via the same ad.
        $this->ctwa('wamid.E2', '919876543299', 'AD-E2E')->assertOk();

        $this->assertSame(2, AdAttribution::count(), 'a redelivered webhook never creates a second row');
        $attribution = AdAttribution::where('referral_message_id', 'wamid.E1')->sole();
        $this->assertSame($campaign->id, $attribution->ad_campaign_id);
        $this->assertNotNull($attribution->crm_lead_id);
        $this->assertNotNull($attribution->journey_started_at);
        $lead = CrmLead::findOrFail($attribution->crm_lead_id);
        $firstTouch = $attribution->referral_received_at->toDateTimeString();

        // Convert through the CRM API, repeatedly (repeated lifecycle events).
        foreach (range(1, 2) as $_) {
            $this->actingAs($actor)->patchJson("/api/crm/leads/{$lead->id}/status", ['status' => 'converted'])->assertOk();
        }
        $this->actingAs($actor)->patchJson("/api/crm/leads/{$lead->id}/conversion-value", ['value' => 2500, 'currency' => 'INR'])->assertOk();
        $this->actingAs($actor)->patchJson("/api/crm/leads/{$lead->id}/conversion-value", ['value' => 2500, 'currency' => 'INR'])->assertOk();

        $this->assertSame($firstTouch, $attribution->fresh()->referral_received_at->toDateTimeString(), 'first-touch never moves');
        $this->assertSame(1, AdAttribution::whereNotNull('converted_at')->count());

        // A later duplicate delivery after conversion must not reset or double the conversion.
        $this->ctwa('wamid.E1', '919876543210', 'AD-E2E')->assertOk();
        $this->assertNotNull($attribution->fresh()->converted_at);
        $this->assertEquals(2500, $attribution->fresh()->conversion_value);

        $data = $this->actingAs($actor)->getJson('/api/social/ads/dashboard')->assertOk()->json('data');
        $row = collect($data['campaigns'])->firstWhere('id', $campaign->id);

        // Dashboard == canonical rows.
        $this->assertSame(AdAttribution::count(), $data['attribution']['referrals']);
        $this->assertSame(AdAttribution::whereNotNull('converted_at')->count(), $data['attribution']['conversions']);
        $this->assertEquals((float) AdAttribution::whereNotNull('converted_at')->sum('conversion_value'), $data['attribution']['conversion_value']['value']);
        $this->assertSame(2, $row['referrals']);
        $this->assertSame(1, $row['conversions']);
        $this->assertEquals(2500, $row['conversion_value']);
        $this->assertEquals(50.0, $row['spend']);
        $this->assertEquals(50.0, $data['spend']['total']['value']);
        $this->assertEquals(50.0, $row['roas']);

        // Campaign rows reconcile with the account totals (nothing unlinked here).
        $this->assertSame($data['attribution']['referrals'], collect($data['campaigns'])->sum('referrals') + $data['attribution']['unlinked_referrals']);
        $this->assertSame($data['attribution']['conversions'], collect($data['campaigns'])->sum('conversions'));

        // The attribution summary and the dashboard agree.
        $summary = $this->actingAs($actor)->getJson('/api/social/ads/attribution/summary')->assertOk()->json('data');
        $this->assertSame($data['attribution']['referrals'], $summary['totals']['referrals']);
        $this->assertSame($data['attribution']['conversions'], $summary['totals']['conversions']);

        // Daily series by conversion date: exactly one conversion today, value counted once.
        $today = collect($data['conversion_daily'])->firstWhere('date', now()->toDateString());
        $this->assertSame(1, $today['conversions']);
        $this->assertEquals(2500, $today['conversion_value']);
        $this->assertSame(1, collect($data['conversion_daily'])->sum('conversions'));
    }

    // ================================================================== reporting semantics

    public function test_zero_stays_zero_unknown_stays_null_and_mixed_currencies_are_never_summed(): void
    {
        $account = $this->tenant();
        $actor = $this->user($account);
        $campaign = $this->campaign($account, 'Mixed');
        $this->spend($campaign, 40.0);
        $leads = [];
        foreach ([['value' => 0, 'currency' => 'INR'], ['value' => 100, 'currency' => 'INR'], ['value' => 50, 'currency' => 'USD'], ['value' => null, 'currency' => null]] as $i => $v) {
            $lead = CrmLead::factory()->forContact(Contact::factory()->forAccount($account)->create(['phone_number' => '9199100000'.$i]))->create(['status' => CrmLead::STATUS_CONVERTED, 'converted_at' => now()]);
            AdAttribution::create([
                'account_id' => $account->id, 'provider' => 'meta', 'channel' => 'whatsapp_ctwa', 'referral_message_id' => "wamid.M{$i}", 'ad_campaign_id' => $campaign->id,
                'crm_lead_id' => $lead->id, 'referral_received_at' => now()->subDays(2), 'converted_at' => now(),
                'conversion_value' => $v['value'], 'conversion_currency' => $v['currency'],
            ]);
            $leads[] = $lead;
        }

        $data = $this->actingAs($actor)->getJson('/api/social/ads/dashboard')->assertOk()->json('data');

        $this->assertSame(4, $data['attribution']['conversions']);
        $this->assertNull($data['attribution']['conversion_value']['value'], 'INR + USD must not be added');
        $this->assertNull($data['attribution']['roas']['value'], 'no ROAS across mixed currencies');
        $this->assertNotNull(AdAttribution::where('referral_message_id', 'wamid.M0')->value('conversion_value'), 'a recorded 0 stays 0, not NULL');
        $this->assertNull(AdAttribution::where('referral_message_id', 'wamid.M3')->value('conversion_value'), 'unknown stays NULL');
    }

    public function test_a_campaign_without_spend_or_value_never_yields_roas_or_a_division_error(): void
    {
        $account = $this->tenant();
        $actor = $this->user($account);
        $noSpend = $this->campaign($account, 'No spend');
        $zeroValue = $this->campaign($account, 'Zero value');
        $this->spend($zeroValue, 30.0);
        foreach ([[$noSpend, 'wamid.Z0', 500], [$zeroValue, 'wamid.Z1', 0]] as $i => [$c, $wamid, $value]) {
            $lead = CrmLead::factory()->forContact(Contact::factory()->forAccount($account)->create(['phone_number' => '9199200000'.$i]))->create(['status' => CrmLead::STATUS_CONVERTED, 'converted_at' => now()]);
            AdAttribution::create([
                'account_id' => $account->id, 'provider' => 'meta', 'channel' => 'whatsapp_ctwa', 'referral_message_id' => $wamid, 'ad_campaign_id' => $c->id,
                'crm_lead_id' => $lead->id, 'referral_received_at' => now()->subDay(), 'converted_at' => now(), 'conversion_value' => $value, 'conversion_currency' => 'INR',
            ]);
        }

        $rows = collect($this->actingAs($actor)->getJson('/api/social/ads/dashboard')->assertOk()->json('data.campaigns'));

        $this->assertNull($rows->firstWhere('id', $noSpend->id)['roas'], 'no stored spend → no ROAS');
        $this->assertEquals(0, $rows->firstWhere('id', $zeroValue->id)['roas'], 'real spend with a real 0 value → ROAS 0');
        $this->assertEquals(0, $rows->firstWhere('id', $zeroValue->id)['conversion_value']);
    }

    public function test_the_dashboard_uses_a_bounded_number_of_queries_regardless_of_campaign_count(): void
    {
        $account = $this->tenant();
        $actor = $this->user($account);
        $count = function () use ($actor) {
            DB::flushQueryLog();
            DB::enableQueryLog();
            $this->actingAs($actor)->getJson('/api/social/ads/dashboard')->assertOk();
            $n = count(DB::getQueryLog());
            DB::disableQueryLog();

            return $n;
        };
        $this->campaign($account, 'One');
        $count(); // warm the permission / entitlement caches so only the dashboard's own queries are compared
        $few = $count();

        foreach (range(1, 15) as $i) {
            $c = $this->campaign($account, "Many {$i}");
            $this->spend($c, 5.0);
            $lead = CrmLead::factory()->forContact(Contact::factory()->forAccount($account)->create(['phone_number' => '9199300000'.str_pad((string) $i, 2, '0', STR_PAD_LEFT)]))->create();
            AdAttribution::create([
                'account_id' => $account->id, 'provider' => 'meta', 'channel' => 'whatsapp_ctwa', 'referral_message_id' => "wamid.Q{$i}", 'ad_campaign_id' => $c->id,
                'crm_lead_id' => $lead->id, 'referral_received_at' => now()->subDay(),
            ]);
        }

        $this->assertSame($few, $count(), 'query count must not grow with campaigns / attributions (no N+1)');
    }
}
