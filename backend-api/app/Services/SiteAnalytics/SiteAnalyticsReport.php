<?php

namespace App\Services\SiteAnalytics;

use App\Models\SiteVisit;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Super Admin — the website visitor report for a date range: totals, visits per day, top pages
 * (views and average time), landing pages, sources, devices, browsers and countries. A visit
 * that opened one page only is a "bounce".
 */
class SiteAnalyticsReport
{
    /** @return array<string, mixed> */
    public function build(CarbonInterface $from, CarbonInterface $to): array
    {
        $visits = fn (): Builder => SiteVisit::query()->whereBetween('started_at', [$from, $to]);

        $totals = $visits()->selectRaw('COUNT(*) AS visits, COUNT(DISTINCT visitor_id) AS visitors, COALESCE(SUM(page_count), 0) AS page_views,
            COALESCE(AVG(duration_seconds), 0) AS avg_duration, COALESCE(SUM(CASE WHEN page_count <= 1 THEN 1 ELSE 0 END), 0) AS bounces,
            COALESCE(SUM(CASE WHEN signed_up THEN 1 ELSE 0 END), 0) AS signups, COALESCE(SUM(CASE WHEN is_returning THEN 1 ELSE 0 END), 0) AS returning_visits')->first();
        $count = (int) $totals->visits;

        $pages = DB::table('site_page_views as p')
            ->join('site_visits as v', 'v.id', '=', 'p.site_visit_id')
            ->whereBetween('v.started_at', [$from, $to])
            ->selectRaw('p.path, COUNT(*) AS views, COUNT(DISTINCT p.site_visit_id) AS visits, COALESCE(AVG(p.duration_seconds), 0) AS avg_seconds')
            ->groupBy('p.path')->orderByDesc('views')->limit(20)->get()
            ->map(fn ($r) => ['path' => $r->path, 'views' => (int) $r->views, 'visits' => (int) $r->visits, 'avg_seconds' => (int) round((float) $r->avg_seconds)]);

        $exits = $visits()->selectRaw('exit_path, COUNT(*) AS c')->whereNotNull('exit_path')->groupBy('exit_path')->pluck('c', 'exit_path');

        return [
            'from' => $from->toDateString(),
            'to' => $to->toDateString(),
            'summary' => [
                'visits' => $count,
                'visitors' => (int) $totals->visitors,
                'page_views' => (int) $totals->page_views,
                'avg_duration_seconds' => (int) round((float) $totals->avg_duration),
                'bounce_rate' => $count > 0 ? round(100 * (int) $totals->bounces / $count, 1) : 0,
                'signups' => (int) $totals->signups,
                'returning_visits' => (int) $totals->returning_visits,
            ],
            'daily' => $visits()->selectRaw('DATE(started_at) AS day, COUNT(*) AS visits, COUNT(DISTINCT visitor_id) AS visitors')
                ->groupByRaw('DATE(started_at)')->orderBy('day')->get()
                ->map(fn ($r) => ['date' => (string) $r->day, 'visits' => (int) $r->visits, 'visitors' => (int) $r->visitors]),
            'pages' => $pages->map(fn ($p) => $p + ['exits' => (int) ($exits[$p['path']] ?? 0)])->values(),
            'landing_pages' => $this->breakdown($visits(), 'landing_path'),
            'sources' => $this->breakdown($visits(), 'source', true),
            'devices' => $this->breakdown($visits(), 'device'),
            'browsers' => $this->breakdown($visits(), 'browser'),
            'countries' => $this->breakdown($visits()->whereNotNull('country'), 'country'),
        ];
    }

    /** @return list<array<string, mixed>> */
    private function breakdown(Builder $q, string $column, bool $withQuality = false): array
    {
        return $q->selectRaw("{$column} AS name, COUNT(*) AS visits, COALESCE(AVG(duration_seconds), 0) AS avg_seconds, COALESCE(AVG(page_count), 0) AS avg_pages,
                COALESCE(SUM(CASE WHEN signed_up THEN 1 ELSE 0 END), 0) AS signups")
            ->groupBy($column)->orderByDesc('visits')->limit(15)->get()
            ->map(fn ($r) => ['name' => (string) $r->name, 'visits' => (int) $r->visits]
                + ($withQuality ? ['avg_seconds' => (int) round((float) $r->avg_seconds), 'avg_pages' => round((float) $r->avg_pages, 1), 'signups' => (int) $r->signups] : []))
            ->values()->all();
    }
}
