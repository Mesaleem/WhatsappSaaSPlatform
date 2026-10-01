<?php

namespace App\Services\Ai\Billing;

use App\Models\AiOperation;
use App\Services\Ai\Data\EmbeddingResponse;

/**
 * Phase 8 Task 9 — what MeteredAiService::embed() returns: the vectors plus
 * the billing outcome (same meaning as MeteredAiResponse).
 */
final class MeteredEmbeddingResponse
{
    public function __construct(
        public readonly EmbeddingResponse $response,
        public readonly AiOperation $operation,
        public readonly int $creditsCharged,
        public readonly bool $settled,
    ) {
    }
}
