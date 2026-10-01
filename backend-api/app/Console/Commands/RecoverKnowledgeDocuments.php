<?php

namespace App\Console\Commands;

use App\Jobs\ProcessKnowledgeDocumentJob;
use App\Models\KnowledgeDocument;
use Illuminate\Console\Command;

/**
 * Phase 8 Task 9 — re-dispatch knowledge documents whose processing job was
 * lost: 'pending' for over a minute, or 'processing' past
 * ai.knowledge.processing_lease_seconds (a worker died). Each job claims the
 * row atomically, so an overlapping run or a duplicate job is harmless.
 * Failed documents are NOT retried automatically (a re-process is an
 * explicit request — it may spend credits).
 */
class RecoverKnowledgeDocuments extends Command
{
    protected $signature = 'knowledge:recover-documents {--limit=200 : Maximum documents per run}';

    protected $description = 'Re-dispatch knowledge documents whose processing job was lost or whose worker died.';

    public function handle(): int
    {
        $limit = max(1, (int) $this->option('limit'));
        $leaseExpired = now()->subSeconds((int) config('ai.knowledge.processing_lease_seconds', 900));

        $documents = KnowledgeDocument::query()
            ->where(fn ($q) => $q->where(fn ($q) => $q->where('status', KnowledgeDocument::STATUS_PENDING)->where('updated_at', '<=', now()->subMinute()))
                ->orWhere(fn ($q) => $q->where('status', KnowledgeDocument::STATUS_PROCESSING)->where('processing_started_at', '<=', $leaseExpired)))
            ->orderBy('id')->limit($limit)->get(['id', 'version']);

        foreach ($documents as $document) {
            ProcessKnowledgeDocumentJob::dispatch((int) $document->id, (int) $document->version)->onConnection(ProcessKnowledgeDocumentJob::CONNECTION)->onQueue(ProcessKnowledgeDocumentJob::QUEUE);
        }

        $this->info("Dispatched {$documents->count()} knowledge document(s).");

        return self::SUCCESS;
    }
}
