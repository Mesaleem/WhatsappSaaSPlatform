<?php

namespace App\Services\Knowledge\Contracts;

use App\Services\Knowledge\KnowledgeException;

/**
 * Phase 8 Task 9 — turns a submitted source into normalized plain text.
 * Only plain text is supported today (PlainTextExtractor); a PDF/DOCX/HTML
 * extractor is another implementation of this contract.
 */
interface DocumentTextExtractor
{
    public function supports(string $sourceType): bool;

    /** @throws KnowledgeException */
    public function extract(string $sourceType, string $raw): string;
}
