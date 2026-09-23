<?php

namespace App\Services\Crm;

use App\Models\Account;
use App\Models\CrmLead;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;

/**
 * Phase 6 — CRM Task 12. CRM analytics over `crm_leads` — never over the
 * `leads` capture table.
 *
 * THE DATASET. One set of leads per request: the account's leads
 * (forAccount — the only tenant boundary, applied first), narrowed by the
 * shared CRM filter vocabulary (CrmLead::scopeFilter() — the same
 * semantics as the lead list and the pipeline) and by the date range on
 * `crm_leads.created_at` (when the lead entered the CRM). Every figure in
 * the response is an aggregate over that one set, so the parts always add
 * up to the total:
 *
 *   total            COUNT(*)
 *   new/contacted/converted/not_converted   COUNT(*) per current status
 *   conversion_rate  converted / total * 100, 2 dp; null when total = 0
 *   by_status        GROUP BY status     (one row per lead — sums to total)
 *   by_source        GROUP BY source     (one row per lead — sums to total)
 *   by_assignee      GROUP BY assigned_user_id, NULL = unassigned (sums to total)
 *   trend            GROUP BY DATE(created_at), folded into day/week/month
 *                    buckets; per bucket: leads created and how many of them
 *                    are converted now (sums to total / converted)
 *
 * Status is the lead's CURRENT status (a cohort view: "of the leads created
 * in this range, how many are converted now"), not the status on the
 * dates. There is no status history table to answer the other question.
 *
 * No join anywhere: tag filters are EXISTS probes (scopeFilter), so a lead
 * with several tags is still counted once. Five queries per request,
 * independent of the number of leads (4 aggregates + 1 name lookup).
 *
 * Dates are calendar days in the application timezone (UTC — config/app.php;
 * accounts have no timezone of their own).
 */
class CrmAnalyticsService
{
    /** A range of at most this many days is bucketed by day. */
    public const DAY_BUCKETS_MAX_DAYS = 92;

    /** Up to this many days by ISO week (Monday start); beyond, by month. */
    public const WEEK_BUCKETS_MAX_DAYS = 731;

    /**
     * @param array<string, mixed> $filters  validated CrmLead::filterRules() keys
     * @return array<string, mixed>
     */
    public function summarize(Account $account, array $filters, ?CarbonImmutable $from, ?CarbonImmutable $to): array
    {
        $base = function () use ($account, $filters, $from, $to): Builder {
            return CrmLead::query()
                ->forAccount($account->id)
                ->filter($filters)
                ->when($from, fn (Builder $q) => $q->where('crm_leads.created_at', '>=', $from->startOfDay()->toDateTimeString()))
                ->when($to, fn (Builder $q) => $q->where('crm_leads.created_at', '<', $to->addDay()->startOfDay()->toDateTimeString()));
        };

        $byStatus = $base()->toBase()->select('status')->selectRaw('COUNT(*) as aggregate')->groupBy('status')->pluck('aggregate', 'status');
        $bySource = $base()->toBase()->select('source')->selectRaw('COUNT(*) as aggregate')->groupBy('source')->pluck('aggregate', 'source');
        $byAssignee = $base()->toBase()->select('assigned_user_id')->selectRaw('COUNT(*) as aggregate')->groupBy('assigned_user_id')->get();
        $byDay = $base()->toBase()
            ->selectRaw('DATE(crm_leads.created_at) as day')
            ->selectRaw('COUNT(*) as total')
            ->selectRaw('SUM(CASE WHEN status = ? THEN 1 ELSE 0 END) as converted', [CrmLead::STATUS_CONVERTED])
            ->groupByRaw('DATE(crm_leads.created_at)')
            ->orderBy('day')
            ->get();

        $total = (int) $byStatus->sum();
        $count = fn (string $status): int => (int) ($byStatus[$status] ?? 0);

        [$rangeFrom, $rangeTo] = $this->resolveRange($from, $to, $byDay->pluck('day')->all());
        $interval = $this->intervalFor($rangeFrom, $rangeTo);

        return [
            'range' => [
                'from' => $rangeFrom->toDateString(),
                'to' => $rangeTo->toDateString(),
                'interval' => $interval,
                'timezone' => config('app.timezone'),
            ],
            'totals' => [
                'total' => $total,
                'new' => $count(CrmLead::STATUS_NEW),
                'contacted' => $count(CrmLead::STATUS_CONTACTED),
                'converted' => $count(CrmLead::STATUS_CONVERTED),
                'not_converted' => $count(CrmLead::STATUS_NOT_CONVERTED),
                'conversion_rate' => $total === 0 ? null : round($count(CrmLead::STATUS_CONVERTED) / $total * 100, 2),
            ],
            'by_status' => $this->breakdown($byStatus->all(), CrmLead::STATUSES, 'status'),
            'by_source' => $this->breakdown($bySource->all(), CrmLead::SOURCES, 'source'),
            'by_assignee' => $this->assignees($account, $byAssignee),
            'trend' => $this->trend($byDay, $rangeFrom, $rangeTo, $interval),
        ];
    }

    /**
     * Every canonical value in its canonical order (zero-filled), then any
     * value found in the data that is not canonical (e.g. a legacy status),
     * so the rows always sum to the total.
     *
     * @param array<string, int|string> $counts
     * @param list<string> $canonical
     * @return list<array<string, mixed>>
     */
    private function breakdown(array $counts, array $canonical, string $key): array
    {
        $rows = [];
        foreach (array_unique(array_merge($canonical, array_map('strval', array_keys($counts)))) as $value) {
            $rows[] = [
                $key => $value,
                'label' => ucwords(str_replace('_', ' ', $value)),
                'count' => (int) ($counts[$value] ?? 0),
            ];
        }

        return $rows;
    }

    /**
     * One name lookup for every assignee in the result, scoped to the
     * account (defence in depth: the saving guard already makes a foreign
     * assignee unstorable). Ordered by count desc, then name.
     *
     * @return list<array{assigned_user_id: int|null, name: string, count: int}>
     */
    private function assignees(Account $account, $rows): array
    {
        $ids = $rows->pluck('assigned_user_id')->filter()->map(fn ($id) => (int) $id)->values()->all();
        $names = $ids === []
            ? collect()
            : User::query()->where('account_id', $account->id)->whereIn('id', $ids)->pluck('name', 'id');

        return $rows
            ->map(fn ($row) => [
                'assigned_user_id' => $row->assigned_user_id === null ? null : (int) $row->assigned_user_id,
                'name' => $row->assigned_user_id === null
                    ? 'Unassigned'
                    : (string) ($names[(int) $row->assigned_user_id] ?? 'User #'.$row->assigned_user_id),
                'count' => (int) $row->aggregate,
            ])
            ->sortBy([['count', 'desc'], ['name', 'asc']])
            ->values()
            ->all();
    }

    /**
     * The explicit range, or — for an open end — the data's own first/last
     * day (today when there is no data), so the trend always covers every
     * counted lead.
     *
     * @param list<string> $days
     * @return array{CarbonImmutable, CarbonImmutable}
     */
    private function resolveRange(?CarbonImmutable $from, ?CarbonImmutable $to, array $days): array
    {
        $today = CarbonImmutable::today();
        $first = $days === [] ? null : CarbonImmutable::parse($days[0]);
        $last = $days === [] ? null : CarbonImmutable::parse(end($days));

        $rangeTo = $to ?? ($last !== null && $last->greaterThan($today) ? $last : $today);
        $rangeFrom = $from ?? ($first !== null && $first->lessThan($rangeTo) ? $first : $rangeTo);

        return [$rangeFrom->startOfDay(), $rangeTo->startOfDay()];
    }

    private function intervalFor(CarbonImmutable $from, CarbonImmutable $to): string
    {
        $days = (int) $from->diffInDays($to) + 1;

        return match (true) {
            $days <= self::DAY_BUCKETS_MAX_DAYS => 'day',
            $days <= self::WEEK_BUCKETS_MAX_DAYS => 'week',
            default => 'month',
        };
    }

    /**
     * Zero-filled buckets from the range start to the range end. A bucket
     * is keyed by its first day, clamped to the range start.
     *
     * @return list<array{period: string, total: int, converted: int}>
     */
    private function trend($byDay, CarbonImmutable $from, CarbonImmutable $to, string $interval): array
    {
        $start = fn (CarbonImmutable $day): CarbonImmutable => match ($interval) {
            'week' => $day->startOfWeek(CarbonImmutable::MONDAY),
            'month' => $day->startOfMonth(),
            default => $day,
        };
        $key = fn (CarbonImmutable $day): string => $start($day)->max($from)->toDateString();

        $buckets = [];
        for ($cursor = $start($from); $cursor->lessThanOrEqualTo($to); $cursor = match ($interval) {
            'week' => $cursor->addWeek(),
            'month' => $cursor->addMonth(),
            default => $cursor->addDay(),
        }) {
            $buckets[$cursor->max($from)->toDateString()] = ['period' => $cursor->max($from)->toDateString(), 'total' => 0, 'converted' => 0];
        }

        foreach ($byDay as $row) {
            $k = $key(CarbonImmutable::parse($row->day));
            if (isset($buckets[$k])) {
                $buckets[$k]['total'] += (int) $row->total;
                $buckets[$k]['converted'] += (int) $row->converted;
            }
        }

        return array_values($buckets);
    }
}
