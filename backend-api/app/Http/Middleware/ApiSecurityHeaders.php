<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Phase 12 Task 2 (M5) — baseline response headers for the JSON API (every /api/* response, registered as global middleware so errors raised before route middleware are covered).
 *
 *   X-Content-Type-Options: nosniff     a response is never re-interpreted as another content type (the public
 *                                       media route serves user-uploaded files, which makes this matter)
 *   X-Frame-Options                     the API is never a framing target (default DENY)
 *   Referrer-Policy                     default no-referrer
 *   Strict-Transport-Security           ONLY on requests that arrived over HTTPS (so plain-http development is not
 *                                       affected); no includeSubDomains / preload unless configured
 *
 * Deliberately absent: Content-Security-Policy (the React SPA is served separately; a CSP for it is out of scope)
 * and anything that would change CORS or caching. A header a route already set is never overwritten.
 */
class ApiSecurityHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        if (! $request->is('api/*') || ! config('security.headers.enabled', true)) {
            return $response;
        }

        $headers = [
            'X-Content-Type-Options' => 'nosniff',
            'X-Frame-Options' => (string) config('security.headers.frame_options', 'DENY'),
            'Referrer-Policy' => (string) config('security.headers.referrer_policy', 'no-referrer'),
        ];

        if ($request->isSecure() && config('security.headers.hsts.enabled', true)) {
            $value = 'max-age='.(int) config('security.headers.hsts.max_age', 15552000);
            if (config('security.headers.hsts.include_subdomains', false)) {
                $value .= '; includeSubDomains';
            }
            $headers['Strict-Transport-Security'] = $value;
        }

        foreach ($headers as $name => $value) {
            if ($value !== '' && ! $response->headers->has($name)) {
                $response->headers->set($name, $value);
            }
        }

        return $response;
    }
}
