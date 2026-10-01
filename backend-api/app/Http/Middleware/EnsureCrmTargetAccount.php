<?php

namespace App\Http\Middleware;

use App\Models\Account;
use App\Services\Access\AccessControlService;
use App\Services\Access\EntitlementAuditLogger;
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
 * 3. TARGET ACCOUNT STATE (Phase 6 fix P6-2). subscription.guard checks the
 *    CALLER's own account (Agent, or none for a Super Admin), so a caller
 *    acting on ANOTHER account — an Agent on a sub-client (?account_id=,
 *    validated by TenantIsolationMiddleware), a Super Admin on a client —
 *    could write CRM data into a suspended account or one without an
 *    active subscription, which that account's own users cannot do. For
 *    every caller whose resolved target is not their own account (and is
 *    not the Super Admin's platform CRM account, which has no
 *    subscription by design), a WRITE is refused when the target is
 *    suspended (CLIENT_ACCOUNT_SUSPENDED) or its subscription is not
 *    active (SUBSCRIPTION_EXPIRED) — the same predicates and envelopes as
 *    SubscriptionGuardMiddleware, applied to the target. Reads stay allowed
 *    (oversight), as for an expired account's own users. Refusals are
 *    recorded through the P5-8 EntitlementAuditLogger on the target.
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

    private const SAFE_METHODS = ['GET', 'HEAD', 'OPTIONS'];

    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->attributes->get('is_super_admin')) {
            return $this->guardForeignTarget($request, $next);
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

        // Owner decision (2026-09-30): a Super Admin is not held to the client's
        // plan — no `crm` capability check here (module switches, suspension and
        // the write-needs-subscription rule below still apply).

        return $this->guardForeignTarget($request, $next);
    }

    /**
     * P6-2 — a write into an account that is not the caller's own must meet
     * that account's own suspension/subscription rule (see the docblock).
     * The target is the RESOLVED request attribute only.
     */
    private function guardForeignTarget(Request $request, Closure $next): Response
    {
        $accountId = $request->attributes->get('account_id');
        $user = $request->user();

        if (! $accountId || in_array($request->method(), self::SAFE_METHODS, true)) {
            return $next($request);
        }

        $ownAccountId = $user?->account_id === null ? null : (int) $user->account_id;

        if ((int) $accountId === $ownAccountId) {
            return $next($request); // own account: subscription.guard already applied its rule
        }

        // Fresh, not findCached(): the subscription must be read as it is now.
        $target = Account::query()->with('currentSubscription')->find((int) $accountId);

        if (! $target || $target->account_type === PlatformCrmAccount::ACCOUNT_TYPE) {
            return $next($request);
        }

        [$category, $errorCode, $message] = match (true) {
            ! $target->isAdministrativelyActive() => ['account_suspended', 'CLIENT_ACCOUNT_SUSPENDED', 'The selected account has been suspended. Its CRM data cannot be changed.'],
            // Owner decision (2026-09-30): a lapsed subscription does not stop a Super Admin.
            ! $request->attributes->get('is_super_admin') && ! $target->hasActiveSubscription() => ['no_active_subscription', 'SUBSCRIPTION_EXPIRED', "The selected account's subscription is not active. Its CRM data can be viewed but not changed until it is renewed."],
            default => [null, null, null],
        };

        if ($category === null) {
            return $next($request);
        }

        app(EntitlementAuditLogger::class)->record($target, false, [
            'action' => 'crm.target', 'resource_type' => 'route', 'source' => 'api', 'module' => 'lead_crm',
            'category' => $category, 'actor_account_id' => $ownAccountId, 'target_account_id' => (int) $target->id,
            'error_code' => $errorCode, 'http_status' => 403,
        ]);

        return response()->json(['success' => false, 'message' => $message, 'error_code' => $errorCode], 403);
    }
}
