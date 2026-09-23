<?php

namespace App\Http\Middleware;

use App\Models\ApiIdempotencyKey;
use Closure;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Phase 3 Task 5 -- optional Idempotency-Key protection for the external
 * Developer API's message-sending operations.
 *
 * Inspection first (this task's own audit): the only pre-existing
 * duplicate-send protection anywhere on these routes is
 * PaymentAlertDispatcher's payment_ref dedup
 * (unique(['account_id','payment_ref']) on payment_alerts, returning
 * 409) -- which covers exactly one endpoint and only when the caller
 * happens to reuse the same payment_ref. LogApiRequestMiddleware mints
 * an X-Request-Id, but purely for observability: nothing reads it back
 * to suppress a replay. The api_idempotency_keys table existed with no
 * model and no reader/writer at all. So generic idempotency was genuinely
 * missing, not already present under another name.
 *
 * Registered as the INNERMOST middleware on both external Developer API
 * route groups, deliberately after auth.apikey/auth.apisecret and after
 * throttle:external-api:
 *   - after auth, because a key is scoped to the authenticated account
 *     (api_account_id, read only from the request attribute the auth
 *     middleware sets -- never from request input), so one tenant's key
 *     can never collide with or replay another's;
 *   - after throttle, so rate limiting is untouched and a replay still
 *     counts against the caller's own limit exactly as before.
 *
 * Backward compatibility: a request with no Idempotency-Key header (or a
 * blank one) is passed straight through with zero added queries and zero
 * behaviour change -- the header is never mandatory. X-API-KEY /
 * Authorization: Bearer / X-API-SECRET handling is entirely untouched:
 * this middleware runs only after one of those has already succeeded,
 * and when auth fails it never even resolves an account (see the
 * api_account_id guard below), so an auth failure can never create a
 * record.
 *
 * Concurrency: the unique(['account_id','idempotency_key']) index is the
 * ONLY guard, deliberately -- there is no `if (exists())` pre-check,
 * which would be race-prone. Two simultaneous requests both attempt the
 * same INSERT; exactly one wins and proceeds to the actual send, and the
 * loser catches SQLSTATE 23000 and resolves against the winner's row
 * instead of ever reaching the Message Engine. This mirrors the exact
 * pattern PaymentAlertDispatcher already established for payment_ref
 * (see its own QueryException/'23000' comment), rather than introducing
 * any new locking framework.
 *
 * Failure handling: the claim row is written BEFORE the operation runs
 * and is kept only if the operation returned 2xx -- i.e. only once the
 * send/queue actually happened and a real response body exists to
 * replay. Every non-2xx outcome (validation 422, authorization 403/404,
 * quota 402/403, provider/send failure 422, disconnected 422) and every
 * thrown exception DELETES the claim, so a transient failure never gets
 * cached as a success and the caller may safely retry with the same key.
 */
class EnsureIdempotentApiRequest
{
    /** Reused key, genuinely different request payload. */
    private const ERROR_REUSED = 'IDEMPOTENCY_KEY_REUSED';

    /** Same key still in flight (or its outcome not yet safely recorded). */
    private const ERROR_IN_PROGRESS = 'IDEMPOTENCY_REQUEST_IN_PROGRESS';

    private const IN_PROGRESS_MESSAGE = 'A request with this Idempotency-Key is still being processed. Retry this request shortly.';

    public function handle(Request $request, Closure $next): Response
    {
        $key = trim((string) $request->header('Idempotency-Key', ''));

        // No header -> pre-existing behaviour, byte for byte. This is the
        // backward-compatibility contract: existing clients never sent
        // this header and must not be affected in any way.
        if ($key === '') {
            return $next($request);
        }

        // Set by AuthenticateApiKey/ApiAuthMiddleware on a successful
        // auth ONLY, and never from request input. Absent means auth did
        // not resolve an account, so there is no tenant to scope a key
        // to: pass through and let the existing auth layer return its own
        // unchanged 401. An authentication failure therefore never
        // creates an idempotency record.
        $accountId = $request->attributes->get('api_account_id');

        if (! $accountId) {
            return $next($request);
        }

        // The column is string(255); a longer key would be silently
        // truncated by MySQL (or stored over-long by SQLite), either of
        // which would let two different keys collide. Refuse instead.
        if (mb_strlen($key) > 255) {
            return $this->conflict($request, self::ERROR_REUSED, 'Idempotency-Key must not exceed 255 characters.');
        }

        $fingerprint = $this->fingerprint($request);

        try {
            $record = ApiIdempotencyKey::create([
                'account_id' => $accountId,
                'idempotency_key' => $key,
                'request_fingerprint' => $fingerprint,
                'status' => ApiIdempotencyKey::STATUS_PROCESSING,
            ]);
        } catch (QueryException $e) {
            // SQLSTATE 23000 = integrity constraint violation -- the
            // unique(['account_id','idempotency_key']) index firing,
            // consistent across SQLite (this project's test/local driver)
            // and MySQL, exactly as PaymentAlertDispatcher relies on for
            // payment_ref.
            if ($e->getCode() !== '23000') {
                throw $e;
            }

            return $this->resolveExisting($request, (int) $accountId, $key, $fingerprint);
        }

        try {
            $response = $next($request);
        } catch (Throwable $e) {
            // Nothing reached a safely-recorded completion point, so the
            // key must stay available for a genuine retry. The exception
            // is re-thrown unmodified -- Laravel's existing rendering
            // (and LogApiRequestMiddleware's own catch, which sits
            // outside this one) proceeds exactly as it already did.
            $record->delete();

            throw $e;
        }

        $status = $response->getStatusCode();

        if ($status >= 200 && $status < 300) {
            $record->forceFill([
                'status' => ApiIdempotencyKey::STATUS_COMPLETED,
                'response_status' => $status,
                'response_body' => $response->getContent(),
            ])->save();
        } else {
            $record->delete();
        }

        return $response;
    }

    /**
     * Our INSERT lost the unique race, so another request owns this
     * (account_id, idempotency_key). Decide what this caller gets
     * WITHOUT re-running the operation.
     */
    private function resolveExisting(Request $request, int $accountId, string $key, string $fingerprint): Response
    {
        $record = ApiIdempotencyKey::query()
            ->where('account_id', $accountId)
            ->where('idempotency_key', $key)
            ->first();

        // The row existed microseconds ago (our INSERT collided with it)
        // and is already gone -- the winner's operation failed and
        // released the key. Refusing is the only answer here that cannot
        // possibly produce a second outbound message; the caller retries
        // and gets a clean claim.
        if (! $record) {
            return $this->conflict($request, self::ERROR_IN_PROGRESS, self::IN_PROGRESS_MESSAGE);
        }

        // Same key, different request. Never silently treated as the
        // original -- that is the one outcome that would let a caller's
        // key-generation bug quietly drop a real message.
        if (! hash_equals((string) $record->request_fingerprint, $fingerprint)) {
            return $this->conflict(
                $request,
                self::ERROR_REUSED,
                'This Idempotency-Key was already used for a different request payload on this account.',
            );
        }

        // Claimed but not yet completed: the twin request is still inside
        // the Message Engine. Refuse rather than send again.
        if (! $record->isCompleted()) {
            return $this->conflict($request, self::ERROR_IN_PROGRESS, self::IN_PROGRESS_MESSAGE);
        }

        // Replay the stored envelope verbatim, at its original status
        // code. Nothing about the body is re-serialized or reshaped, so
        // each endpoint's own response contract ({status:...} vs
        // {success:...} vs {message:..., alert:{...}}) is preserved
        // exactly as that endpoint produced it the first time.
        return new HttpResponse(
            (string) $record->response_body,
            (int) ($record->response_status ?: HttpResponse::HTTP_OK),
            [
                'Content-Type' => 'application/json',
                // Additive, informational only -- lets a caller tell a
                // replay from a fresh execution. No response BODY is
                // changed by this feature.
                'Idempotent-Replay' => 'true',
            ],
        );
    }

    /**
     * A deterministic one-way hash of this request's identity: method,
     * path, and the full input payload with every nested key sorted, so
     * two logically identical requests hash the same regardless of JSON
     * key order. Only the hash is ever stored -- never the payload -- so
     * this table holds no message content, no recipient numbers, and no
     * credentials (the Idempotency-Key header itself is caller-chosen and
     * carries no secret; X-API-KEY/X-API-SECRET are never part of the
     * fingerprint).
     */
    private function fingerprint(Request $request): string
    {
        $payload = $request->all();
        $this->ksortRecursive($payload);

        return hash('sha256', implode('|', [
            $request->method(),
            $request->path(),
            (string) json_encode($payload),
        ]));
    }

    /**
     * @param array<mixed> $value
     */
    private function ksortRecursive(array &$value): void
    {
        ksort($value);

        foreach ($value as &$item) {
            if (is_array($item)) {
                $this->ksortRecursive($item);
            }
        }
    }

    /**
     * 409 Conflict -- the status this API already uses for a duplicate
     * send (ExternalAlertController's own payment_ref duplicate
     * response). The body shape is chosen per endpoint so each route
     * keeps its own established envelope key rather than having a new
     * one imposed on it.
     */
    private function conflict(Request $request, string $errorCode, string $message): Response
    {
        $path = $request->path();

        $body = match (true) {
            // {message: ...} -- ExternalAlertController's envelope.
            str_ends_with($path, 'v1/messages/send-payment-alert') => [
                'message' => $message,
                'error_code' => $errorCode,
            ],
            // {status: false, ...} -- Api\V1\TemplateMessageController::send().
            str_ends_with($path, 'v1/messages/send-template') => [
                'status' => false,
                'error_code' => $errorCode,
                'message' => $message,
            ],
            // {success: false, ...} -- /v1/send-message and every
            // /v1/whatsapp/* route.
            default => [
                'success' => false,
                'error_code' => $errorCode,
                'message' => $message,
            ],
        };

        return response()->json($body, HttpResponse::HTTP_CONFLICT);
    }
}
