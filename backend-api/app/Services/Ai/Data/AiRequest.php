<?php

namespace App\Services\Ai\Data;

use App\Services\Ai\AiException;

/**
 * Phase 8 AI foundation — a provider-neutral generation request.
 *
 * $operation is a short label for logs only ("text", "structured", or a
 * caller's own, e.g. "journey.prompt"); it never selects a vendor and never
 * authorizes anything.
 *
 * $requiredKeys (structured generation only): top-level keys the returned
 * JSON object must contain; a response without them is malformed.
 */
final class AiRequest
{
    /**
     * @param list<string> $requiredKeys
     */
    public function __construct(
        public readonly string $prompt,
        public readonly ?string $system = null,
        public readonly ?int $maxTokens = null,
        public readonly ?float $temperature = null,
        public readonly ?string $model = null,
        public readonly string $operation = 'text',
        public readonly array $requiredKeys = [],
        // Phase 8 Task 5 — correlation id for logs/events (set by MeteredAiService).
        public readonly ?string $operationId = null,
    ) {
        if (trim($prompt) === '') {
            throw AiException::invalidRequest('The AI prompt must not be empty.');
        }

        if ($maxTokens !== null && $maxTokens < 1) {
            throw AiException::invalidRequest('max_tokens must be at least 1.');
        }
    }

    public function withOperationId(string $operationId): self
    {
        return new self($this->prompt, $this->system, $this->maxTokens, $this->temperature, $this->model, $this->operation, $this->requiredKeys, $operationId);
    }

    public function maxTokens(): int
    {
        return $this->maxTokens ?? max(1, (int) config('ai.defaults.max_tokens', 1024));
    }

    public function temperature(): float
    {
        return $this->temperature ?? (float) config('ai.defaults.temperature', 0.7);
    }
}
