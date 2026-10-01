<?php

namespace App\Services\Ai\Data;

/**
 * Phase 8 AI foundation — the normalized result every provider returns.
 *
 * $data is set only for structured generation (the decoded JSON object).
 * $inputTokens / $outputTokens are what the vendor reported (null when it
 * reported nothing) — recorded for a later metering task, not acted on here.
 */
final class AiResponse
{
    /**
     * @param array<string, mixed>|null $data
     */
    public function __construct(
        public readonly string $provider,
        public readonly string $model,
        public readonly string $text,
        public readonly ?array $data = null,
        public readonly ?int $inputTokens = null,
        public readonly ?int $outputTokens = null,
        public readonly ?string $finishReason = null,
        public readonly int $latencyMs = 0,
    ) {
    }

    public function withLatency(int $latencyMs): self
    {
        return new self($this->provider, $this->model, $this->text, $this->data, $this->inputTokens, $this->outputTokens, $this->finishReason, $latencyMs);
    }

    public function withData(array $data): self
    {
        return new self($this->provider, $this->model, $this->text, $data, $this->inputTokens, $this->outputTokens, $this->finishReason, $this->latencyMs);
    }
}
