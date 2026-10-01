<?php

namespace App\Services\Ai\Retrieval;

/**
 * Phase 8 Task 9 — the result of one retrieval: the hits (best first) and
 * the billing of the query embedding ($aiOperationId null and 0 credits
 * when nothing was embedded, e.g. an empty knowledge base).
 */
final class RetrievalResult
{
    /** @param list<RetrievedChunk> $chunks */
    public function __construct(
        public readonly int $knowledgeBaseId,
        public readonly array $chunks,
        public readonly ?int $aiOperationId = null,
        public readonly int $creditsCharged = 0,
    ) {
    }
}
