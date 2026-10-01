<?php

namespace App\Services\Knowledge;

use App\Services\Knowledge\Contracts\TextChunker;

/**
 * Phase 8 Task 9 — deterministic sliding-window chunker over characters.
 *
 * Each chunk is at most $size characters. Its end is moved back to the
 * best natural break found in the second half of the window — a blank
 * line, then a line break, then a sentence end, then a space — and the
 * next chunk starts $overlap characters before that end, aligned forward
 * to a word start, so a passage split across two chunks is still found by
 * either. Offsets are character (not byte) positions into the normalized
 * text. Provider-neutral: characters, not vendor tokens.
 */
final class CharacterWindowChunker implements TextChunker
{
    private const BREAKS = ["\n\n", "\n", '. ', '? ', '! ', ' '];

    public function __construct(private readonly int $size, private readonly int $overlap)
    {
    }

    public static function fromConfig(): self
    {
        $size = max(200, (int) config('ai.knowledge.chunk_chars', 1200));

        return new self($size, min(max(0, (int) config('ai.knowledge.chunk_overlap_chars', 200)), intdiv($size, 2)));
    }

    public function chunk(string $text): array
    {
        $length = mb_strlen($text);
        $chunks = [];
        $start = 0;

        while ($start < $length) {
            $end = min($length, $start + $this->size);

            if ($end < $length) {
                $end = $this->breakBefore($text, $start, $end);
            }

            $piece = mb_substr($text, $start, $end - $start);
            $trimmedLeft = mb_strlen($piece) - mb_strlen(ltrim($piece));
            $piece = trim($piece);

            if ($piece !== '') {
                $chunkStart = $start + $trimmedLeft;
                $chunks[] = new TextChunk(count($chunks), $piece, $chunkStart, $chunkStart + mb_strlen($piece));
            }

            if ($end >= $length) {
                break;
            }

            $next = max($end - $this->overlap, $start + 1);
            // align forward to the start of a word (never beyond $end)
            while ($next < $end && ! ctype_space(mb_substr($text, $next - 1, 1))) {
                $next++;
            }
            $start = $next;
        }

        return $chunks;
    }

    private function breakBefore(string $text, int $start, int $end): int
    {
        $window = mb_substr($text, $start, $end - $start);
        $minimum = intdiv($end - $start, 2);

        foreach (self::BREAKS as $break) {
            $position = mb_strrpos($window, $break);

            if ($position !== false && $position >= $minimum) {
                return $start + $position + mb_strlen($break);
            }
        }

        return $end; // no natural break: a hard cut
    }
}
