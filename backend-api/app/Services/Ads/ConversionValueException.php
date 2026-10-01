<?php

namespace App\Services\Ads;

use RuntimeException;

/** A conversion value cannot be recorded (Phase 10 Task 5) — carries the API error code and status. */
class ConversionValueException extends RuntimeException
{
    public function __construct(string $message, public readonly string $errorCode, public readonly int $httpStatus = 422)
    {
        parent::__construct($message);
    }
}
