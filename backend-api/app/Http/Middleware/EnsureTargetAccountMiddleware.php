<?php

namespace App\Http\Middleware;

use App\Models\Account;
use App\Services\Crm\PlatformCrmAccount;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Phase 9 Task 6 — `target.account[:module[,capability]]`: when a Super
 * Admin acts on a selected client (?account_id=), that CLIENT is checked
 * exactly as the client's own users are checked by subscription.guard +
 * module.guard (+ capability.guard):
 *
 *   - the client account must be administratively active (403 CLIENT_ACCOUNT_SUSPENDED);
 *   - the route's module must be enabled for it (403 MODULE_DISABLED), when given;
 *   - the route's capability must be held (403 CAPABILITY_NOT_ENTITLED), when given;
 *   - its subscription must be active for write requests (403 SUBSCRIPTION_EXPIRED);
 *     reads stay allowed, as they are for the tenant ("you can still view your data").
 *
 * Why: module.guard and subscription.guard let a Super Admin through
 * ("Absolute Super Admin Control"), so on the pre-Phase-9 Social routes
 * (Ads, Inbox, Comment Rules, Social Leads, Media, AI copy, Reports) a Super
 * Admin could e.g. launch paid ads for a suspended client. Phase 9 routes
 * use SocialTargetGate in their controllers instead (stricter: social
 * capability too); this middleware adds no capability, so tenants see no
 * change.
 *
 * Only a Super Admin WITH a selected client is checked here: tenant users
 * are already checked on their own account by subscription.guard /
 * module.guard, and a Super Admin with no client is refused by the
 * controller (requireTargetAccount → 422). Must run after tenant.isolation.
 *
 * Owner decision (2026-09-30), replacing two rules above:
 *   - a Super Admin with NO client selected acts on its own "Platform (Super
 *     Admin)" account (PlatformCrmAccount, the account CRM already uses) —
 *     so it can connect its own Facebook / Instagram / Ad Account, post and
 *     run ads. Without that row the controller still answers 422;
 *   - the capability (plan) and subscription checks are skipped for a Super
 *     Admin (a lapsed client can still be managed by the platform owner);
 *     suspension and module switches still apply to a selected client.
 */
class EnsureTargetAccountMiddleware
{
    public function handle(Request $request, Closure $next, ?string $module = null, ?string $capability = null): Response
    {
        if (! $request->attributes->get('is_super_admin')) {
            return $next($request);
        }

        $accountId = $request->attributes->get('account_id');

        if (! $accountId && ($own = app(PlatformCrmAccount::class)->find())) {
            $request->attributes->set('account_id', $own->id);
            $accountId = $own->id;
        }

        if (! $accountId) {
            return $next($request);
        }

        $account = Account::query()->with('currentSubscription')->find((int) $accountId);

        if (! $account || ! $account->isAdministrativelyActive()) {
            return $this->deny('This client account is suspended. Reactivate it before acting on its behalf.', 'CLIENT_ACCOUNT_SUSPENDED');
        }

        if ($module !== null && $module !== '' && ! $account->hasModuleEnabled($module)) {
            return $this->deny('This feature is switched off for the selected client account.', 'MODULE_DISABLED');
        }

        return $next($request);
    }

    private function deny(string $message, string $code): Response
    {
        return response()->json(['success' => false, 'message' => $message, 'error_code' => $code], 403);
    }
}
