<?php

namespace App\Services\Scheduling;

use RuntimeException;

/** A scheduling request that cannot be accepted. The message is safe to show the client. */
class ScheduledMessageException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly string $errorCode,
        public readonly int $status = 422,
    ) {
        parent::__construct($message);
    }
}
