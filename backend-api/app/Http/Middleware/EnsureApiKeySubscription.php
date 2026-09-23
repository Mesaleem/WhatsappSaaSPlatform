<?php

namespace App\Http\Middleware;

use App\Models\Account;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Phase 6 — CRM Task 11. `subscription.guard` for the external Developer
 * API, with the same rule: reads are allowed without an active
 * subscription ("you can still view your data"), writes are not.
 *
 * SubscriptionGuardMiddleware cannot be reused on /api/v1/*: it reads
 * $request->user(), and an API-key request has no user (it would 401 every
 * call). This applies the identical predicate — Account::hasActiveSubscription()
 * — to the API key's account. A suspended account never reaches here
 * (AuthenticateApiKey already refuses it with 401). Fails closed: no
 * resolved key account is a 401.
 *
 * Must run after auth.apikey. Usage: ->middleware('subscription.apikey')
 */
class EnsureApiKeySubscription
{
    private const SAFE_METHODS = ['GET', 'HEAD', 'OPTIONS'];

    public function handle(Request $request, Closure $next): Response
    {
        $accountId = $request->attributes->get('api_account_id');
        $account = $accountId ? Account::find((int) $accountId) : null;

        if (! $account) {
            return response()->json(['status' => false, 'message' => 'Unauthenticated.'], 401);
        }

        if (! in_array($request->method(), self::SAFE_METHODS, true) && ! $account->hasActiveSubscription()) {
            return response()->json([
                'success' => false,
                'message' => "Your account's subscription is not active. You can still view your data, but this action is disabled until it's renewed.",
                'error_code' => 'SUBSCRIPTION_EXPIRED',
            ], 403);
        }

        return $next($request);
    }
}
