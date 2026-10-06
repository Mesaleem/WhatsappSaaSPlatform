<?php

namespace App\Console\Commands;

use App\Services\Scheduling\ScheduledMessageService;
use Illuminate\Console\Command;

/** Sends the scheduled messages that are due. Runs every minute. */
class DispatchScheduledMessages extends Command
{
    protected $signature = 'messages:dispatch-scheduled {--limit=100 : Most messages to attempt in one run}';

    protected $description = 'Send the scheduled messages whose time has come';

    public function handle(ScheduledMessageService $service): int
    {
        $attempted = $service->dispatchDue((int) $this->option('limit'));

        if ($attempted > 0) {
            $this->info("Attempted {$attempted} scheduled message(s).");
        }

        return self::SUCCESS;
    }
}
