<?php

namespace App\Services\Knowledge;

/** Phase 8 Task 9 — one chunk produced by a TextChunker (character offsets into the normalized text). */
final class TextChunk
{
    public readonly string $hash;

    public function __construct(
        public readonly int $index,
        public readonly string $text,
        public readonly int $start,
        public readonly int $end,
    ) {
        $this->hash = hash('sha256', $text);
    }
}
