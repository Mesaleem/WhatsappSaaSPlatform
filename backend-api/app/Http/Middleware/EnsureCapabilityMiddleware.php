<?php

namespace App\Http\Middleware;

use App\Models\Account;
use App\Services\Access\AccessControlService;
use App\Services\Access\EntitlementAuditLogger;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Phase 6 — CRM, Task 2. Route-level enforcement for the CAPABILITY
 * dimension of this platform's authorization chain:
 *
 *   PLAN -> ENTITLEMENT -> CAPABILITY -> PERMISSION -> USAGE/QUOTA
 *
 * This is NOT a new permission system. It is a thin adapter over the
 * existing AccessControlService::canTenant() — the same call
 * JourneyNodeAuthorizer and AccountController already make — exposed as
 * middleware so a route group declares its capability the same way it
 * already declares its module (`module.guard:<slug>`) and its
 * subscription state (`subscription.guard`).
 *
 * WHY MIDDLEWARE RATHER THAN A CHECK IN EACH CONTROLLER ACTION: the
 * other two dimensions of the chain are already enforced as middleware,
 * and the capability dimension was the only one that was not — every
 * capability check in this codebase is hand-written inside one
 * controller action (WhatsAppFlowController::assertNodesEntitled()),
 * which is correct there because that check is per-NODE, not per-route.
 * A per-route capability is uniform across every action in its group and
 * cannot be forgotten when a fifth endpoint is added later.
 *
 * Behaviour is deliberately identical to EnsureModuleEnabledMiddleware:
 * Super Admin bypasses (never blocked by an entitlement they themselves
 * grant, even while acting on a client's behalf via ?account_id=); an
 * unresolvable account falls through rather than 500ing (unreachable for
 * a non-Super-Admin, who always carries their own account_id from
 * TenantIsolationMiddleware, and a Super Admin with no ?account_id= is
 * rejected by the controller's own requireAccount() with a 422); and the
 * refusal uses the same {success, message, error_code} 403 envelope.
 *
 * NOT CACHED, AND THAT IS A DECISION (Round 2, Limitation 8). Caching
 * was investigated rather than assumed impossible, and rejected on
 * evidence:
 *  - The cost is one indexed EXISTS against account_entitlements; the
 *    Account row itself is already served by Account::findCached(), so
 *    the uncached part is a single narrow query.
 *  - The thing it would cache is an AUTHORIZATION decision, and this
 *    platform revokes entitlements for real: PlanEntitlementReconciliation
 *    Service revokes on a plan downgrade and on a provider change, and
 *    AccountController can revoke by hand. Any TTL is a window in which a
 *    revoked tenant keeps CRM access — the brief's own "revoked CRM
 *    capability immediately denies access" requirement would become
 *    "denies access within N seconds".
 *  - Making it safe would mean invalidating on every write path that can
 *    touch an entitlement. AccountEntitlement::booted() already flushes
 *    Account::cacheKey() for exactly this reason, so a second cache key
 *    would have to be flushed in the same place — and the one existing
 *    precedent for a TTL cache over an authorization fact in this
 *    codebase (ProviderCapabilityService's 3600s memo) has already
 *    produced one real bug, fixed in Phase 5 Task 9 by adding explicit
 *    Cache::forget() calls to the seeder.
 * A single indexed query per request is not the bottleneck that justifies
 * carrying that risk. If profiling later shows otherwise, the safe shape
 * is a request-scoped memo (one resolution per HTTP request, no TTL at
 * all), not a timed cache — deliberately not added now, because with one
 * capability check per request it would memoize nothing.
 *
 * Must run AFTER TenantIsolationMiddleware — every route it is attached
 * to sits inside that group.
 *
 * Usage: ->middleware('capability.guard:crm')
 */
class EnsureCapabilityMiddleware
{
    public function __construct(private readonly AccessControlService $accessControl)
    {
    }

    public function handle(Request $request, Closure $next, string $capability): Response
    {
        if ($request->attributes->get('is_super_admin')) {
            return $next($request);
        }

        $accountId = $request->attributes->get('account_id');

        if (! $accountId) {
            return $next($request);
        }

        $account = Account::findCached((int) $accountId);

        if ($account && ! $this->accessControl->canTenant($account, $capability)) {
            // P5-8 — the denial is audited on the RESOLVED tenant (never a request value).
            app(EntitlementAuditLogger::class)->record($account, false, [
                'action' => 'route.access', 'resource_type' => 'route', 'source' => 'api',
                'category' => 'capability_not_entitled', 'capability' => $capability,
                'error_code' => 'CAPABILITY_NOT_ENTITLED', 'http_status' => 403,
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Your current plan does not include this feature. Please upgrade your subscription to unlock it.',
                'error_code' => 'CAPABILITY_NOT_ENTITLED',
            ], 403);
        }

        return $next($request);
    }
}
