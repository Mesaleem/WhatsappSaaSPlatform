<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class VerifyInternalSecret
{
    /**
     * Gates service-to-service routes (currently: the qr-engine-service
     * webhook). Requires the X-Internal-Secret header to match
     * services.qr_engine.internal_secret exactly. Fails closed if that
     * config value is null/empty.
     *
     * [Local-dev fallback, disclosed]: config('services.qr_engine.internal_secret')
     * itself now resolves to a shared placeholder (not null) when
     * INTERNAL_API_SECRET is unset AND APP_ENV=local (see
     * config/services.php) — this class needs no change for that, since
     * it only ever compares against whatever that config value already
     * resolved to. In every non-local environment, an unset secret still
     * resolves to null here and this still rejects with 401, unchanged.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $expected = config('services.qr_engine.internal_secret');

        if (! $expected || $request->header('X-Internal-Secret') !== $expected) {
            return response()->json(['message' => 'Unauthorized.'], 401);
        }

        return $next($request);
    }
}
