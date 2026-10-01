<?php

namespace App\Services\Ads;

use App\Models\AdAttribution;
use App\Models\AdCampaign;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Phase 10 Task 3 — account-level Ads reporting, read from PERSISTED data only:
 * `ad_campaigns` (current lifecycle status), `ad_campaign_daily_metrics`
 * (spend / impressions stored by `ads:check-performance-rules`) and
 * `ad_attributions` (Task 1 referral → lead → journey → conversion lifecycle).
 * Never calls Meta.
 *
 * Every query is filtered by the target account id. A fixed number of
 * aggregate queries runs regardless of how many campaigns or referrals exist
 * (no per-campaign query, no attribution rows loaded into PHP).
 *
 * Availability — a value that is not known is NULL with a status, never 0:
 *   available       stored data covers the metric;
 *   not_fetched     the metric could exist but nothing is stored for the range
 *                   (e.g. no insights recorded yet);
 *   unavailable     the source cannot provide it (no conversion value is
 *                   captured; a campaign Meta reports as gone);
 *   not_applicable  it cannot exist (no campaigns; a launch that never reached Meta;
 *                   a rate with a zero denominator).
 */
class AdsDashboardService
{
    public const AVAILABLE = 'available';

    public const NOT_FETCHED = 'not_fetched';

    public const UNAVAILABLE = 'unavailable';

    public const NOT_APPLICABLE = 'not_applicable';

    /** Campaign breakdown is bounded; `campaigns_truncated` says when more exist. */
    public const MAX_CAMPAIGN_ROWS = 200;

    /** @return array<string, mixed> */
    public function dashboard(int $accountId, CarbonImmutable $from, CarbonImmutable $to): array
    {
        [$fromAt, $toAt] = [$from->startOfDay(), $to->endOfDay()];
        [$fromDate, $toDate] = [$from->toDateString(), $to->toDateString()];
        // metric_date is a DATE on MySQL/MariaDB but the model's date cast writes
        // 'Y-m-d 00:00:00' on SQLite — the inclusive upper bound covers both.

        // 1. Campaign counts by CURRENT status (lifecycle state, not range-bound).
        $statusCounts = AdCampaign::query()->forAccount($accountId)
            ->selectRaw('status, COUNT(*) as n')->groupBy('status')->pluck('n', 'status')
            ->map(fn ($n) => (int) $n);
        $campaignTotal = (int) $statusCounts->sum();

        // 2. Spend per campaign over the range (one grouped query).
        $spendByCampaign = DB::table('ad_campaign_daily_metrics')
            ->where('account_id', $accountId)
            ->whereBetween('metric_date', [$fromDate, $toDate.' 23:59:59'])
            ->selectRaw('ad_campaign_id, SUM(spend) as spend, SUM(impressions) as impressions, SUM(leads) as meta_leads, COUNT(*) as days')
            ->groupBy('ad_campaign_id')
            ->get()->keyBy('ad_campaign_id');

        // 3. Daily spend trend (one grouped query; missing days stay NULL, not 0).
        $daily = DB::table('ad_campaign_daily_metrics')
            ->where('account_id', $accountId)
            ->whereBetween('metric_date', [$fromDate, $toDate.' 23:59:59'])
            ->selectRaw('metric_date, SUM(spend) as spend')
            ->groupBy('metric_date')
            ->pluck('spend', 'metric_date')
            ->mapWithKeys(fn ($spend, $date) => [substr((string) $date, 0, 10) => round((float) $spend, 2)]);

        // 4. Attribution per launcher campaign (NULL = ad not created by the launcher), one grouped query.
        $attributionByCampaign = AdAttribution::query()->forAccount($accountId)
            ->whereBetween('referral_received_at', [$fromAt, $toAt])
            ->selectRaw('ad_campaign_id,
                COUNT(*) as referrals,
                COUNT(DISTINCT contact_phone) as conversations,
                COUNT(crm_lead_id) as leads,
                COUNT(flow_session_id) as journeys,
                COUNT(converted_at) as conversions,
                SUM(conversion_value) as conversion_value,
                COUNT(conversion_value) as valued_conversions')
            ->groupBy('ad_campaign_id')
            ->get()->keyBy(fn ($row) => $row->ad_campaign_id === null ? 'unlinked' : (int) $row->ad_campaign_id);

        // 5. Account-wide funnel (distinct counts cannot be summed across groups).
        $funnel = AdAttribution::query()->forAccount($accountId)
            ->whereBetween('referral_received_at', [$fromAt, $toAt])
            ->selectRaw('COUNT(DISTINCT source_id) as ads,
                COUNT(*) as referrals,
                COUNT(DISTINCT contact_phone) as conversations,
                COUNT(crm_lead_id) as leads,
                COUNT(flow_session_id) as journeys,
                COUNT(converted_at) as conversions,
                SUM(conversion_value) as conversion_value,
                COUNT(conversion_value) as valued_conversions')
            ->first();

        // 6. Campaign rows (bounded) — one query.
        $campaignRows = AdCampaign::query()->forAccount($accountId)
            ->orderByDesc('id')
            ->limit(self::MAX_CAMPAIGN_ROWS + 1)
            ->get(['id', 'name', 'objective', 'status', 'meta_campaign_id', 'meta_ad_id', 'daily_budget', 'last_checked_at', 'created_at']);
        $truncated = $campaignRows->count() > self::MAX_CAMPAIGN_ROWS;
        $campaignRows = $campaignRows->take(self::MAX_CAMPAIGN_ROWS);

        $campaigns = $campaignRows->map(fn (AdCampaign $c) => $this->campaignRow($c, $spendByCampaign->get($c->id), $attributionByCampaign->get($c->id)))
            ->sortByDesc(fn (array $row) => [$row['spend'] ?? -1, $row['referrals'], $row['id']])
            ->values();

        $totalSpend = $spendByCampaign->isEmpty() ? null : round((float) $spendByCampaign->sum('spend'), 2);

        // Cost per lead / ROAS at account level use ONLY launcher-linked attribution,
        // against the spend of those same campaigns, so ads without stored spend
        // never distort the ratio.
        $linkedWithSpend = $attributionByCampaign->filter(fn ($row, $id) => $id !== 'unlinked' && $spendByCampaign->has($id));
        $linkedSpend = $linkedWithSpend->isEmpty() ? null : round((float) $linkedWithSpend->keys()->sum(fn ($id) => (float) $spendByCampaign[$id]->spend), 2);
        $linkedLeads = (int) $linkedWithSpend->sum('leads');
        $linkedValue = $this->value($linkedWithSpend->sum('valued_conversions'), $linkedWithSpend->sum('conversion_value'));

        $referrals = (int) $funnel->referrals;
        $conversions = (int) $funnel->conversions;
        $conversionValue = $this->value($funnel->valued_conversions, $funnel->conversion_value);

        // Owner request (2026-09-30): the ad account currency stored at launch —
        // one currency across the tenant's campaigns, or null (unknown / mixed).
        $currencies = AdCampaign::query()->forAccount($accountId)->whereNotNull('currency')->distinct()->limit(2)->pluck('currency');

        return [
            'range' => ['from' => $fromDate, 'to' => $toDate, 'days' => $from->diffInDays($to) + 1],
            'currency' => $currencies->count() === 1 ? $currencies->first() : null,
            'campaigns_summary' => [
                'total' => $campaignTotal,
                'active' => $statusCounts->get(AdCampaign::STATUS_ACTIVE, 0),
                'paused' => $statusCounts->get(AdCampaign::STATUS_PAUSED, 0),
                'launching' => $statusCounts->get(AdCampaign::STATUS_LAUNCHING, 0),
                'failed' => $statusCounts->get(AdCampaign::STATUS_FAILED, 0),
                'unconfirmed' => $statusCounts->get(AdCampaign::STATUS_UNCONFIRMED, 0),
                'unavailable' => $statusCounts->get(AdCampaign::STATUS_UNAVAILABLE, 0),
            ],
            'spend' => [
                'total' => $this->metric($totalSpend, $totalSpend !== null ? self::AVAILABLE : ($campaignTotal === 0 ? self::NOT_APPLICABLE : self::NOT_FETCHED)),
                'impressions' => $this->metric(
                    $spendByCampaign->isEmpty() ? null : (int) $spendByCampaign->sum('impressions'),
                    $spendByCampaign->isEmpty() ? ($campaignTotal === 0 ? self::NOT_APPLICABLE : self::NOT_FETCHED) : self::AVAILABLE,
                ),
                'campaigns_with_spend' => $spendByCampaign->count(),
                'daily' => $this->dailySeries($from, $to, $daily),
            ],
            'attribution' => [
                'ads' => (int) $funnel->ads,
                'referrals' => $referrals,
                'conversations' => (int) $funnel->conversations,
                'leads' => (int) $funnel->leads,
                'journeys' => (int) $funnel->journeys,
                'conversions' => $conversions,
                'conversion_rate' => $this->metric($referrals > 0 ? round($conversions / $referrals, 4) : null, $referrals > 0 ? self::AVAILABLE : self::NOT_APPLICABLE),
                'conversion_value' => $this->metric($conversionValue, $conversionValue !== null ? self::AVAILABLE : self::UNAVAILABLE),
                'unlinked_referrals' => (int) ($attributionByCampaign->get('unlinked')?->referrals ?? 0),
                'cost_per_lead' => $this->ratio($linkedSpend, $linkedLeads, 2, $linkedSpend === null ? self::NOT_FETCHED : null),
                'roas' => $this->roas($linkedSpend, $linkedValue),
            ],
            'funnel' => [
                ['stage' => 'ads', 'label' => 'Ads', 'count' => (int) $funnel->ads],
                ['stage' => 'referrals', 'label' => 'Referrals', 'count' => $referrals],
                ['stage' => 'conversations', 'label' => 'Conversations', 'count' => (int) $funnel->conversations],
                ['stage' => 'leads', 'label' => 'CRM leads', 'count' => (int) $funnel->leads],
                ['stage' => 'journeys', 'label' => 'Journey starts', 'count' => (int) $funnel->journeys],
                ['stage' => 'conversions', 'label' => 'Conversions', 'count' => $conversions],
            ],
            'campaigns' => $campaigns->all(),
            'campaigns_truncated' => $truncated,
            'notes' => [
                'source' => 'Figures come from stored data only; the dashboard never calls Meta.',
                'spend' => 'Spend exists only for campaigns created by the Meta Ads launcher, from the insights stored every 15 minutes.',
                'campaign_status' => 'Campaign counts show each campaign\'s current status, not the status during the period.',
                'funnel' => 'The funnel counts click-to-WhatsApp referrals received in the period and how far each has got; each stage is counted on its own.',
                'conversion' => 'A conversion is an attributed CRM lead currently in status "converted".',
                'conversion_value' => 'No conversion value is captured, so value and ROAS stay unavailable unless one is recorded.',
                'cost_per_lead' => 'Cost per lead and ROAS use only referrals from launcher campaigns that have stored spend in the period.',
            ],
        ];
    }

    /** @return array<string, mixed> */
    private function campaignRow(AdCampaign $c, ?object $spend, ?object $attribution): array
    {
        $neverReachedMeta = $c->meta_campaign_id === null;
        $spendStatus = match (true) {
            $spend !== null => self::AVAILABLE,
            $neverReachedMeta => self::NOT_APPLICABLE,
            $c->status === AdCampaign::STATUS_UNAVAILABLE => self::UNAVAILABLE,
            default => self::NOT_FETCHED,
        };
        $spendValue = $spend !== null ? round((float) $spend->spend, 2) : null;

        $referrals = (int) ($attribution->referrals ?? 0);
        $leads = (int) ($attribution->leads ?? 0);
        $conversions = (int) ($attribution->conversions ?? 0);
        $value = $attribution ? $this->value($attribution->valued_conversions, $attribution->conversion_value) : null;

        return [
            'id' => $c->id,
            'name' => $c->name,
            'objective' => $c->objective,
            'status' => $c->status,
            'spend' => $spendValue,
            'spend_status' => $spendStatus,
            'impressions' => $spend !== null ? (int) $spend->impressions : null,
            'referrals' => $referrals,
            'conversations' => (int) ($attribution->conversations ?? 0),
            'leads' => $leads,
            'journeys' => (int) ($attribution->journeys ?? 0),
            'conversions' => $conversions,
            'conversion_rate' => $referrals > 0 ? round($conversions / $referrals, 4) : null,
            'cost_per_lead' => $spendValue !== null && $leads > 0 ? round($spendValue / $leads, 2) : null,
            'conversion_value' => $value,
            'roas' => $spendValue !== null && $spendValue > 0 && $value !== null ? round($value / $spendValue, 4) : null,
            'last_checked_at' => $c->last_checked_at?->toIso8601String(),
        ];
    }

    /** @return list<array{date: string, spend: float|null}> */
    private function dailySeries(CarbonImmutable $from, CarbonImmutable $to, Collection $daily): array
    {
        $points = [];
        for ($day = $from; $day->lte($to); $day = $day->addDay()) {
            $date = $day->toDateString();
            $points[] = ['date' => $date, 'spend' => $daily->has($date) ? $daily[$date] : null];
        }

        return $points;
    }

    private function value(mixed $valuedCount, mixed $sum): ?float
    {
        return (int) $valuedCount > 0 && $sum !== null ? round((float) $sum, 2) : null;
    }

    /** @return array{value: float|int|null, status: string} */
    private function metric(float|int|null $value, string $status): array
    {
        return ['value' => $value, 'status' => $value === null && $status === self::AVAILABLE ? self::UNAVAILABLE : $status];
    }

    /** @return array{value: float|null, status: string} */
    private function ratio(?float $numerator, int $denominator, int $precision, ?string $statusWhenMissing): array
    {
        if ($numerator === null) {
            return $this->metric(null, $statusWhenMissing ?? self::NOT_FETCHED);
        }

        return $denominator > 0
            ? $this->metric(round($numerator / $denominator, $precision), self::AVAILABLE)
            : $this->metric(null, self::NOT_APPLICABLE);
    }

    /** ROAS only when real spend AND a real conversion value exist. */
    private function roas(?float $spend, ?float $value): array
    {
        if ($value === null) {
            return $this->metric(null, self::UNAVAILABLE);
        }
        if ($spend === null) {
            return $this->metric(null, self::NOT_FETCHED);
        }

        return $spend > 0 ? $this->metric(round($value / $spend, 4), self::AVAILABLE) : $this->metric(null, self::NOT_APPLICABLE);
    }
}
