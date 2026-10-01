<?php

namespace App\Services\Ai\Data;

use App\Services\Ai\AiException;

/**
 * Phase 8 Task 9 — the normalized result of an embedding request: one
 * float vector per input, in input order, all of the same dimension.
 * $inputTokens is what the vendor reported (null when it reported none —
 * the metering then applies ai.credits.missing_usage; nothing is guessed).
 */
final class EmbeddingResponse
{
    public readonly int $dimensions;

    /** @param list<list<float>> $vectors */
    public function __construct(
        public readonly string $provider,
        public readonly string $model,
        public readonly array $vectors,
        public readonly ?int $inputTokens = null,
        public readonly int $latencyMs = 0,
    ) {
        $dimensions = null;

        foreach ($vectors as $vector) {
            if (! is_array($vector) || $vector === [] || ! array_is_list($vector)) {
                throw AiException::malformedResponse($provider);
            }

            foreach ($vector as $value) {
                if (! is_int($value) && ! is_float($value)) {
                    throw AiException::malformedResponse($provider);
                }
            }

            $dimensions ??= count($vector);

            if (count($vector) !== $dimensions) {
                throw AiException::malformedResponse($provider);
            }
        }

        if ($vectors === [] || ! array_is_list($vectors)) {
            throw AiException::malformedResponse($provider);
        }

        $this->dimensions = (int) $dimensions;
    }

    public function withLatency(int $latencyMs): self
    {
        return new self($this->provider, $this->model, $this->vectors, $this->inputTokens, $latencyMs);
    }
}
