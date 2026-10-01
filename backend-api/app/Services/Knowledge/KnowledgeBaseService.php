<?php

namespace App\Services\Knowledge;

use App\Jobs\ProcessKnowledgeDocumentJob;
use App\Models\Account;
use App\Models\KnowledgeBase;
use App\Models\KnowledgeDocument;
use App\Services\Knowledge\Contracts\DocumentTextExtractor;
use App\Services\Knowledge\Contracts\VectorStore;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * Phase 8 Task 9 — knowledge-base and document management (no AI call, no
 * charge here). Every method is scoped to the account passed in — the
 * target account the caller already authorized (AiAuthorizer); a
 * knowledge-base or document id is only ever resolved inside that account.
 *
 * submitDocument() is idempotent per (knowledge base, source_key):
 *   new key                      → a new document, version 1, pending
 *   same key, same text & title  → unchanged (nothing re-processed/charged)
 *   same key, same text          → title updated only
 *   same key, different text     → version + 1, pending (re-indexed; the
 *                                  current version stays searchable until
 *                                  the new one is ready)
 * Processing happens in ProcessKnowledgeDocumentJob (database:knowledge).
 */
class KnowledgeBaseService
{
    public function __construct(
        private readonly DocumentTextExtractor $extractor,
        private readonly VectorStore $vectors,
    ) {
    }

    public function findKnowledgeBase(Account $account, int|string $id): KnowledgeBase
    {
        return is_numeric($id) ? (KnowledgeBase::query()->forAccount((int) $account->id)->find((int) $id) ?? throw KnowledgeException::knowledgeBaseNotFound())
            : throw KnowledgeException::knowledgeBaseNotFound();
    }

    public function findDocument(KnowledgeBase $knowledgeBase, int|string $id): KnowledgeDocument
    {
        return is_numeric($id) ? (KnowledgeDocument::query()->forAccount((int) $knowledgeBase->account_id)
            ->where('knowledge_base_id', $knowledgeBase->id)->find((int) $id) ?? throw KnowledgeException::documentNotFound())
            : throw KnowledgeException::documentNotFound();
    }

    public function createKnowledgeBase(Account $account, string $name, ?string $description, ?int $userId): KnowledgeBase
    {
        $name = trim($name);

        if (KnowledgeBase::query()->forAccount((int) $account->id)->where('name', $name)->exists()) {
            throw KnowledgeException::nameTaken();
        }

        try {
            return KnowledgeBase::create([
                'account_id' => $account->id, 'name' => $name,
                'description' => $description === null || trim($description) === '' ? null : trim($description),
                'created_by_user_id' => $userId,
            ]);
        } catch (QueryException $e) {
            if (KnowledgeBase::query()->forAccount((int) $account->id)->where('name', $name)->exists()) {
                throw KnowledgeException::nameTaken();
            }

            throw $e;
        }
    }

    public function deleteKnowledgeBase(KnowledgeBase $knowledgeBase): void
    {
        $accountId = (int) $knowledgeBase->account_id;

        foreach (KnowledgeDocument::query()->forAccount($accountId)->where('knowledge_base_id', $knowledgeBase->id)->pluck('id') as $documentId) {
            $this->vectors->forgetDocument($accountId, (int) $documentId);
        }

        // documents and chunks go with it (composite FK cascade)
        KnowledgeBase::query()->forAccount($accountId)->whereKey($knowledgeBase->id)->delete();
    }

    /**
     * @return array{document: KnowledgeDocument, created: bool, changed: bool}
     */
    public function submitDocument(KnowledgeBase $knowledgeBase, string $title, string $content, ?string $sourceKey = null, string $sourceType = KnowledgeDocument::SOURCE_TEXT, ?int $userId = null): array
    {
        $text = $this->extractor->extract($sourceType, $content);
        $max = (int) config('ai.knowledge.max_document_chars', 200000);

        if ($text === '') {
            throw KnowledgeException::documentEmpty();
        }

        if (mb_strlen($text) > $max) {
            throw KnowledgeException::documentTooLarge($max);
        }

        $hash = hash('sha256', $text);
        $title = mb_substr(trim($title), 0, 191);
        $sourceKey = $sourceKey !== null && trim($sourceKey) !== '' ? mb_substr(trim($sourceKey), 0, 191) : 'sha256:'.$hash;
        $accountId = (int) $knowledgeBase->account_id;

        $result = DB::transaction(function () use ($knowledgeBase, $accountId, $title, $text, $hash, $sourceKey, $sourceType, $userId) {
            $existing = KnowledgeDocument::query()->forAccount($accountId)->where('knowledge_base_id', $knowledgeBase->id)
                ->where('source_key', $sourceKey)->lockForUpdate()->first();

            if (! $existing) {
                $document = KnowledgeDocument::create([
                    'account_id' => $accountId, 'knowledge_base_id' => $knowledgeBase->id, 'title' => $title,
                    'source_type' => $sourceType, 'source_key' => $sourceKey, 'content' => $text, 'content_hash' => $hash,
                    'content_chars' => mb_strlen($text), 'version' => 1, 'status' => KnowledgeDocument::STATUS_PENDING,
                    'created_by_user_id' => $userId,
                ]);

                return ['document' => $document, 'created' => true, 'changed' => true];
            }

            if ($existing->content_hash === $hash) {
                if ($existing->title !== $title) {
                    $existing->forceFill(['title' => $title])->save();
                }

                return ['document' => $existing, 'created' => false, 'changed' => false];
            }

            $existing->forceFill([
                'title' => $title, 'source_type' => $sourceType, 'content' => $text, 'content_hash' => $hash,
                'content_chars' => mb_strlen($text), 'version' => $existing->version + 1,
                'status' => KnowledgeDocument::STATUS_PENDING, 'error_code' => null, 'error_message' => null,
                'processing_started_at' => null,
            ])->save();

            return ['document' => $existing, 'created' => false, 'changed' => true];
        }, 3);

        if ($result['changed']) {
            $this->dispatch($result['document']);
        }

        return $result;
    }

    /**
     * Re-process a document. A FAILED version (other than "already charged")
     * is resumed as the same version — batches already embedded are not
     * embedded or charged again. A ready (or already-charged) document is
     * rebuilt as a new version (a full, explicitly requested re-embedding).
     */
    public function reprocess(KnowledgeDocument $document): KnowledgeDocument
    {
        $resume = $document->status === KnowledgeDocument::STATUS_FAILED && $document->error_code !== KnowledgeException::ALREADY_CHARGED;

        KnowledgeDocument::query()->forAccount((int) $document->account_id)->whereKey($document->id)->update([
            'version' => $resume ? $document->version : $document->version + 1,
            'status' => KnowledgeDocument::STATUS_PENDING,
            'error_code' => null, 'error_message' => null, 'processing_started_at' => null, 'updated_at' => now(),
        ]);

        $document->refresh();
        $this->dispatch($document);

        return $document;
    }

    public function deleteDocument(KnowledgeDocument $document): void
    {
        $this->vectors->forgetDocument((int) $document->account_id, (int) $document->id);
        KnowledgeDocument::query()->forAccount((int) $document->account_id)->whereKey($document->id)->delete();
    }

    public function dispatch(KnowledgeDocument $document): void
    {
        ProcessKnowledgeDocumentJob::dispatch((int) $document->id, (int) $document->version)
            ->onConnection('database')->onQueue('knowledge')->afterCommit();
    }
}
