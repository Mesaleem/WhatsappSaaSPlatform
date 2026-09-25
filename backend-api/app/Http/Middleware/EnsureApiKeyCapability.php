<?php

namespace App\Http\Middleware;

use App\Models\Account;
use App\Services\Access\AccessControlService;
use App\Services\Access\EntitlementAuditLogger;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Phase 6 — CRM Hardening Round 2, Issue 1. The capability gate for the
 * external Developer API.
 *
 * WHY THIS EXISTS RATHER THAN REUSING capability.guard: that middleware
 * reads the `account_id` request attribute set by
 * TenantIsolationMiddleware, and the /api/v1/* route groups deliberately
 * do not run tenant.isolation — there is no Sanctum user on those
 * requests at all, only an API key. Attaching capability.guard there
 * would read a null account and fall through, silently granting access.
 * So this is the SAME check against the SAME AccessControlService, with
 * the one difference that matters: it reads `api_account_id`, the
 * attribute AuthenticateApiKey resolved from the presented key's hash.
 *
 * That is also what makes the tenant authoritative. The account comes
 * from the key, never from the body, a header or a query parameter, so
 * a caller cannot name an account at all — let alone someone else's.
 *
 * NO SUPER-ADMIN BYPASS, unlike capability.guard. There is no human on
 * an API-key request; a key belongs to exactly one tenant and has
 * exactly that tenant's entitlements. A bypass here would be a way to
 * escape entitlement checks entirely.
 *
 * FAILS CLOSED. If the key somehow did not resolve an account, or the
 * account row is gone, this refuses rather than passing the request on —
 * again the opposite of capability.guard, whose fall-through is safe
 * only because a Sanctum-authenticated controller then calls
 * requireAccount().
 *
 * Usage: ->middleware('capability.apikey:crm')
 */
class EnsureApiKeyCapability
{
    public function __construct(private readonly AccessControlService $accessControl)
    {
    }

    public function handle(Request $request, Closure $next, string $capability): Response
    {
        $accountId = $request->attributes->get('api_account_id');

        if (! $accountId) {
            // Defensive: AuthenticateApiKey guarantees this for any
            // request that reaches here. Mirrors the same defensive 401
            // ExternalAlertController already performs.
            return response()->json(['status' => false, 'message' => 'Unauthenticated.'], 401);
        }

        $account = Account::find($accountId);

        if (! $account) {
            return response()->json(['status' => false, 'message' => 'Unauthenticated.'], 401);
        }

        if (! $this->accessControl->canTenant($account, $capability)) {
            // P5-8 — same boundary as the UI guard, audited the same way: the
            // key's own account, source api_key (never the request body).
            app(EntitlementAuditLogger::class)->record($account, false, [
                'action' => 'route.access', 'resource_type' => 'route', 'source' => 'api_key',
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
