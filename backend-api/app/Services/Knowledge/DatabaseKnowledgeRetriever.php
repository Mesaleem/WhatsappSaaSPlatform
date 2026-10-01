<?php

namespace App\Services\Knowledge;

use App\Models\Account;
use App\Models\KnowledgeBase;
use App\Models\KnowledgeChunk;
use App\Services\Ai\AiException;
use App\Services\Ai\Billing\MeteredAiService;
use App\Services\Ai\Data\EmbeddingRequest;
use App\Services\Ai\Retrieval\KnowledgeRetriever;
use App\Services\Ai\Retrieval\RetrievalQuery;
use App\Services\Ai\Retrieval\RetrievalResult;
use App\Services\Ai\Retrieval\RetrievedChunk;
use App\Services\Knowledge\Contracts\VectorStore;

/**
 * Phase 8 Task 9 — KnowledgeRetriever over the VectorStore contract (see
 * that interface for the guarantees). The query is embedded with the
 * knowledge base's OWN embedding provider/model (MeteredAiService::embed,
 * billed to the target account, operation "knowledge.query"); the search
 * and the chunk lookup are filtered by the target account at every step.
 */
final class DatabaseKnowledgeRetriever implements KnowledgeRetriever
{
    public function __construct(
        private readonly MeteredAiService $metered,
        private readonly VectorStore $vectors,
    ) {
    }

    public function retrieve(RetrievalQuery $query): RetrievalResult
    {
        $accountId = (int) $query->account->id;

        if ($accountId !== (int) $query->authorization->account->id) {
            throw AiException::targetAccountForbidden();
        }

        $text = trim(PlainTextExtractor::normalize($query->query));
        $maxResults = max(1, (int) config('ai.knowledge.max_results', 20));

        if ($text === '' || mb_strlen($text) > (int) config('ai.knowledge.max_query_chars', 2000)) {
            throw KnowledgeException::invalidQuery('The search query must be non-empty and at most '.(int) config('ai.knowledge.max_query_chars', 2000).' characters.');
        }

        if ($query->limit < 1 || $query->limit > $maxResults) {
            throw KnowledgeException::invalidQuery("The result limit must be between 1 and {$maxResults}.");
        }

        if ($query->minScore !== null && ($query->minScore < -1 || $query->minScore > 1)) {
            throw KnowledgeException::invalidQuery('The minimum score must be between -1 and 1.');
        }

        $knowledgeBase = is_numeric($query->knowledgeBaseId)
            ? KnowledgeBase::query()->forAccount($accountId)->find((int) $query->knowledgeBaseId)
            : null;

        if (! $knowledgeBase) {
            throw KnowledgeException::knowledgeBaseNotFound();
        }

        // Nothing indexed yet: no embedding space, nothing to compare with — no vendor call, no charge.
        if (! $knowledgeBase->hasEmbeddingSpace()) {
            return new RetrievalResult((int) $knowledgeBase->id, []);
        }

        $embedded = $this->metered->embed(
            $query->authorization,
            new EmbeddingRequest([$text], EmbeddingRequest::PURPOSE_QUERY, $knowledgeBase->embedding_model, 'knowledge.query'),
            $query->operationKey,
            $knowledgeBase->embedding_provider,
        );

        if ($embedded->response->dimensions !== (int) $knowledgeBase->embedding_dimensions) {
            throw KnowledgeException::embeddingMismatch();
        }

        $hits = $this->vectors->search($accountId, (int) $knowledgeBase->id, $embedded->response->vectors[0], $query->limit, $query->minScore);
        $chunks = $this->load($accountId, (int) $knowledgeBase->id, array_column($hits, 'score', 'chunk_id'));

        return new RetrievalResult((int) $knowledgeBase->id, $chunks, (int) $embedded->operation->id, $embedded->creditsCharged);
    }

    public function passages(Account $account, int|string $knowledgeBaseId, array $scoresByChunkId): array
    {
        $knowledgeBase = is_numeric($knowledgeBaseId) ? KnowledgeBase::query()->forAccount((int) $account->id)->find((int) $knowledgeBaseId) : null;

        if (! $knowledgeBase) {
            throw KnowledgeException::knowledgeBaseNotFound();
        }

        return $this->load((int) $account->id, (int) $knowledgeBase->id, $scoresByChunkId);
    }

    /**
     * Chunks of the account's knowledge base, LIVE versions only, in score order.
     *
     * @param  array<int|string, float|int>  $scores  chunk id => score
     * @return list<RetrievedChunk>
     */
    private function load(int $accountId, int $knowledgeBaseId, array $scores): array
    {
        if ($scores === []) {
            return [];
        }

        $rows = KnowledgeChunk::query()->where('knowledge_chunks.account_id', $accountId)
            ->where('knowledge_chunks.knowledge_base_id', $knowledgeBaseId)
            ->whereIn('knowledge_chunks.id', array_map('intval', array_keys($scores)))
            ->join('knowledge_documents as d', function ($join) {
                $join->on('d.id', '=', 'knowledge_chunks.knowledge_document_id')
                    ->on('d.account_id', '=', 'knowledge_chunks.account_id')
                    ->on('d.indexed_version', '=', 'knowledge_chunks.document_version');
            })
            ->get(['knowledge_chunks.id', 'knowledge_chunks.knowledge_document_id', 'knowledge_chunks.document_version', 'knowledge_chunks.chunk_index', 'knowledge_chunks.content', 'd.title', 'd.source_key'])
            ->keyBy('id');

        $chunks = [];
        foreach ($scores as $chunkId => $score) {
            if ($row = $rows->get((int) $chunkId)) {
                $chunks[] = new RetrievedChunk(
                    (int) $row->id, (int) $row->knowledge_document_id, $knowledgeBaseId, (int) $row->document_version,
                    (int) $row->chunk_index, (string) $row->content, (float) $score, (string) $row->title, (string) $row->source_key,
                );
            }
        }

        return $chunks;
    }
}
