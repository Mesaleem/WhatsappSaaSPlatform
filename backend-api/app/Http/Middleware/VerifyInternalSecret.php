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
     * services.qr_engine.internal_secret exactly. Fails closed if the
     * secret isn't configured at all.
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
