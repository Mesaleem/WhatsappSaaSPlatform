<?php

namespace App\Http\Middleware;

use App\Support\Observability\RequestId;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Context;
use Symfony\Component\HttpFoundation\Response;

/**
 * Phase 12 Task 3 — a correlation id for every /api/* request.
 *
 * The id goes (1) onto the request as the `request_id` attribute — LogApiRequestMiddleware (/api/v1) reuses it,
 * (2) into Laravel's log Context, which stamps every log record written during the request and is carried
 * automatically in the payload of any job the request dispatches, and (3) onto the response header.
 *
 * Global middleware, so a 401/403/404/429 raised before the route's own middleware still carries the header.
 * It reads no credentials and no body; account_id/user_id are added once the caller is authenticated
 * (see CorrelationContext).
 */
class AssignRequestId
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->is('api/*')) {
            return $next($request);
        }

        $header = (string) config('observability.request_id.header', 'X-Request-Id');
        $requestId = RequestId::accept($request->headers->get($header)) ?? RequestId::generate();

        $request->attributes->set('request_id', $requestId);

        // Long-lived workers (Octane) reuse the process: never inherit another request's identity.
        Context::forget(['account_id', 'user_id']);
        Context::add('request_id', $requestId);

        $response = $next($request);
        $response->headers->set($header, $requestId);

        return $response;
    }
}
