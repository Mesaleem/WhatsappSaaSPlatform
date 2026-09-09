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
 * That is deliberately no longer the behavior: an account whose
 * subscription is expired/inactive may still freely GET everything
 * behind this middleware (Dashboard, Analytics, Logs, Chatbot rules,
 * ...) — the platform stays fully READABLE. Only a request that would
 * MUTATE something (POST/PUT/PATCH/DELETE) is blocked with 403
 * SUBSCRIPTION_EXPIRED. The frontend mirrors this exact GET-vs-mutating
 * split client-side (see AuthContext::isReadOnly()) so mutating buttons
 * are disabled/tooltipped before the request is even attempted — this
 * middleware is the server-side backstop for that, not the only guard.
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
        $subscriptionActive = $account && $account->hasActiveSubscription();

        if (! $subscriptionActive && ! in_array($request->method(), self::SAFE_METHODS, true)) {
            return response()->json([
                'message' => "Your account's subscription is not active. You can still view your data, but this action is disabled until it's renewed.",
                'error_code' => 'SUBSCRIPTION_EXPIRED',
            ], 403);
        }

        return $next($request);
    }
}
