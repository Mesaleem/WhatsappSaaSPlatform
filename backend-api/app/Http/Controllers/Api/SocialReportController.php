<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Concerns\ResolvesTenantAccount;
use App\Http\Controllers\Controller;
use App\Models\Account;
use App\Models\AdCampaign;
use App\Models\AdCampaignDailyMetric;
use App\Models\Lead;
use App\Services\Pdf\SimplePdfWriter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Carbon;

/**
 * Social Media Marketing & Meta Ads Automation Expansion — Final Phase.
 * White-Label Automated PDF Reporting.
 *
 * ROOT-CAUSE FINDING — TWO DISTINCT "leads" NUMBERS, deliberately NOT
 * blended into one: `leads` (Phase 2's Instant Lead Bridge table) is
 * every Meta Lead Ads submission this app actually received via webhook,
 * regardless of which campaign (or whether one launched through THIS
 * app at all) generated it. `ad_campaign_daily_metrics.leads` is Meta's
 * OWN Insights-API-attributed conversion count for campaigns launched
 * through MetaAdsService specifically (see that table's migration
 * docblock for why it exists). These can legitimately differ. Mixing
 * them into a single "leads" figure would produce a number with no
 * coherent definition, so this report shows both, separately labeled:
 * `total_leads_generated` (the real Instant Lead Bridge count) and
 * `meta_attributed_leads` (the campaign-attributed count, which is what
 * `average_cpl` is actually computed against — spend and leads MUST come
 * from the same source for a CPL ratio to be meaningful, so average_cpl
 * = SUM(ad_campaign_daily_metrics.spend) / SUM(ad_campaign_daily_metrics.leads),
 * never mixed with the Instant Lead Bridge count).
 *
 * `combined_impressions` is the closest REAL field this app stores to
 * "Combined Social Reach" — Meta's Insights API distinguishes impressions
 * (total ad views, can double-count the same person) from reach (unique
 * people), and only `impressions` is fetched/stored anywhere in this
 * schema (MetaAdsService::fetchInsights()'s `fields` param has no
 * `reach`). Labeled accordingly rather than silently presenting
 * impressions AS reach.
 */
class SocialReportController extends Controller
{
    use ResolvesTenantAccount;

    /** GET /api/social/reports/summary — JSON, for SocialReportsPage's recharts. */
    public function summary(Request $request): JsonResponse
    {
        $account = $this->requireAccount($request);
        [$from, $to, $label] = $this->resolvePeriod($request);

        return response()->json(['data' => $this->buildReportData($account, $from, $to, $label)]);
    }

    /** GET /api/social/reports/generate — the literal spec endpoint, PDF bytes. */
    public function generate(Request $request): Response
    {
        $account = $this->requireAccount($request);
        [$from, $to, $label] = $this->resolvePeriod($request);
        $data = $this->buildReportData($account, $from, $to, $label);

        $pdf = SimplePdfWriter::renderBrandedReport(
            $account->company_name,
            $account->brand_accent_color,
            $this->reportLines($account, $data)
        );

        $filename = sprintf('social-report-%d-%s.pdf', $account->id, str_replace(' ', '-', $label));

        return response($pdf, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="'.$filename.'"',
            'Content-Length' => (string) strlen($pdf),
        ]);
    }

    /**
     * @return array{0: string, 1: string, 2: string} [from, to, label] — from/to are 'Y-m-d', label is e.g. "September 2026".
     */
    private function resolvePeriod(Request $request): array
    {
        $validated = $request->validate([
            'month' => ['sometimes', 'regex:/^\d{4}-(0[1-9]|1[0-2])$/'],
        ]);

        $date = isset($validated['month'])
            ? Carbon::createFromFormat('Y-m-d', $validated['month'].'-01')
            : now();

        $date = $date->copy()->startOfMonth();

        return [$date->toDateString(), $date->copy()->endOfMonth()->toDateString(), $date->format('F Y')];
    }

    /**
     * @return array<string, mixed>
     */
    private function buildReportData(Account $account, string $from, string $to, string $label): array
    {
        $metricsBase = fn () => AdCampaignDailyMetric::query()->forAccount($account->id)->betweenDates($from, $to);

        $totals = $metricsBase()
            ->selectRaw('COALESCE(SUM(spend),0) as total_spend, COALESCE(SUM(impressions),0) as total_impressions, COALESCE(SUM(leads),0) as total_meta_leads')
            ->first();

        $totalSpend = (float) $totals->total_spend;
        $metaAttributedLeads = (int) $totals->total_meta_leads;
        $combinedImpressions = (int) $totals->total_impressions;
        $averageCpl = $metaAttributedLeads > 0 ? round($totalSpend / $metaAttributedLeads, 2) : null;

        $totalLeadsGenerated = Lead::query()
            ->forAccount($account->id)
            ->whereBetween('created_at', [$from.' 00:00:00', $to.' 23:59:59'])
            ->count();

        $perCampaign = $metricsBase()
            ->selectRaw('ad_campaign_id, SUM(spend) as spend, SUM(leads) as leads')
            ->groupBy('ad_campaign_id')
            ->orderByDesc('leads')
            ->limit(5)
            ->get();

        $campaignNames = AdCampaign::query()
            ->whereIn('id', $perCampaign->pluck('ad_campaign_id'))
            ->pluck('name', 'id');

        $topCampaigns = $perCampaign->map(function ($row) use ($campaignNames) {
            $spend = (float) $row->spend;
            $leads = (int) $row->leads;

            return [
                'name' => $campaignNames[$row->ad_campaign_id] ?? 'Unknown campaign',
                'spend' => $spend,
                'leads' => $leads,
                'cpl' => $leads > 0 ? round($spend / $leads, 2) : null,
            ];
        })->values()->all();

        $dailySeries = $metricsBase()
            ->selectRaw('metric_date, SUM(spend) as spend, SUM(leads) as leads')
            ->groupBy('metric_date')
            ->orderBy('metric_date')
            ->get()
            ->map(fn ($row) => [
                'date' => $row->metric_date->toDateString(),
                'spend' => (float) $row->spend,
                'leads' => (int) $row->leads,
            ])
            ->values()
            ->all();

        return [
            'period' => ['from' => $from, 'to' => $to, 'label' => $label],
            'total_ad_spend' => $totalSpend,
            'total_leads_generated' => $totalLeadsGenerated,
            'meta_attributed_leads' => $metaAttributedLeads,
            'average_cpl' => $averageCpl,
            'combined_impressions' => $combinedImpressions,
            'top_campaigns' => $topCampaigns,
            'daily_series' => $dailySeries,
        ];
    }

    /**
     * @param array<string, mixed> $data
     * @return list<string|null>
     */
    private function reportLines(Account $account, array $data): array
    {
        $lines = [
            'Monthly Social Performance Report',
            'Period: '.$data['period']['label'],
            '',
            'Total Ad Spend: $'.number_format($data['total_ad_spend'], 2),
            'Total Leads Generated (Instant Lead Bridge): '.$data['total_leads_generated'],
            'Meta-Attributed Conversions (launched campaigns): '.$data['meta_attributed_leads'],
            'Average Cost Per Lead: '.($data['average_cpl'] !== null
                ? '$'.number_format($data['average_cpl'], 2)
                : 'N/A (no attributed conversions this period)'),
            'Combined Social Reach (ad impressions): '.number_format($data['combined_impressions']),
            '',
            'Top Performing Ad Campaigns',
        ];

        if (empty($data['top_campaigns'])) {
            $lines[] = 'No campaign activity recorded for this period yet.';
        } else {
            foreach ($data['top_campaigns'] as $i => $campaign) {
                $lines[] = sprintf(
                    '%d. %s - Spend $%s, Leads %d, CPL %s',
                    $i + 1,
                    $campaign['name'],
                    number_format($campaign['spend'], 2),
                    $campaign['leads'],
                    $campaign['cpl'] !== null ? '$'.number_format($campaign['cpl'], 2) : 'N/A'
                );
            }
        }

        $lines[] = '';
        $lines[] = $account->logo_url ? 'Logo: '.$account->logo_url : null;
        $lines[] = 'Generated: '.now()->toDateTimeString();

        return $lines;
    }
}
