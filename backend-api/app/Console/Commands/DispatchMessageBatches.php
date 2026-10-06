<?php

namespace App\Console\Commands;

use App\Services\Batches\MessageBatchService;
use Illuminate\Console\Command;

/** Every minute: sends the next chunk of each batch that is due (see MessageBatchService::dispatchDue). */
class DispatchMessageBatches extends Command
{
    protected $signature = 'batches:dispatch-due {--limit=20 : Most batches handled in one run}';

    protected $description = 'Send the next chunk of each Send Notification batch that is due, or start a scheduled batch.';

    public function handle(MessageBatchService $batches): int
    {
        $processed = $batches->dispatchDue(null, (int) $this->option('limit'));

        $this->info("Batches advanced in this run: {$processed}.");

        return self::SUCCESS;
    }
}
