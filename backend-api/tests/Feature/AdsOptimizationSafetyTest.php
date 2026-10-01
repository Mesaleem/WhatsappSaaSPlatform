<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\AccountEntitlement;
use App\Models\AdAttribution;
use App\Models\AdCampaign;
use App\Models\AdCampaignDailyMetric;
use App\Models\Capability;
use App\Models\SocialAccount;
use App\Models\Subscription;
use Database\Seeders\Phase1FoundationSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Phase 10 Task 5 — audit of the CPL / zero-conversion auto-pause rule
 * (`ads:check-performance-rules`): stored/known metrics only, tenant-scoped,
 * idempotent, lifecycle-locked, never relaunches, and untouched by the new
 * conversion-value reporting (which only exposes data — no optimization is added).
 */
class AdsOptimizationSafetyTest extends TestCase
{
    use RefreshDatabase;

    private int $seq = 0;

    /** @var list<string> */
    private array $calls = [];

    /** @var array<string, array{0:int,1:array}> insights answers by meta campaign id */
    private array $insights = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        $this->seed(Phase1FoundationSeeder::class);

        Http::fake(function (Request $request) {
            $path = parse_url($request->url(), PHP_URL_PATH);
            $this->calls[] = $request->method().' '.$path;

            if (str_ends_with($path, '/insights')) {
                $id = basename(dirname($path));

                return Http::response(...array_reverse($this->insights[$id] ?? [200, ['data' => []]]));
            }

            return Http::response(['success' => true]);
        });
    }

    private function tenant(): Account
    {
        $account = Account::factory()->create();
        Subscription::factory()->create(['account_id' => $account->id]);
        AccountEntitlement::firstOrCreate(['account_id' => $account->id, 'capability_id' => Capability::where('slug', 'ads')->value('id')], ['source' => 'manual_grant']);
        SocialAccount::create([
            'account_id' => $account->id, 'provider' => 'meta', 'asset_type' => 'meta_ad_account', 'provider_id' => 'act_'.$account->id,
            'name' => 'Ad account', 'access_token' => 'EAA-'.$account->id, 'health_status' => SocialAccount::HEALTH_CONNECTED,
        ]);

        return $account->fresh();
    }

    private function campaign(Account $account, string $status = AdCampaign::STATUS_ACTIVE, array $attributes = []): AdCampaign
    {
        $n = ++$this->seq;

        return AdCampaign::create($attributes + [
            'account_id' => $account->id,
            'social_account_id' => SocialAccount::forAccount($account->id)->where('asset_type', 'meta_ad_account')->value('id'),
            'meta_campaign_id' => "OPT-CMP-{$n}", 'meta_adset_id' => "OPT-SET-{$n}", 'meta_ad_id' => "OPT-AD-{$n}",
            'name' => "Opt {$n}", 'objective' => 'CLICK_TO_WHATSAPP', 'status' => $status, 'daily_budget' => 5,
        ]);
    }

    private function meta(AdCampaign $c, string $spend, array $actions = []): void
    {
        $this->insights[$c->meta_campaign_id] = [200, ['data' => [['spend' => $spend, 'impressions' => '100', 'actions' => $actions]]]];
    }

    private function runRules(): void
    {
        $this->artisan('ads:check-performance-rules')->assertSuccessful();
    }

    /** @return list<string> */
    private function writes(): array
    {
        return array_values(array_filter($this->calls, fn (string $c) => str_starts_with($c, 'POST /v19.0/')));
    }

    public function test_only_active_campaigns_are_evaluated_and_none_is_ever_relaunched_or_resumed(): void
    {
        $account = $this->tenant();
        foreach ([AdCampaign::STATUS_PAUSED, AdCampaign::STATUS_UNCONFIRMED, AdCampaign::STATUS_UNAVAILABLE, AdCampaign::STATUS_FAILED, AdCampaign::STATUS_LAUNCHING] as $status) {
            $c = $this->campaign($account, $status);
            $this->meta($c, '99.00'); // would trip the rule if it were looked at
        }

        $this->runRules();

        $this->assertSame([], $this->calls, 'no Meta call at all for a campaign that is not ACTIVE');
        $this->assertEqualsCanonicalizing(
            [AdCampaign::STATUS_PAUSED, AdCampaign::STATUS_UNCONFIRMED, AdCampaign::STATUS_UNAVAILABLE, AdCampaign::STATUS_FAILED, AdCampaign::STATUS_LAUNCHING],
            AdCampaign::query()->pluck('status')->all(),
        );
        $this->assertSame(0, AdCampaignDailyMetric::count());
    }

    public function test_the_rule_fires_once_is_idempotent_and_never_resumes(): void
    {
        $account = $this->tenant();
        $campaign = $this->campaign($account);
        $this->meta($campaign, '9.00'); // spend above the 5.00 budget, no leads

        $this->runRules();
        $this->assertSame(AdCampaign::STATUS_PAUSED, $campaign->fresh()->status);
        $this->assertCount(1, $this->writes());
        $reason = $campaign->fresh()->auto_pause_reason;

        // Later runs, even with perfect insights: no second pause, no resume, no relaunch.
        $this->meta($campaign, '0.50', [['action_type' => 'lead', 'value' => '10']]);
        $this->runRules();
        $this->runRules();

        $this->assertSame(AdCampaign::STATUS_PAUSED, $campaign->fresh()->status);
        $this->assertSame($reason, $campaign->fresh()->auto_pause_reason);
        $this->assertCount(1, $this->writes(), 'exactly one Meta write across all runs');
    }

    public function test_an_unavailable_insight_is_never_treated_as_zero(): void
    {
        $account = $this->tenant();
        $campaign = $this->campaign($account, attributes: ['last_spend' => 3.5, 'last_leads' => 4]);
        $this->insights[$campaign->meta_campaign_id] = [500, ['error' => ['message' => 'boom']]];

        $this->runRules();

        $fresh = $campaign->fresh();
        $this->assertSame(AdCampaign::STATUS_ACTIVE, $fresh->status, 'no data → no action');
        $this->assertEquals(3.5, (float) $fresh->last_spend, 'the previous known figure is not overwritten with 0');
        $this->assertSame(4, (int) $fresh->last_leads);
        $this->assertSame(0, AdCampaignDailyMetric::count(), 'no stored daily row for a failed fetch');
        $this->assertSame([], $this->writes());
    }

    public function test_evaluation_is_tenant_scoped_and_stores_metrics_under_the_owning_account(): void
    {
        $a = $this->tenant();
        $b = $this->tenant();
        $trip = $this->campaign($a);
        $fine = $this->campaign($b);
        $this->meta($trip, '9.00');
        $this->meta($fine, '1.00', [['action_type' => 'lead', 'value' => '2']]);

        $this->runRules();

        $this->assertSame(AdCampaign::STATUS_PAUSED, $trip->fresh()->status);
        $this->assertSame(AdCampaign::STATUS_ACTIVE, $fine->fresh()->status, "another tenant's campaign is never affected");
        $this->assertSame($a->id, AdCampaignDailyMetric::where('ad_campaign_id', $trip->id)->value('account_id'));
        $this->assertSame($b->id, AdCampaignDailyMetric::where('ad_campaign_id', $fine->id)->value('account_id'));
        $this->assertSame(['POST /v19.0/'.$trip->meta_campaign_id], $this->writes());
    }

    public function test_conversion_values_and_attribution_do_not_influence_the_rule(): void
    {
        $account = $this->tenant();
        $campaign = $this->campaign($account, attributes: ['objective' => 'TRAFFIC']);
        // A converted, valued attribution row exists for the campaign…
        AdAttribution::create([
            'account_id' => $account->id, 'provider' => 'meta', 'channel' => 'whatsapp_ctwa', 'referral_message_id' => 'wamid.OPT',
            'ad_campaign_id' => $campaign->id, 'referral_received_at' => now(), 'converted_at' => now(), 'conversion_value' => 1000, 'conversion_currency' => 'INR',
        ]);
        // …but the rule still decides from Meta's stored insight figures only (no optimization algorithm was added).
        $this->meta($campaign, '9.00');

        $this->runRules();

        $this->assertSame(AdCampaign::STATUS_PAUSED, $campaign->fresh()->status);
        $this->assertSame('1000.00', AdAttribution::sole()->conversion_value, 'optimization never edits conversion data');
    }

    public function test_the_cpl_threshold_rule_needs_a_known_cpl(): void
    {
        $account = $this->tenant();
        $unknown = $this->campaign($account, attributes: ['cpl_threshold' => 1, 'daily_budget' => 100]);
        $known = $this->campaign($account, attributes: ['cpl_threshold' => 1, 'daily_budget' => 100]);
        $this->meta($unknown, '8.00');                                              // no leads → CPL unknown (null), not 0 and not infinite
        $this->meta($known, '8.00', [['action_type' => 'lead', 'value' => '2']]);   // CPL 4.00 > 1.00

        $this->runRules();

        $this->assertSame(AdCampaign::STATUS_ACTIVE, $unknown->fresh()->status);
        $this->assertNull($unknown->fresh()->last_cpl);
        $this->assertSame(AdCampaign::STATUS_PAUSED, $known->fresh()->status);
    }

    // ================================================================== Phase 10 Task 6 — CTWA metric

    private function referral(Account $account, AdCampaign $campaign, string $wamid, $at = null): void
    {
        AdAttribution::create([
            'account_id' => $account->id, 'provider' => 'meta', 'channel' => 'whatsapp_ctwa', 'referral_message_id' => $wamid,
            'ad_campaign_id' => $campaign->id, 'referral_received_at' => $at ?? now()->subHour(),
        ]);
    }

    public function test_a_ctwa_campaign_with_messaging_conversations_is_not_paused_as_zero_conversion(): void
    {
        $account = $this->tenant();
        $campaign = $this->campaign($account); // CLICK_TO_WHATSAPP
        // Meta reports conversations started, never Lead Ads `lead` actions, for this objective.
        $this->meta($campaign, '9.00', [['action_type' => 'onsite_conversion.messaging_conversation_started_7d', 'value' => '3']]);

        $this->runRules();

        $this->assertSame(AdCampaign::STATUS_ACTIVE, $campaign->fresh()->status, 'a healthy CTWA campaign is not judged by Lead Ads form leads');
        $this->assertSame([], $this->writes());
    }

    public function test_a_ctwa_campaign_with_a_recent_stored_referral_is_not_paused_but_a_genuine_zero_is(): void
    {
        $account = $this->tenant();
        $other = $this->tenant();
        $withReferral = $this->campaign($account);
        $stale = $this->campaign($account);
        $genuineZero = $this->campaign($account);
        $foreign = $this->campaign($account); // a referral belonging to ANOTHER tenant's campaign must not shield it
        $otherCampaign = $this->campaign($other);
        foreach ([$withReferral, $stale, $genuineZero, $foreign] as $c) {
            $this->meta($c, '9.00');
        }
        $this->referral($account, $withReferral, 'wamid.R1');
        $this->referral($account, $stale, 'wamid.R2', now()->subDays(2));
        $this->referral($other, $otherCampaign, 'wamid.R3');

        $this->runRules();

        $this->assertSame(AdCampaign::STATUS_ACTIVE, $withReferral->fresh()->status);
        $this->assertSame(AdCampaign::STATUS_PAUSED, $stale->fresh()->status, 'a referral older than 24 h proves nothing about today');
        $this->assertSame(AdCampaign::STATUS_PAUSED, $genuineZero->fresh()->status, 'no Meta result and no referral is a real zero');
        $this->assertSame(AdCampaign::STATUS_PAUSED, $foreign->fresh()->status, "another tenant's referral never shields this campaign");
    }

    public function test_a_ctwa_cost_per_result_uses_conversations_and_unknown_is_never_zero(): void
    {
        $account = $this->tenant();
        $over = $this->campaign($account, attributes: ['cpl_threshold' => 1, 'daily_budget' => 100]);
        $under = $this->campaign($account, attributes: ['cpl_threshold' => 10, 'daily_budget' => 100]);
        $none = $this->campaign($account, attributes: ['cpl_threshold' => 1, 'daily_budget' => 100]);
        $this->meta($over, '8.00', [['action_type' => 'onsite_conversion.messaging_conversation_started_7d', 'value' => '2']]);  // 4.00 per conversation > 1
        $this->meta($under, '8.00', [['action_type' => 'onsite_conversion.messaging_conversation_started_7d', 'value' => '2']]); // 4.00 < 10
        $this->meta($none, '8.00');                                                                                            // unknown cost per result → no CPL pause

        $this->runRules();

        $this->assertSame(AdCampaign::STATUS_PAUSED, $over->fresh()->status);
        $this->assertStringContainsString('CPL $4.00', $over->fresh()->auto_pause_reason);
        $this->assertSame(AdCampaign::STATUS_ACTIVE, $under->fresh()->status);
        $this->assertSame(AdCampaign::STATUS_ACTIVE, $none->fresh()->status);
    }

    public function test_other_objectives_keep_the_original_lead_ads_rule(): void
    {
        $account = $this->tenant();
        $leadGen = $this->campaign($account, attributes: ['objective' => 'LEAD_GENERATION']);
        $this->meta($leadGen, '9.00', [['action_type' => 'onsite_conversion.messaging_conversation_started_7d', 'value' => '5']]); // not a lead-gen result
        $traffic = $this->campaign($account, attributes: ['objective' => 'TRAFFIC']);
        $this->meta($traffic, '9.00', [['action_type' => 'lead', 'value' => '1']]);
        $this->referral($account, $leadGen, 'wamid.LG'); // referrals never shield a non-messaging campaign

        $this->runRules();

        $this->assertSame(AdCampaign::STATUS_PAUSED, $leadGen->fresh()->status);
        $this->assertSame(AdCampaign::STATUS_ACTIVE, $traffic->fresh()->status);
    }

    public function test_one_unreachable_campaign_never_stops_the_rest_of_the_batch(): void
    {
        $account = $this->tenant();
        $broken = $this->campaign($account);
        $this->insights[$broken->meta_campaign_id] = [500, ['error' => ['message' => 'Meta outage']]]; // its insights call fails
        $trip = $this->campaign($account);
        $this->meta($trip, '9.00');

        $this->runRules();

        $this->assertSame(AdCampaign::STATUS_ACTIVE, $broken->fresh()->status);
        $this->assertSame(AdCampaign::STATUS_PAUSED, $trip->fresh()->status);
    }

    public function test_the_guard_is_scheduled_every_15_minutes_with_an_expiring_overlap_lock(): void
    {
        $event = collect(app(Schedule::class)->events())->first(fn ($e) => str_contains((string) $e->command, 'ads:check-performance-rules'));

        $this->assertNotNull($event);
        $this->assertSame('*/15 * * * *', $event->expression);
        $this->assertTrue($event->withoutOverlapping);
        $this->assertSame(30, $event->expiresAt, 'a killed run must not disable the guard for the default 24 hours');
    }
}
