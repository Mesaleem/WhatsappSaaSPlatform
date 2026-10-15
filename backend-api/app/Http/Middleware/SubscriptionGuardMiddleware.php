<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Read-Only Subscription Expired Mode. Previously this middleware
 * blocked EVERY request (GET included) with a 403 the moment an
 * account's subscription lapsed, which the frontend turned into a
 * full-screen redirect to /subscription-expired — a hard lock-out.
 *
 * That is deliberately no longer the behavior for a lapsed SUBSCRIPTION:
 * an account whose subscription is expired/inactive may still freely GET
 * everything behind this middleware (Dashboard, Analytics, Logs, Chatbot
 * rules, ...) — the platform stays fully READABLE. Only a request that
 * would MUTATE something (POST/PUT/PATCH/DELETE) is blocked with 403
 * SUBSCRIPTION_EXPIRED. The frontend mirrors this exact GET-vs-mutating
 * split client-side (see AuthContext::isReadOnly()) so mutating buttons
 * are disabled/tooltipped before the request is even attempted — this
 * middleware is the server-side backstop for that, not the only guard.
 *
 * Client Management & Account Deactivation Engine — a Super-Admin
 * DEACTIVATION (Account::status !== 'active') is deliberately NOT given
 * this same read-only leniency. The spec requires deactivation to
 * "instantly block authentication for ALL users" on that account_id —
 * "instantly" means an already-issued Sanctum token must stop working on
 * its very next request too, not only block a future login attempt (see
 * AuthController::login()'s matching check for the login-time half of
 * this). So this check runs FIRST, before the subscription check, and
 * blocks EVERY method including GET — a deactivated client sees nothing,
 * a merely-expired-subscription client still sees everything.
 *
 * Super Admin (global access, no tenant account) always passes through
 * unconditionally, regardless of HTTP method — unchanged from before.
 */
class SubscriptionGuardMiddleware
{
    private const SAFE_METHODS = ['GET', 'HEAD', 'OPTIONS'];

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user) {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }

        if ($user->isSuperAdmin()) {
            return $next($request);
        }

        $account = $user->account;

        if ($account && ! $account->isAdministrativelyActive()) {
            return response()->json([
                'message' => 'Your organization account has been suspended. Contact Super Admin.',
                'error_code' => 'CLIENT_ACCOUNT_SUSPENDED',
            ], 403);
        }

        // QA fixes (2026-10-14): an agent acting inside one of its sub-clients
        // (?account_id=, resolved by tenant.isolation) is held to THAT client's
        // state — a suspended or lapsed sub-client stays read-only for its agent
        // too — and not to the agent's own subscription.
        $targetId = $request->attributes->get('account_id');
        $target = $account;
        $actingForClient = $account && $targetId !== null && (int) $targetId !== (int) $account->id;
        // CRM routes check the selected client themselves (crm.target, with its own audit entry).
        if ($actingForClient && in_array('crm.target', (array) $request->route()?->gatherMiddleware(), true)) {
            return $next($request);
        }
        if ($actingForClient) {
            $target = \App\Models\Account::query()->with('currentSubscription')->find((int) $targetId) ?? $account;
            if (! in_array($request->method(), self::SAFE_METHODS, true)) {
                [$category, $errorCode, $message] = match (true) {
                    ! $target->isAdministrativelyActive() => ['account_suspended', 'CLIENT_ACCOUNT_SUSPENDED', 'This client account is suspended. Its data can be viewed but not changed.'],
                    ! $target->hasActiveSubscription() => ['no_active_subscription', 'SUBSCRIPTION_EXPIRED', "This client's subscription is not active. Its data can be viewed but not changed until it is renewed."],
                    default => [null, null, null],
                };
                if ($category !== null) {
                    app(\App\Services\Access\EntitlementAuditLogger::class)->record($target, false, [
                        'action' => 'account.target', 'resource_type' => 'route', 'source' => 'api',
                        'category' => $category, 'actor_account_id' => (int) $account->id, 'target_account_id' => (int) $target->id,
                        'error_code' => $errorCode, 'http_status' => 403,
                    ]);

                    return response()->json(['message' => $message, 'error_code' => $errorCode], 403);
                }
            }
        }

        $subscriptionActive = $target && $target->hasActiveSubscription();

        if (! $subscriptionActive && ! in_array($request->method(), self::SAFE_METHODS, true)) {
            return response()->json([
                'message' => "Your account's subscription is not active. You can still view your data, but this action is disabled until it's renewed.",
                'error_code' => 'SUBSCRIPTION_EXPIRED',
            ], 403);
        }

        return $next($request);
    }
}
