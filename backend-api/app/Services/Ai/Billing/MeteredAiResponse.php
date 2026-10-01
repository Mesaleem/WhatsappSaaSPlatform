<?php

namespace App\Services\Ai\Billing;

use App\Models\AiOperation;
use App\Services\Ai\Data\AiResponse;

/**
 * Phase 8 Task 5 — an AI answer together with what it cost.
 * $settled = false: the answer was delivered and the charge is recorded on
 * the ai_operations row, but writing it to the ledger failed; the scheduled
 * `ai:settle-operations` completes it idempotently.
 */
final class MeteredAiResponse
{
    public function __construct(
        public readonly AiResponse $response,
        public readonly AiOperation $operation,
        public readonly int $creditsCharged,
        public readonly bool $settled,
    ) {
    }
}
