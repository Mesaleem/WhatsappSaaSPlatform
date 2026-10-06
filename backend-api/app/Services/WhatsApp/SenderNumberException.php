<?php

namespace App\Services\WhatsApp;

use RuntimeException;

/** A send refused because of its sending number: not this account's, not connected, or not the group's number. */
class SenderNumberException extends RuntimeException
{
    public function __construct(public readonly string $errorCode, string $message, public readonly int $status = 422)
    {
        parent::__construct($message);
    }
}
