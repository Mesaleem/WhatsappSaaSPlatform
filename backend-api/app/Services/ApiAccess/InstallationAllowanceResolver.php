<?php

namespace App\Services\ApiAccess;

use App\Models\Account;
use App\Models\Plan;
use App\Services\Access\AccessControlService;
use App\Services\Access\PlanEntitlementReconciliationService;

/**
 * Phase 4 Task 2 — `api_installations` capability foundation.
 *
 * Resolves the maximum number of LIVE API-key installations (bindings)
 * an account may hold. Deliberately separate from `external_api`
 * (AccessControlService::hasApiKeyAccess()), which only decides whether
 * an account may hold/use a Developer API key at all — this resolver
 * answers a different question: how many servers may be bound at once.
 *
 * DOES NOT COUNT LIVE BINDINGS. That belongs to Task 6, which will call
 * resolveForAccount() and compare its `allowance` against
 * COUNT(live ApiKeyBinding rows across every key of the account). This
 * class only resolves the NUMBER — it has no knowledge of ApiKeyBinding
 * at all, by design, so Task 6 can be reviewed as a single additive
 * diff on top of an already-correct resolver.
 *
 * WHERE THE LIMIT LIVES: `account_entitlements` (Phase 1 Foundation) is
 * boolean-only — it has no usage_limit column, so it answers "does the
 * account hold this capability" but not "what is the account's current
 * numeric limit for it". The only existing, codebase-sanctioned way to
 * reach a concrete `usage_limit` for an account is to resolve the
 * account's current Plan (PlanEntitlementReconciliationService::
 * currentPlanSlug() — "the established link... Adding a second source
 * of truth here is precisely what Task 10 forbids", per that method's
 * own docblock) and read that Plan's `plan_entitlements.usage_limit`
 * pivot for this capability. This class reuses that exact method rather
 * than inventing a second resolution path.
 *
 * NO UNLIMITED SENTINEL: a NULL usage_limit is never "no cap" here. It
 * is only ever read as a misconfiguration (rule 2) and defaults to
 * DEFAULT_ALLOWANCE with a diagnostic warning attached, never to an
 * unbounded allowance.
 *
 * AUDITED CURRENT-PLAN SEMANTICS (correction round): currentPlanSlug()
 * answers "the plan of the account's most recently PAID invoice", not
 * "a currently active, unexpired subscription". Verified against every
 * plan-lifecycle transition that exists in this codebase:
 *   - initial assignment / upgrade / downgrade / renewal: each is a new
 *     paid Invoice, so currentPlanSlug() (and therefore this resolver)
 *     updates immediately and correctly — proven in
 *     tests/Feature/Access/PlanManagementApiInstallationsTest.php,
 *     reusing the exact production fulfilment path
 *     (InvoiceCreditService::markPaidAndCreditQuota() ->
 *     PlanEntitlementReconciliationService::reconcile()) that
 *     tests/Feature/PlanLifecycleReconciliationTest.php already
 *     exhaustively covers for entitlement PRESENCE.
 *   - expiry / cancellation / non-renewal: NEITHER currentPlanSlug()
 *     NOR account_entitlements (the PRESENCE half this resolver also
 *     reads) is touched by Subscription expiry anywhere in this
 *     codebase — there is no reconciliation trigger tied to
 *     subscription status at all, only to a payment or a plan-bundle
 *     edit. So presence and the resolved limit continue reflecting the
 *     last paid plan after expiry, in lockstep, with no new divergence
 *     introduced by this resolver — exactly the pre-existing behavior
 *     every other plan-entitlement capability (e.g. external_api)
 *     already has.
 *   - a pending/unpaid invoice has zero effect until it is actually
 *     marked paid (currentPlanSlug() filters status='paid' only).
 * CONCLUSION: currentPlanSlug() is authoritative for "which plan this
 * account's entitlements currently mirror" by construction — it is the
 * ONLY input that ever changes account_entitlements' source='plan'
 * rows, so reading usage_limit off that same plan can never disagree
 * with what canTenant() already reports held. It is deliberately NOT a
 * real-time "is this subscription currently paid for" signal, and this
 * resolver does not claim otherwise.
 */
class InstallationAllowanceResolver
{
    public const CAPABILITY = 'api_installations';

    /** Allowance used whenever the capability is absent, or present but misconfigured (NULL limit). */
    public const DEFAULT_ALLOWANCE = 1;

    public const SOURCE_CAPABILITY_ABSENT = 'capability_absent';

    public const SOURCE_NULL_LIMIT = 'capability_null_limit';

    public const SOURCE_NEGATIVE_LIMIT = 'capability_negative_limit_defensive';

    public const SOURCE_EXPLICIT_LIMIT = 'capability_limit';

    public function __construct(
        private readonly AccessControlService $accessControl,
        private readonly PlanEntitlementReconciliationService $planResolver,
    ) {
    }

    /**
     * The pure rule (requirements 1–5), independent of how the inputs
     * were looked up — unit-testable without a database.
     *
     * @return array{allowance: int, source: string, warning: ?string}
     */
    public function resolveFromUsageLimit(bool $capabilityPresent, ?int $usageLimit): array
    {
        if (! $capabilityPresent) {
            // Rule 1.
            return [
                'allowance' => self::DEFAULT_ALLOWANCE,
                'source' => self::SOURCE_CAPABILITY_ABSENT,
                'warning' => null,
            ];
        }

        if ($usageLimit === null) {
            // Rule 2 — the capability is held but no concrete limit was
            // ever set (e.g. a manual grant with no plan behind it, or a
            // plan bundling it with its usage_limit never populated).
            return [
                'allowance' => self::DEFAULT_ALLOWANCE,
                'source' => self::SOURCE_NULL_LIMIT,
                'warning' => 'api_installations is held but has no configured usage_limit; defaulting to '.self::DEFAULT_ALLOWANCE.'. Set an explicit non-negative usage_limit on the governing plan.',
            ];
        }

        if ($usageLimit < 0) {
            // Rule 5 — defensive only. The `plan_entitlements.usage_limit`
            // column is UNSIGNED INT (see its migration), so no value this
            // service itself writes can ever be negative; this branch
            // exists purely in case a negative integer reaches here by
            // any other means (direct DB write, a future schema change, a
            // different write path introduced later) — it is never
            // exercised through the application's own write paths today.
            return [
                'allowance' => 0,
                'source' => self::SOURCE_NEGATIVE_LIMIT,
                'warning' => "api_installations usage_limit was negative ({$usageLimit}); resolved defensively to 0.",
            ];
        }

        // Rule 3 (usageLimit === 0 -> explicit deny) and rule 4 (positive
        // -> exact allowance) are the same branch: the limit IS the
        // allowance, with no special-casing of zero.
        return [
            'allowance' => $usageLimit,
            'source' => self::SOURCE_EXPLICIT_LIMIT,
            'warning' => null,
        ];
    }

    /**
     * Account-level convenience: resolves capability presence and the
     * account's current concrete usage_limit (if any) and applies the
     * pure rule above.
     *
     * @return array{allowance: int, source: string, warning: ?string}
     */
    public function resolveForAccount(Account $account): array
    {
        $present = $this->accessControl->canTenant($account, self::CAPABILITY);

        if (! $present) {
            return $this->resolveFromUsageLimit(false, null);
        }

        $usageLimit = $this->currentUsageLimit($account);

        return $this->resolveFromUsageLimit(true, $usageLimit);
    }

    /**
     * The `api_installations` usage_limit of the account's CURRENT plan,
     * or null if there is no current plan, no Plan row for its slug, or
     * that plan does not bundle this capability at all (e.g. the
     * account's entitlement was granted manually, independent of any
     * plan) — every one of those is a "no concrete limit configured"
     * case, read as rule 2 (NULL) by the caller above.
     */
    private function currentUsageLimit(Account $account): ?int
    {
        $planSlug = $this->planResolver->currentPlanSlug($account);

        if ($planSlug === null) {
            return null;
        }

        $plan = Plan::where('slug', $planSlug)->first();

        if (! $plan) {
            return null;
        }

        $capability = $plan->capabilities()->where('slug', self::CAPABILITY)->first();

        if (! $capability) {
            return null;
        }

        return $capability->pivot->usage_limit === null ? null : (int) $capability->pivot->usage_limit;
    }
}
