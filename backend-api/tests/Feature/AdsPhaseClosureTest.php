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
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Phase 10 Task 7 — final reconciliation: one deterministic fixture proves that
 * campaign rows + unlinked referrals = account totals for every additive metric,
 * and that every ratio is the documented function of those canonical rows.
 */
class AdsPhaseClosureTest extends TestCase
{
    use RefreshDatabase;

    private int $seq = 0;

    private Account $account;

    private User $actor;

    private WhatsAppFlow $flow;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        $this->seed(Phase1FoundationSeeder::class);
        Http::fake();
        $this->account = Account::factory()->create();
        Subscription::factory()->create(['account_id' => $this->account->id]);
        foreach (['crm', 'ads'] as $slug) {
            AccountEntitlement::create(['account_id' => $this->account->id, 'capability_id' => Capability::where('slug', $slug)->value('id'), 'source' => 'manual_grant']);
        }
        $this->actor = User::factory()->create(['account_id' => $this->account->id, 'is_active' => true]);
        $this->actor->assignRole('admin');
        $this->flow = WhatsAppFlow::create(['account_id' => $this->account->id, 'name' => 'J', 'trigger_type' => 'ctwa_referral', 'trigger_value' => '', 'is_active' => true, 'graph_data' => ['nodes' => [], 'edges' => []]]);
    }

    private function campaign(string $name, float $spend): AdCampaign
    {
        $n = ++$this->seq;
        $c = AdCampaign::create([
            'account_id' => $this->account->id, 'meta_campaign_id' => "CL-CMP-{$n}", 'meta_adset_id' => "CL-SET-{$n}", 'meta_ad_id' => "CL-AD-{$n}",
            'name' => $name, 'objective' => 'CLICK_TO_WHATSAPP', 'status' => 'ACTIVE', 'daily_budget' => 10, 'currency' => 'INR',
        ]);
        AdCampaignDailyMetric::create(['account_id' => $this->account->id, 'ad_campaign_id' => $c->id, 'metric_date' => now()->subDay()->toDateString(), 'spend' => $spend, 'impressions' => 1000, 'leads' => 0, 'cpl' => null]);

        return $c;
    }

    /**
     * @param  array{lead?: bool, journey?: bool, converted?: bool, value?: ?float, currency?: ?string}  $o
     */
    private function referral(?AdCampaign $campaign, string $phone, array $o = []): AdAttribution
    {
        $n = ++$this->seq;
        $lead = ($o['lead'] ?? false)
            ? CrmLead::factory()->forContact(Contact::factory()->forAccount($this->account)->create(['phone_number' => $phone]))->create(['source' => CrmLead::SOURCE_META_AD])
            : null;
        $session = ($o['journey'] ?? false)
            ? WhatsAppFlowSession::create(['account_id' => $this->account->id, 'flow_id' => $this->flow->id, 'phone_number' => $phone, 'status' => 'active', 'context_data' => []])
            : null;
        $converted = $o['converted'] ?? false;

        return AdAttribution::create([
            'account_id' => $this->account->id, 'provider' => 'meta', 'channel' => 'whatsapp_ctwa', 'source_type' => 'ad', 'source_id' => $campaign?->meta_ad_id ?? 'AD-UNLINKED',
            'referral_message_id' => "wamid.CL{$n}", 'contact_phone' => $phone, 'ad_campaign_id' => $campaign?->id, 'crm_lead_id' => $lead?->id,
            'flow_session_id' => $session?->id, 'journey_started_at' => $session ? now() : null, 'referral_received_at' => now()->subDays(2),
            'converted_at' => $converted ? now() : null, 'conversion_value' => $converted ? ($o['value'] ?? null) : null, 'conversion_currency' => $converted && ($o['value'] ?? null) !== null ? ($o['currency'] ?? 'INR') : null,
        ]);
    }

    private function dashboard(): array
    {
        return $this->actingAs($this->actor)->getJson('/api/social/ads/dashboard')->assertOk()->json('data');
    }

    public function test_campaign_totals_plus_unlinked_referrals_equal_account_totals_for_every_additive_metric(): void
    {
        $a = $this->campaign('A', 100.0);
        $b = $this->campaign('B', 50.0);
        // A: 3 referrals — converted valued 1000, converted with a REAL 0, plain referral.
        $this->referral($a, '9100000001', ['lead' => true, 'journey' => true, 'converted' => true, 'value' => 1000]);
        $this->referral($a, '9100000002', ['lead' => true, 'converted' => true, 'value' => 0]);
        $this->referral($a, '9100000003');
        // B: 2 referrals — converted with UNKNOWN value, plain referral.
        $this->referral($b, '9100000004', ['lead' => true, 'journey' => true, 'converted' => true, 'value' => null]);
        $this->referral($b, '9100000005');
        // Unlinked (no launcher campaign): one converted valued 500, one plain.
        $this->referral(null, '9100000006', ['lead' => true, 'converted' => true, 'value' => 500]);
        $this->referral(null, '9100000007');

        $d = $this->dashboard();
        $rows = collect($d['campaigns']);
        $att = $d['attribution'];
        $unlinked = AdAttribution::whereNull('ad_campaign_id');

        // ---- additive metrics: Σ campaigns + unlinked = account (and = canonical rows)
        foreach (['referrals' => 'count', 'leads' => 'count', 'journeys' => 'count', 'conversions' => 'count'] as $metric => $_) {
            $unlinkedCount = match ($metric) {
                'referrals' => (clone $unlinked)->count(),
                'leads' => (clone $unlinked)->whereNotNull('crm_lead_id')->count(),
                'journeys' => (clone $unlinked)->whereNotNull('flow_session_id')->count(),
                'conversions' => (clone $unlinked)->whereNotNull('converted_at')->count(),
            };
            $this->assertSame($att[$metric], $rows->sum($metric) + $unlinkedCount, "{$metric}: campaigns + unlinked = account");
        }
        $this->assertSame(7, $att['referrals']);
        $this->assertSame(4, $att['leads']);
        $this->assertSame(2, $att['journeys']);
        $this->assertSame(4, $att['conversions'], 'A 2 + B 1 + unlinked 1');
        $this->assertSame(7, $att['conversations'], 'seven distinct phones');
        $this->assertSame(7, $rows->sum('conversations') + (clone $unlinked)->distinct('contact_phone')->count('contact_phone'), 'disjoint phones are additive');
        $this->assertSame(2, $att['unlinked_referrals']);

        // ---- value: NULL stays NULL, 0 stays 0, nothing double counted
        $this->assertSame(3, $att['valued_conversions'], 'two valued in A (one of them a real 0) + one unlinked; B unknown');
        $this->assertEquals(1500, $att['conversion_value']['value'], '1000 + 0 + (unknown ignored) + 500');
        $this->assertEquals(1000, $rows->firstWhere('id', $a->id)['conversion_value']);
        $this->assertNull($rows->firstWhere('id', $b->id)['conversion_value'], 'a campaign whose only conversion has an unknown value reports NULL, not 0');
        $this->assertEquals((float) AdAttribution::whereNotNull('converted_at')->sum('conversion_value'), $att['conversion_value']['value']);

        // ---- spend and ratios: only launcher-linked spend and rows
        $this->assertEquals(150.0, $d['spend']['total']['value']);
        $this->assertEquals(150.0, $rows->sum('spend'));
        $this->assertEquals(round(150 / 3, 2), $att['cost_per_lead']['value'], 'linked leads: A 2 + B 1');
        $this->assertEquals(round(150 / 3, 2), $att['cost_per_conversion']['value'], 'linked conversions: A 2 + B 1');
        $this->assertEquals(round(1000 / 150, 4), $att['roas']['value'], 'linked value 1000 / linked spend 150 (unlinked 500 excluded)');
        $this->assertEquals(round(100 / 2, 2), $rows->firstWhere('id', $a->id)['cost_per_lead']);
        $this->assertEquals(10.0, $rows->firstWhere('id', $a->id)['roas']);
        $this->assertNull($rows->firstWhere('id', $b->id)['roas'], 'no recorded value → no ROAS');

        // ---- the series counts each conversion once, on the day it was recorded
        $this->assertSame(4, collect($d['conversion_daily'])->sum('conversions'));
        $this->assertEquals(1500, collect($d['conversion_daily'])->sum('conversion_value'));
    }

    public function test_the_same_phone_in_two_campaigns_is_counted_per_scope_a_documented_non_additive_case(): void
    {
        $a = $this->campaign('A', 10.0);
        $b = $this->campaign('B', 10.0);
        $this->referral($a, '9100000099');
        $this->referral($b, '9100000099');

        $d = $this->dashboard();

        $this->assertSame(1, $d['attribution']['conversations'], 'one person');
        $this->assertSame(2, collect($d['campaigns'])->sum('conversations'), 'one person per campaign');
        $this->assertSame(2, $d['attribution']['referrals'], 'referrals stay additive');
    }

    public function test_an_account_with_nothing_has_nulls_and_never_divides_by_zero(): void
    {
        $this->campaign('Idle', 0.0); // spend recorded as a real zero

        $d = $this->dashboard();

        $this->assertSame(0, $d['attribution']['referrals']);
        $this->assertNull($d['attribution']['conversion_rate']['value']);
        $this->assertNull($d['attribution']['cost_per_lead']['value']);
        $this->assertNull($d['attribution']['cost_per_conversion']['value']);
        $this->assertNull($d['attribution']['roas']['value']);
        $this->assertNull($d['attribution']['conversion_value']['value']);
    }

    public function test_mixed_currency_values_stay_unaggregated_at_account_and_campaign_level(): void
    {
        $a = $this->campaign('A', 100.0);
        $this->referral($a, '9100000011', ['lead' => true, 'converted' => true, 'value' => 100, 'currency' => 'INR']);
        $this->referral($a, '9100000012', ['lead' => true, 'converted' => true, 'value' => 5, 'currency' => 'USD']);

        $d = $this->dashboard();
        $row = collect($d['campaigns'])->firstWhere('id', $a->id);

        $this->assertSame(2, $d['attribution']['conversions']);
        $this->assertNull($d['attribution']['conversion_value']['value']);
        $this->assertSame('mixed_currency', $d['attribution']['conversion_value_issue']);
        $this->assertNull($d['attribution']['roas']['value']);
        $this->assertNull($row['conversion_value']);
        $this->assertNull($row['roas']);
        $this->assertEquals(50.0, $d['attribution']['cost_per_conversion']['value'], 'counts are still valid');
    }
}
