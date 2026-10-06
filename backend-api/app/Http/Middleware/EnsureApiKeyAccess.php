<?php

namespace App\Http\Middleware;

use App\Models\Account;
use App\Services\Access\AccessControlService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Gate for the Developer API key: the account must have bought a plan (see AccessControlService::hasApiKeyAccess).
 * Used on the /api/v1 routes (account from the API key) and on the Profile key screen (account from the session).
 * A Super Admin session passes, like capability.guard. Any other account without a plan is refused.
 */
class EnsureApiKeyAccess
{
    public function __construct(private readonly AccessControlService $accessControl)
    {
    }

    public function handle(Request $request, Closure $next): Response
    {
        if ($request->attributes->get('is_super_admin')) {
            return $next($request);
        }

        $accountId = $request->attributes->get('api_account_id') ?? $request->attributes->get('account_id');

        if (! $accountId) {
            return $next($request);
        }

        $account = Account::findCached((int) $accountId);

        if ($account && ! $this->accessControl->hasApiKeyAccess($account)) {
            return response()->json([
                'success' => false,
                'status' => false,
                'message' => 'Your current plan does not include this feature. Please upgrade your subscription to unlock it.',
                'error_code' => 'CAPABILITY_NOT_ENTITLED',
            ], 403);
        }

        return $next($request);
    }
}
