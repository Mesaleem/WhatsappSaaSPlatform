<?php

namespace App\Http\Middleware;

use App\Models\Account;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Client Management, User Creation, Multi-Role Permissions & Feature
 * Module Checklists refactor — server-side enforcement for
 * Account::MODULES/allowed_modules, closing the gap this codebase's own
 * ACCOUNT_MODULE_LABELS docblock previously disclosed: "Server-side API
 * enforcement... exists ONLY for 'team_management'... Extending
 * server-side enforcement to the rest is a disclosed follow-up." This
 * middleware makes that follow-up reusable for any module slug, but is
 * currently wired to exactly ONE route group (social/ads → 'meta_ads'),
 * per this refactor's explicit, literal requirement — see routes/api.php
 * and this refactor's audit report for which modules remain
 * client-side-only (nav hiding), same as before.
 *
 * Must run AFTER TenantIsolationMiddleware (relies on its 'account_id'
 * and 'is_super_admin' request attributes, exactly like
 * ResolvesTenantAccount and SubscriptionGuardMiddleware do) — every
 * route this is attached to already sits inside that middleware group.
 *
 * Usage: ->middleware('module.guard:meta_ads')
 */
class EnsureModuleEnabledMiddleware
{
    /**
     * Group Messaging Phase 2 — per-module override for the 403 body
     * below. A module NOT listed here (every module this middleware
     * already gated before this map existed: chatbot, meta_ads,
     * social_accounts, message_logs) keeps the exact original generic
     * message/error_code, unchanged — this only ever ADDS an
     * override, never alters existing behavior for a route already
     * using this middleware.
     */
    private const CUSTOM_RESPONSES = [
        'contact_groups' => [
            'error_code' => 'GROUP_MODULE_DISABLED',
            'message' => 'Group Messaging is a paid feature. Please upgrade your subscription plan to unlock custom contact groups.',
        ],
    ];

    public function handle(Request $request, Closure $next, string $module): Response
    {
        // Absolute Super Admin Control — Super Admin is never blocked by
        // a toggle they themselves control, even while acting on a
        // client's behalf via ?account_id= (same bypass TeamController
        // and SubscriptionGuardMiddleware already give super_admin).
        if ($request->attributes->get('is_super_admin')) {
            return $next($request);
        }

        $accountId = $request->attributes->get('account_id');

        // A non-Super-Admin always carries their own account_id via
        // TenantIsolationMiddleware, so this is unreachable in practice
        // for them — but fail OPEN rather than 500 if it ever isn't set,
        // consistent with resolveAccount()'s own null-safety elsewhere.
        if (! $accountId) {
            return $next($request);
        }

        $account = Account::findCached((int) $accountId);

        if ($account && ! $account->hasModuleEnabled($module)) {
            $custom = self::CUSTOM_RESPONSES[$module] ?? null;

            return response()->json([
                'success' => false,
                'message' => $custom['message'] ?? 'This feature has been disabled for your account by the Super Admin.',
                'error_code' => $custom['error_code'] ?? 'MODULE_DISABLED',
            ], 403);
        }

        return $next($request);
    }
}
