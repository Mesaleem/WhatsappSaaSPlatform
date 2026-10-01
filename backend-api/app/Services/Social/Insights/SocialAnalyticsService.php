<?php

namespace App\Services\Social\Insights;

use App\Models\OrganicPost;
use App\Models\OrganicPostInsight;
use App\Models\SocialAccount;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Phase 9 Task 5 — account-level social analytics, read ONLY from the
 * persisted Task 4 snapshots (organic_posts ⟕ organic_post_insights). No
 * provider is ever called here.
 *
 * Aggregation rules (all SQL-side, a constant number of queries):
 *
 *  - Population: the account's organic posts with status `published` and
 *    `published_at` inside the range (inclusive days, app timezone). Failed,
 *    cancelled, scheduled, … posts are never in the metrics; they appear only
 *    in the separate `status_summary` (counted by created_at, labelled so).
 *  - One organic_posts row = one publication on one platform; its snapshot is
 *    1:1 (organic_post_insights.organic_post_id is unique), so a caption
 *    published to Facebook AND Instagram is two publications, each counted
 *    once, in its own platform — never double counted.
 *  - A metric total is SUM over posts that REPORTED it (NULL = not reported
 *    is skipped, 0 counts as 0). Its status:
 *        no_data      no published post in the range
 *        not_fetched  published posts, but none has a successful snapshot
 *        unavailable  fetched, but no post reported this metric
 *        available    value is the sum (0 = a real zero)
 *  - Engagement = reactions + comments + shares + saves (interaction counts
 *    of the same kind). Clicks, impressions, reach and views are NOT added
 *    (different units). The response lists which components were included.
 *    Per post (top posts) the same rule applies over its reported components.
 *  - Average watch time = Σ(avg_ms × views) / Σ(views) over posts reporting
 *    BOTH with views > 0 (a view-weighted mean; averages are never summed).
 *  - Metrics are each post's latest LIFETIME snapshot, attributed to the
 *    post's publish date — trends show "results of posts published in the
 *    period", not day-by-day platform activity.
 */
class SocialAnalyticsService
{
    public const SUM_METRICS = ['impressions', 'reach', 'reactions', 'comments', 'shares', 'saves', 'clicks', 'video_views'];

    public const ENGAGEMENT_COMPONENTS = ['reactions', 'comments', 'shares', 'saves'];

    public const TOP_METRICS = ['engagement', 'reach', 'impressions', 'video_views'];

    /** A range longer than this many days is bucketed by week. */
    public const DAILY_BUCKET_MAX_DAYS = 31;

    /**
     * @return array<string, mixed>
     */
    public function dashboard(int $accountId, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $byPlatform = $this->platformAggregates($accountId, $from, $to);
        $totals = $this->combine($byPlatform);

        return [
            'range' => $this->rangeView($from, $to),
            'summary' => $this->metricView($totals),
            'coverage' => $this->coverage($totals),
            'platforms' => collect($byPlatform)
                ->map(fn (array $row, string $platform) => ['platform' => $platform] + $this->metricView($row) + ['coverage' => $this->coverage($row)])
                ->values()
                ->all(),
            'freshness' => [
                'last_fetched_at' => $totals['last_fetched_at'] ? CarbonImmutable::parse($totals['last_fetched_at'])->toIso8601String() : null,
                'never_fetched' => $totals['published'] - $totals['fetched'],
            ],
            'trend' => $this->trend($accountId, $from, $to),
            'top_posts' => $this->topPosts($accountId, $from, $to, 'engagement'),
            'status_summary' => $this->statusSummary($accountId, $from, $to),
            'connections' => $this->connections($accountId),
        ];
    }

    /**
     * Posts with the highest value of one metric; posts that did not report
     * it are never ranked.
     *
     * @return list<array<string, mixed>>
     */
    public function topPosts(int $accountId, CarbonImmutable $from, CarbonImmutable $to, string $metric, int $limit = 10): array
    {
        $valueSql = match ($metric) {
            'engagement' => implode(' + ', array_map(fn ($m) => "COALESCE(i.{$m}, 0)", self::ENGAGEMENT_COMPONENTS)),
            'reach', 'impressions', 'video_views' => "i.{$metric}",
            default => throw new \InvalidArgumentException("Unsupported ranking metric '{$metric}'."),
        };

        $query = $this->published($accountId, $from, $to)
            ->join('organic_post_insights as i', fn ($j) => $j->on('i.organic_post_id', '=', 'p.id')->on('i.account_id', '=', 'p.account_id'))
            ->whereNotNull('i.metrics_fetched_at');

        if ($metric === 'engagement') {
            $query->where(fn ($q) => array_map(fn ($m) => $q->orWhereNotNull("i.{$m}"), self::ENGAGEMENT_COMPONENTS));
        } else {
            $query->whereNotNull("i.{$metric}");
        }

        return $query
            ->select([
                'p.id', 'p.platform', 'p.caption', 'p.media_type', 'p.published_at', 'i.state', 'i.metrics_fetched_at',
                'i.reach', 'i.impressions', 'i.video_views', ...array_map(fn ($m) => "i.{$m}", self::ENGAGEMENT_COMPONENTS),
            ])
            ->selectRaw("({$valueSql}) as rank_value")
            ->orderByDesc('rank_value')
            ->orderByDesc('p.published_at')
            ->orderByDesc('p.id')
            ->limit(max(1, min(50, $limit)))
            ->get()
            ->map(function ($row) {
                $components = [];
                foreach (self::ENGAGEMENT_COMPONENTS as $m) {
                    $components[$m] = $row->{$m} !== null ? (int) $row->{$m} : null;
                }

                return [
                    'post_id' => (int) $row->id,
                    'platform' => $row->platform,
                    'media_type' => $row->media_type,
                    'caption' => mb_strimwidth((string) $row->caption, 0, 140, '…'),
                    'published_at' => $row->published_at ? CarbonImmutable::parse($row->published_at)->toIso8601String() : null,
                    'insights_state' => $row->state,
                    'fetched_at' => $row->metrics_fetched_at ? CarbonImmutable::parse($row->metrics_fetched_at)->toIso8601String() : null,
                    'value' => (int) $row->rank_value,
                    'engagement' => array_filter($components, fn ($v) => $v !== null) === [] ? null : array_sum(array_filter($components, fn ($v) => $v !== null)),
                    'engagement_components' => $components,
                    'reach' => $row->reach !== null ? (int) $row->reach : null,
                    'impressions' => $row->impressions !== null ? (int) $row->impressions : null,
                    'video_views' => $row->video_views !== null ? (int) $row->video_views : null,
                ];
            })
            ->all();
    }

    // ================================================================ queries

    /** Published posts of the account in the range (alias p). */
    private function published(int $accountId, CarbonImmutable $from, CarbonImmutable $to): Builder
    {
        return DB::table('organic_posts as p')
            ->where('p.account_id', $accountId)
            ->where('p.status', OrganicPost::STATUS_PUBLISHED)
            ->whereNotNull('p.published_at')
            ->whereBetween('p.published_at', [$from->startOfDay(), $to->endOfDay()]);
    }

    /** @return array<string, array<string, mixed>> one aggregate row per platform (one query) */
    private function platformAggregates(int $accountId, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $select = [
            'p.platform',
            'COUNT(*) as published',
            "SUM(CASE WHEN p.external_post_id IS NOT NULL AND p.external_post_id <> '' THEN 1 ELSE 0 END) as with_provider_id",
            'SUM(CASE WHEN i.metrics_fetched_at IS NOT NULL THEN 1 ELSE 0 END) as fetched',
            "SUM(CASE WHEN i.state = 'reconnect_required' THEN 1 ELSE 0 END) as reconnect_required",
            "SUM(CASE WHEN i.state = 'post_not_found' THEN 1 ELSE 0 END) as post_not_found",
            "SUM(CASE WHEN i.state IN ('rate_limited', 'provider_error') THEN 1 ELSE 0 END) as provider_error",
            "SUM(CASE WHEN i.state = 'partial' THEN 1 ELSE 0 END) as partial",
            'MAX(i.metrics_fetched_at) as last_fetched_at',
            // View-weighted average watch time inputs (posts reporting both, views > 0).
            'SUM(CASE WHEN i.video_avg_watch_time_ms IS NOT NULL AND i.video_views > 0 THEN i.video_avg_watch_time_ms * i.video_views ELSE 0 END) as watch_weighted',
            'SUM(CASE WHEN i.video_avg_watch_time_ms IS NOT NULL AND i.video_views > 0 THEN i.video_views ELSE 0 END) as watch_views',
            'SUM(CASE WHEN i.video_avg_watch_time_ms IS NOT NULL AND i.video_views > 0 THEN 1 ELSE 0 END) as watch_posts',
        ];

        foreach (self::SUM_METRICS as $metric) {
            $select[] = "SUM(i.{$metric}) as sum_{$metric}";
            $select[] = "COUNT(i.{$metric}) as n_{$metric}";
        }

        $rows = $this->published($accountId, $from, $to)
            ->leftJoin('organic_post_insights as i', fn ($j) => $j->on('i.organic_post_id', '=', 'p.id')->on('i.account_id', '=', 'p.account_id'))
            ->selectRaw(implode(', ', $select))
            ->groupBy('p.platform')
            ->orderBy('p.platform')
            ->get();

        $out = [];
        foreach ($rows as $row) {
            $agg = [
                'published' => (int) $row->published,
                'with_provider_id' => (int) $row->with_provider_id,
                'fetched' => (int) $row->fetched,
                'reconnect_required' => (int) $row->reconnect_required,
                'post_not_found' => (int) $row->post_not_found,
                'provider_error' => (int) $row->provider_error,
                'partial' => (int) $row->partial,
                'last_fetched_at' => $row->last_fetched_at,
                'watch_weighted' => (float) $row->watch_weighted,
                'watch_views' => (int) $row->watch_views,
                'watch_posts' => (int) $row->watch_posts,
            ];
            foreach (self::SUM_METRICS as $metric) {
                $agg["sum_{$metric}"] = (int) $row->{"sum_{$metric}"};
                $agg["n_{$metric}"] = (int) $row->{"n_{$metric}"};
            }
            $out[(string) $row->platform] = $agg;
        }

        return $out;
    }

    /**
     * Daily rows from SQL (one query, DATE() works on MySQL/MariaDB and
     * SQLite), bucketed by week in PHP for ranges over 31 days. Every bucket
     * of the range is present; a bucket with no post has null metrics.
     *
     * @return array<string, mixed>
     */
    private function trend(int $accountId, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $metrics = ['reach', 'impressions', 'video_views', ...self::ENGAGEMENT_COMPONENTS];
        $select = ['DATE(p.published_at) as day', 'COUNT(*) as published'];
        foreach ($metrics as $metric) {
            $select[] = "SUM(i.{$metric}) as sum_{$metric}";
            $select[] = "COUNT(i.{$metric}) as n_{$metric}";
        }

        $days = $this->published($accountId, $from, $to)
            ->leftJoin('organic_post_insights as i', fn ($j) => $j->on('i.organic_post_id', '=', 'p.id')->on('i.account_id', '=', 'p.account_id'))
            ->selectRaw(implode(', ', $select))
            ->groupByRaw('DATE(p.published_at)')
            ->get()
            ->keyBy(fn ($r) => substr((string) $r->day, 0, 10));

        $weekly = $from->diffInDays($to) + 1 > self::DAILY_BUCKET_MAX_DAYS;
        $buckets = [];

        for ($day = $from->startOfDay(); $day->lte($to); $day = $day->addDay()) {
            $key = $weekly ? $day->startOfWeek()->max($from->startOfDay())->toDateString() : $day->toDateString();
            $buckets[$key] ??= ['period' => $key, 'published' => 0] + array_fill_keys(array_map(fn ($m) => "sum_{$m}", $metrics), 0) + array_fill_keys(array_map(fn ($m) => "n_{$m}", $metrics), 0);

            if ($row = $days->get($day->toDateString())) {
                $buckets[$key]['published'] += (int) $row->published;
                foreach ($metrics as $metric) {
                    $buckets[$key]["sum_{$metric}"] += (int) $row->{"sum_{$metric}"};
                    $buckets[$key]["n_{$metric}"] += (int) $row->{"n_{$metric}"};
                }
            }
        }

        $points = array_map(function (array $b) {
            $value = fn (string $m) => $b["n_{$m}"] > 0 ? $b["sum_{$m}"] : null;
            $components = array_filter(self::ENGAGEMENT_COMPONENTS, fn ($m) => $b["n_{$m}"] > 0);

            return [
                'period' => $b['period'],
                'published' => $b['published'],
                'reach' => $value('reach'),
                'impressions' => $value('impressions'),
                'video_views' => $value('video_views'),
                'engagement' => $components === [] ? null : array_sum(array_map(fn ($m) => $b["sum_{$m}"], $components)),
            ];
        }, array_values($buckets));

        return ['interval' => $weekly ? 'week' : 'day', 'points' => $points];
    }

    /** @return array<string, int> failed / cancelled / waiting posts created in the range (separate from the metrics) */
    private function statusSummary(int $accountId, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $counts = DB::table('organic_posts')
            ->where('account_id', $accountId)
            ->where('status', '!=', OrganicPost::STATUS_PUBLISHED)
            ->whereBetween('created_at', [$from->startOfDay(), $to->endOfDay()])
            ->selectRaw('status, COUNT(*) as n')
            ->groupBy('status')
            ->pluck('n', 'status');

        return collect([
            OrganicPost::STATUS_SCHEDULED, OrganicPost::STATUS_PUBLISHING, OrganicPost::STATUS_PENDING,
            OrganicPost::STATUS_FAILED, OrganicPost::STATUS_RECONNECT_REQUIRED, OrganicPost::STATUS_CANCELLED,
        ])->mapWithKeys(fn ($s) => [$s => (int) ($counts[$s] ?? 0)])->all();
    }

    /** @return array<string, int> */
    private function connections(int $accountId): array
    {
        $rows = SocialAccount::query()
            ->forAccount($accountId)
            ->where('provider', 'meta')
            ->whereIn('asset_type', ['facebook_page', 'instagram'])
            ->selectRaw("asset_type, SUM(CASE WHEN health_status = 'connected' THEN 1 ELSE 0 END) as healthy, COUNT(*) as n")
            ->groupBy('asset_type')
            ->get()
            ->keyBy('asset_type');

        return [
            'facebook_pages' => (int) ($rows['facebook_page']->n ?? 0),
            'instagram_accounts' => (int) ($rows['instagram']->n ?? 0),
            'needing_reconnect' => (int) $rows->sum(fn ($r) => $r->n - $r->healthy),
        ];
    }

    // ================================================================ shaping

    /**
     * @param  array<string, array<string, mixed>>  $byPlatform
     * @return array<string, mixed>
     */
    private function combine(array $byPlatform): array
    {
        $total = ['published' => 0, 'with_provider_id' => 0, 'fetched' => 0, 'reconnect_required' => 0, 'post_not_found' => 0,
            'provider_error' => 0, 'partial' => 0, 'last_fetched_at' => null, 'watch_weighted' => 0.0, 'watch_views' => 0, 'watch_posts' => 0];
        foreach (self::SUM_METRICS as $metric) {
            $total["sum_{$metric}"] = 0;
            $total["n_{$metric}"] = 0;
        }

        foreach ($byPlatform as $row) {
            foreach ($row as $key => $value) {
                if ($key === 'last_fetched_at') {
                    $total[$key] = $value !== null && ($total[$key] === null || $value > $total[$key]) ? $value : $total[$key];
                } else {
                    $total[$key] += $value;
                }
            }
        }

        return $total;
    }

    /**
     * @param  array<string, mixed>  $agg
     * @return array<string, mixed>
     */
    private function metricView(array $agg): array
    {
        $status = fn (int $reporting) => match (true) {
            $agg['published'] === 0 => 'no_data',
            $agg['fetched'] === 0 => 'not_fetched',
            $reporting === 0 => 'unavailable',
            default => 'available',
        };

        $metrics = [];
        foreach (self::SUM_METRICS as $metric) {
            $n = $agg["n_{$metric}"];
            $metrics[$metric] = ['value' => $n > 0 ? $agg["sum_{$metric}"] : null, 'status' => $status($n), 'posts_reporting' => $n];
        }

        $metrics['video_avg_watch_time_ms'] = [
            'value' => $agg['watch_views'] > 0 ? (int) round($agg['watch_weighted'] / $agg['watch_views']) : null,
            'status' => $status($agg['watch_posts']),
            'posts_reporting' => $agg['watch_posts'],
        ];

        $included = array_values(array_filter(self::ENGAGEMENT_COMPONENTS, fn ($m) => $agg["n_{$m}"] > 0));

        return [
            'published' => $agg['published'],
            'metrics' => $metrics,
            'engagement' => [
                'value' => $included === [] ? null : array_sum(array_map(fn ($m) => $agg["sum_{$m}"], $included)),
                'status' => $status(count($included)),
                'components_included' => $included,
                'components_unavailable' => array_values(array_diff(self::ENGAGEMENT_COMPONENTS, $included)),
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $agg
     * @return array<string, int>
     */
    private function coverage(array $agg): array
    {
        return [
            'published' => $agg['published'],
            'with_provider_id' => $agg['with_provider_id'],
            'without_provider_id' => $agg['published'] - $agg['with_provider_id'],
            'fetched' => $agg['fetched'],
            'never_fetched' => $agg['published'] - $agg['fetched'],
            'partial' => $agg['partial'],
            'reconnect_required' => $agg['reconnect_required'],
            'post_not_found' => $agg['post_not_found'],
            'provider_error' => $agg['provider_error'],
        ];
    }

    /** @return array<string, mixed> */
    private function rangeView(CarbonImmutable $from, CarbonImmutable $to): array
    {
        return [
            'from' => $from->toDateString(),
            'to' => $to->toDateString(),
            'days' => (int) $from->diffInDays($to) + 1,
            'interval' => $from->diffInDays($to) + 1 > self::DAILY_BUCKET_MAX_DAYS ? 'week' : 'day',
            'timezone' => (string) config('app.timezone'),
        ];
    }
}
