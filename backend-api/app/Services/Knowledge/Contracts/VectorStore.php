<?php

namespace App\Services\Knowledge\Contracts;

/**
 * Phase 8 Task 9 — where chunk vectors live and how they are searched.
 *
 * Every method takes the account explicitly and an implementation MUST
 * filter by it (and by knowledge base) itself — never trust a chunk id
 * alone. search() returns only chunks of a document's LIVE version
 * (knowledge_documents.indexed_version).
 *
 * The development implementation (DatabaseVectorStore) keeps vectors on
 * the knowledge_chunks rows and scans them; a real vector backend
 * (pgvector, Qdrant, …) implements the same contract keyed by chunk id with
 * account/knowledge-base metadata filters.
 */
interface VectorStore
{
    /**
     * @param  array<int, list<float>>  $vectorsByChunkId  chunk id => vector (all of one account)
     */
    public function store(int $accountId, array $vectorsByChunkId, string $provider, string $model): void;

    /**
     * @param  list<float>  $queryVector
     * @return list<array{chunk_id: int, score: float}> best first (score = cosine similarity, -1..1)
     */
    public function search(int $accountId, int $knowledgeBaseId, array $queryVector, int $limit, ?float $minScore = null): array;

    /** Drop every vector of a document (called before/after its rows are deleted). */
    public function forgetDocument(int $accountId, int $documentId): void;
}
