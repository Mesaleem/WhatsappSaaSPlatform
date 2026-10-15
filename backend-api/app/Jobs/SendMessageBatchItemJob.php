<?php

namespace App\Jobs;

use App\Services\Batches\MessageBatchService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/**
 * Sends one claimed batch number at its planned time — the every-minute runner
 * queues it with a delay instead of sleeping until then (no blocking wait in
 * production code, P5-9). The service re-checks the sending number's gap and the
 * batch state when the job runs, so a late or duplicated job never breaks either.
 */
class SendMessageBatchItemJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    /** WhatsApp sends are not idempotent: one attempt; a lost one is put back to pending by the runner's stuck sweep. */
    public int $tries = 1;

    public int $timeout = 60;

    public function __construct(public readonly int $itemId)
    {
    }

    public function handle(MessageBatchService $batches): void
    {
        $batches->sendQueuedItem($this->itemId);
    }
}
