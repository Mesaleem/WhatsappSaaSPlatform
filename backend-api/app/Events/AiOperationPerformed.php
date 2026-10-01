<?php

namespace App\Events;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * Phase 8 AI foundation — the hook later tasks (metering, analytics) listen
 * to. Carries METADATA only: never a prompt, a response text, a credential
 * or a header. Dispatched once per AiService operation, success or failure.
 * Nothing listens to it yet.
 */
final class AiOperationPerformed
{
    use Dispatchable;

    public function __construct(
        public readonly int $accountId,
        public readonly ?int $actorUserId,
        public readonly string $operation,
        public readonly string $source,
        public readonly ?string $provider,
        public readonly ?string $model,
        public readonly bool $success,
        public readonly int $latencyMs,
        public readonly ?string $errorCode = null,
        public readonly ?string $errorCategory = null,
        public readonly ?int $inputTokens = null,
        public readonly ?int $outputTokens = null,
        // Phase 8 Task 5 — the ai_operations row this belongs to (metered calls).
        public readonly ?string $operationId = null,
    ) {
    }
}
