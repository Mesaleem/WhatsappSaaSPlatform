<?php

namespace App\Jobs;

use App\Services\Knowledge\KnowledgeIngestionService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Phase 8 Task 9 — process ONE version of a knowledge document
 * (KnowledgeIngestionService::process). Dispatched on database:knowledge
 * (drained by the scheduled worker in routes/console.php); carries only
 * ids. The document row is the state: the service claims it atomically, so
 * a duplicate, late or superseded job is a no-op, and a lost one is picked
 * up by `knowledge:recover-documents`. $tries = 1: retries belong to the
 * document state machine, never to a blind queue retry (which could
 * re-embed and re-charge).
 */
class ProcessKnowledgeDocumentJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * Phase 12 Task 1 — the 600 s timeout exceeds the default `database` connection's retry_after (90 s), so
     * this job runs on the long-retry connection (config/queue.php `database_long`, same table). Every
     * dispatch site and the scheduled worker name it; same pattern as ProcessDeferredInboundMessageJob.
     */
    public const CONNECTION = 'database_long';

    public const QUEUE = 'knowledge';

    public int $tries = 1;

    public int $timeout = 600;

    public function __construct(public readonly int $documentId, public readonly int $version)
    {
    }

    public function handle(KnowledgeIngestionService $ingestion): void
    {
        $ingestion->process($this->documentId, $this->version);
    }
}
