<?php

namespace App\Services\Knowledge;

use App\Services\Knowledge\Contracts\DocumentTextExtractor;
use Normalizer;

/**
 * Phase 8 Task 9 — plain text: the one source type supported today.
 *
 * Normalization (deterministic — the same input always gives the same
 * text, hence the same content hash and the same chunks):
 *   invalid UTF-8 replaced · Unicode NFC (when ext-intl is present) ·
 *   CRLF/CR → LF · control characters except LF/TAB removed · TAB → space ·
 *   trailing spaces per line removed · runs of spaces collapsed ·
 *   3+ consecutive newlines → one blank line · trimmed.
 */
final class PlainTextExtractor implements DocumentTextExtractor
{
    public function supports(string $sourceType): bool
    {
        return $sourceType === 'text';
    }

    public function extract(string $sourceType, string $raw): string
    {
        if (! $this->supports($sourceType)) {
            throw KnowledgeException::unsupportedSource($sourceType);
        }

        return self::normalize($raw);
    }

    public static function normalize(string $text): string
    {
        $text = mb_scrub($text, 'UTF-8');

        if (class_exists(Normalizer::class)) {
            $text = Normalizer::normalize($text, Normalizer::FORM_C) ?: $text;
        }

        $text = str_replace(["\r\n", "\r"], "\n", $text);
        $text = (string) preg_replace('/[^\P{C}\n\t]/u', '', $text); // control/format chars except LF and TAB
        $text = str_replace("\t", ' ', $text);
        $text = (string) preg_replace('/[ ]+$/m', '', $text);
        $text = (string) preg_replace('/ {2,}/', ' ', $text);
        $text = (string) preg_replace('/\n{3,}/', "\n\n", $text);

        return trim($text);
    }
}
