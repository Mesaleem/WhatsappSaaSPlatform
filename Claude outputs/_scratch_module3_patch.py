import io

path = "/sessions/rcw-01seoneqazzequq9r4hgmvsf/mnt/wa-saas-platform/backend-api/app/Http/Controllers/Api/AnalyticsController.php"
with io.open(path, "r", encoding="utf-8", newline="") as f:
    content = f.read()

orig_len = len(content)
edits_applied = []

def apply(label, old, new, count=1):
    n = content.count(old)
    assert n == count, f"{label}: expected {count} match(es), found {n}"
    globals()['content'] = content.replace(old, new, count)
    edits_applied.append(label)

# ============================================================
# E1: insert resolveHierarchicalScope() between summary() and charts()
# ============================================================
old_e1 = """            'active_contact_groups' => $activeContactGroups,
        ]);
    }

    /**
     * GET /api/analytics/charts — daily sent/failed series (gap-filled, so"""

new_e1 = """            'active_contact_groups' => $activeContactGroups,
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
     *   filter", see resolveAccountIdsFilter() below).
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
     * GET /api/analytics/charts — daily sent/failed series (gap-filled, so"""

apply("E1 insert resolveHierarchicalScope", old_e1, new_e1)

# ============================================================
# E2: summary() - resolve hierarchical scope right after resolveAccount()
# ============================================================
old_e2 = """        $account = $this->resolveAccount($request);
        $subscription = $account?->currentSubscription;

        abort_if($account && ! $subscription, 404, 'This account has no subscription yet.');"""

new_e2 = """        $account = $this->resolveAccount($request);

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

        abort_if($account && ! $subscription, 404, 'This account has no subscription yet.');"""

apply("E2 summary() scope resolution", old_e2, new_e2)

# ============================================================
# E3: costQuery
# ============================================================
apply(
    "E3 costQuery",
    "        $costQuery = $account ? PaymentAlert::forAccount($account->id) : PaymentAlert::query();",
    "        $costQuery = $accountIds !== null ? PaymentAlert::whereIn('account_id', $accountIds) : PaymentAlert::query();",
)

# ============================================================
# E4: period totals via resolveRecipientAwareTotals
# ============================================================
apply(
    "E4 period totals base query",
    """            $totals = $this->resolveRecipientAwareTotals(
                $account ? MessageDispatchLog::forAccount($account->id) : MessageDispatchLog::query(),
                $from,
                $to,""",
    """            $totals = $this->resolveRecipientAwareTotals(
                $accountIds !== null ? MessageDispatchLog::whereIn('account_id', $accountIds) : MessageDispatchLog::query(),
                $from,
                $to,""",
)

# ============================================================
# E5: period fallback $agg (payment_alerts path)
# ============================================================
apply(
    "E5 period fallback agg",
    """            $agg = ($account ? PaymentAlert::forAccount($account->id) : PaymentAlert::query())
                ->whereBetween('created_at', [$from, $to])""",
    """            $agg = ($accountIds !== null ? PaymentAlert::whereIn('account_id', $accountIds) : PaymentAlert::query())
                ->whereBetween('created_at', [$from, $to])""",
)

# ============================================================
# E6: today totals via resolveRecipientAwareTotals
# ============================================================
apply(
    "E6 today totals base query",
    """            $todayTotals = $this->resolveRecipientAwareTotals(
                $account ? MessageDispatchLog::forAccount($account->id) : MessageDispatchLog::query(),
                now()->startOfDay(),""",
    """            $todayTotals = $this->resolveRecipientAwareTotals(
                $accountIds !== null ? MessageDispatchLog::whereIn('account_id', $accountIds) : MessageDispatchLog::query(),
                now()->startOfDay(),""",
)

# ============================================================
# E7: today fallback $todayAgg (payment_alerts path)
# ============================================================
apply(
    "E7 today fallback agg",
    """            $todayAgg = ($account ? PaymentAlert::forAccount($account->id) : PaymentAlert::query())
                ->whereDate('created_at', now()->toDateString())""",
    """            $todayAgg = ($accountIds !== null ? PaymentAlert::whereIn('account_id', $accountIds) : PaymentAlert::query())
                ->whereDate('created_at', now()->toDateString())""",
)

# ============================================================
# E8: todayRange recipientTypeBreakdown call
# ============================================================
apply(
    "E8 todayRange call",
    "            $todayRange = $this->recipientTypeBreakdown($account, now()->startOfDay(), now()->endOfDay(), $resolutionCountsAvailable);",
    "            $todayRange = $this->recipientTypeBreakdown($account, now()->startOfDay(), now()->endOfDay(), $resolutionCountsAvailable, $accountIds);",
)

# ============================================================
# E9: recipientBreakdown call
# ============================================================
apply(
    "E9 recipientBreakdown call",
    """        $recipientBreakdown = $recipientTypeAvailable
            ? $this->recipientTypeBreakdown($account, $from, $to, $resolutionCountsAvailable)
            : null;""",
    """        $recipientBreakdown = $recipientTypeAvailable
            ? $this->recipientTypeBreakdown($account, $from, $to, $resolutionCountsAvailable, $accountIds)
            : null;""",
)

# ============================================================
# E10: activeContactGroups block
# ============================================================
apply(
    "E10 activeContactGroups",
    """        $activeContactGroups = null;
        if ($groupModuleEnabled && Schema::hasTable('contact_groups')) {
            $activeContactGroups = $account
                ? ContactGroup::where('account_id', $account->id)->count()
                : ContactGroup::count();
        }""",
    """        $activeContactGroups = null;
        if ($groupModuleEnabled && Schema::hasTable('contact_groups')) {
            // Module 3 Fix (2026-09-16) - $accountIds !== null covers
            // both the pre-existing single-account case AND the new
            // Agent rollup case (whereIn over every id in the rollup);
            // only a genuinely unscoped Super Admin request still gets
            // the true platform-wide count.
            $activeContactGroups = $accountIds !== null
                ? ContactGroup::whereIn('account_id', $accountIds)->count()
                : ContactGroup::count();
        }""",
)

# ============================================================
# E11: summary() response scope + source_distribution call
# ============================================================
apply(
    "E11 summary() scope + source_distribution",
    """        return response()->json([
            'scope' => $account ? 'account' : 'global',
            'period' => ['from' => $from->toDateString(), 'to' => $to->toDateString()],""",
    """        return response()->json([
            // Module 3 Fix (2026-09-16) - $scopeLabel is 'account',
            // 'agent_rollup', or 'global' - see resolveHierarchicalScope().
            'scope' => $scopeLabel,
            'period' => ['from' => $from->toDateString(), 'to' => $to->toDateString()],""",
)

apply(
    "E11b source_distribution call",
    "            'source_distribution' => $dispatchLogsAvailable ? $this->sourceDistribution($account, $from, $to) : [],",
    "            'source_distribution' => $dispatchLogsAvailable ? $this->sourceDistribution($account, $from, $to, $accountIds) : [],",
)

# ============================================================
# E13/E14/E15: helper signatures - add trailing ?array $accountIds = null
# ============================================================
apply(
    "E13 recipientTypeBreakdown signature",
    """    private function recipientTypeBreakdown(?Account $account, Carbon $from, Carbon $to, bool $resolutionCountsAvailable): array
    {
        $base = $account ? MessageDispatchLog::forAccount($account->id) : MessageDispatchLog::query();""",
    """    private function recipientTypeBreakdown(?Account $account, Carbon $from, Carbon $to, bool $resolutionCountsAvailable, ?array $accountIds = null): array
    {
        // Module 3 Fix (2026-09-16) - $accountIds, when passed, takes
        // priority over $account (which is null for an Agent rollup,
        // where there is no single Account to key off) but is otherwise
        // just [$account->id] - see resolveHierarchicalScope().
        $base = $accountIds !== null
            ? MessageDispatchLog::whereIn('account_id', $accountIds)
            : ($account ? MessageDispatchLog::forAccount($account->id) : MessageDispatchLog::query());""",
)

apply(
    "E14 dailyRecipientTypeSeries signature",
    """    private function dailyRecipientTypeSeries(?Account $account, Carbon $from, Carbon $to, bool $resolutionCountsAvailable): array
    {
        $base = $account ? MessageDispatchLog::forAccount($account->id) : MessageDispatchLog::query();""",
    """    private function dailyRecipientTypeSeries(?Account $account, Carbon $from, Carbon $to, bool $resolutionCountsAvailable, ?array $accountIds = null): array
    {
        // Module 3 Fix (2026-09-16) - see recipientTypeBreakdown()'s
        // identical comment above.
        $base = $accountIds !== null
            ? MessageDispatchLog::whereIn('account_id', $accountIds)
            : ($account ? MessageDispatchLog::forAccount($account->id) : MessageDispatchLog::query());""",
)

apply(
    "E15 sourceDistribution signature",
    """    private function sourceDistribution(?Account $account, Carbon $from, Carbon $to): array
    {
        $counts = ($account ? MessageDispatchLog::forAccount($account->id) : MessageDispatchLog::query())
            ->whereBetween('created_at', [$from, $to])""",
    """    private function sourceDistribution(?Account $account, Carbon $from, Carbon $to, ?array $accountIds = null): array
    {
        // Module 3 Fix (2026-09-16) - see recipientTypeBreakdown()'s
        // identical comment above.
        $counts = ($accountIds !== null ? MessageDispatchLog::whereIn('account_id', $accountIds) : MessageDispatchLog::query())
            ->whereBetween('created_at', [$from, $to])""",
)

# ============================================================
# E16: charts() - resolve hierarchical scope after $subscription
# ============================================================
apply(
    "E16 charts() scope resolution",
    """        $account = $this->resolveAccount($request);
        $subscription = $account?->currentSubscription;

        $data = $request->validate([""",
    """        $account = $this->resolveAccount($request);

        // Module 3 Fix (2026-09-16) - see summary()'s identical comment
        // above resolveHierarchicalScope().
        $hierarchicalScope = $this->resolveHierarchicalScope($request, $account);
        $account = $hierarchicalScope['account'];
        $accountIds = $hierarchicalScope['accountIds'];
        $scopeLabel = $hierarchicalScope['scope'];

        $subscription = $account?->currentSubscription;

        $data = $request->validate([""",
)

# ============================================================
# E17: cache key + closure use() clause
# ============================================================
apply(
    "E17 cache key + use clause",
    """        $cacheKey = 'analytics_charts_'.($account?->id ?? 'global').'_'.md5(json_encode($data)).'_'.($dispatchLogsAvailable ? 'v2' : 'v1').($recipientTypeAvailable ? 'rt' : '');

        return response()->json(Cache::remember($cacheKey, 60, function () use ($account, $subscription, $from, $to, $dispatchLogsAvailable, $recipientTypeAvailable, $resolutionCountsAvailable) {""",
    """        // Module 3 Fix (2026-09-16) - the cache key must distinguish
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

        return response()->json(Cache::remember($cacheKey, 60, function () use ($account, $accountIds, $scopeLabel, $subscription, $from, $to, $dispatchLogsAvailable, $recipientTypeAvailable, $resolutionCountsAvailable) {""",
)

# ============================================================
# E18: $query ternary inside closure
# ============================================================
apply(
    "E18 charts() base query",
    """            $query = $dispatchLogsAvailable
                ? ($account ? MessageDispatchLog::forAccount($account->id) : MessageDispatchLog::query())
                : ($account ? PaymentAlert::forAccount($account->id) : PaymentAlert::query());""",
    """            $query = $dispatchLogsAvailable
                ? ($accountIds !== null ? MessageDispatchLog::whereIn('account_id', $accountIds) : MessageDispatchLog::query())
                : ($accountIds !== null ? PaymentAlert::whereIn('account_id', $accountIds) : PaymentAlert::query());""",
)

# ============================================================
# E19: dailyRecipientTypeSeries call
# ============================================================
apply(
    "E19 dailyRecipientTypeSeries call",
    """            $dailyByRecipientType = $recipientTypeAvailable
                ? $this->dailyRecipientTypeSeries($account, $from, $to, $resolutionCountsAvailable)
                : null;""",
    """            $dailyByRecipientType = $recipientTypeAvailable
                ? $this->dailyRecipientTypeSeries($account, $from, $to, $resolutionCountsAvailable, $accountIds)
                : null;""",
)

# ============================================================
# E20: engineBreakdown 3-way branch
# ============================================================
apply(
    "E20 engineBreakdown",
    """            $engineBreakdown = $account
                ? ($subscription ? [['engine_type' => $subscription->engine_type, 'count' => $attemptedTotal]] : [])
                : $this->globalEngineBreakdown($from, $to);""",
    """            // Module 3 Fix (2026-09-16) - an Agent rollup ($account is
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
            }""",
)

# ============================================================
# E21: dailyRevenue
# ============================================================
apply(
    "E21 dailyRevenue",
    "            $dailyRevenue = $account ? null : $this->globalDailyRevenue($from, $to);",
    """            // Module 3 Fix (2026-09-16) - same true-platform-data leak
            // this method's $engineBreakdown above was just guarded
            // against: an Agent rollup must not receive platform-wide
            // revenue either.
            $dailyRevenue = $accountIds !== null ? null : $this->globalDailyRevenue($from, $to);""",
)

# ============================================================
# E22: charts() response scope
# ============================================================
apply(
    "E22 charts() response scope",
    """            return [
                'scope' => $account ? 'account' : 'global',
                'range' => ['from' => $from->toDateString(), 'to' => $to->toDateString()],""",
    """            return [
                // Module 3 Fix (2026-09-16) - see summary()'s identical
                // $scopeLabel comment above.
                'scope' => $scopeLabel,
                'range' => ['from' => $from->toDateString(), 'to' => $to->toDateString()],""",
)

with io.open(path, "w", encoding="utf-8", newline="") as f:
    f.write(content)

print("OK - applied %d edits. orig_len=%d new_len=%d" % (len(edits_applied), orig_len, len(content)))
for e in edits_applied:
    print(" -", e)
