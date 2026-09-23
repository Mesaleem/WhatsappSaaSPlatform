<?php

namespace App\Http\Middleware;

use App\Models\ApiRequestLog;
use Closure;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Phase 3 Task 4 -- Public API observability/auditability. Registered as
 * the OUTERMOST middleware on both external Developer API route groups
 * (routes/api.php's /v1 auth.apikey group and /v1/whatsapp auth.apisecret
 * group), ahead of the auth middleware itself, so every request reaching
 * either group is logged exactly once regardless of outcome -- including
 * a request that never even resolves an API key.
 *
 * [Disclosed, deliberate scope boundary]: this middleware answers this
 * task's own checklist (successful requests, authentication failures,
 * authorization failures -- all of which are plain returned Responses
 * in this codebase, never thrown exceptions) plus, as a bonus, any
 * thrown exception (a FormRequest validation failure or a genuine 500)
 * via the catch block below, which re-throws the EXACT SAME exception
 * unchanged afterward -- Laravel's existing exception rendering (see
 * bootstrap/app.php's shouldRenderJsonWhen()) is completely untouched;
 * this middleware only ever adds one extra log row before letting the
 * original exception/response continue exactly as it already did.
 *
 * Never touches request/response BODY, query string, or the
 * Authorization/X-API-KEY/X-API-SECRET headers -- only path (no query
 * string, via Request::path()), method, the eventual status code,
 * duration, IP, and User-Agent are read. account_id/api_key_id/
 * api_key_prefix are read exclusively from the 'api_account_id'/
 * 'api_key' request attributes AuthenticateApiKey/ApiAuthMiddleware set
 * ONLY on a successful auth -- never from request input -- so a
 * cross-tenant injection attempt in the body/query/headers has the same
 * zero effect on this log that it already has on tenant resolution
 * itself (see PublicApiSecurityTest/PublicApiAuthorizationTest).
 */
class LogApiRequestMiddleware
{
    public function handle(Request $request, Closure $next): Response
    {
        // Reuse an inbound X-Request-Id if the caller already supplies
        // one (a common cross-service correlation convention) --
        // otherwise mint one. No existing request/correlation-id
        // generator was found anywhere in this codebase (this task's own
        // audit report), so this is the smallest addition covering it.
        $requestId = (string) ($request->header('X-Request-Id') ?: Str::uuid());
        $request->attributes->set('request_id', $requestId);

        $startedAt = microtime(true);

        try {
            $response = $next($request);
            $statusCode = $response->getStatusCode();
        } catch (Throwable $e) {
            // HttpResponseException (thrown by CreateWhatsAppGroupRequest/
            // UnifiedSendMessageRequest's failedValidation()) already
            // carries its own real status code -- logged accurately
            // rather than as a generic failure. Any other throwable is
            // logged as 500. Either way the exception is re-thrown
            // unmodified immediately after, so Laravel's normal exception
            // handling/rendering proceeds exactly as it already did.
            $statusCode = $e instanceof HttpResponseException ? $e->getResponse()->getStatusCode() : 500;
            $this->record($request, $requestId, $statusCode, $startedAt);

            throw $e;
        }

        $response->headers->set('X-Request-Id', $requestId);
        $this->record($request, $requestId, $statusCode, $startedAt);

        return $response;
    }

    private function record(Request $request, string $requestId, int $statusCode, float $startedAt): void
    {
        $apiKey = $request->attributes->get('api_key');
        $userAgent = $request->userAgent();

        ApiRequestLog::create([
            // Truncated defensively to this column's own width -- these
            // are caller-controlled inputs (path length is bounded in
            // practice by this app's own routes, but the header is
            // entirely the caller's choice) and this is an observability
            // log, not a place a caller should be able to fail a write
            // against.
            'request_id' => mb_substr($requestId, 0, 64),
            'account_id' => $request->attributes->get('api_account_id'),
            'api_key_id' => $apiKey?->id,
            'api_key_prefix' => $apiKey?->key_prefix,
            'method' => $request->method(),
            'path' => mb_substr($request->path(), 0, 255),
            'status_code' => $statusCode,
            'duration_ms' => (int) round((microtime(true) - $startedAt) * 1000),
            'ip' => $request->ip(),
            'user_agent' => $userAgent !== null ? mb_substr($userAgent, 0, 255) : null,
        ]);
    }
}
