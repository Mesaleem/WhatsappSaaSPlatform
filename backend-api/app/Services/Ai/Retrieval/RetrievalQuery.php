<?php

namespace App\Services\Ai\Retrieval;

use App\Models\Account;
use App\Services\Ai\AiAuthorization;

/**
 * Phase 8 Task 9 — one retrieval request.
 *
 * $account is the TARGET account, stated explicitly by the caller; it must be
 * the account $authorization was issued for (AiAuthorizer) — the retriever
 * refuses a mismatch — and it is the only account whose data is searched and
 * the account billed for the query embedding. $knowledgeBaseId is resolved
 * inside that account only.
 */
final class RetrievalQuery
{
    public function __construct(
        public readonly Account $account,
        public readonly AiAuthorization $authorization,
        public readonly int|string $knowledgeBaseId,
        public readonly string $query,
        public readonly int $limit = 5,
        public readonly ?float $minScore = null,
        public readonly ?string $operationKey = null,
    ) {
    }
}
