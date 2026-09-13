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

        // [Fix, disclosed]: hash_equals() instead of !== for a
        // constant-time comparison, avoiding a byte-by-byte timing side
        // channel on the shared secret (mirrors the equivalent fix now
        // applied to qr-engine-service/src/server.js's requireInternalSecret,
        // which used a plain !== on the other side of this same secret
        // check). hash_equals() requires both arguments to be strings, so
        // the provided header is cast to string first — a null header
        // becomes '', which can never equal a non-empty configured
        // secret, preserving the existing fail-closed behavior when the
        // header is missing.
        if (! $expected || ! hash_equals((string) $expected, (string) $request->header('X-Internal-Secret'))) {
            return response()->json(['message' => 'Unauthorized.'], 401);
        }

        return $next($request);
    }
}
