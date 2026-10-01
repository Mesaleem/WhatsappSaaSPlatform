<?php

namespace App\Services\Ai;

use App\Events\AiOperationPerformed;
use App\Services\Ai\Data\AiRequest;
use App\Services\Ai\Data\AiResponse;
use App\Services\Ai\Data\EmbeddingRequest;
use App\Services\Ai\Data\EmbeddingResponse;
use Throwable;

/**
 * Phase 8 AI foundation — the stable application API for AI.
 *
 * Callers (future Journey/CRM/Ads features) pass an AiAuthorization (only
 * AiAuthorizer makes one — so the full entitlement check has always run
 * against ONE target account) and a provider-neutral AiRequest. This class:
 *   - resolves the configured provider through AiManager;
 *   - calls it and returns the normalized AiResponse (with latency);
 *   - turns ANY unexpected failure into a controlled AiException;
 *   - logs one metadata-only record per operation (AiOperationLogger).
 *
 * It knows nothing about CRM, Journeys, Ads or credits. No credit is
 * reserved or consumed here — metering is a later task that can listen to
 * AiOperationPerformed or wrap this service.
 */
class AiService
{
    public function __construct(
        private readonly AiManager $manager,
        private readonly AiOperationLogger $logger,
    ) {
    }

    /** @throws AiException */
    public function generateText(AiAuthorization $authorization, AiRequest $request, ?string $provider = null): AiResponse
    {
        return $this->run($authorization, $request, $provider, false);
    }

    /**
     * A JSON-object answer, decoded into AiResponse::$data.
     *
     * @throws AiException
     */
    public function generateStructured(AiAuthorization $authorization, AiRequest $request, ?string $provider = null): AiResponse
    {
        return $this->run($authorization, $request, $provider, true);
    }

    /**
     * Phase 8 Task 9 — embeddings through the configured EmbeddingProvider.
     * Same authorization requirement and metadata-only logging as
     * generation (texts and vectors are never logged). Billable callers use
     * MeteredAiService::embed(), never this directly.
     *
     * @throws AiException
     */
    public function embed(AiAuthorization $authorization, EmbeddingRequest $request, ?string $provider = null): EmbeddingResponse
    {
        $started = hrtime(true);
        $resolvedName = $provider;

        try {
            $embedder = $this->manager->embeddingProvider($provider);
            $resolvedName = $embedder->name();
            $response = $embedder->embed($request)->withLatency($this->elapsedMs($started));

            $this->logger->record(new AiOperationPerformed(
                accountId: (int) $authorization->account->id,
                actorUserId: $authorization->actorUserId,
                operation: $request->operation,
                source: $authorization->source,
                provider: $response->provider,
                model: $response->model,
                success: true,
                latencyMs: $response->latencyMs,
                inputTokens: $response->inputTokens,
                operationId: $request->operationId,
            ));

            return $response;
        } catch (Throwable $e) {
            $error = $e instanceof AiException ? $e : AiException::providerFailed((string) ($resolvedName ?? 'unknown'), $e);

            $this->logger->record(new AiOperationPerformed(
                accountId: (int) $authorization->account->id,
                actorUserId: $authorization->actorUserId,
                operation: $request->operation,
                source: $authorization->source,
                provider: $resolvedName,
                model: $request->model,
                success: false,
                latencyMs: $this->elapsedMs($started),
                errorCode: $error->errorCode,
                errorCategory: $error->category(),
                operationId: $request->operationId,
            ));

            throw $error;
        }
    }

    /** Is a provider resolvable right now (configured, enabled, credentialed)? No network call. */
    public function available(?string $provider = null): bool
    {
        return $this->manager->available($provider);
    }

    private function run(AiAuthorization $authorization, AiRequest $request, ?string $providerName, bool $structured): AiResponse
    {
        $started = hrtime(true);
        $operation = $request->operation !== 'text' ? $request->operation : ($structured ? 'structured' : 'text');
        $resolvedName = $providerName ?? $this->manager->defaultProviderName();
        $model = $request->model;

        try {
            $provider = $this->manager->provider($providerName);
            $resolvedName = $provider->name();

            $response = $structured ? $provider->generateStructured($request) : $provider->generateText($request);
            $response = $response->withLatency($this->elapsedMs($started));

            $this->logger->record(new AiOperationPerformed(
                accountId: (int) $authorization->account->id,
                actorUserId: $authorization->actorUserId,
                operation: $operation,
                source: $authorization->source,
                provider: $response->provider,
                model: $response->model,
                success: true,
                latencyMs: $response->latencyMs,
                inputTokens: $response->inputTokens,
                outputTokens: $response->outputTokens,
                operationId: $request->operationId,
            ));

            return $response;
        } catch (Throwable $e) {
            $error = $e instanceof AiException ? $e : AiException::providerFailed((string) ($resolvedName ?? 'unknown'), $e);

            $this->logger->record(new AiOperationPerformed(
                accountId: (int) $authorization->account->id,
                actorUserId: $authorization->actorUserId,
                operation: $operation,
                source: $authorization->source,
                provider: $resolvedName,
                model: $model,
                success: false,
                latencyMs: $this->elapsedMs($started),
                errorCode: $error->errorCode,
                errorCategory: $error->category(),
                operationId: $request->operationId,
            ));

            throw $error;
        }
    }

    private function elapsedMs(int $started): int
    {
        return (int) round((hrtime(true) - $started) / 1_000_000);
    }
}
