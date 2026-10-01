<?php

namespace App\Services\Knowledge;

use App\Models\KnowledgeChunk;
use App\Services\Knowledge\Contracts\VectorStore;
use Illuminate\Support\Facades\DB;

/**
 * Phase 8 Task 9 — the DEVELOPMENT vector store: no vector database exists
 * in this platform, so vectors are kept on the knowledge_chunks rows
 * (`embedding`: little-endian float32, L2-normalized at write time) and a
 * search is an exact brute-force cosine scan in PHP over ONE knowledge
 * base's live chunks.
 *
 * Deliberately simple — no ANN index is built in MySQL. The scan is
 * bounded by ai.knowledge.max_search_chunks (oldest chunks first beyond
 * it); a knowledge base larger than that needs a real vector backend
 * implementing VectorStore. Isolation: every statement filters by
 * account_id (and knowledge_base_id) — a chunk id alone is never trusted.
 */
final class DatabaseVectorStore implements VectorStore
{
    public function store(int $accountId, array $vectorsByChunkId, string $provider, string $model): void
    {
        $now = now();

        foreach ($vectorsByChunkId as $chunkId => $vector) {
            KnowledgeChunk::query()->forAccount($accountId)->whereKey((int) $chunkId)->update([
                'embedding' => self::pack(self::normalize($vector)),
                'embedding_provider' => mb_substr($provider, 0, 32),
                'embedding_model' => mb_substr($model, 0, 128),
                'embedding_dimensions' => count($vector),
                'embedded_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }

    public function search(int $accountId, int $knowledgeBaseId, array $queryVector, int $limit, ?float $minScore = null): array
    {
        $query = self::normalize($queryVector);
        $dimensions = count($query);
        $scores = [];

        DB::table('knowledge_chunks as c')
            ->join('knowledge_documents as d', function ($join) {
                $join->on('d.id', '=', 'c.knowledge_document_id')
                    ->on('d.account_id', '=', 'c.account_id')
                    ->on('d.knowledge_base_id', '=', 'c.knowledge_base_id')
                    ->on('d.indexed_version', '=', 'c.document_version');
            })
            ->where('c.account_id', $accountId)
            ->where('c.knowledge_base_id', $knowledgeBaseId)
            ->where('d.account_id', $accountId)
            ->whereNotNull('c.embedding')
            ->where('c.embedding_dimensions', $dimensions)
            ->orderBy('c.id')
            ->limit(max(1, (int) config('ai.knowledge.max_search_chunks', 20000)))
            ->select(['c.id', 'c.embedding'])
            ->cursor()
            ->each(function ($row) use ($query, $dimensions, $minScore, &$scores) {
                $vector = self::unpack((string) $row->embedding);

                if (count($vector) !== $dimensions) {
                    return;
                }

                $score = 0.0;
                foreach ($vector as $i => $value) {
                    $score += $value * $query[$i];
                }

                if ($minScore === null || $score >= $minScore) {
                    $scores[(int) $row->id] = $score;
                }
            });

        // best first; ties broken by chunk id so results are deterministic
        uksort($scores, fn (int $a, int $b) => [$scores[$b], $a] <=> [$scores[$a], $b]);

        $results = [];
        foreach (array_slice($scores, 0, max(1, $limit), true) as $chunkId => $score) {
            $results[] = ['chunk_id' => $chunkId, 'score' => round($score, 6)];
        }

        return $results;
    }

    public function forgetDocument(int $accountId, int $documentId): void
    {
        // The vectors live on the chunk rows: deleting the document's rows
        // (composite FK cascade) removes them. Nothing else to do here.
    }

    /** @param list<float|int> $vector @return list<float> */
    public static function normalize(array $vector): array
    {
        $norm = sqrt(array_sum(array_map(fn ($v) => $v * $v, $vector)));

        return $norm > 0 ? array_map(fn ($v) => $v / $norm, array_values($vector)) : array_map('floatval', array_values($vector));
    }

    /** @param list<float> $vector */
    public static function pack(array $vector): string
    {
        return pack('g*', ...$vector);
    }

    /** @return list<float> */
    public static function unpack(string $binary): array
    {
        if ($binary === '' || strlen($binary) % 4 !== 0) {
            return [];
        }

        return array_values(unpack('g*', $binary));
    }
}
