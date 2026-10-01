<?php

namespace App\Services\Knowledge\Contracts;

use App\Services\Knowledge\TextChunk;

/**
 * Phase 8 Task 9 — splits normalized text into retrievable passages.
 * MUST be deterministic: the same text always yields the same chunks (same
 * boundaries, same order), so re-processing is repeatable and chunk
 * identity (document, version, index) is stable.
 */
interface TextChunker
{
    /** @return list<TextChunk> */
    public function chunk(string $text): array;
}
