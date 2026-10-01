<?php

namespace App\Services\Ai\Billing;

use App\Models\AiOperation;
use App\Models\CreditReservation;
use App\Services\Ai\AiAuthorization;
use App\Services\Ai\AiException;
use App\Services\Ai\AiManager;
use App\Services\Ai\AiService;
use App\Services\Ai\Data\AiRequest;
use App\Services\Ai\Data\AiResponse;
use App\Services\Ai\Data\EmbeddingRequest;
use App\Services\Ai\Data\EmbeddingResponse;
use App\Services\Credits\CreditConsumptionService;
use App\Services\Credits\CreditEntitlementService;
use App\Services\Credits\CreditException;
use App\Services\Credits\CreditOperationResult;
use Closure;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * Phase 8 Task 5 — THE entry point for billable AI. Every feature (manual
 * or automated: API, Journey, CRM, Ads, future UI) calls this, never
 * AiService directly; the billing is identical whatever the source.
 *
 * It composes the two existing subsystems without changing either:
 *   AiAuthorizer → AiAuthorization (who may, for which TARGET account)
 *   CreditService / CreditConsumptionService (the only balance writer)
 *   AiService (vendor-neutral call; providers know nothing about credits)
 *
 * FLOW (one ai_operations row per operation key, per target account):
 *   1. claim the operation key (row 'running'; a key already running,
 *      succeeded or settled is refused — never charged twice);
 *   2. price an upper-bound estimate (AiCreditPricing) and RESERVE it with
 *      the existing reservation model, gated by the existing
 *      CreditEntitlementService (ai capability, account, subscription) under
 *      the credit-account row lock — insufficient credits refuse the
 *      operation before any provider call and write nothing to the ledger;
 *   3. call the provider through AiService;
 *        failure  → RELEASE the hold (no charge), row 'failed';
 *        success  → record usage + the computed charge (row 'succeeded'),
 *                   then CONSUME exactly that from the hold (the rest is
 *                   released in the same transaction), row 'settled'.
 *
 * Why not an AiOperationPerformed listener: a listener runs AFTER the call
 * and so cannot refuse an operation the account cannot pay for; the event
 * still fires (with operation_id) for logs and later consumers.
 *
 * IDEMPOTENCY. Ledger keys are derived from the row: reserve "ai:{id}:a{n}"
 * (n = attempt), settle/release use CreditService's own consume:{r}/
 * release:{r} keys — one effect per reservation however often it is retried.
 * A failed/abandoned key may be retried as a new attempt (a new hold).
 *
 * CONSISTENCY. If the provider answered but the consume could not be
 * written, the answer is still returned, the row keeps status 'succeeded'
 * with credits_charged, and `ai:settle-operations` completes the charge with
 * the same idempotent consume (no expires_at is set on AI holds, so nothing
 * can release a hold that is owed). A run that never reports back (process
 * killed mid-call) is abandoned after ai.credits.stale_after_seconds: hold
 * released, nothing charged — the system errs towards not charging.
 */
class MeteredAiService
{
    private const RESERVATION_REFERENCE = 'ai_operation';

    public function __construct(
        private readonly AiService $ai,
        private readonly AiManager $manager,
        private readonly AiCreditPricing $pricing,
        private readonly CreditConsumptionService $consumption,
        private readonly CreditEntitlementService $gate,
    ) {
    }

    /**
     * $accept (Phase 8 Task 6, optional): the caller's own check that the
     * answer is usable (e.g. the Ad Copywriter needs at least one complete
     * variant). A rejected answer is treated exactly like a malformed one —
     * hold released, nothing charged, AI_MALFORMED_RESPONSE — so a caller
     * never pays for output it cannot use.
     *
     * @param (callable(\App\Services\Ai\Data\AiResponse): bool)|null $accept
     * @throws AiException
     */
    public function generateText(AiAuthorization $authorization, AiRequest $request, ?string $operationKey = null, ?string $provider = null, ?callable $accept = null): MeteredAiResponse
    {
        return $this->run($authorization, $request, $operationKey, $provider, false, $accept);
    }

    /**
     * @param (callable(\App\Services\Ai\Data\AiResponse): bool)|null $accept see generateText()
     * @throws AiException
     */
    public function generateStructured(AiAuthorization $authorization, AiRequest $request, ?string $operationKey = null, ?string $provider = null, ?callable $accept = null): MeteredAiResponse
    {
        return $this->run($authorization, $request, $operationKey, $provider, true, $accept);
    }

    /**
     * Complete a recorded-but-unsettled charge (idempotent). Used by
     * `ai:settle-operations`; safe to call any number of times.
     */
    public function settle(AiOperation $operation): bool
    {
        $operation->refresh();

        if ($operation->status !== AiOperation::STATUS_SUCCEEDED || $operation->reservation_id === null) {
            return $operation->status === AiOperation::STATUS_SETTLED;
        }

        $reservation = CreditReservation::query()->forAccount((int) $operation->account_id)->findOrFail($operation->reservation_id);
        $charged = (int) $operation->credits_charged;

        $result = $charged > 0
            ? $this->consumption->settle($operation->account, $reservation->id, $charged, $this->context($operation))
            : $this->consumption->release($operation->account, $reservation->id, null, $this->context($operation));

        AiOperation::query()->whereKey($operation->id)->where('status', AiOperation::STATUS_SUCCEEDED)->update([
            'status' => AiOperation::STATUS_SETTLED,
            'consumption_entry_id' => $charged > 0 ? $result->entry->id : null,
            'settled_at' => now(),
            'updated_at' => now(),
        ]);

        return true;
    }

    /** Release the hold of a failed or abandoned operation, if still open (idempotent). */
    public function releaseHold(AiOperation $operation): void
    {
        if ($operation->reservation_id === null) {
            return;
        }

        $reservation = CreditReservation::query()->forAccount((int) $operation->account_id)->find($operation->reservation_id);

        if ($reservation && $reservation->status === CreditReservation::STATUS_RESERVED) {
            $this->consumption->release($operation->account, $reservation->id, null, $this->context($operation));
        }
    }

    /**
     * Phase 8 Task 7 — abandon ONE operation that has been 'running' longer
     * than ai.credits.stale_after_seconds (its process died mid-call): the
     * row becomes 'abandoned' (retryable as a new attempt) and its hold is
     * released — nothing charged. The same guarded write `ai:settle-operations`
     * uses (it now calls this), so a caller that finds its own key stuck can
     * recover it without waiting for the scheduler. Idempotent; false when
     * the operation is not running or not stale yet.
     */
    public function abandonIfStale(AiOperation $operation): bool
    {
        $cutoff = now()->subSeconds((int) config('ai.credits.stale_after_seconds', 900));

        $claimed = AiOperation::query()->whereKey($operation->id)->where('status', AiOperation::STATUS_RUNNING)->where('updated_at', '<=', $cutoff)
            ->update(['status' => AiOperation::STATUS_ABANDONED, 'error_code' => 'AI_OPERATION_ABANDONED', 'completed_at' => now(), 'updated_at' => now()]);

        if ($claimed !== 1) {
            return false;
        }

        $this->releaseHold($operation->fresh());

        return true;
    }

    private function run(AiAuthorization $authorization, AiRequest $request, ?string $operationKey, ?string $providerName, bool $structured, ?callable $accept = null): MeteredAiResponse
    {
        $operationKey = $this->normalizeKey($operationKey);
        $providerName ??= $this->manager->defaultProviderName();
        $provider = $this->manager->provider($providerName); // unavailable/unconfigured fails BEFORE anything is claimed or held
        $model = $request->model ?? (config("ai.providers.{$provider->name()}.model") ?: null);
        $label = $request->operation !== 'text' ? $request->operation : ($structured ? 'structured' : 'text');

        [$response, $operation, $charged, $settled] = $this->meter(
            $authorization, $operationKey, $label, $provider->name(), $model,
            $this->pricing->estimate($request, $provider->name(), $model),
            function (AiOperation $operation) use ($authorization, $request, $structured, $provider, $accept): AiResponse {
                $response = $structured
                    ? $this->ai->generateStructured($authorization, $request->withOperationId((string) $operation->id), $provider->name())
                    : $this->ai->generateText($authorization, $request->withOperationId((string) $operation->id), $provider->name());

                if ($accept !== null && ! $accept($response)) {
                    throw AiException::malformedResponse($provider->name());
                }

                return $response;
            },
            fn (AiResponse $r) => [$r->provider, $r->model, $r->inputTokens, $r->outputTokens, $r->latencyMs],
        );

        return new MeteredAiResponse($response, $operation, $charged, $settled);
    }

    /**
     * Phase 8 Task 9 — metered EMBEDDINGS (knowledge-base ingestion and
     * retrieval queries). The same claim → hold → provider → record → settle
     * flow, ledger, idempotency and abandonment as generation (meter()); only
     * the call (AiService::embed → an EmbeddingProvider) and the estimate
     * (input bytes only — an embedding has no generated output) differ.
     * Usage = the input tokens the vendor reported; none reported → the
     * existing ai.credits.missing_usage rule.
     *
     * @throws AiException
     */
    public function embed(AiAuthorization $authorization, EmbeddingRequest $request, ?string $operationKey = null, ?string $provider = null): MeteredEmbeddingResponse
    {
        $operationKey = $this->normalizeKey($operationKey);
        $embedder = $this->manager->embeddingProvider($provider); // unconfigured/unsupported fails BEFORE anything is claimed or held
        $model = $request->model ?? (config("ai.providers.{$embedder->name()}.embedding_model") ?: null);

        [$response, $operation, $charged, $settled] = $this->meter(
            $authorization, $operationKey, $request->operation, $embedder->name(), $model,
            $this->pricing->estimateEmbedding($request, $embedder->name(), $model),
            function (AiOperation $operation) use ($authorization, $request, $embedder, $model): EmbeddingResponse {
                $response = $this->ai->embed($authorization, $request->withOperationId((string) $operation->id), $embedder->name());

                if (count($response->vectors) !== count($request->inputs)) {
                    throw AiException::malformedResponse($embedder->name());
                }

                return $response;
            },
            fn (EmbeddingResponse $r) => [$r->provider, $r->model, $r->inputTokens, $r->inputTokens === null ? null : 0, $r->latencyMs],
        );

        return new MeteredEmbeddingResponse($response, $operation, $charged, $settled);
    }

    /**
     * The one billing flow (Phase 8 Task 5), shared by generation and
     * embeddings since Task 9.
     *
     * @param  Closure(AiOperation): object  $call  the provider call through AiService
     * @param  Closure(object): array{0: string, 1: string, 2: ?int, 3: ?int, 4: int}  $usage  provider, model, input, output, latency
     * @return array{0: object, 1: AiOperation, 2: int, 3: bool}
     */
    private function meter(AiAuthorization $authorization, string $operationKey, string $label, string $providerName, ?string $model, int $estimate, Closure $call, Closure $usage): array
    {
        $operation = $this->claim($authorization, $operationKey, $label, $providerName, $model);

        // 2. hold the upper bound
        $reservation = $this->reserve($authorization, $operation, $estimate);

        // 3. call the provider
        try {
            $response = $call($operation);
        } catch (Throwable $e) {
            $error = $e instanceof AiException ? $e : AiException::providerFailed($providerName, $e);
            $this->fail($operation, $error->errorCode, $error->category());

            throw $error;
        }

        [$respProvider, $respModel, $inputTokens, $outputTokens, $latency] = $usage($response);

        // 4. record usage + the computed charge (durable before the ledger write)
        $price = $this->pricing->chargeTokens($inputTokens, $outputTokens, $respProvider, $respModel, $estimate);

        if (! $price['usage_reported']) {
            Log::warning('MeteredAiService: the provider reported no token usage; charged by the missing-usage rule.', [
                'account_id' => $operation->account_id, 'operation_id' => $operation->id, 'provider' => $respProvider,
                'rule' => config('ai.credits.missing_usage'), 'credits' => $price['charged'],
            ]);
        }

        if ($price['uncharged'] > 0) {
            Log::warning('MeteredAiService: usage exceeded the credit hold; the excess was not charged.', [
                'account_id' => $operation->account_id, 'operation_id' => $operation->id, 'uncharged' => $price['uncharged'],
            ]);
        }

        $recorded = AiOperation::query()->whereKey($operation->id)->where('status', AiOperation::STATUS_RUNNING)->update([
            'status' => AiOperation::STATUS_SUCCEEDED,
            'provider' => $respProvider,
            'model' => mb_substr($respModel, 0, 128),
            'input_tokens' => $inputTokens,
            'output_tokens' => $outputTokens,
            'usage_reported' => $price['usage_reported'],
            'credits_charged' => $price['charged'],
            'credits_uncharged' => $price['uncharged'],
            'latency_ms' => $latency,
            'completed_at' => now(),
            'updated_at' => now(),
        ]);

        if ($recorded !== 1) {
            // The run outlived ai.credits.stale_after_seconds and was abandoned
            // (hold already released): the answer is delivered uncharged.
            Log::warning('MeteredAiService: a late AI answer arrived for an abandoned operation; it is not charged.', [
                'account_id' => $operation->account_id, 'operation_id' => $operation->id,
            ]);

            return [$response, $operation->fresh(), 0, false];
        }

        // 5. settle through the ledger (idempotent); a failure here is completed later
        $settled = false;
        try {
            $settled = $this->settle($operation);
        } catch (Throwable $e) {
            Log::error('MeteredAiService: the AI answer was delivered but its charge could not be written yet; ai:settle-operations will complete it.', [
                'account_id' => $operation->account_id, 'operation_id' => $operation->id, 'reservation_id' => $reservation->id,
                'credits' => $price['charged'], 'exception' => class_basename($e),
            ]);
        }

        return [$response, $operation->fresh(), $price['charged'], $settled];
    }

    private function claim(AiAuthorization $authorization, string $key, string $label, string $provider, ?string $model): AiOperation
    {
        $attributes = [
            'actor_user_id' => $authorization->actorUserId,
            'source' => mb_substr($authorization->source, 0, 32),
            'operation' => mb_substr($label, 0, 64),
            'provider' => $provider,
            'model' => $model === null ? null : mb_substr($model, 0, 128),
            'status' => AiOperation::STATUS_RUNNING,
        ];

        $accountId = (int) $authorization->account->id;

        try {
            // First claim: a plain INSERT the unique index arbitrates. No locking
            // read here — SELECT … FOR UPDATE on a missing row takes an InnoDB
            // gap lock, and concurrent first claims then deadlock (found by
            // tests/Probes/ai_credit_concurrency_probe.php).
            $now = now();
            $inserted = DB::table('ai_operations')->insertOrIgnore($attributes + [
                'account_id' => $accountId, 'operation_key' => $key, 'attempt' => 1,
                'credits_reserved' => 0, 'credits_uncharged' => 0, 'created_at' => $now, 'updated_at' => $now,
            ]);

            if ($inserted === 1) {
                return AiOperation::query()->forAccount($accountId)->where('operation_key', $key)->firstOrFail();
            }

            // The key exists: lock THAT row (a record lock — it exists) and decide.
            return DB::transaction(function () use ($accountId, $key, $attributes) {
                $existing = AiOperation::query()->forAccount($accountId)->where('operation_key', $key)->lockForUpdate()->firstOrFail();

                if (! in_array($existing->status, AiOperation::RETRYABLE_STATUSES, true)) {
                    throw AiException::duplicateOperation();
                }

                // A new attempt of a failed/abandoned key: fresh state, new hold key.
                $existing->forceFill($attributes + [
                    'attempt' => $existing->attempt + 1, 'reservation_id' => null, 'credits_reserved' => 0,
                    'credits_charged' => null, 'credits_uncharged' => 0, 'input_tokens' => null, 'output_tokens' => null,
                    'usage_reported' => null, 'consumption_entry_id' => null, 'error_code' => null, 'latency_ms' => null,
                    'completed_at' => null, 'settled_at' => null,
                ])->save();

                return $existing;
            }, 3);
        } catch (AiException $e) {
            throw $e;
        } catch (Throwable $e) {
            Log::error('MeteredAiService: could not claim the AI operation.', ['account_id' => $accountId, 'exception' => class_basename($e)]);

            throw AiException::billingUnavailable($e);
        }
    }

    private function reserve(AiAuthorization $authorization, AiOperation $operation, int $estimate): CreditReservation
    {
        try {
            $result = $this->consumption->reserve($authorization->account, $estimate, "ai:{$operation->id}:a{$operation->attempt}", $this->context($operation), $this->gate);
        } catch (CreditException $e) {
            $error = match ($e->reason) {
                CreditException::INSUFFICIENT_CREDITS => AiException::insufficientCredits($estimate, $this->available($authorization), $e),
                CreditException::ENTITLEMENT_BLOCKED => AiException::capabilityUnavailable(),
                CreditException::ACCOUNT_BLOCKED => str_contains($e->getMessage(), 'suspended') ? AiException::accountSuspended() : AiException::subscriptionInactive(),
                default => AiException::billingUnavailable($e),
            };
            $this->fail($operation, $error->errorCode, $error->category(), releaseHold: false);

            throw $error;
        } catch (Throwable $e) {
            $this->fail($operation, AiException::BILLING_UNAVAILABLE, 'billing', releaseHold: false);

            throw AiException::billingUnavailable($e);
        }

        $reservation = $this->reservationOf($result);

        AiOperation::query()->whereKey($operation->id)->update([
            'reservation_id' => $reservation->id,
            'credits_reserved' => $estimate,
            'updated_at' => now(),
        ]);
        $operation->forceFill(['reservation_id' => $reservation->id, 'credits_reserved' => $estimate]);

        return $reservation;
    }

    private function fail(AiOperation $operation, string $errorCode, string $category, bool $releaseHold = true): void
    {
        AiOperation::query()->whereKey($operation->id)->where('status', AiOperation::STATUS_RUNNING)->update([
            'status' => AiOperation::STATUS_FAILED,
            'error_code' => $errorCode,
            'completed_at' => now(),
            'updated_at' => now(),
        ]);

        if ($releaseHold) {
            try {
                $this->releaseHold($operation->fresh());
            } catch (Throwable $e) {
                // Left for ai:settle-operations (it releases the holds of failed rows).
                Log::error('MeteredAiService: could not release the credit hold of a failed AI operation yet.', [
                    'account_id' => $operation->account_id, 'operation_id' => $operation->id, 'exception' => class_basename($e),
                ]);
            }
        }
    }

    private function reservationOf(CreditOperationResult $result): CreditReservation
    {
        return $result->reservation ?? CreditReservation::query()->findOrFail($result->entry->reservation_id);
    }

    private function available(AiAuthorization $authorization): int
    {
        try {
            return (int) (app(\App\Services\Credits\CreditService::class)->balance($authorization->account)['available'] ?? 0);
        } catch (Throwable) {
            return 0;
        }
    }

    /** @return array<string, mixed> ledger context: safe metadata only */
    private function context(AiOperation $operation): array
    {
        return [
            'reference_type' => self::RESERVATION_REFERENCE,
            'reference_id' => (string) $operation->id,
            'source' => 'ai',
            'actor_user_id' => $operation->actor_user_id,
            'reason' => 'AI usage',
            'metadata' => ['operation' => $operation->operation, 'source' => $operation->source],
        ];
    }

    private function normalizeKey(?string $key): string
    {
        if ($key === null || trim($key) === '') {
            return 'auto:'.Str::uuid();
        }

        $key = trim($key);

        if (mb_strlen($key) > 150) {
            throw AiException::invalidRequest('The AI operation key must be at most 150 characters.');
        }

        return $key;
    }
}
