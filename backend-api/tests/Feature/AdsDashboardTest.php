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
use App\Models\Subscription;
use App\Models\User;
use App\Models\WhatsAppFlow;
use App\Models\WhatsAppFlowSession;
use Database\Seeders\Phase1FoundationSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Phase 10 Task 3 — Ads dashboard (GET /api/social/ads/dashboard): stored
 * campaign / spend / attribution data only, target-account scoped, the Ads
 * gates (permission, meta_ads, ads, target), unknown values reported as NULL
 * with a status instead of 0, ROAS only with real spend and value, a fixed
 * number of queries, never a Meta call.
 */
class AdsDashboardTest extends TestCase
{
    use RefreshDatabase;

    private const URL = '/api/social/ads/dashboard';

    private int $seq = 0;

    /** @var array<int, WhatsAppFlow> */
    private array $flows = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        $this->seed(Phase1FoundationSeeder::class);
        Http::fake();
    }

    // ------------------------------------------------------------------ fixtures

    private function tenant(array $grants = ['ads']): Account
    {
        $account = Account::factory()->create();
        Subscription::factory()->create(['account_id' => $account->id]);
        foreach ($grants as $slug) {
            AccountEntitlement::firstOrCreate(
                ['account_id' => $account->id, 'capability_id' => Capability::where('slug', $slug)->value('id')],
                ['source' => 'manual_grant'],
            );
        }

        return $account->fresh();
    }

    private function user(Account $account, string $role = 'social_marketer'): User
    {
        $user = User::factory()->create(['account_id' => $account->id, 'is_active' => true]);
        $user->assignRole($role);

        return $user;
    }

    private function viewer(Account $account): User
    {
        Role::firstOrCreate(['name' => 'ads_viewer', 'guard_name' => 'web'])->syncPermissions(['social_ads.view']);

        return $this->user($account, 'ads_viewer');
    }

    private function superAdmin(): User
    {
        $user = User::factory()->create(['account_id' => null, 'is_active' => true]);
        $user->assignRole('super_admin');

        return $user;
    }

    private function campaign(Account $account, string $name, string $status = AdCampaign::STATUS_ACTIVE, array $attributes = []): AdCampaign
    {
        $n = ++$this->seq;

        return AdCampaign::create($attributes + [
            'account_id' => $account->id, 'meta_campaign_id' => "CMP-{$n}", 'meta_adset_id' => "SET-{$n}", 'meta_ad_id' => "AD-{$n}",
            'name' => $name, 'objective' => 'CLICK_TO_WHATSAPP', 'status' => $status, 'daily_budget' => 10,
        ]);
    }

    private function spend(AdCampaign $campaign, string $date, float $spend, int $impressions = 100): void
    {
        AdCampaignDailyMetric::create([
            'account_id' => $campaign->account_id, 'ad_campaign_id' => $campaign->id, 'metric_date' => $date,
            'spend' => $spend, 'impressions' => $impressions, 'leads' => 0, 'cpl' => null,
        ]);
    }

    /**
     * One click-to-WhatsApp referral, optionally carried to lead / journey / conversion.
     *
     * @param array{lead?: bool, journey?: bool, converted?: bool, value?: float|null, at?: string, source?: string} $o
     */
    private function referral(Account $account, ?AdCampaign $campaign, string $phone, array $o = []): AdAttribution
    {
        $n = ++$this->seq;
        $crmLead = null;
        if (($o['lead'] ?? false) || ($o['converted'] ?? false)) {
            $crmLead = CrmLead::factory()->forContact(Contact::factory()->forAccount($account)->create(['phone_number' => $phone.$n]))->create();
        }
        $session = null;
        if ($o['journey'] ?? false) {
            $this->flows[$account->id] ??= WhatsAppFlow::create([
                'account_id' => $account->id, 'name' => 'Ad journey', 'trigger_type' => 'ctwa_referral', 'trigger_value' => '', 'is_active' => true,
                'graph_data' => ['nodes' => [['id' => 't', 'type' => 'trigger', 'position' => ['x' => 0, 'y' => 0], 'data' => []]], 'edges' => []],
            ]);
            $session = WhatsAppFlowSession::create(['account_id' => $account->id, 'flow_id' => $this->flows[$account->id]->id, 'phone_number' => $phone, 'status' => 'active', 'context_data' => []]);
        }

        return AdAttribution::create([
            'account_id' => $account->id, 'provider' => AdAttribution::PROVIDER_META, 'channel' => AdAttribution::CHANNEL_WHATSAPP_CTWA,
            'source_type' => 'ad', 'source_id' => $o['source'] ?? $campaign?->meta_ad_id ?? 'AD-EXTERNAL',
            'referral_message_id' => "wamid.{$n}", 'contact_phone' => $phone, 'ad_campaign_id' => $campaign?->id,
            'crm_lead_id' => $crmLead?->id, 'flow_session_id' => $session?->id,
            'referral_received_at' => $o['at'] ?? now()->subDay(),
            'converted_at' => ($o['converted'] ?? false) ? now() : null,
            'conversion_value' => $o['value'] ?? null,
        ]);
    }

    private function dashboard(User $actor, string $query = '')
    {
        return $this->actingAs($actor)->getJson(self::URL.$query);
    }

    // ================================================================== authorization

    public function test_permission_module_and_capability_are_required(): void
    {
        $noAds = $this->tenant(grants: ['social']);
        $this->dashboard($this->user($noAds))->assertForbidden()->assertJsonPath('error_code', 'CAPABILITY_NOT_ENTITLED');

        $moduleOff = $this->tenant();
        $moduleOff->forceFill(['allowed_modules' => ['campaigns']])->save();
        $this->dashboard($this->user($moduleOff))->assertForbidden();

        $account = $this->tenant();
        Role::firstOrCreate(['name' => 'no_ads', 'guard_name' => 'web'])->syncPermissions(['view-social-analytics']);
        $this->dashboard($this->user($account, 'no_ads'))->assertForbidden();

        Http::assertNothingSent();
    }

    public function test_a_view_only_ads_user_can_read_the_dashboard(): void
    {
        $account = $this->tenant();
        $this->campaign($account, 'Monsoon');

        $this->dashboard($this->viewer($account))->assertOk()->assertJsonPath('data.campaigns.0.name', 'Monsoon');
    }

    public function test_an_expired_subscription_can_still_read_like_the_campaign_list(): void
    {
        $account = $this->tenant();
        Subscription::where('account_id', $account->id)->update(['expires_at' => now()->subDay()]);

        $this->dashboard($this->user($account))->assertOk();
    }

    public function test_super_admin_must_select_a_client_and_never_gets_the_first_account(): void
    {
        $first = $this->tenant();
        $this->campaign($first, 'First tenant campaign');
        $this->app['env'] = 'local';

        $this->dashboard($this->superAdmin())->assertStatus(422)->assertJsonMissing(['name' => 'First tenant campaign']);
    }

    public function test_super_admin_reads_only_the_selected_client_and_is_held_to_its_limits(): void
    {
        $admin = $this->superAdmin();
        $client = $this->tenant();
        $other = $this->tenant();
        $this->campaign($client, 'Client campaign');
        $this->campaign($other, 'Other campaign');

        $this->dashboard($admin, "?account_id={$client->id}")->assertOk()
            ->assertJsonCount(1, 'data.campaigns')->assertJsonPath('data.campaigns.0.name', 'Client campaign');

        $suspended = $this->tenant();
        $suspended->forceFill(['status' => 'suspended'])->save();
        $this->dashboard($admin, "?account_id={$suspended->id}")->assertForbidden()->assertJsonPath('error_code', 'CLIENT_ACCOUNT_SUSPENDED');

        $noAds = $this->tenant(grants: []);
        // Owner decision (2026-09-30): the client's plan does not bind a Super Admin.
        $this->dashboard($admin, "?account_id={$noAds->id}")->assertOk();

        $moduleOff = $this->tenant();
        $moduleOff->forceFill(['allowed_modules' => ['campaigns']])->save();
        $this->dashboard($admin, "?account_id={$moduleOff->id}")->assertForbidden()->assertJsonPath('error_code', 'MODULE_DISABLED');
    }

    public function test_tenant_isolation_including_a_shared_meta_ad_id(): void
    {
        $mine = $this->tenant();
        $theirs = $this->tenant();
        $myCampaign = $this->campaign($mine, 'Mine', attributes: ['meta_ad_id' => 'AD-SHARED']);
        $theirCampaign = $this->campaign($theirs, 'Theirs', attributes: ['meta_ad_id' => 'AD-SHARED']);
        $this->spend($theirCampaign, now()->subDay()->toDateString(), 500);
        $this->referral($theirs, $theirCampaign, '919800000001', ['lead' => true, 'converted' => true]);
        $this->referral($theirs, null, '919800000002', ['source' => 'AD-SHARED']);

        $response = $this->dashboard($this->user($mine), "?account_id={$theirs->id}")->assertOk();

        $response->assertJsonPath('data.attribution.referrals', 0)
            ->assertJsonPath('data.spend.total.value', null)
            ->assertJsonPath('data.campaigns_summary.total', 1)
            ->assertJsonPath('data.campaigns.0.id', $myCampaign->id)
            ->assertJsonPath('data.campaigns.0.referrals', 0);
        $response->assertJsonMissing(['name' => 'Theirs']);
    }

    // ================================================================== ranges

    public function test_preset_and_custom_ranges_bound_the_data(): void
    {
        $account = $this->tenant();
        $campaign = $this->campaign($account, 'C');
        $this->spend($campaign, now()->subDays(3)->toDateString(), 10);
        $this->spend($campaign, now()->subDays(20)->toDateString(), 20);
        $this->spend($campaign, now()->subDays(60)->toDateString(), 40);
        $this->referral($account, $campaign, '919800000001', ['at' => now()->subDays(3)->toDateTimeString()]);
        $this->referral($account, $campaign, '919800000002', ['at' => now()->subDays(60)->toDateTimeString()]);
        $user = $this->user($account);

        $this->dashboard($user, '?range=7d')->assertJsonPath('data.range.days', 7)->assertJsonPath('data.spend.total.value', 10)->assertJsonPath('data.attribution.referrals', 1);
        $this->dashboard($user, '?range=30d')->assertJsonPath('data.spend.total.value', 30);
        $this->dashboard($user, '?range=90d')->assertJsonPath('data.spend.total.value', 70)->assertJsonPath('data.attribution.referrals', 2);
        $this->dashboard($user)->assertJsonPath('data.range.days', 30);

        $from = now()->subDays(21)->toDateString();
        $to = now()->subDays(20)->toDateString();
        $this->dashboard($user, "?range=custom&from={$from}&to={$to}")
            ->assertJsonPath('data.range.from', $from)->assertJsonPath('data.range.to', $to)
            ->assertJsonPath('data.spend.total.value', 20)->assertJsonCount(2, 'data.spend.daily');
    }

    public function test_invalid_custom_ranges_are_rejected(): void
    {
        $user = $this->user($this->tenant());
        $today = now()->toDateString();

        $this->dashboard($user, '?range=custom')->assertStatus(422);
        $this->dashboard($user, '?range=365d')->assertStatus(422);
        $this->dashboard($user, "?range=custom&from={$today}&to=".now()->subDay()->toDateString())->assertStatus(422);
        $this->dashboard($user, '?range=custom&from='.now()->subDays(400)->toDateString()."&to={$today}")->assertStatus(422);
        $this->dashboard($user, "?range=custom&from={$today}&to=".now()->addDay()->toDateString())->assertStatus(422);
        $this->dashboard($user, '?range=custom&from=2026-13-01&to=2026-13-02')->assertStatus(422);
    }

    // ================================================================== aggregation

    public function test_spend_attribution_funnel_and_campaign_breakdown(): void
    {
        $account = $this->tenant();
        $a = $this->campaign($account, 'Alpha');
        $b = $this->campaign($account, 'Beta', AdCampaign::STATUS_PAUSED);
        $yesterday = now()->subDay()->toDateString();
        $this->spend($a, $yesterday, 30, 1000);
        $this->spend($a, now()->subDays(2)->toDateString(), 10, 500);
        $this->spend($b, $yesterday, 5, 200);

        // Alpha: 4 referrals from 3 phones; 2 leads; 1 journey; 1 conversion.
        $this->referral($account, $a, '919800000001', ['lead' => true, 'journey' => true, 'converted' => true]);
        $this->referral($account, $a, '919800000001');
        $this->referral($account, $a, '919800000002', ['lead' => true]);
        $this->referral($account, $a, '919800000003');
        // An ad not created by the launcher.
        $this->referral($account, null, '919800000004', ['lead' => true, 'source' => 'AD-EXTERNAL']);

        $data = $this->dashboard($this->user($account))->assertOk()->json('data');

        $this->assertSame(['total' => 2, 'active' => 1, 'paused' => 1, 'launching' => 0, 'failed' => 0, 'unconfirmed' => 0, 'unavailable' => 0], $data['campaigns_summary']);
        $this->assertEquals(['value' => 45, 'status' => 'available'], $data['spend']['total']);
        $this->assertEquals(['value' => 1700, 'status' => 'available'], $data['spend']['impressions']);
        $this->assertEquals(35, collect($data['spend']['daily'])->firstWhere('date', $yesterday)['spend']);
        $this->assertNull(collect($data['spend']['daily'])->firstWhere('date', now()->subDays(5)->toDateString())['spend'], 'a day with no stored insights is null, not 0');

        $this->assertSame([2, 5, 4, 3, 1, 1], array_column($data['funnel'], 'count'));
        $this->assertSame(['ads', 'referrals', 'conversations', 'leads', 'journeys', 'conversions'], array_column($data['funnel'], 'stage'));
        $this->assertSame(1, $data['attribution']['unlinked_referrals']);
        $this->assertEquals(['value' => 0.2, 'status' => 'available'], $data['attribution']['conversion_rate']);
        // CPL uses launcher-linked leads only: Alpha 2 leads over 40 spend (Beta has spend but no leads) = 40 / 2.
        $this->assertEquals(['value' => 20, 'status' => 'available'], $data['attribution']['cost_per_lead']);

        $alpha = collect($data['campaigns'])->firstWhere('name', 'Alpha');
        $this->assertEquals(40, $alpha['spend']);
        $this->assertSame('available', $alpha['spend_status']);
        $this->assertSame([4, 3, 2, 1, 1], [$alpha['referrals'], $alpha['conversations'], $alpha['leads'], $alpha['journeys'], $alpha['conversions']]);
        $this->assertEquals(0.25, $alpha['conversion_rate']);
        $this->assertEquals(20, $alpha['cost_per_lead']);
        $beta = collect($data['campaigns'])->firstWhere('name', 'Beta');
        $this->assertSame(0, $beta['referrals']);
        $this->assertNull($beta['conversion_rate']);
        $this->assertNull($beta['cost_per_lead']);
    }

    public function test_null_is_never_reported_as_zero(): void
    {
        $account = $this->tenant();
        $user = $this->user($account);

        // No campaigns, no attribution.
        $empty = $this->dashboard($user)->assertOk()->json('data');
        $this->assertEquals(['value' => null, 'status' => 'not_applicable'], $empty['spend']['total']);
        $this->assertSame(0, $empty['attribution']['referrals']);
        $this->assertEquals(['value' => null, 'status' => 'not_applicable'], $empty['attribution']['conversion_rate']);
        $this->assertEquals(['value' => null, 'status' => 'unavailable'], $empty['attribution']['conversion_value']);
        $this->assertEquals(['value' => null, 'status' => 'unavailable'], $empty['attribution']['roas']);
        $this->assertSame([], $empty['campaigns']);

        // Campaigns without stored insights; a launch that never reached Meta; one Meta reports gone.
        $noInsights = $this->campaign($account, 'No insights');
        $this->campaign($account, 'Failed', AdCampaign::STATUS_FAILED, ['meta_campaign_id' => null, 'meta_adset_id' => null, 'meta_ad_id' => null]);
        $this->campaign($account, 'Gone', AdCampaign::STATUS_UNAVAILABLE);
        $this->campaign($account, 'Lost', AdCampaign::STATUS_UNCONFIRMED, ['meta_adset_id' => null, 'meta_ad_id' => null]);
        $this->referral($account, $noInsights, '919800000001', ['lead' => true]);

        $data = $this->dashboard($user)->assertOk()->json('data');
        $this->assertEquals(['value' => null, 'status' => 'not_fetched'], $data['spend']['total']);
        $this->assertEquals(['value' => null, 'status' => 'not_fetched'], $data['attribution']['cost_per_lead']);
        $rows = collect($data['campaigns'])->keyBy('name');
        $this->assertSame([null, 'not_fetched'], [$rows['No insights']['spend'], $rows['No insights']['spend_status']]);
        $this->assertSame([null, 'not_applicable'], [$rows['Failed']['spend'], $rows['Failed']['spend_status']]);
        $this->assertSame([null, 'unavailable'], [$rows['Gone']['spend'], $rows['Gone']['spend_status']]);
        $this->assertSame('not_fetched', $rows['Lost']['spend_status']);
        $this->assertNull($rows['No insights']['cost_per_lead']);
        $this->assertSame(['failed' => 1, 'unconfirmed' => 1, 'unavailable' => 1], array_intersect_key($data['campaigns_summary'], array_flip(['failed', 'unconfirmed', 'unavailable'])));
    }

    public function test_roas_only_with_real_spend_and_real_conversion_value(): void
    {
        $account = $this->tenant();
        $withSpend = $this->campaign($account, 'Spend');
        $noSpend = $this->campaign($account, 'No spend');
        $this->spend($withSpend, now()->subDay()->toDateString(), 50);
        $user = $this->user($account);

        // Conversions without a value → no ROAS.
        $this->referral($account, $withSpend, '919800000001', ['converted' => true]);
        $data = $this->dashboard($user)->json('data');
        $this->assertEquals(['value' => null, 'status' => 'unavailable'], $data['attribution']['roas']);
        $this->assertNull(collect($data['campaigns'])->firstWhere('name', 'Spend')['roas']);

        // A recorded value without stored spend → still no ROAS.
        $this->referral($account, $noSpend, '919800000002', ['converted' => true, 'value' => 300]);
        $rows = collect($this->dashboard($user)->json('data.campaigns'))->keyBy('name');
        $this->assertEquals(300, $rows['No spend']['conversion_value']);
        $this->assertNull($rows['No spend']['roas']);

        // Real spend + real value → ROAS.
        $this->referral($account, $withSpend, '919800000003', ['converted' => true, 'value' => 200]);
        $data = $this->dashboard($user)->json('data');
        $this->assertEquals(['value' => 4, 'status' => 'available'], $data['attribution']['roas']);
        $this->assertEquals(['value' => 500, 'status' => 'available'], $data['attribution']['conversion_value']);
        $this->assertEquals(4, collect($data['campaigns'])->firstWhere('name', 'Spend')['roas']);
    }

    // ================================================================== performance / provider

    public function test_the_query_count_does_not_grow_with_campaigns_or_referrals(): void
    {
        $account = $this->tenant();
        $user = $this->user($account);
        $first = $this->campaign($account, 'Only');
        $this->spend($first, now()->subDay()->toDateString(), 5);
        $this->referral($account, $first, '919800000001', ['lead' => true]);

        $count = function () use ($user) {
            DB::flushQueryLog();
            DB::enableQueryLog();
            $this->dashboard($user)->assertOk();
            $n = count(DB::getQueryLog());
            DB::disableQueryLog();

            return $n;
        };
        $count(); // warm the per-request auth / permission caches first
        $small = $count();

        for ($i = 0; $i < 15; $i++) {
            $c = $this->campaign($account, "More {$i}");
            $this->spend($c, now()->subDay()->toDateString(), 1);
            $this->referral($account, $c, '91980000100'.$i, ['lead' => true, 'converted' => true]);
        }

        $this->assertSame($small, $count());
    }

    public function test_dashboard_reads_never_call_meta(): void
    {
        $account = $this->tenant();
        $campaign = $this->campaign($account, 'C');
        $this->spend($campaign, now()->subDay()->toDateString(), 5);
        $this->referral($account, $campaign, '919800000001');

        $this->dashboard($this->user($account))->assertOk();
        $this->dashboard($this->superAdmin(), "?account_id={$account->id}&range=90d")->assertOk();

        Http::assertNothingSent();
    }
}
