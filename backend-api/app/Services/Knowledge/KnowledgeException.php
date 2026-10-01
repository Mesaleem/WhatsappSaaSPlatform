<?php

namespace App\Services\Knowledge;

use Illuminate\Http\JsonResponse;
use RuntimeException;

/**
 * Phase 8 Task 9 — the knowledge-base domain's controlled error (same
 * envelope as AiException: {success:false, message, error_code}). Messages
 * are safe: never document text, a credential or another account's data.
 * "Not found" is also what another account's id gets — existence is never
 * revealed across accounts.
 */
class KnowledgeException extends RuntimeException
{
    public const KNOWLEDGE_BASE_NOT_FOUND = 'KNOWLEDGE_BASE_NOT_FOUND';

    public const DOCUMENT_NOT_FOUND = 'KNOWLEDGE_DOCUMENT_NOT_FOUND';

    public const DOCUMENT_EMPTY = 'KNOWLEDGE_DOCUMENT_EMPTY';

    public const DOCUMENT_TOO_LARGE = 'KNOWLEDGE_DOCUMENT_TOO_LARGE';

    public const UNSUPPORTED_SOURCE = 'KNOWLEDGE_UNSUPPORTED_SOURCE';

    public const NAME_TAKEN = 'KNOWLEDGE_BASE_NAME_TAKEN';

    public const EMBEDDING_MISMATCH = 'KNOWLEDGE_EMBEDDING_MISMATCH';

    public const ALREADY_CHARGED = 'KNOWLEDGE_EMBEDDING_ALREADY_CHARGED';

    public const PROCESSING_FAILED = 'KNOWLEDGE_PROCESSING_FAILED';

    public const INVALID_QUERY = 'KNOWLEDGE_INVALID_QUERY';

    public function __construct(public readonly string $errorCode, string $message, public readonly int $httpStatus)
    {
        parent::__construct($message);
    }

    public function render(): JsonResponse
    {
        return response()->json(['success' => false, 'message' => $this->getMessage(), 'error_code' => $this->errorCode], $this->httpStatus);
    }

    public static function knowledgeBaseNotFound(): self
    {
        return new self(self::KNOWLEDGE_BASE_NOT_FOUND, 'Knowledge base not found.', 404);
    }

    public static function documentNotFound(): self
    {
        return new self(self::DOCUMENT_NOT_FOUND, 'Document not found.', 404);
    }

    public static function documentEmpty(): self
    {
        return new self(self::DOCUMENT_EMPTY, 'The document has no text once normalized.', 422);
    }

    public static function documentTooLarge(int $max): self
    {
        return new self(self::DOCUMENT_TOO_LARGE, "The document is larger than {$max} characters.", 422);
    }

    public static function unsupportedSource(string $type): self
    {
        return new self(self::UNSUPPORTED_SOURCE, "Documents of type '".mb_substr($type, 0, 32)."' cannot be ingested.", 422);
    }

    public static function nameTaken(): self
    {
        return new self(self::NAME_TAKEN, 'A knowledge base with this name already exists.', 422);
    }

    public static function embeddingMismatch(): self
    {
        return new self(self::EMBEDDING_MISMATCH, "The embedding does not match this knowledge base's embedding model; re-index the knowledge base.", 409);
    }

    public static function alreadyCharged(): self
    {
        return new self(self::ALREADY_CHARGED, 'This version was already embedded and charged, but its vectors were not stored (the run was interrupted). Re-process the document to rebuild it.', 409);
    }

    public static function processingFailed(): self
    {
        return new self(self::PROCESSING_FAILED, 'The document could not be processed.', 500);
    }

    public static function invalidQuery(string $detail): self
    {
        return new self(self::INVALID_QUERY, $detail, 422);
    }
}
