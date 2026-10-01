<?php

namespace App\Services\Ads\Exceptions;

use App\Models\AdCampaign;
use RuntimeException;

/**
 * Phase 10 Task 2 — a Meta Ads WRITE whose outcome is unknown: the request
 * may or may not have been applied (no response, a transport error, or a
 * 5xx). It is never retried automatically. Extends RuntimeException so every
 * existing `catch (RuntimeException)` keeps handling it.
 */
class AdProviderOutcomeUnknown extends RuntimeException
{
    /** The local campaign row the unconfirmed operation belongs to, when there is one. */
    public ?AdCampaign $campaign = null;
}
