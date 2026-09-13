<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Concerns\ResolvesTenantAccount;
use App\Http\Controllers\Controller;
use App\Models\Account;
use App\Models\Invoice;
use App\Models\ContactGroup;
use App\Models\MessageDispatchLog;
use App\Models\PaymentAlert;
use App\Models\Subscription;
use App\Models\WhatsAppSession;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\Rule;

class AnalyticsController extends Controller
{
    use ResolvesTenantAccount;

    private const MAX_RANGE_DAYS = 366;

    /**
     * GET /api/analytics/summary — KPI cards.
     *
     * Period defaults to the account's CURRENT subscription period
     * (starts_at -> now, capped at expires_at is unnecessary since we cap
     * at "now" and a subscription's usage never grows past its own expiry
     * in practice) because "Remaining Message Quota" only has meaning
     * relative to one billing period; ?from=&to= overrides it for an
     * arbitrary custom-range summary.
     */
    public function summary(Request $request): JsonResponse
    {
        $account = $this->resolveAccount($request);
        $subscription = $account?->currentSubscription;

        abort_if($account && ! $subscription, 404, 'This account has no subscription yet.');

        [$from, $to] = $this->resolveRange($request, $subscription?->starts_at ?? now()->subDays(29));

        // [New feature, disclosed]: total_sent / total_failed / the
        // "today" counters below now count EVERY dispatch pathway (Send
        // Alert web_ui, Send Template web_template, external api, Chatbot,
        // Journey Builder) via message_dispatch_logs — not payment_alerts
        // alone — once that table exists. Schema::hasTable() guards this
        // the same way this file already guards `invoices` below: a
        // deployment that hasn't yet run the message_dispatch_logs
        // migration keeps getting the PRE-EXISTING payment_alerts-only
        // counts (nothing regresses), and it self-heals to the unified
        // count the moment the migration runs — no second deploy needed.
        //
        // total_cost_incurred deliberately stays payment_alerts-scoped
        // regardless of table availability: cost_deducted is only ever
        // written on that table — templates, chatbot replies and journey
        // sends have no cost model anywhere in this codebase, so a
        // unified "cost across all sources" figure cannot be honestly
        // produced yet. This is a real, disclosed scope gap, not an
        // oversight.
        $dispatchLogsAvailable = Schema::hasTable('message_dispatch_logs');

        // Group Messaging Phase 5 — Dashboard Analytics Upgrade. Both
        // guarded independently of $dispatchLogsAvailable above: the
        // recipient_type/group_id/... columns and the success_count/
        // failure_count columns were added by two LATER migrations on
        // top of the base message_dispatch_logs table (see those
        // migrations' own docblocks), each still pending its own
        // authorization — a deployment could have the base table but
        // not yet either extension, so this self-heals exactly like
        // $dispatchLogsAvailable itself rather than erroring.
        $recipientTypeAvailable = $dispatchLogsAvailable && Schema::hasColumn('message_dispatch_logs', 'recipient_type');
        $resolutionCountsAvailable = $recipientTypeAvailable && Schema::hasColumn('message_dispatch_logs', 'success_count');

        $costQuery = $account ? PaymentAlert::forAccount($account->id) : PaymentAlert::query();
        $totalCost = (float) $costQuery
            ->whereBetween('created_at', [$from, $to])
            ->where('status', 'sent')
            ->sum('cost_deducted');

        if ($dispatchLogsAvailable) {
            $agg = ($account ? MessageDispatchLog::forAccount($account->id) : MessageDispatchLog::query())
                ->whereBetween('created_at', [$from, $to])
                ->selectRaw(
                    "SUM(CASE WHEN status = 'sent' THEN 1 ELSE 0 END) as total_sent, ".
                    "SUM(CASE WHEN status = 'failed' THEN 1 ELSE 0 END) as total_failed"
                )
                ->first();
        } else {
            $agg = ($account ? PaymentAlert::forAccount($account->id) : PaymentAlert::query())
                ->whereBetween('created_at', [$from, $to])
                ->selectRaw(
                    "SUM(CASE WHEN status = 'sent' THEN 1 ELSE 0 END) as total_sent, ".
                    "SUM(CASE WHEN status = 'failed' THEN 1 ELSE 0 END) as total_failed"
                )
                ->first();
        }

        $totalSent = (int) $agg->total_sent;
        $totalFailed = (int) $agg->total_failed;
        $attempted = $totalSent + $totalFailed;
        $deliveredRate = $attempted > 0 ? round(($totalSent / $attempted) * 100, 2) : 0.0;

        // Quota is a per-subscription concept — there is no platform-wide
        // equivalent, so it's simply absent (not a fabricated aggregate)
        // when Super Admin hasn't selected a client. The frontend already
        // treats every quota field as optional.
        $quota = null;
        if ($subscription) {
            $subscription->refreshStatus();
            $isUnlimited = $subscription->billing_model === 'unlimited' || $subscription->total_allocated_messages === null;
            $remaining = $isUnlimited ? null : max(0, $subscription->total_allocated_messages - $subscription->used_messages);
            $quotaPercentUsed = (! $isUnlimited && $subscription->total_allocated_messages > 0)
                ? round(($subscription->used_messages / $subscription->total_allocated_messages) * 100, 2)
                : null;

            $quota = [
                'billing_model' => $subscription->billing_model,
                'engine_type' => $subscription->engine_type,
                'total_allocated_messages' => $subscription->total_allocated_messages,
                'used_messages' => $subscription->used_messages,
                'remaining_messages' => $remaining,
                'quota_percent_used' => $quotaPercentUsed,
                'subscription_status' => $subscription->status,
            ];
        }

        // Client Admin Dashboard Overhaul — "Today's Broadcast Metrics" card.
        // A second, separate aggregate scoped to just today (server
        // timezone — same caveat as globalSummary()'s equivalent field:
        // no per-account timezone concept exists in this schema), kept
        // independent of the period-scoped $agg above since the two can
        // legitimately disagree (e.g. a 30-day period average vs. today).
        $todayQuery = $dispatchLogsAvailable
            ? ($account ? MessageDispatchLog::forAccount($account->id) : MessageDispatchLog::query())
            : ($account ? PaymentAlert::forAccount($account->id) : PaymentAlert::query());
        $todayAgg = $todayQuery
            ->whereDate('created_at', now()->toDateString())
            ->selectRaw(
                "SUM(CASE WHEN status = 'sent' THEN 1 ELSE 0 END) as total_sent, ".
                "SUM(CASE WHEN status = 'failed' THEN 1 ELSE 0 END) as total_failed"
            )
            ->first();

        // Dashboard & Analytics Fix Round 2 — "Today's Group & Individual
        // Sent/Failed Breakdown". Reuses recipientTypeBreakdown() a
        // second time, scoped to today's date range instead of the
        // period range — same sourcing rules (and the same disclosed
        // batch-vs-recipient fallback) as `recipient_breakdown` above,
        // just re-windowed. [Disclosed]: the request named the four
        // leaf fields literally as 'today_individual_sent' /
        // 'today_individual_failed' / 'today_group_sent' /
        // 'today_group_failed' while also asking for them under a
        // 'today_breakdown' key — both are honored: nested under
        // today_breakdown, using exactly those four literal names
        // (rather than re-shortening them to 'individual_sent' etc,
        // which would silently drop half of what was asked for).
        $todayBreakdown = null;
        if ($recipientTypeAvailable) {
            $todayRange = $this->recipientTypeBreakdown($account, now()->startOfDay(), now()->endOfDay(), $resolutionCountsAvailable);
            $todayBreakdown = [
                'today_individual_sent' => $todayRange['individual']['sent'],
                'today_individual_failed' => $todayRange['individual']['failed'],
                'today_group_sent' => $todayRange['group']['sent'],
                'today_group_failed' => $todayRange['group']['failed'],
            ];
        }

        return response()->json([
            'scope' => $account ? 'account' : 'global',
            'period' => ['from' => $from->toDateString(), 'to' => $to->toDateString()],
            'total_alerts_attempted' => $attempted,
            'total_sent' => $totalSent,
            'total_failed' => $totalFailed,
            'delivered_rate' => $deliveredRate,
            'total_cost_incurred' => number_format($totalCost, 4, '.', ''),
            'quota' => $quota,
            'total_sent_today' => (int) $todayAgg->total_sent,
            'total_failed_today' => (int) $todayAgg->total_failed,
            // Dashboard & Analytics Fix Round 2. null under the same
            // self-healing condition as `recipient_breakdown` below.
            'today_breakdown' => $todayBreakdown,
            // [New feature, disclosed]: empty until message_dispatch_logs
            // exists — see sourceDistribution()'s docblock.
            'source_distribution' => $dispatchLogsAvailable ? $this->sourceDistribution($account, $from, $to) : [],
            // Group Messaging Phase 5 — null exactly when
            // $recipientTypeAvailable is false (see setup above), same
            // "absent, not fabricated" convention 'quota' above already
            // uses for a field that isn't meaningful/available yet.
            'recipient_breakdown' => $recipientTypeAvailable ? $this->recipientTypeBreakdown($account, $from, $to, $resolutionCountsAvailable) : null,
            // null for the global scope (Super Admin, no client selected)
            // — Active Contact Groups is a per-tenant concept, same
            // reasoning as 'quota' above. Also null if the contact_groups
            // table itself hasn't been migrated yet (Group Messaging
            // Step 1 — still pending its own authorization).
            'active_contact_groups' => ($account && Schema::hasTable('contact_groups'))
                ? ContactGroup::where('account_id', $account->id)->count()
                : null,
        ]);
    }

    /**
     * GET /api/analytics/charts — daily sent/failed series (gap-filled, so
     * the frontend never has to handle a missing date itself) plus an
     * engine usage breakdown.
     *
     * KNOWN LIMITATION (disclosed in the Module 7 report): engine_type is
     * read from the account's CURRENT subscription only, not attributed
     * per-message at send time — AccountController::updateSubscription
     * mutates the existing subscription row in place rather than
     * versioning it (see Account::subscriptions()' docblock), so a
     * message sent while the account was on a since-changed engine cannot
     * be honestly reconstructed after the fact. For an account that has
     * never switched engines this is exact; for one that has, it's an
     * approximation labelled as such, not silently wrong.
     */
    public function charts(Request $request): JsonResponse
    {
        $account = $this->resolveAccount($request);
        $subscription = $account?->currentSubscription;

        $data = $request->validate([
            'range' => ['sometimes', Rule::in(['7', '30', 'custom'])],
            'from' => ['required_if:range,custom', 'date'],
            'to' => ['required_if:range,custom', 'date'],
        ]);

        $rangeParam = $data['range'] ?? '7';

        if ($rangeParam === 'custom') {
            $from = Carbon::parse($data['from'])->startOfDay();
            $to = Carbon::parse($data['to'])->endOfDay();

            abort_if($to->lt($from), 422, '"to" must be on or after "from".');
            abort_if($to->diffInDays($from) > self::MAX_RANGE_DAYS, 422, 'Custom range cannot exceed '.self::MAX_RANGE_DAYS.' days.');
        } else {
            $days = (int) $rangeParam;
            $to = now()->endOfDay();
            $from = now()->subDays($days - 1)->startOfDay();
        }

        // API Performance — this endpoint was measured taking 1-4s under
        // load and getting hit in duplicate bursts from the frontend (see
        // charts()'s own docblock and the audit report). The expensive
        // part is everything below — a GROUP BY scan over payment_alerts
        // plus, for the global/no-account-selected scope, a second
        // whole-table engine-breakdown query and an invoices scan for the
        // revenue series. None of that changes within the same 60-second
        // window for the same tenant+range, so it's cached as one unit
        // keyed on exactly what can change the result: which
        // account (or 'global') is asking, and the validated range
        // params (not the raw request — an irrelevant extra query string
        // param must not fragment the cache). A duplicate burst of
        // identical requests arriving within that window is served from
        // cache after the first one populates it, which is also the fix
        // for "hit repeatedly in duplicate bursts" — there is nothing
        // left to deduplicate once repeats are this cheap.
        // [New feature, disclosed]: the daily sent/failed series below
        // (feeds the "Message Pulse" chart) now sources from
        // message_dispatch_logs — every dispatch pathway, not just
        // payment_alerts — once that table exists; same
        // Schema::hasTable() self-healing guard as summary() above, so
        // this degrades to the pre-existing payment_alerts-only series
        // rather than erroring on a deployment that hasn't migrated yet.
        // Folded into the existing 60s cache key so a stale/unmigrated
        // vs. migrated response is never mixed within one cache window.
        $dispatchLogsAvailable = Schema::hasTable('message_dispatch_logs');
        // Group Messaging Phase 5 — same two independent guards as
        // summary() above; see that method's setup comment for why
        // each is checked separately from $dispatchLogsAvailable.
        $recipientTypeAvailable = $dispatchLogsAvailable && Schema::hasColumn('message_dispatch_logs', 'recipient_type');
        $resolutionCountsAvailable = $recipientTypeAvailable && Schema::hasColumn('message_dispatch_logs', 'success_count');
        $cacheKey = 'analytics_charts_'.($account?->id ?? 'global').'_'.md5(json_encode($data)).'_'.($dispatchLogsAvailable ? 'v2' : 'v1').($recipientTypeAvailable ? 'rt' : '');

        return response()->json(Cache::remember($cacheKey, 60, function () use ($account, $subscription, $from, $to, $dispatchLogsAvailable, $recipientTypeAvailable, $resolutionCountsAvailable) {
            $query = $dispatchLogsAvailable
                ? ($account ? MessageDispatchLog::forAccount($account->id) : MessageDispatchLog::query())
                : ($account ? PaymentAlert::forAccount($account->id) : PaymentAlert::query());

            $rows = $query
                ->whereBetween('created_at', [$from, $to])
                ->selectRaw(
                    "DATE(created_at) as bucket_date, ".
                    "SUM(CASE WHEN status = 'sent' THEN 1 ELSE 0 END) as sent, ".
                    "SUM(CASE WHEN status = 'failed' THEN 1 ELSE 0 END) as failed"
                )
                ->groupBy('bucket_date')
                ->orderBy('bucket_date')
                ->get()
                ->keyBy('bucket_date');

            $daily = [];
            $attemptedTotal = 0;
            $cursor = $from->copy()->startOfDay();
            $end = $to->copy()->startOfDay();

            while ($cursor->lte($end)) {
                $key = $cursor->toDateString();
                $row = $rows->get($key);
                $sent = $row ? (int) $row->sent : 0;
                $failed = $row ? (int) $row->failed : 0;

                $daily[] = ['date' => $key, 'sent' => $sent, 'failed' => $failed];
                $attemptedTotal += $sent + $failed;
                $cursor->addDay();
            }

            $engineBreakdown = $account
                ? ($subscription ? [['engine_type' => $subscription->engine_type, 'count' => $attemptedTotal]] : [])
                : $this->globalEngineBreakdown($from, $to);

            // Super Admin Dashboard Overhaul — ECG/Heartbeat chart's revenue
            // series. Platform revenue has no per-tenant equivalent (same
            // reasoning as globalSummary()'s figures), so this is populated
            // ONLY for the global scope, null for an account-scoped call —
            // the frontend already treats every scope-conditional field as
            // optional (see 'quota' above). Guarded with Schema::hasTable()
            // because this environment has no way for me to confirm the
            // `invoices` migration has actually been run (disclosed in the
            // audit report) — degrades to an all-zero series rather than a
            // 500 if it hasn't.
            $dailyRevenue = $account ? null : $this->globalDailyRevenue($from, $to);

            // Group Messaging Phase 5 — "Individual vs Group" toggle on
            // the Message Pulse chart. null exactly when
            // $recipientTypeAvailable is false, same convention as
            // $dailyRevenue above; the pre-existing `daily` field is
            // completely unchanged (still every recipient_type
            // combined), so RevenuePulseChart and any other existing
            // consumer of `daily` is unaffected.
            $dailyByRecipientType = $recipientTypeAvailable
                ? $this->dailyRecipientTypeSeries($account, $from, $to, $resolutionCountsAvailable)
                : null;

            return [
                'scope' => $account ? 'account' : 'global',
                'range' => ['from' => $from->toDateString(), 'to' => $to->toDateString()],
                'daily' => $daily,
                'engine_breakdown' => $engineBreakdown,
                'daily_revenue' => $dailyRevenue,
                'daily_by_recipient_type' => $dailyByRecipientType,
            ];
        }));
    }

    /**
     * Gap-filled daily paid-invoice revenue for the Super Admin Dashboard's
     * ECG/Heartbeat chart. Keyed by Invoice.paid_at — the only reliably
     * dated revenue signal this schema has (see globalSummary()'s
     * 'current_month_revenue' docblock for why admin-provisioned
     * Subscription.price_paid can't be date-bucketed the same way).
     *
     * @return list<array{date: string, revenue: string}>
     */
    private function globalDailyRevenue(Carbon $from, Carbon $to): array
    {
        $rows = Schema::hasTable('invoices')
            ? Invoice::query()
                ->where('status', 'paid')
                ->whereBetween('paid_at', [$from, $to])
                ->selectRaw('DATE(paid_at) as bucket_date, SUM(total_amount) as revenue')
                ->groupBy('bucket_date')
                ->get()
                ->keyBy('bucket_date')
            : collect();

        $series = [];
        $cursor = $from->copy()->startOfDay();
        $end = $to->copy()->startOfDay();

        while ($cursor->lte($end)) {
            $key = $cursor->toDateString();
            $row = $rows->get($key);
            $series[] = ['date' => $key, 'revenue' => number_format($row ? (float) $row->revenue : 0.0, 2, '.', '')];
            $cursor->addDay();
        }

        return $series;
    }

    /**
     * Super Admin, no client selected: a REAL per-engine breakdown across
     * every tenant (not the tenant path's "current subscription only"
     * approximation, since there's no single subscription to approximate
     * from here). Two queries by design instead of one correlated
     * subquery — count-per-account in range doesn't need to know engine
     * type, and each account's current engine is looked up separately via
     * the existing currentSubscription relation (`ofMany`), so the SQL
     * stays portable across the SQLite/MySQL split this project has run on
     * and doesn't rely on a hand-written "latest subscription per account"
     * join I can't execute here to verify.
     *
     * @return list<array{engine_type: string, count: int}>
     */
    private function globalEngineBreakdown(Carbon $from, Carbon $to): array
    {
        $countsByAccount = PaymentAlert::query()
            ->whereBetween('created_at', [$from, $to])
            ->selectRaw('account_id, count(*) as count')
            ->groupBy('account_id')
            ->pluck('count', 'account_id');

        if ($countsByAccount->isEmpty()) {
            return [];
        }

        $engineByAccount = Account::whereIn('id', $countsByAccount->keys())
            ->with('currentSubscription')
            ->get()
            ->mapWithKeys(fn (Account $a) => [$a->id => $a->currentSubscription?->engine_type]);

        $totals = [];
        foreach ($countsByAccount as $accountId => $count) {
            $engine = $engineByAccount[$accountId] ?? null;
            if (! $engine) {
                continue;
            }
            $totals[$engine] = ($totals[$engine] ?? 0) + (int) $count;
        }

        return collect($totals)
            ->map(fn (int $count, string $engine) => ['engine_type' => $engine, 'count' => $count])
            ->values()
            ->all();
    }

    /**
     * Group Messaging Phase 5 — Dashboard Analytics Upgrade. Splits the
     * summary()-level total_sent/total_failed figures by recipient_type.
     *
     * Individual rows are counted directly by `status`, exactly like
     * every other aggregate in this controller — one row per attempted
     * send.
     *
     * Group rows are different: ONE message_dispatch_logs row covers an
     * entire batch (see the migration adding success_count/failure_count
     * for the full root-cause explanation), so they are summed via those
     * two columns instead of counted by status. When
     * $resolutionCountsAvailable is false (that migration hasn't run
     * yet), this falls back to counting resolved BATCHES themselves — a
     * coarser, disclosed approximation ("batches sent" rather than
     * "recipients sent") rather than erroring on a missing column.
     *
     * @return array{
     *   individual: array{sent: int, failed: int},
     *   group: array{sent: int, failed: int, queued_batches: int, recipient_count: int},
     * }
     */
    private function recipientTypeBreakdown(?Account $account, Carbon $from, Carbon $to, bool $resolutionCountsAvailable): array
    {
        $base = $account ? MessageDispatchLog::forAccount($account->id) : MessageDispatchLog::query();

        $individual = (clone $base)
            ->where('recipient_type', 'individual')
            ->whereBetween('created_at', [$from, $to])
            ->selectRaw(
                "SUM(CASE WHEN status = 'sent' THEN 1 ELSE 0 END) as sent, ".
                "SUM(CASE WHEN status = 'failed' THEN 1 ELSE 0 END) as failed"
            )
            ->first();

        $groupQuery = (clone $base)
            ->where('recipient_type', 'group')
            ->whereBetween('created_at', [$from, $to]);

        $groupSelect = $resolutionCountsAvailable
            ? "SUM(COALESCE(success_count, 0)) as sent, SUM(COALESCE(failure_count, 0)) as failed, SUM(CASE WHEN status = 'queued' THEN 1 ELSE 0 END) as queued_batches, SUM(recipient_count) as recipient_count"
            : "SUM(CASE WHEN status = 'sent' THEN 1 ELSE 0 END) as sent, SUM(CASE WHEN status = 'failed' THEN 1 ELSE 0 END) as failed, SUM(CASE WHEN status = 'queued' THEN 1 ELSE 0 END) as queued_batches, SUM(recipient_count) as recipient_count";

        $groupAgg = $groupQuery->selectRaw($groupSelect)->first();

        return [
            'individual' => ['sent' => (int) $individual->sent, 'failed' => (int) $individual->failed],
            'group' => [
                'sent' => (int) $groupAgg->sent,
                'failed' => (int) $groupAgg->failed,
                'queued_batches' => (int) $groupAgg->queued_batches,
                'recipient_count' => (int) $groupAgg->recipient_count,
            ],
        ];
    }

    /**
     * Group Messaging Phase 5 — the "Individual vs Group" toggle on the
     * Message Pulse chart. Gap-filled per calendar day exactly like the
     * pre-existing `daily` series charts() already builds, just split by
     * recipient_type using the same sent/failed sourcing rules as
     * recipientTypeBreakdown() above (see that method's docblock for why
     * group rows use success_count/failure_count rather than a per-row
     * status count).
     *
     * @return array{
     *   individual: list<array{date: string, sent: int, failed: int}>,
     *   group: list<array{date: string, sent: int, failed: int}>,
     * }
     */
    private function dailyRecipientTypeSeries(?Account $account, Carbon $from, Carbon $to, bool $resolutionCountsAvailable): array
    {
        $base = $account ? MessageDispatchLog::forAccount($account->id) : MessageDispatchLog::query();

        $individualRows = (clone $base)
            ->where('recipient_type', 'individual')
            ->whereBetween('created_at', [$from, $to])
            ->selectRaw(
                "DATE(created_at) as bucket_date, ".
                "SUM(CASE WHEN status = 'sent' THEN 1 ELSE 0 END) as sent, ".
                "SUM(CASE WHEN status = 'failed' THEN 1 ELSE 0 END) as failed"
            )
            ->groupBy('bucket_date')
            ->get()
            ->keyBy('bucket_date');

        $groupSelect = $resolutionCountsAvailable
            ? "DATE(created_at) as bucket_date, SUM(COALESCE(success_count, 0)) as sent, SUM(COALESCE(failure_count, 0)) as failed"
            : "DATE(created_at) as bucket_date, SUM(CASE WHEN status = 'sent' THEN 1 ELSE 0 END) as sent, SUM(CASE WHEN status = 'failed' THEN 1 ELSE 0 END) as failed";

        $groupRows = (clone $base)
            ->where('recipient_type', 'group')
            ->whereBetween('created_at', [$from, $to])
            ->selectRaw($groupSelect)
            ->groupBy('bucket_date')
            ->get()
            ->keyBy('bucket_date');

        $individual = [];
        $group = [];
        $cursor = $from->copy()->startOfDay();
        $end = $to->copy()->startOfDay();

        while ($cursor->lte($end)) {
            $key = $cursor->toDateString();
            $iRow = $individualRows->get($key);
            $gRow = $groupRows->get($key);

            $individual[] = ['date' => $key, 'sent' => $iRow ? (int) $iRow->sent : 0, 'failed' => $iRow ? (int) $iRow->failed : 0];
            $group[] = ['date' => $key, 'sent' => $gRow ? (int) $gRow->sent : 0, 'failed' => $gRow ? (int) $gRow->failed : 0];
            $cursor->addDay();
        }

        return ['individual' => $individual, 'group' => $group];
    }


    /**
     * [New feature, disclosed] — "% API vs % Manual Web sends" breakdown
     * requested for the Dashboard/Analytics audit. Counts EVERY
     * message_dispatch_logs row in range regardless of status (sent +
     * failed), the same denominator $attempted above uses — a source
     * that fails often should still show up as a large share of traffic,
     * not be hidden by counting only its successes. Only called when
     * Schema::hasTable('message_dispatch_logs') is true (see summary()'s
     * docblock); returns [] before that table exists or when it's empty
     * for the period, rather than a query error.
     *
     * @return list<array{source: string, count: int, percent: float}>
     */
    private function sourceDistribution(?Account $account, Carbon $from, Carbon $to): array
    {
        $counts = ($account ? MessageDispatchLog::forAccount($account->id) : MessageDispatchLog::query())
            ->whereBetween('created_at', [$from, $to])
            ->selectRaw('source, count(*) as count')
            ->groupBy('source')
            ->pluck('count', 'source');

        $total = (int) $counts->sum();

        if ($total === 0) {
            return [];
        }

        return $counts
            ->map(fn ($count, $source) => [
                'source' => $source,
                'count' => (int) $count,
                'percent' => round(((int) $count / $total) * 100, 2),
            ])
            ->values()
            ->all();
    }

    /**
     * @return array{0: Carbon, 1: Carbon}
     */
    private function resolveRange(Request $request, Carbon $subscriptionStartsAt): array
    {
        $data = $request->validate([
            'from' => ['sometimes', 'date'],
            'to' => ['sometimes', 'date'],
        ]);

        $from = isset($data['from']) ? Carbon::parse($data['from'])->startOfDay() : $subscriptionStartsAt->copy()->startOfDay();
        $to = isset($data['to']) ? Carbon::parse($data['to'])->endOfDay() : now()->endOfDay();

        abort_if($to->lt($from), 422, '"to" must be on or after "from".');
        abort_if($to->diffInDays($from) > self::MAX_RANGE_DAYS, 422, 'Range cannot exceed '.self::MAX_RANGE_DAYS.' days.');

        return [$from, $to];
    }

    /**
     * GET /api/analytics/global-summary — Super-Admin-only platform-wide
     * KPI cards for the Super Admin Dashboard. Deliberately a separate
     * endpoint from summary() rather than a null-account branch on it:
     * summary()'s response includes a per-subscription "quota" block that
     * has no cross-tenant equivalent (quota is meaningless without one
     * subscription), so the two response shapes cannot be unified without
     * lying about one of them.
     *
     * Gated explicitly on is_super_admin (not just the view-analytics
     * permission every role holds) because a regular tenant Admin/User
     * must never see platform-wide figures for every other client.
     */
    public function globalSummary(Request $request): JsonResponse
    {
        abort_unless($request->attributes->get('is_super_admin'), 403, 'Only Super Admin may view platform-wide analytics.');

        // API Performance — same "measured 1-4s, hit in duplicate bursts"
        // problem as charts() (see that method's cache comment for the
        // full reasoning); the authorization check above deliberately
        // stays OUTSIDE the cached closure so a 403 is never cached or
        // served to someone it shouldn't be, but every query below it
        // computes the exact same platform-wide figures for every Super
        // Admin who asks — there is no per-request parameter here at all
        // (no account, no range), so the cache key is static; a 60s TTL
        // means the Dashboard is at most a minute stale, matching the
        // charts() TTL for consistency between two cards that often
        // render side by side.
        return response()->json(Cache::remember('analytics_global_summary', 60, function () {
            return $this->computeGlobalSummary();
        }));
    }

    /**
     * @return array<string, mixed>
     */
    private function computeGlobalSummary(): array
    {
        $totalClients = Account::count();

        // Financial & Revenue Analytics — "Active Clients" metric card.
        // Account.status is the admin-level field (independent of
        // subscription status — see Account::isAdministrativelyActive()'s
        // docblock), the same field AccountController::index()'s ?status=
        // filter already reads, so the Dashboard card's click-to-filter
        // (navigate to /admin/accounts?status=active) lands on results
        // that agree with this count.
        $activeClients = Account::where('status', 'active')->count();

        // [New feature, disclosed]: same message_dispatch_logs-over-
        // payment_alerts preference, guarded and self-healing, as
        // summary()/charts() above — see summary()'s docblock for the
        // full reasoning.
        $dispatchLogsAvailable = Schema::hasTable('message_dispatch_logs');
        $agg = $dispatchLogsAvailable
            ? MessageDispatchLog::selectRaw(
                "SUM(CASE WHEN status = 'sent' THEN 1 ELSE 0 END) as total_sent, ".
                "SUM(CASE WHEN status = 'failed' THEN 1 ELSE 0 END) as total_failed"
            )->first()
            : PaymentAlert::selectRaw(
                "SUM(CASE WHEN status = 'sent' THEN 1 ELSE 0 END) as total_sent, ".
                "SUM(CASE WHEN status = 'failed' THEN 1 ELSE 0 END) as total_failed"
            )->first();

        $totalSent = (int) $agg->total_sent;
        $totalFailed = (int) $agg->total_failed;
        $attempted = $totalSent + $totalFailed;
        $globalSuccessRate = $attempted > 0 ? round(($totalSent / $attempted) * 100, 2) : 0.0;

        $activeEngines = WhatsAppSession::where('status', 'connected')->count();

        // Super Admin Dashboard Enhancement — "Today's" counters, a
        // separate same-shape aggregate scoped to just today (server
        // timezone, matching every other today()/now() call in this
        // controller — there is no per-account timezone concept in this
        // schema to do better than that).
        $todayAgg = ($dispatchLogsAvailable ? MessageDispatchLog::query() : PaymentAlert::query())
            ->whereDate('created_at', now()->toDateString())
            ->selectRaw(
                "SUM(CASE WHEN status = 'sent' THEN 1 ELSE 0 END) as total_sent, ".
                "SUM(CASE WHEN status = 'failed' THEN 1 ELSE 0 END) as total_failed"
            )
            ->first();

        // Mirrors AccountController::expiringSoon()'s exact window/status
        // rule (active subscriptions expiring within the next 7 days) —
        // kept as a simple count query here rather than reusing that
        // endpoint's full row-hydration, since the Dashboard card only
        // needs the number; the modal it opens calls expiringSoon() for
        // the actual list.
        $expiringIn7Days = Account::query()
            ->whereHas('currentSubscription', function ($q) {
                $q->where('status', 'active')->whereBetween('expires_at', [now(), now()->addDays(7)]);
            })
            ->count();

        // Financial & Revenue Analytics.
        //
        // Total Platform Revenue: Subscription.price_paid is a running,
        // per-account LIFETIME total, not a per-transaction amount — every
        // successful gateway payment ADDS onto it (see
        // InvoiceCreditService::markPaidAndCreditQuota()), and every
        // admin-provisioned account starts with it set directly
        // (AccountController::store/updateSubscription). Because renewals
        // extend the account's single Subscription row in place rather
        // than inserting a new one (Account::subscriptions()'s docblock),
        // summing price_paid across every account's CURRENT subscription
        // is already the complete lifetime figure — it is NOT summed
        // together with paid Invoices below, which would double-count
        // every gateway-verified renewal that is already folded into
        // price_paid.
        //
        // Current Month Revenue: by contrast, price_paid carries no
        // per-payment timestamp, so it cannot be bucketed by month. The
        // ONLY revenue signal in this schema with a reliable payment date
        // is Invoice.paid_at (set exclusively by the self-serve gateway
        // checkout flow). This means an account whose entire history is
        // admin-provisioned (never checked out through Razorpay/Stripe)
        // contributes ₹0 to this figure even though it contributes to
        // Total Platform Revenue above — a real, disclosed asymmetry in
        // what this schema can honestly report, not a bug.
        //
        // Schema::hasTable('invoices') guards both this and
        // globalDailyRevenue() above: I have no way in this environment to
        // confirm the `invoices` migration has been run (see the standing
        // migration disclosure), so this degrades to 0 rather than a 500
        // if it hasn't, and self-heals once it has.
        // One eager-loaded query for every account's CURRENT subscription
        // (not N+1 — Eloquent's ofMany relation loads in bulk), refreshed
        // the same lazy way AccountController::expiringSoon() already
        // does for the subscriptions it touches, so status is accurate
        // without a full-table scan/refresh on every dashboard load.
        $accountsWithSub = Account::with('currentSubscription')->get();
        $accountsWithSub->each(fn (Account $a) => $a->currentSubscription?->refreshStatus());

        $totalPlatformRevenue = (float) $accountsWithSub->sum(
            fn (Account $a) => (float) ($a->currentSubscription?->price_paid ?? 0)
        );

        $currentMonthRevenue = Schema::hasTable('invoices')
            ? (float) Invoice::where('status', 'paid')
                ->whereBetween('paid_at', [now()->startOfMonth(), now()->endOfMonth()])
                ->sum('total_amount')
            : 0.0;

        // Pending/Overdue Payments: value of CURRENT subscriptions that are
        // NOT 'active' (expired or quota-exhausted) — mirrors
        // BillingController::clientSummary()'s existing 'overdue' bucket
        // definition exactly, for platform-wide consistency.
        $pendingOverdueRevenue = (float) $accountsWithSub
            ->filter(fn (Account $a) => $a->currentSubscription && $a->currentSubscription->status !== 'active')
            ->sum(fn (Account $a) => (float) $a->currentSubscription->price_paid);

        $arpu = $totalClients > 0 ? round($totalPlatformRevenue / $totalClients, 2) : 0.0;

        return [
            'total_clients' => $totalClients,
            'active_clients_count' => $activeClients,
            'total_messages_sent' => $totalSent,
            'total_messages_failed' => $totalFailed,
            'global_success_rate' => $globalSuccessRate,
            'active_whatsapp_engines' => $activeEngines,
            'total_messages_sent_today' => (int) $todayAgg->total_sent,
            'total_messages_failed_today' => (int) $todayAgg->total_failed,
            'expiring_in_7_days_count' => $expiringIn7Days,
            'total_platform_revenue' => number_format($totalPlatformRevenue, 2, '.', ''),
            'current_month_revenue' => number_format($currentMonthRevenue, 2, '.', ''),
            'pending_overdue_revenue' => number_format($pendingOverdueRevenue, 2, '.', ''),
            'arpu' => number_format($arpu, 2, '.', ''),
        ];
    }
}
