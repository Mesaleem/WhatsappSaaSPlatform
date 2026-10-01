<?php

namespace App\Services\Ai\Data;

use App\Services\Ai\AiException;

/**
 * Phase 8 Task 9 — a provider-neutral embedding request: one or more texts
 * embedded with the same model.
 *
 * $purpose tells retrieval-tuned models whether the texts are stored
 * documents or a search query (PURPOSE_*); a provider that has no such
 * notion ignores it. $operation is a log/metering label only.
 */
final class EmbeddingRequest
{
    public const PURPOSE_DOCUMENT = 'document';

    public const PURPOSE_QUERY = 'query';

    /** Upper bound on inputs per request (vendors cap batches; callers batch below it). */
    public const MAX_INPUTS = 100;

    /** @param list<string> $inputs */
    public function __construct(
        public readonly array $inputs,
        public readonly string $purpose = self::PURPOSE_DOCUMENT,
        public readonly ?string $model = null,
        public readonly string $operation = 'embedding',
        public readonly ?string $operationId = null,
    ) {
        if ($inputs === [] || ! array_is_list($inputs) || count($inputs) > self::MAX_INPUTS) {
            throw AiException::invalidRequest('An embedding request needs between 1 and '.self::MAX_INPUTS.' inputs.');
        }

        foreach ($inputs as $input) {
            if (! is_string($input) || trim($input) === '') {
                throw AiException::invalidRequest('Every embedding input must be non-empty text.');
            }
        }

        if (! in_array($purpose, [self::PURPOSE_DOCUMENT, self::PURPOSE_QUERY], true)) {
            throw AiException::invalidRequest('Unknown embedding purpose.');
        }
    }

    public function withOperationId(string $operationId): self
    {
        return new self($this->inputs, $this->purpose, $this->model, $this->operation, $operationId);
    }
}
