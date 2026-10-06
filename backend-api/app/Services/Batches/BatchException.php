<?php

namespace App\Services\Batches;

use RuntimeException;

/** A refused batch action, with a stable code and the HTTP status the page should show it with. */
class BatchException extends RuntimeException
{
    public function __construct(public readonly string $errorCode, string $message, public readonly int $status = 422)
    {
        parent::__construct($message);
    }
}
