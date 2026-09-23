<?php

namespace App\Http\Middleware;

use App\Models\Account;
use App\Services\Access\AccessControlService;
use App\Services\Crm\PlatformCrmAccount;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * CRM — the Super Admin's CRM target account. Runs first inside the
 * /api/crm route group (after tenant.isolation + subscription.guard) and
 * does nothing for anyone who is not a Super Admin.
 *
 * 1. TARGET. A Super Admin with no client selected (TenantIsolationMiddleware
 *    left account_id = null, i.e. no ?account_id=) acts in the CRM on the
 *    Super Admin's own account (PlatformCrmAccount — the account_type
 *    'super_admin' row). A selected client (?account_id=, already
 *    validated by TenantIsolationMiddleware) stays the target. If no
 *    platform account exists, account_id stays null and the controllers'
 *    requireAccount() answers 422 exactly as before. Nothing is read from
 *    the request body.
 *
 * 2. ENTITLEMENT (owner decision, replaces the Task 11 bypass for CRM).
 *    module.guard / capability.guard deliberately let a Super Admin
 *    through; for CRM the TARGET account must itself hold the lead_crm
 *    module and the crm capability — the same predicates
 *    (Account::hasModuleEnabled(), AccessControlService::canTenant()) and
 *    the same 403 envelopes those guards use. So a Super Admin can manage
 *    CRM leads only for accounts entitled to CRM, including their own.
 *
 * Scope: the /api/crm group only. No other route's Super Admin behaviour
 * (global views, billing, Meta config) changes.
 */
class EnsureCrmTargetAccount
{
    public function __construct(
        private readonly AccessControlService $accessControl,
        private readonly PlatformCrmAccount $platform,
    ) {
    }

    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->attributes->get('is_super_admin')) {
            return $next($request);
        }

        $accountId = $request->attributes->get('account_id');

        if (! $accountId) {
            $own = $this->platform->find();
            if ($own) {
                $request->attributes->set('account_id', $own->id);
                $accountId = $own->id;
            }
        }

        if (! $accountId) {
            return $next($request);
        }

        $account = Account::findCached((int) $accountId);

        if (! $account) {
            return $next($request);
        }

        if (! $account->hasModuleEnabled('lead_crm')) {
            return response()->json([
                'success' => false,
                'message' => 'This feature has been disabled for your account by the Super Admin.',
                'error_code' => 'MODULE_DISABLED',
            ], 403);
        }

        if (! $this->accessControl->canTenant($account, 'crm')) {
            return response()->json([
                'success' => false,
                'message' => 'Your current plan does not include this feature. Please upgrade your subscription to unlock it.',
                'error_code' => 'CAPABILITY_NOT_ENTITLED',
            ], 403);
        }

        return $next($request);
    }
}
