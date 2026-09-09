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
     * Non-Super-Admin users: always their own account_id. A ?account_id=
     * query parameter from a regular tenant user is never honored here —
     * only Super Admin may select a different tenant, preserving the
     * original isolation guarantee for everyone else.
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
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user) {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }

        $isSuperAdmin = $user->isSuperAdmin();
        $accountId = $user->account_id;

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
        }

        $request->attributes->set('account_id', $accountId);
        $request->attributes->set('is_super_admin', $isSuperAdmin);

        return $next($request);
    }
}
