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

        /**
         * Social Media Marketing & Meta Ads Automation Expansion —
         * Final Phase Production Polish.
         *
         * ROOT-CAUSE FINDING, verified directly against
         * vendor/laravel/framework/.../Configuration/Middleware.php: this
         * app's bootstrap/app.php never calls ->throttleApi(), so
         * Middleware::$apiLimiter stays null and Laravel 11's 'api'
         * middleware group therefore adds NO throttle middleware at all
         * (see that class's getMiddlewareGroups(), line ~496:
         * `$this->apiLimiter ? 'throttle:'.$this->apiLimiter : null`).
         * Concretely: EVERY route in routes/api.php was completely
         * unrated-limited before this — including the public,
         * unauthenticated Meta endpoints (/webhooks/meta,
         * /social/callback/{provider}, /social/webhook/{provider}), which
         * accept traffic from the open internet with no bearer token and
         * no session. This limiter closes that gap for those routes
         * specifically (applied in routes/api.php) rather than turning on
         * a blanket ->throttleApi() for the whole file, which would also
         * throttle authenticated tenant traffic — out of scope for this
         * fix and a behavior change nobody asked for.
         *
         * 120/minute per IP is deliberately generous: Meta's own retry/
         * delivery cadence for even a very active Page's lead+comment
         * webhooks is nowhere near this, so genuine Meta traffic should
         * never be throttled; this exists to bound abuse/DoS exposure on
         * a public endpoint, not to rate-shape legitimate webhook volume.
         */
        RateLimiter::for('meta-webhook', function (Request $request) {
            return Limit::perMinute(120)
                ->by($request->ip())
                ->response(fn () => response()->json([
                    'message' => 'Too many requests.',
                ], 429));
        });
    }
}
