<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Concerns\ResolvesTenantAccount;
use App\Http\Controllers\Controller;
use App\Models\Account;
use App\Models\Invoice;
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

        $query = $account ? PaymentAlert::forAccount($account->id) : PaymentAlert::query();

        $agg = $query
            ->whereBetween('created_at', [$from, $to])
            ->selectRaw(
                "SUM(CASE WHEN status = 'sent' THEN 1 ELSE 0 END) as total_sent, ".
                "SUM(CASE WHEN status = 'failed' THEN 1 ELSE 0 END) as total_failed, ".
                "COALESCE(SUM(CASE WHEN status = 'sent' THEN cost_deducted ELSE 0 END), 0) as total_cost"
            )
            ->first();

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
        $todayQuery = $account ? PaymentAlert::forAccount($account->id) : PaymentAlert::query();
        $todayAgg = $todayQuery
            ->whereDate('created_at', now()->toDateString())
            ->selectRaw(
                "SUM(CASE WHEN status = 'sent' THEN 1 ELSE 0 END) as total_sent, ".
                "SUM(CASE WHEN status = 'failed' THEN 1 ELSE 0 END) as total_failed"
            )
            ->first();

        return response()->json([
            'scope' => $account ? 'account' : 'global',
            'period' => ['from' => $from->toDateString(), 'to' => $to->toDateString()],
            'total_alerts_attempted' => $attempted,
            'total_sent' => $totalSent,
            'total_failed' => $totalFailed,
            'delivered_rate' => $deliveredRate,
            'total_cost_incurred' => number_format((float) $agg->total_cost, 4, '.', ''),
            'quota' => $quota,
            'total_sent_today' => (int) $todayAgg->total_sent,
            'total_failed_today' => (int) $todayAgg->total_failed,
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
        $cacheKey = 'analytics_charts_'.($account?->id ?? 'global').'_'.md5(json_encode($data));

        return response()->json(Cache::remember($cacheKey, 60, function () use ($account, $subscription, $from, $to) {
            $query = $account ? PaymentAlert::forAccount($account->id) : PaymentAlert::query();

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

            return [
                'scope' => $account ? 'account' : 'global',
                'range' => ['from' => $from->toDateString(), 'to' => $to->toDateString()],
                'daily' => $daily,
                'engine_breakdown' => $engineBreakdown,
                'daily_revenue' => $dailyRevenue,
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

        $agg = PaymentAlert::selectRaw(
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
        $todayAgg = PaymentAlert::whereDate('created_at', now()->toDateString())
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
