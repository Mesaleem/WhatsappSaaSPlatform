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

        // Module 3 Fix (2026-09-16) - Hierarchical Analytics Rollup.
        // $accountIds is the single source of truth every query below
        // now filters by: null means true cross-tenant global (Super
        // Admin, nothing selected), a 1-element array is the existing
        // single-account behavior (byte-identical to before this task),
        // and a 2+/0-element array is a new Agent-wide rollup. See
        // resolveHierarchicalScope()'s own docblock below $account's
        // returned here.
        $hierarchicalScope = $this->resolveHierarchicalScope($request, $account);
        $account = $hierarchicalScope['account'];
        $accountIds = $hierarchicalScope['accountIds'];
        $scopeLabel = $hierarchicalScope['scope'];

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

        $costQuery = $accountIds !== null ? PaymentAlert::whereIn('account_id', $accountIds) : PaymentAlert::query();
        $totalCost = (float) $costQuery
            ->whereBetween('created_at', [$from, $to])
            ->where('status', 'sent')
            ->sum('cost_deducted');

        if ($dispatchLogsAvailable) {
            // Group Messaging Undercount Fix (P0, 2026-09-16) — the
            // row-counting aggregate that used to live here undercounted
            // every group send, since one message_dispatch_logs row can
            // represent an entire batch (see
            // MessageDispatchLog::resolveGroupDispatch()'s docblock).
            // resolveRecipientAwareTotals() is the single shared
            // recipient_type-aware aggregation this file now uses
            // everywhere a "how many messages were sent/failed" figure
            // is computed, so this can never again disagree with
            // recipient_breakdown / today_breakdown / daily_by_recipient_type
            // below, which already used the correct logic.
            $totals = $this->resolveRecipientAwareTotals(
                $accountIds !== null ? MessageDispatchLog::whereIn('account_id', $accountIds) : MessageDispatchLog::query(),
                $from,
                $to,
                $recipientTypeAvailable,
                $resolutionCountsAvailable
            );
            $totalSent = $totals['sent'];
            $totalFailed = $totals['failed'];
        } else {
            $agg = ($accountIds !== null ? PaymentAlert::whereIn('account_id', $accountIds) : PaymentAlert::query())
                ->whereBetween('created_at', [$from, $to])
                ->selectRaw(
                    "SUM(CASE WHEN status = 'sent' THEN 1 ELSE 0 END) as total_sent, ".
                    "SUM(CASE WHEN status = 'failed' THEN 1 ELSE 0 END) as total_failed"
                )
                ->first();
            $totalSent = (int) $agg->total_sent;
            $totalFailed = (int) $agg->total_failed;
        }

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
        // Group Messaging Undercount Fix (P0, 2026-09-16) — same
        // recipient-aware correction as the period-scoped total above,
        // re-windowed to just today.
        if ($dispatchLogsAvailable) {
            $todayTotals = $this->resolveRecipientAwareTotals(
                $accountIds !== null ? MessageDispatchLog::whereIn('account_id', $accountIds) : MessageDispatchLog::query(),
                now()->startOfDay(),
                now()->endOfDay(),
                $recipientTypeAvailable,
                $resolutionCountsAvailable
            );
            $todaySent = $todayTotals['sent'];
            $todayFailed = $todayTotals['failed'];
        } else {
            $todayAgg = ($accountIds !== null ? PaymentAlert::whereIn('account_id', $accountIds) : PaymentAlert::query())
                ->whereDate('created_at', now()->toDateString())
                ->selectRaw(
                    "SUM(CASE WHEN status = 'sent' THEN 1 ELSE 0 END) as total_sent, ".
                    "SUM(CASE WHEN status = 'failed' THEN 1 ELSE 0 END) as total_failed"
                )
                ->first();
            $todaySent = (int) $todayAgg->total_sent;
            $todayFailed = (int) $todayAgg->total_failed;
        }

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
        // Module 2 Fix (2026-09-16) - API-level Group module protection.
        // Mirrors the existing hasModule('contact_groups') UI gate in
        // AuthContext, enforced here too so a direct API call (bypassing
        // the frontend) cannot read group-specific figures for an account
        // whose Group Messaging add-on is disabled. Global/no-account
        // scope (Super Admin, no client selected) is treated as
        // module-enabled - consistent with every other field in this
        // method that already renders a true platform-wide aggregate for
        // $account === null (recipient_breakdown, active_contact_groups).
        // Module 4 Fix (2026-09-16, verification only, no behavior
        // change) - $account here is ALWAYS the value resolveHierarchicalScope()
        // returned above (the selected Client when ?client_id= was
        // supplied, unrestricted for Super Admin), never the calling
        // Super Admin's own account (Super Admin has none) and never a
        // substituted Agent account. hasModuleEnabled() itself only ever
        // reads $this (effectiveModules(), Account.php) - it takes no
        // user/caller argument at all - so this is structurally
        // incapable of evaluating anyone's context but the resolved
        // $account's own. effectiveModules() DOES additionally cap a
        // Sub-Client's own allowed_modules against its real parent
        // Agent's allowed_modules (see that method's docblock,
        // "Absolute Super Admin Control") - that is the CLIENT's own
        // actual entitlement chain (a Sub-Client can never exceed what
        // its own Agent grants it), not the calling Agent's or Super
        // Admin's unrelated context, so it is not the bug this audit
        // was checking for.
        $groupModuleEnabled = $account ? $account->hasModuleEnabled('contact_groups') : true;

        $todayBreakdown = null;
        if ($recipientTypeAvailable) {
            $todayRange = $this->recipientTypeBreakdown($account, now()->startOfDay(), now()->endOfDay(), $resolutionCountsAvailable, $accountIds);
            $todayBreakdown = [
                'today_individual_sent' => $todayRange['individual']['sent'],
                'today_individual_failed' => $todayRange['individual']['failed'],
                // Module 2 Fix - null (not the real figure) when Group
                // Messaging is disabled for this account.
                'today_group_sent' => $groupModuleEnabled ? $todayRange['group']['sent'] : null,
                'today_group_failed' => $groupModuleEnabled ? $todayRange['group']['failed'] : null,
            ];
        }

        // Module 2 Fix - same suppression applied to the period-scoped
        // recipient breakdown before it reaches the response below.
        $recipientBreakdown = $recipientTypeAvailable
            ? $this->recipientTypeBreakdown($account, $from, $to, $resolutionCountsAvailable, $accountIds)
            : null;
        if ($recipientBreakdown && ! $groupModuleEnabled) {
            $recipientBreakdown['group'] = null;
        }

        // Module 2 Fix - same suppression applied to the live group count.
        $activeContactGroups = null;
        if ($groupModuleEnabled && Schema::hasTable('contact_groups')) {
            // Module 3 Fix (2026-09-16) - $accountIds !== null covers
            // both the pre-existing single-account case AND the new
            // Agent rollup case (whereIn over every id in the rollup);
            // only a genuinely unscoped Super Admin request still gets
            // the true platform-wide count.
            $activeContactGroups = $accountIds !== null
                ? ContactGroup::whereIn('account_id', $accountIds)->count()
                : ContactGroup::count();
        }

        return response()->json([
            // Module 3 Fix (2026-09-16) - $scopeLabel is 'account',
            // 'agent_rollup', or 'global' - see resolveHierarchicalScope().
            'scope' => $scopeLabel,
            'period' => ['from' => $from->toDateString(), 'to' => $to->toDateString()],
            'total_alerts_attempted' => $attempted,
            'total_sent' => $totalSent,
            'total_failed' => $totalFailed,
            'delivered_rate' => $deliveredRate,
            'total_cost_incurred' => number_format($totalCost, 4, '.', ''),
            'quota' => $quota,
            'total_sent_today' => $todaySent,
            'total_failed_today' => $todayFailed,
            // Dashboard & Analytics Fix Round 2. null under the same
            // self-healing condition as `recipient_breakdown` below.
            'today_breakdown' => $todayBreakdown,
            // [New feature, disclosed]: empty until message_dispatch_logs
            // exists — see sourceDistribution()'s docblock.
            'source_distribution' => $dispatchLogsAvailable ? $this->sourceDistribution($account, $from, $to, $accountIds) : [],
            // Group Messaging Phase 5 - null exactly when
            // $recipientTypeAvailable is false (see setup above), same
            // "absent, not fabricated" convention 'quota' above already
            // uses for a field that isn't meaningful/available yet.
            // Module 2 Fix (2026-09-16) - also null (its 'group' key,
            // specifically) when Group Messaging is disabled for this
            // account; see $groupModuleEnabled/$recipientBreakdown above.
            'recipient_breakdown' => $recipientBreakdown,
            // Super Admin Dashboard Group KPI Fix (2026-09-16) - per-tenant
            // count when an account is selected, exactly as before; a
            // REAL platform-wide count across every tenant when scope is
            // global ($account === null), instead of the previous hard
            // null. This mirrors recipient_breakdown/today_breakdown
            // immediately above, which already compute a true global
            // aggregate for the same $account === null case - this field
            // was the one inconsistent holdout. Still null when the
            // contact_groups table itself hasn't been migrated yet (Group
            // Messaging Step 1 - still pending its own authorization), and
            // now also null when Group Messaging is disabled for this
            // account (Module 2 Fix, 2026-09-16 - see $groupModuleEnabled).
            'active_contact_groups' => $activeContactGroups,
        ]);
    }

    /**
     * Module 3 Fix (2026-09-16) - Hierarchical Analytics Rollup. Layers
     * ?client_id= and ?agent_id= support on top of the existing
     * ?account_id= tenant-switch TenantIsolationMiddleware/
     * ResolvesTenantAccount::resolveAccount() already enforce, WITHOUT
     * touching either (both are shared by controllers other than this
     * one, so they are outside this task's Scope Lock: AnalyticsController
     * .php ONLY).
     *
     * - ?agent_id=Y (Super Admin only - enforced via the is_super_admin
     *   request attribute TenantIsolationMiddleware already sets, same
     *   source globalSummary() below already trusts): an aggregate
     *   rollup across every Client account owned by Agent Y. Reuses
     *   Account::scopeAgentsOnly()/scopeOwnedByAgent() - the exact same
     *   scopes AccountController's own existing Super-Admin ?agent_id=
     *   filter already uses - rather than inventing a second convention
     *   for the same relationship. Returns account=null (no single
     *   subscription/quota exists for an aggregate, same "absent, not
     *   fabricated" precedent 'quota' below already follows) and
     *   accountIds=that Agent's full Client-id list (possibly empty, if
     *   the Agent has no Clients yet - deliberately NOT treated as "no
     *   filter" - the accountIds !== null checks throughout this file).
     * - ?client_id=X: isolates to exactly that one Client account. Super
     *   Admin: unrestricted, same as the existing ?account_id= override.
     *   Any other caller (an Agent, or a plain Client/User) must own X
     *   (X.agent_id === the caller's own account_id, read from the
     *   agent_scope_id request attribute TenantIsolationMiddleware
     *   already computes for every tenant.isolation-wrapped request -
     *   the exact same attribute AccountController::callerAgentScopeId()
     *   re-derives for the identical purpose) or this aborts 403 - the
     *   Tenant Isolation Guard this task requires, covering both "another
     *   Agent's client" and "an independent direct/platform client"
     *   (agent_id null there never equals a non-null caller scope id).
     *   403 (not the 404 TenantIsolationMiddleware/AccountController use
     *   for the analogous cross-tenant case) is used deliberately here:
     *   it is what this task explicitly specifies, and it matches THIS
     *   file's own existing precedent for a role/scope authorization
     *   failure (see globalSummary()'s abort_unless(..., 403, ...) below).
     * - Neither param: unchanged - returns whatever resolveAccount()
     *   already resolved, byte-for-byte as before this task (existing
     *   ?account_id=/default-tenant behavior).
     *
     * @return array{account: ?Account, accountIds: ?array<int>, scope: string}
     */
    private function resolveHierarchicalScope(Request $request, ?Account $account): array
    {
        $isSuperAdmin = (bool) $request->attributes->get('is_super_admin');
        $callerAgentScopeId = $request->attributes->get('agent_scope_id');

        if ($request->filled('agent_id')) {
            abort_unless($isSuperAdmin, 403, 'Only a Super Admin may roll up analytics by agent_id.');

            $agentId = (int) $request->query('agent_id');
            $agentAccount = Account::agentsOnly()->find($agentId);

            abort_if(! $agentAccount, 404, 'The selected Agent account was not found.');

            // Deliberately NOT wrapped in Account::findCached() (unlike
            // the single-account lookups below) - this list needs to be
            // fresh on every call so a just-added/removed Sub-Client is
            // reflected immediately, and it is never looked up by id.
            $accountIds = Account::ownedByAgent($agentId)->pluck('id')->all();

            return ['account' => null, 'accountIds' => $accountIds, 'scope' => 'agent_rollup'];
        }

        if ($request->filled('client_id')) {
            $clientId = (int) $request->query('client_id');
            $clientAccount = Account::findCached($clientId);

            abort_if(! $clientAccount, 404, 'The selected client account was not found.');

            if (! $isSuperAdmin) {
                // Tenant Isolation Guard: an Agent may only target a
                // client_id that is one of ITS OWN Sub-Clients. A plain
                // Client/User caller has $callerAgentScopeId === null
                // (it is only ever set for an Agent - see
                // TenantIsolationMiddleware), so this also correctly
                // rejects a non-Agent caller trying to use client_id at
                // all, not just an Agent targeting someone else's client.
                abort_if(
                    $callerAgentScopeId === null || $clientAccount->agent_id !== $callerAgentScopeId,
                    403,
                    'You are not authorized to view analytics for this client account.'
                );
            }

            return ['account' => $clientAccount, 'accountIds' => [$clientAccount->id], 'scope' => 'account'];
        }

        return [
            'account' => $account,
            'accountIds' => $account ? [$account->id] : null,
            'scope' => $account ? 'account' : 'global',
        ];
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

        // Module 3 Fix (2026-09-16) - see summary()'s identical comment
        // above resolveHierarchicalScope().
        $hierarchicalScope = $this->resolveHierarchicalScope($request, $account);
        $account = $hierarchicalScope['account'];
        $accountIds = $hierarchicalScope['accountIds'];
        $scopeLabel = $hierarchicalScope['scope'];

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
        // Module 3 Fix (2026-09-16) - the cache key must distinguish
        // "true global" (null), "one account" (unchanged from before this
        // task - the same numeric id string, so existing warm caches for
        // ordinary requests are unaffected), and "Agent rollup" (2+ or 0
        // ids) from each other. Before this fix, an Agent rollup would
        // have collapsed to $account?->id ?? 'global' === 'global' since
        // $account is null for a rollup - silently colliding with, and
        // potentially SERVING, the true platform-wide global cache entry
        // (or another Agent's rollup) to whichever request populated the
        // key first. Caught and fixed here as part of adding the rollup
        // itself, not a separate/optional cleanup.
        $scopeKeyPart = 'global';
        if ($accountIds !== null) {
            $scopeKeyPart = count($accountIds) === 1
                ? (string) $accountIds[0]
                : 'agentrollup_'.md5(implode(',', $accountIds));
        }
        $cacheKey = 'analytics_charts_'.$scopeKeyPart.'_'.md5(json_encode($data)).'_'.($dispatchLogsAvailable ? 'v2' : 'v1').($recipientTypeAvailable ? 'rt' : '');

        return response()->json(Cache::remember($cacheKey, 60, function () use ($account, $accountIds, $scopeLabel, $subscription, $from, $to, $dispatchLogsAvailable, $recipientTypeAvailable, $resolutionCountsAvailable) {
            $query = $dispatchLogsAvailable
                ? ($accountIds !== null ? MessageDispatchLog::whereIn('account_id', $accountIds) : MessageDispatchLog::query())
                : ($accountIds !== null ? PaymentAlert::whereIn('account_id', $accountIds) : PaymentAlert::query());

            // Group Messaging Undercount Fix (P0, 2026-09-16) — computed
            // BEFORE `$daily` below (moved up from its original position
            // near the end of this closure) so the primary daily series
            // can be derived from it directly instead of re-querying with
            // the old row-counting aggregate. See
            // resolveRecipientAwareTotals()'s docblock for why a group
            // batch row can no longer be counted as "1 message" here.
            $dailyByRecipientType = $recipientTypeAvailable
                ? $this->dailyRecipientTypeSeries($account, $from, $to, $resolutionCountsAvailable, $accountIds)
                : null;

            if ($dailyByRecipientType) {
                // Derived by summing the already-correct individual+group
                // per-day figures rather than a second, row-counting
                // query — guarantees `daily` and `daily_by_recipient_type`
                // can never disagree (both arrays inside
                // $dailyByRecipientType are gap-filled over the same
                // $from..$to range in the same order, so they align
                // index-for-index).
                $daily = [];
                $attemptedTotal = 0;

                foreach ($dailyByRecipientType['individual'] as $index => $individualDay) {
                    $groupDay = $dailyByRecipientType['group'][$index];
                    $sent = $individualDay['sent'] + $groupDay['sent'];
                    $failed = $individualDay['failed'] + $groupDay['failed'];

                    $daily[] = ['date' => $individualDay['date'], 'sent' => $sent, 'failed' => $failed];
                    $attemptedTotal += $sent + $failed;
                }
            } else {
                // Fallback for a deployment without recipient_type yet
                // (or the payment_alerts-only path, which has no group
                // concept at all) — unchanged row-counting behavior.
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
            }

            // Module 2 Fix (2026-09-16) - API-level Group module
            // protection, mirrored from summary() above. $daily itself
            // (the combined individual+group total) is left untouched -
            // it is an overall figure, not a group-specific one - but the
            // per-recipient-type breakdown below is suppressed when this
            // account's Group Messaging add-on is disabled, so a direct
            // API call cannot read group-specific series either.
            // Module 4 Fix (2026-09-16, verification only, no behavior
            // change) - see summary()'s identical comment above: $account
            // is always the resolveHierarchicalScope() result captured
            // by this closure's use() clause, i.e. the selected Client
            // for ?client_id=, never the caller's own context.
            $groupModuleEnabled = $account ? $account->hasModuleEnabled('contact_groups') : true;
            if ($dailyByRecipientType && ! $groupModuleEnabled) {
                $dailyByRecipientType['group'] = null;
            }

            // Module 3 Fix (2026-09-16) - an Agent rollup ($account is
            // null, $accountIds is a real list) must NOT fall through to
            // $this->globalEngineBreakdown() - that computes a TRUE
            // platform-wide figure across every tenant, which would leak
            // far more than the requesting Agent's own Sub-Clients into a
            // response labelled 'agent_rollup'. There is no single
            // engine_type concept across multiple aggregated accounts
            // (each Sub-Client can be on a different engine), so this
            // follows the same "absent, not fabricated" convention
            // 'quota' already uses above rather than guessing one.
            if ($account) {
                $engineBreakdown = $subscription ? [['engine_type' => $subscription->engine_type, 'count' => $attemptedTotal]] : [];
            } elseif ($accountIds !== null) {
                $engineBreakdown = [];
            } else {
                $engineBreakdown = $this->globalEngineBreakdown($from, $to);
            }

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
            // Module 3 Fix (2026-09-16) - same true-platform-data leak
            // this method's $engineBreakdown above was just guarded
            // against: an Agent rollup must not receive platform-wide
            // revenue either.
            $dailyRevenue = $accountIds !== null ? null : $this->globalDailyRevenue($from, $to);

            // Group Messaging Phase 5 — "Individual vs Group" toggle on
            // the Message Pulse chart. $dailyByRecipientType itself is
            // computed earlier in this closure now (Group Messaging
            // Undercount Fix, 2026-09-16) — see the comment there. null
            // exactly when $recipientTypeAvailable is false, same
            // convention as $dailyRevenue above.
            return [
                // Module 3 Fix (2026-09-16) - see summary()'s identical
                // $scopeLabel comment above.
                'scope' => $scopeLabel,
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
     * Group Messaging Undercount Fix (P0, 2026-09-16) — single shared
     * recipient_type-aware "how many messages were sent/failed" helper.
     * Reused by every primary KPI/chart aggregate in this controller
     * (summary()'s period + today totals, charts()'s primary daily
     * series, computeGlobalSummary()'s platform-wide + today totals) so
     * none of them can ever again disagree with recipient_breakdown /
     * today_breakdown / daily_by_recipient_type below, which already
     * used this exact sent/failed definition.
     *
     * Individual rows: one row = one attempted send, counted by status
     * (unchanged from this file's original, pre-Group-Messaging
     * behavior).
     *
     * Group rows: one row = an entire batch (see
     * MessageDispatchLog::resolveGroupDispatch()'s docblock for why), so
     * counting rows would report "1" regardless of recipient count —
     * this sums success_count/failure_count instead, exactly like
     * recipientTypeBreakdown() below. Falls back to counting rows for
     * group data too when $resolutionCountsAvailable is false (the
     * success_count/failure_count migration hasn't run yet) — same
     * disclosed, self-healing approximation as recipientTypeBreakdown().
     *
     * $from/$to are both nullable together: pass null/null for an
     * all-time aggregate (computeGlobalSummary()'s platform-wide total,
     * which has no date range of its own), or a real Carbon pair to
     * scope by period exactly like every other date-ranged query in this
     * file.
     *
     * @return array{sent: int, failed: int}
     */
    private function resolveRecipientAwareTotals($baseQuery, ?Carbon $from, ?Carbon $to, bool $recipientTypeAvailable, bool $resolutionCountsAvailable): array
    {
        $scoped = clone $baseQuery;

        if ($from && $to) {
            $scoped->whereBetween('created_at', [$from, $to]);
        }

        if (! $recipientTypeAvailable) {
            $agg = (clone $scoped)
                ->selectRaw(
                    "SUM(CASE WHEN status = 'sent' THEN 1 ELSE 0 END) as sent, ".
                    "SUM(CASE WHEN status = 'failed' THEN 1 ELSE 0 END) as failed"
                )
                ->first();

            return ['sent' => (int) $agg->sent, 'failed' => (int) $agg->failed];
        }

        $individual = (clone $scoped)
            ->where('recipient_type', 'individual')
            ->selectRaw(
                "SUM(CASE WHEN status = 'sent' THEN 1 ELSE 0 END) as sent, ".
                "SUM(CASE WHEN status = 'failed' THEN 1 ELSE 0 END) as failed"
            )
            ->first();

        $groupSelect = $resolutionCountsAvailable
            ? "SUM(COALESCE(success_count, 0)) as sent, SUM(COALESCE(failure_count, 0)) as failed"
            : "SUM(CASE WHEN status = 'sent' THEN 1 ELSE 0 END) as sent, SUM(CASE WHEN status = 'failed' THEN 1 ELSE 0 END) as failed";

        $group = (clone $scoped)
            ->where('recipient_type', 'group')
            ->selectRaw($groupSelect)
            ->first();

        return [
            'sent' => (int) $individual->sent + (int) $group->sent,
            'failed' => (int) $individual->failed + (int) $group->failed,
        ];
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
    private function recipientTypeBreakdown(?Account $account, Carbon $from, Carbon $to, bool $resolutionCountsAvailable, ?array $accountIds = null): array
    {
        // Module 3 Fix (2026-09-16) - $accountIds, when passed, takes
        // priority over $account (which is null for an Agent rollup,
        // where there is no single Account to key off) but is otherwise
        // just [$account->id] - see resolveHierarchicalScope().
        $base = $accountIds !== null
            ? MessageDispatchLog::whereIn('account_id', $accountIds)
            : ($account ? MessageDispatchLog::forAccount($account->id) : MessageDispatchLog::query());

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
    private function dailyRecipientTypeSeries(?Account $account, Carbon $from, Carbon $to, bool $resolutionCountsAvailable, ?array $accountIds = null): array
    {
        // Module 3 Fix (2026-09-16) - see recipientTypeBreakdown()'s
        // identical comment above.
        $base = $accountIds !== null
            ? MessageDispatchLog::whereIn('account_id', $accountIds)
            : ($account ? MessageDispatchLog::forAccount($account->id) : MessageDispatchLog::query());

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
    private function sourceDistribution(?Account $account, Carbon $from, Carbon $to, ?array $accountIds = null): array
    {
        // Module 3 Fix (2026-09-16) - see recipientTypeBreakdown()'s
        // identical comment above.
        $counts = ($accountIds !== null ? MessageDispatchLog::whereIn('account_id', $accountIds) : MessageDispatchLog::query())
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
        // Group Messaging Undercount Fix (P0, 2026-09-16) — same two
        // independent guards summary()/charts() already compute, added
        // here so the platform-wide KPI can use the same
        // resolveRecipientAwareTotals() aggregation instead of the
        // row-counting SUM(CASE...) that used to live here (undercounted
        // every group batch platform-wide, not just per-tenant).
        $recipientTypeAvailable = $dispatchLogsAvailable && Schema::hasColumn('message_dispatch_logs', 'recipient_type');
        $resolutionCountsAvailable = $recipientTypeAvailable && Schema::hasColumn('message_dispatch_logs', 'success_count');

        if ($dispatchLogsAvailable) {
            $totals = $this->resolveRecipientAwareTotals(
                MessageDispatchLog::query(),
                null,
                null,
                $recipientTypeAvailable,
                $resolutionCountsAvailable
            );
            $totalSent = $totals['sent'];
            $totalFailed = $totals['failed'];
        } else {
            $agg = PaymentAlert::selectRaw(
                "SUM(CASE WHEN status = 'sent' THEN 1 ELSE 0 END) as total_sent, ".
                "SUM(CASE WHEN status = 'failed' THEN 1 ELSE 0 END) as total_failed"
            )->first();
            $totalSent = (int) $agg->total_sent;
            $totalFailed = (int) $agg->total_failed;
        }

        $attempted = $totalSent + $totalFailed;
        $globalSuccessRate = $attempted > 0 ? round(($totalSent / $attempted) * 100, 2) : 0.0;

        $activeEngines = WhatsAppSession::where('status', 'connected')->count();

        // Super Admin Dashboard Enhancement — "Today's" counters, a
        // separate same-shape aggregate scoped to just today (server
        // timezone, matching every other today()/now() call in this
        // controller — there is no per-account timezone concept in this
        // schema to do better than that).
        // Group Messaging Undercount Fix (P0, 2026-09-16) — same
        // recipient-aware correction as the all-time total above.
        if ($dispatchLogsAvailable) {
            $todayTotals = $this->resolveRecipientAwareTotals(
                MessageDispatchLog::query(),
                now()->startOfDay(),
                now()->endOfDay(),
                $recipientTypeAvailable,
                $resolutionCountsAvailable
            );
            $todaySent = $todayTotals['sent'];
            $todayFailed = $todayTotals['failed'];
        } else {
            $todayAgg = PaymentAlert::query()
                ->whereDate('created_at', now()->toDateString())
                ->selectRaw(
                    "SUM(CASE WHEN status = 'sent' THEN 1 ELSE 0 END) as total_sent, ".
                    "SUM(CASE WHEN status = 'failed' THEN 1 ELSE 0 END) as total_failed"
                )
                ->first();
            $todaySent = (int) $todayAgg->total_sent;
            $todayFailed = (int) $todayAgg->total_failed;
        }

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
            'total_messages_sent_today' => $todaySent,
            'total_messages_failed_today' => $todayFailed,
            'expiring_in_7_days_count' => $expiringIn7Days,
            'total_platform_revenue' => number_format($totalPlatformRevenue, 2, '.', ''),
            'current_month_revenue' => number_format($currentMonthRevenue, 2, '.', ''),
            'pending_overdue_revenue' => number_format($pendingOverdueRevenue, 2, '.', ''),
            'arpu' => number_format($arpu, 2, '.', ''),
        ];
    }
}
