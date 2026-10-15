<?php

namespace App\Services\Billing;

use Illuminate\Http\JsonResponse;
use RuntimeException;

/**
 * A plan limit was reached (plan restructure, 2026-10-15). Renders itself as
 * a 403 with `error_code` = PLAN_LIMIT_REACHED, which limit and the number
 * allowed, so every endpoint that checks a limit answers the same way.
 */
class PlanLimitException extends RuntimeException
{
    public function __construct(string $message, public readonly string $limit, public readonly int $allowed)
    {
        parent::__construct($message);
    }

    public function render(): JsonResponse
    {
        return response()->json([
            'message' => $this->getMessage(),
            'error_code' => 'PLAN_LIMIT_REACHED',
            'limit' => $this->limit,
            'allowed' => $this->allowed,
        ], 403);
    }
}
