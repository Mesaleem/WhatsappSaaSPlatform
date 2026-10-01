<?php

namespace App\Services\Ai\Retrieval;

use App\Services\Ai\AiException;
use App\Services\Knowledge\KnowledgeException;

/**
 * Phase 8 Task 7 (contract) / Task 9 (completed) — retrieval of knowledge
 * passages for one account. Implemented by
 * App\Services\Knowledge\DatabaseKnowledgeRetriever (bound in
 * AppServiceProvider).
 *
 * Guarantees every implementation must keep:
 *   - the caller names the target account explicitly (RetrievalQuery::
 *     $account) and it must equal the authorization's account;
 *   - the knowledge base is resolved within that account only — a foreign
 *     or unknown id is KNOWLEDGE_BASE_NOT_FOUND, never another account's
 *     data and never an empty "success";
 *   - only the live (indexed) version of each document is searched;
 *   - the query embedding (a billable vendor call) goes through
 *     MeteredAiService::embed and is billed to that account; the vector
 *     search itself is not billed; an unindexed knowledge base returns no
 *     hits without calling any provider;
 *   - $limit ≤ ai.knowledge.max_results; an optional $minScore filters by
 *     cosine similarity;
 *   - no answer is fabricated: no hits → an empty list.
 *
 * Phase 8 Task 10 — the Journey `rag` node retrieves through this
 * contract (JourneyAiNodeRunner). passages() re-reads the hits of a
 * retrieval that already happened (a retried Journey step) without
 * embedding — and charging — the query again.
 */
interface KnowledgeRetriever
{
    /** @throws AiException|KnowledgeException */
    public function retrieve(RetrievalQuery $query): RetrievalResult;

    /**
     * Re-load previously retrieved chunks by id — no vendor call, no charge.
     * Same isolation as retrieve(): only chunks of $account's knowledge base
     * $knowledgeBaseId and of each document's LIVE version are returned (a
     * chunk deleted or re-indexed since is simply absent), in the order of
     * $scoresByChunkId, with those scores.
     *
     * @param  array<int, float>  $scoresByChunkId
     * @return list<RetrievedChunk>
     *
     * @throws KnowledgeException KNOWLEDGE_BASE_NOT_FOUND
     */
    public function passages(\App\Models\Account $account, int|string $knowledgeBaseId, array $scoresByChunkId): array;
}
