<?php

namespace Tests\Support;

use Illuminate\Support\Facades\Log;
use Monolog\Handler\TestHandler;

/**
 * Routes the default log channel to an in-memory Monolog handler, so a test can read the records exactly as a
 * handler sees them — including `extra`, where Laravel puts the log Context (request_id, account_id, job …).
 */
trait CapturesLogs
{
    protected function captureLogs(): TestHandler
    {
        config([
            'logging.default' => 'capture',
            'logging.channels.capture' => ['driver' => 'monolog', 'handler' => TestHandler::class, 'level' => 'debug'],
        ]);
        Log::forgetChannel('capture');

        return Log::channel('capture')->getLogger()->getHandlers()[0];
    }

    /** @return list<\Monolog\LogRecord> */
    protected function recordsMatching(TestHandler $handler, string $messageContains): array
    {
        return array_values(array_filter($handler->getRecords(), fn ($r) => str_contains($r->message, $messageContains)));
    }
}
