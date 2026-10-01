<?php

namespace App\Services\Ai\Agents\Tools;

use RuntimeException;

/**
 * Phase 8 Task 11 — a controlled tool failure (not found, invalid transition
 * …). Reported back to the model as {ok:false, error, message} and recorded
 * on the invocation; any side effect of the failed call is rolled back. The
 * message must be safe to show the model (no secrets, no other account's data).
 */
class ToolError extends RuntimeException
{
    public function __construct(public readonly string $errorCode, string $message)
    {
        parent::__construct($message);
    }
}
