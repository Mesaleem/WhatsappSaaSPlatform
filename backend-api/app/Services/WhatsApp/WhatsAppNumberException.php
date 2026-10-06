<?php

namespace App\Services\WhatsApp;

use RuntimeException;

/** A refused WhatsApp-number operation. The message is safe to show the user. */
class WhatsAppNumberException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly string $errorCode,
        public readonly int $status = 422,
    ) {
        parent::__construct($message);
    }
}
