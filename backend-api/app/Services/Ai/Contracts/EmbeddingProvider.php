<?php

namespace App\Services\Ai\Contracts;

use App\Services\Ai\AiException;
use App\Services\Ai\Data\EmbeddingRequest;
use App\Services\Ai\Data\EmbeddingResponse;

/**
 * Phase 8 Task 9 — the provider-agnostic EMBEDDING contract, deliberately
 * separate from AiProvider (text generation): an embedding is not a
 * generation and must never be emulated with generateText().
 *
 * A vendor class may implement both contracts (OpenAiProvider,
 * GeminiProvider) and reuse the same credentials and HTTP plumbing; a
 * vendor without an embedding API (Anthropic) simply does not implement
 * it. Implementations turn the neutral EmbeddingRequest into vendor calls
 * and return one vector per input, in input order, as an
 * EmbeddingResponse — no vendor response shape leaves the provider — and
 * report every failure as an AiException.
 *
 * Resolved only through AiManager::embeddingProvider() and called only
 * through AiService::embed() (from MeteredAiService::embed()).
 */
interface EmbeddingProvider
{
    /** Stable provider key, as used in config/ai.php. */
    public function name(): string;

    /** @throws AiException */
    public function embed(EmbeddingRequest $request): EmbeddingResponse;
}
