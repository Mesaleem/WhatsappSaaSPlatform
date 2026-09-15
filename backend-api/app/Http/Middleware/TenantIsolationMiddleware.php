<?php

namespace App\Http\Middleware;

use App\Models\Account;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class TenantIsolationMiddleware
{
    /**
     * Injects the EFFECTIVE tenant account_id into the request scope so
     * downstream controllers/queries can strictly isolate tenant data.
     *
     * Non-Super-Admin, non-Agent users: always their own account_id. A
     * ?account_id= query parameter from a plain Client Admin/User/Social
     * Marketer is never honored here — only Super Admin, and now an
     * Agent acting within its own Sub-Client tree (see the Agent
     * Client-Switcher branch below), may select a different tenant.
     *
     * Super Admin users (account_id === null on the user row): carry no
     * tenant of their own. They may optionally pass ?account_id=X to scope
     * this one request to a specific client/tenant (a 404 is returned if
     * that account doesn't exist); omitting it resolves to null ("global
     * view"). Downstream controllers decide how to honor a null account:
     * read/list actions return aggregated cross-tenant data, while
     * mutating actions that inherently need exactly one tenant reject
     * with a 422 asking the caller to select one (see
     * App\Http\Controllers\Concerns\ResolvesTenantAccount).
     *
     * 3-Tier Hierarchy & Agent-Client Scope Engine (Phase 1) — also sets
     * `agent_scope_id`: non-null only when the caller's OWN account is an
     * Agent (Reseller), giving downstream account-MANAGEMENT endpoints
     * (distinct from the tenant's own `account_id` data above) the id
     * every cross-tenant Account query must then be constrained to via
     * Account::scopeOwnedByAgent(), so an Agent can only ever see/manage
     * its own sub-clients. Super Admin is unrestricted (always null),
     * matching account_id's own "no tenant of its own" treatment above.
     *
     * [Disclosed, Phase 1]: this attribute currently reaches no live
     * route. routes/api.php's `/api/admin/accounts` group — the only
     * endpoint this phase's spec asks to scope by agent — is deliberately
     * NOT wrapped in this middleware (see that route group's own
     * docblock: "operate across every tenant ... permission:manage-accounts
     * alone is the correct gate"), so AccountController implements the
     * equivalent guard itself, directly off $request->user(), rather than
     * off this attribute. This class is still updated per this phase's
     * literal spec, and the attribute is set correctly, for whichever
     * future tenant-scoped (i.e. actually wrapped in tenant.isolation)
     * route ends up needing an Agent-vs-Agent boundary. Separately: no
     * role/permission currently grants a non-Super-Admin user the
     * `manage-accounts` permission that route requires at all (seeded
     * only onto `super_admin` in RolePermissionSeeder) — wiring that is
     * not part of this phase's stated scope (schema + relationships +
     * scoping engine) and is called out here so it isn't mistaken for an
     * oversight.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user) {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }

        $isSuperAdmin = $user->isSuperAdmin();
        $accountId = $user->account_id;

        // Resolved BEFORE the Super Admin ?account_id= override below on
        // purpose: an Agent is never a Super Admin (the branch below only
        // ever runs for $isSuperAdmin === true), so $accountId here is
        // always this user's own, unoverridden account_id.
        $isAgent = ! $isSuperAdmin && $user->account?->account_type === 'agent';

        if ($isSuperAdmin) {
            $requestedAccountId = $request->query('account_id');

            if ($requestedAccountId !== null && $requestedAccountId !== '') {
                if (! Account::whereKey($requestedAccountId)->exists()) {
                    return response()->json(['message' => 'The selected client/tenant account was not found.'], 404);
                }

                $accountId = (int) $requestedAccountId;
            } else {
                $accountId = null;
            }
        } elseif ($isAgent) {
            // Agent Client-Switcher: an Agent may pass ?account_id=X to act
            // AS one of their own Sub-Clients for this one request, scoped
            // strictly to their own tree — mirroring the Super Admin
            // override above but bounded by ownership instead of being
            // unrestricted. $accountId still starts as the Agent's OWN
            // account_id (set above), so omitting the query param, or
            // passing the Agent's own id back, is always a no-op.
            $requestedAccountId = $request->query('account_id');

            if ($requestedAccountId !== null && $requestedAccountId !== '') {
                $targetAccount = Account::whereKey($requestedAccountId)->first();
                $isOwnAccount = $targetAccount && (int) $targetAccount->id === $accountId;
                $isOwnedSubClient = $targetAccount && $targetAccount->agent_id === $accountId;

                if (! $targetAccount || (! $isOwnAccount && ! $isOwnedSubClient)) {
                    return response()->json(['message' => 'The selected client account was not found or is not one of your Sub-Clients.'], 404);
                }

                $accountId = (int) $targetAccount->id;
            }
        }

        $request->attributes->set('account_id', $accountId);
        $request->attributes->set('is_super_admin', $isSuperAdmin);
        $request->attributes->set('agent_scope_id', $isAgent ? $user->account_id : null);

        return $next($request);
    }
}
