<?php

namespace App\Services\Knowledge;

use App\Models\Account;
use App\Models\AiOperation;
use App\Models\KnowledgeBase;
use App\Models\KnowledgeChunk;
use App\Models\KnowledgeDocument;
use App\Services\Ai\AiAuthorization;
use App\Services\Ai\AiAuthorizer;
use App\Services\Ai\AiException;
use App\Services\Ai\AiManager;
use App\Services\Ai\Billing\MeteredAiService;
use App\Services\Ai\Data\EmbeddingRequest;
use App\Services\Knowledge\Contracts\TextChunker;
use App\Services\Knowledge\Contracts\VectorStore;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Phase 8 Task 9 — processes ONE version of a document:
 *
 *   claim → authorize (the document's own account) → chunk (deterministic)
 *   → persist chunk rows for this version → embed each batch through
 *   MeteredAiService::embed (billed to the document's account) → store the
 *   vectors → switch the live version in one transaction.
 *
 * CLAIM. One conditional UPDATE (pending, or processing past its lease, AND
 * still this version) — a duplicate/late job, or a version superseded by a
 * newer submission, does nothing.
 *
 * AUTHORIZATION. AiAuthorizer::forAccount(document's account, 'chatbot',
 * 'knowledge') — the same account checks as any automated AI path (active,
 * subscription, module, `ai` capability); nothing is trusted from where
 * the job came from.
 *
 * BILLING / IDEMPOTENCY. One metered operation per batch, key
 * kb:d{document}:v{version}:b{batch}. A batch whose vectors are stored is
 * never embedded again, so a retried/resumed version only pays for the
 * batches still missing; a failed batch key is retried as a new attempt
 * (no double charge). A batch that was charged but whose vectors were lost
 * (crash between the two) is NOT re-run: the document fails with
 * KNOWLEDGE_EMBEDDING_ALREADY_CHARGED and a re-process builds a new version.
 *
 * VERSIONING. Chunks of the new version are built beside the live ones;
 * indexed_version switches (and older-version chunks are deleted) only when
 * every chunk has its vector. A failure leaves the live version searchable.
 *
 * EMBEDDING SPACE. A knowledge base is locked to the provider/model/dimension
 * of its first indexed batch; later documents use that provider/model (not
 * whatever the platform default became), and a dimension mismatch fails.
 *
 * Logs carry ids and error codes only — never document text or vectors.
 */
class KnowledgeIngestionService
{
    public const MODULE = 'chatbot';

    public const SOURCE = 'knowledge';

    public function __construct(
        private readonly TextChunker $chunker,
        private readonly VectorStore $vectors,
        private readonly AiAuthorizer $authorizer,
        private readonly MeteredAiService $metered,
        private readonly AiManager $manager,
    ) {
    }

    /** @return string skipped|ready|failed|superseded|deferred */
    public function process(int $documentId, int $version): string
    {
        if (! $this->claim($documentId, $version)) {
            return 'skipped';
        }

        $document = KnowledgeDocument::query()->find($documentId);

        if (! $document) {
            return 'skipped';
        }

        try {
            return $this->run($document, $version);
        } catch (KnowledgeException $e) {
            return $this->fail($document, $version, $e->errorCode, $e->getMessage());
        } catch (AiException $e) {
            return $this->fail($document, $version, $e->errorCode, $e->getMessage());
        } catch (Throwable $e) {
            Log::error('KnowledgeIngestionService: a document could not be processed.', [
                'account_id' => $document->account_id, 'document_id' => $document->id, 'version' => $version, 'exception' => class_basename($e),
            ]);

            $error = KnowledgeException::processingFailed();

            return $this->fail($document, $version, $error->errorCode, $error->getMessage());
        }
    }

    private function claim(int $documentId, int $version): bool
    {
        $leaseExpired = now()->subSeconds((int) config('ai.knowledge.processing_lease_seconds', 900));

        return KnowledgeDocument::query()->whereKey($documentId)->where('version', $version)
            ->where(fn ($q) => $q->where('status', KnowledgeDocument::STATUS_PENDING)
                ->orWhere(fn ($q) => $q->where('status', KnowledgeDocument::STATUS_PROCESSING)->where('processing_started_at', '<=', $leaseExpired)))
            ->update(['status' => KnowledgeDocument::STATUS_PROCESSING, 'processing_started_at' => now(), 'updated_at' => now()]) === 1;
    }

    private function run(KnowledgeDocument $document, int $version): string
    {
        $accountId = (int) $document->account_id;
        $account = Account::query()->findOrFail($accountId);
        $knowledgeBase = KnowledgeBase::query()->forAccount($accountId)->findOrFail($document->knowledge_base_id);
        $authorization = $this->authorizer->forAccount($account, self::MODULE, self::SOURCE);

        // 1. chunk rows for this version (deterministic → insertOrIgnore is idempotent)
        $chunks = $this->chunker->chunk((string) $document->content);

        if ($chunks === []) {
            throw KnowledgeException::documentEmpty();
        }

        $now = now();
        DB::table('knowledge_chunks')->insertOrIgnore(array_map(fn (TextChunk $c) => [
            'account_id' => $accountId, 'knowledge_base_id' => $knowledgeBase->id, 'knowledge_document_id' => $document->id,
            'document_version' => $version, 'chunk_index' => $c->index, 'content' => $c->text, 'content_hash' => $c->hash,
            'char_start' => $c->start, 'char_end' => $c->end, 'created_at' => $now, 'updated_at' => $now,
        ], $chunks));

        // 2. embed the batches still missing vectors
        $batchSize = max(1, min(EmbeddingRequest::MAX_INPUTS, (int) config('ai.knowledge.embedding_batch_size', 32)));
        [$provider, $model] = $knowledgeBase->hasEmbeddingSpace()
            ? [$knowledgeBase->embedding_provider, $knowledgeBase->embedding_model]
            : [$this->manager->embeddingProvider()->name(), null];

        $pending = KnowledgeChunk::query()->forAccount($accountId)->where('knowledge_document_id', $document->id)
            ->where('document_version', $version)->whereNull('embedding')->orderBy('chunk_index')->get(['id', 'chunk_index', 'content']);

        foreach ($pending->groupBy(fn (KnowledgeChunk $c) => intdiv($c->chunk_index, $batchSize)) as $batch => $rows) {
            if (! $this->stillCurrent($document->id, $version)) {
                return 'superseded';
            }

            $result = $this->embedBatch($authorization, $document, $version, (int) $batch, $rows->pluck('content')->all(), $provider, $model);

            if ($result === null) {
                return $this->defer($document, $version);
            }

            $knowledgeBase = $this->lockEmbeddingSpace($knowledgeBase, $result->response->provider, $result->response->model, $result->response->dimensions);
            [$provider, $model] = [$knowledgeBase->embedding_provider, $knowledgeBase->embedding_model];

            DB::transaction(fn () => $this->vectors->store(
                $accountId,
                array_combine($rows->pluck('id')->map(fn ($id) => (int) $id)->all(), $result->response->vectors),
                $result->response->provider,
                $result->response->model,
            ));
        }

        // 3. switch the live version (only if every chunk of it has a vector)
        return DB::transaction(function () use ($document, $version, $accountId, $chunks) {
            $locked = KnowledgeDocument::query()->forAccount($accountId)->whereKey($document->id)->lockForUpdate()->first();

            if (! $locked || $locked->version !== $version || $locked->status !== KnowledgeDocument::STATUS_PROCESSING) {
                return 'superseded';
            }

            $missing = KnowledgeChunk::query()->forAccount($accountId)->where('knowledge_document_id', $document->id)
                ->where('document_version', $version)->whereNull('embedding')->count();

            if ($missing > 0) {
                throw KnowledgeException::processingFailed();
            }

            $locked->forceFill([
                'status' => KnowledgeDocument::STATUS_READY, 'indexed_version' => $version, 'chunk_count' => count($chunks),
                'error_code' => null, 'error_message' => null, 'processing_started_at' => null, 'processed_at' => now(),
            ])->save();

            KnowledgeChunk::query()->forAccount($accountId)->where('knowledge_document_id', $document->id)
                ->where('document_version', '!=', $version)->delete();

            return 'ready';
        });
    }

    /**
     * One metered embedding call; null = a previous run of this very batch is
     * still recorded as running (try again later).
     *
     * @param  list<string>  $inputs
     */
    private function embedBatch(AiAuthorization $authorization, KnowledgeDocument $document, int $version, int $batch, array $inputs, string $provider, ?string $model): ?\App\Services\Ai\Billing\MeteredEmbeddingResponse
    {
        $key = "kb:d{$document->id}:v{$version}:b{$batch}";
        $request = new EmbeddingRequest($inputs, EmbeddingRequest::PURPOSE_DOCUMENT, $model, 'knowledge.embed');

        try {
            return $this->metered->embed($authorization, $request, $key, $provider);
        } catch (AiException $e) {
            if ($e->errorCode !== AiException::OPERATION_DUPLICATE) {
                throw $e;
            }
        }

        $operation = AiOperation::query()->forAccount((int) $document->account_id)->where('operation_key', $key)->first();

        if ($operation && $operation->status === AiOperation::STATUS_RUNNING) {
            if ($this->metered->abandonIfStale($operation)) {
                return $this->metered->embed($authorization, $request, $key, $provider);
            }

            return null;
        }

        if ($operation && in_array($operation->status, [AiOperation::STATUS_SUCCEEDED, AiOperation::STATUS_SETTLED], true)) {
            throw KnowledgeException::alreadyCharged();
        }

        throw AiException::duplicateOperation();
    }

    private function lockEmbeddingSpace(KnowledgeBase $knowledgeBase, string $provider, string $model, int $dimensions): KnowledgeBase
    {
        KnowledgeBase::query()->forAccount((int) $knowledgeBase->account_id)->whereKey($knowledgeBase->id)->whereNull('embedding_provider')->update([
            'embedding_provider' => mb_substr($provider, 0, 32), 'embedding_model' => mb_substr($model, 0, 128),
            'embedding_dimensions' => $dimensions, 'updated_at' => now(),
        ]);

        $knowledgeBase->refresh();

        // Provider + dimension decide comparability; the model is requested
        // explicitly from the knowledge base's own record for every later batch.
        if ($knowledgeBase->embedding_provider !== $provider || (int) $knowledgeBase->embedding_dimensions !== $dimensions) {
            throw KnowledgeException::embeddingMismatch();
        }

        return $knowledgeBase;
    }

    private function stillCurrent(int $documentId, int $version): bool
    {
        return KnowledgeDocument::query()->whereKey($documentId)->where('version', $version)
            ->where('status', KnowledgeDocument::STATUS_PROCESSING)->exists();
    }

    /** Hand the version back to the recovery sweep (a previous run's operation is still in flight). */
    private function defer(KnowledgeDocument $document, int $version): string
    {
        KnowledgeDocument::query()->whereKey($document->id)->where('version', $version)->where('status', KnowledgeDocument::STATUS_PROCESSING)
            ->update(['status' => KnowledgeDocument::STATUS_PENDING, 'processing_started_at' => null, 'updated_at' => now()]);

        return 'deferred';
    }

    private function fail(KnowledgeDocument $document, int $version, string $code, string $message): string
    {
        $written = KnowledgeDocument::query()->whereKey($document->id)->where('version', $version)->where('status', KnowledgeDocument::STATUS_PROCESSING)
            ->update([
                'status' => KnowledgeDocument::STATUS_FAILED, 'error_code' => mb_substr($code, 0, 64),
                'error_message' => mb_substr($message, 0, 255), 'processing_started_at' => null, 'updated_at' => now(),
            ]);

        if ($written === 1) {
            Log::warning('KnowledgeIngestionService: document processing failed.', [
                'account_id' => $document->account_id, 'document_id' => $document->id, 'version' => $version, 'error_code' => $code,
            ]);
        }

        return $written === 1 ? 'failed' : 'superseded';
    }
}
