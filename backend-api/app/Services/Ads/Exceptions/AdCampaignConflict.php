<?php

namespace App\Services\Ads\Exceptions;

use App\Models\AdCampaign;
use Illuminate\Http\JsonResponse;
use RuntimeException;

/**
 * Phase 10 Task 2 — a campaign action refused because of the campaign's own
 * state (another change in progress, an invalid transition, a launch still
 * running / already failed / unconfirmed, a campaign gone at Meta). Rendered
 * in the standard {success, message, error_code} shape; never a provider's
 * raw error text.
 */
class AdCampaignConflict extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly string $errorCode,
        public readonly int $httpStatus = 409,
        public readonly ?AdCampaign $campaign = null,
    ) {
        parent::__construct($message);
    }

    /** Laravel's handler calls this if the exception escapes a controller. */
    public function render(): JsonResponse
    {
        return $this->respond();
    }

    public function respond(?callable $present = null): JsonResponse
    {
        $body = ['success' => false, 'message' => $this->getMessage(), 'error_code' => $this->errorCode];

        if ($this->campaign !== null && $present !== null) {
            $body['data'] = $present($this->campaign);
        }

        return response()->json($body, $this->httpStatus);
    }
}
