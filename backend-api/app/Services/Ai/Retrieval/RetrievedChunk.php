<?php

namespace App\Services\Ai\Retrieval;

/**
 * Phase 8 Task 9 — one normalized retrieval hit. $score is the cosine
 * similarity between the query and the chunk embeddings (-1..1, higher is
 * closer); results are ordered by it.
 */
final class RetrievedChunk
{
    public function __construct(
        public readonly int $chunkId,
        public readonly int $documentId,
        public readonly int $knowledgeBaseId,
        public readonly int $documentVersion,
        public readonly int $chunkIndex,
        public readonly string $text,
        public readonly float $score,
        public readonly string $documentTitle,
        public readonly string $sourceKey,
    ) {
    }

    /** @return array<string, int|float|string> */
    public function toArray(): array
    {
        return [
            'chunk_id' => $this->chunkId, 'document_id' => $this->documentId, 'knowledge_base_id' => $this->knowledgeBaseId,
            'document_version' => $this->documentVersion, 'chunk_index' => $this->chunkIndex, 'text' => $this->text,
            'score' => $this->score, 'document_title' => $this->documentTitle, 'source_key' => $this->sourceKey,
        ];
    }
}
