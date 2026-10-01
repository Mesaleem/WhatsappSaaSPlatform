<?php

namespace App\Services\Ai\Contracts;

use App\Services\Ai\AiException;
use App\Services\Ai\Data\AiRequest;
use App\Services\Ai\Data\AiResponse;

/**
 * Phase 8 AI foundation — the provider-agnostic contract.
 *
 * Application code depends on this interface (through AiService /
 * AiManager), never on a vendor class. Implementations translate the
 * neutral AiRequest into one vendor API call and the vendor answer back
 * into an AiResponse, and report EVERY failure as an AiException (never a
 * raw HTTP/vendor exception, never a response body).
 *
 * Deliberately minimal: plain text and a JSON-object ("structured")
 * answer. Embeddings/classification are not declared until a feature needs
 * them — adding a method here is the extension point.
 */
interface AiProvider
{
    /** Stable provider key, as used in config/ai.php (e.g. "openai"). */
    public function name(): string;

    /** @throws AiException */
    public function generateText(AiRequest $request): AiResponse;

    /**
     * A single JSON object answer, decoded into AiResponse::$data.
     *
     * @throws AiException MALFORMED_RESPONSE when the answer is not a JSON object
     */
    public function generateStructured(AiRequest $request): AiResponse;
}
