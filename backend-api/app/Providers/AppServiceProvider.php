<?php

namespace App\Providers;

use App\Models\Account;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        /**
         * Module 9 — "Enforce rate limiting based on the tenant account's
         * rate limit setting" (spec, requirement 1). Applied to the
         * external Developer API (Api\V1\*) via `throttle:external-api`,
         * positioned AFTER the `auth.apikey` middleware in that route
         * group so `api_account_id` is already resolved by the time this
         * callback runs. Falls back to a conservative 20/min keyed by IP
         * for the (should-be-unreachable) case where this fires without
         * AuthenticateApiKey having run first.
         */
        RateLimiter::for('external-api', function (Request $request) {
            $accountId = $request->attributes->get('api_account_id');
            $perMinute = $accountId
                ? (Account::find($accountId)?->api_rate_limit_per_minute ?? 60)
                : 20;

            return Limit::perMinute($perMinute)
                ->by($accountId ? "api-account:{$accountId}" : $request->ip())
                ->response(fn () => response()->json([
                    'message' => 'Rate limit exceeded. Please slow down your requests.',
                ], 429));
        });
    }
}
