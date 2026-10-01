<?php

namespace App\Http\Middleware;

use App\Models\Account;
use App\Services\Industry\IndustryAuthorizer;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Phase 11 Task 1 — `industry.guard[:industry[,module]]`: the route gate every industry module route
 * uses. The industry / module come from the arguments, else from the route's {industry} / {module}
 * parameters. Must run after tenant.isolation (and, for a permission, the route's own permission
 * middleware); the user's permission for a module with its own `permission` is checked here too.
 *
 * The target is the account tenant.isolation resolved — a tenant's own, an Agent's selected
 * sub-client, a Super Admin's explicit ?account_id=. There is NO fallback: a Super Admin with no
 * client gets 422, never the first account and never the Platform account (this route deliberately
 * does not use target.account, whose Platform fallback is a Social/Ads-only owner decision).
 * Reads (GET/HEAD) stay allowed on an expired subscription; writes do not.
 */
class EnsureIndustryAccessMiddleware
{
    public function __construct(private readonly IndustryAuthorizer $authorizer)
    {
    }

    public function handle(Request $request, Closure $next, ?string $industry = null, ?string $module = null): Response
    {
        $industry ??= (string) $request->route('industry');
        $module ??= $request->route('module') !== null ? (string) $request->route('module') : null;

        $accountId = $request->attributes->get('account_id');
        $account = $accountId ? Account::findCached((int) $accountId) : null;

        if (! $account) {
            return response()->json([
                'success' => false,
                'message' => 'Select a client/tenant account first (pass ?account_id=).',
                'error_code' => 'TARGET_ACCOUNT_REQUIRED',
            ], 422);
        }

        $denial = $this->authorizer->denial($account, $industry, $module, $request->isMethodSafe(), $request->user());

        if ($denial !== null) {
            return response()->json(['success' => false, 'message' => $denial['message'], 'error_code' => $denial['code']], $denial['status']);
        }

        return $next($request);
    }
}
