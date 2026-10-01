<?php

namespace App\Services\Social\Publishing;

/** Phase 9 Task 4 — classification of a non-connection insights failure. */
final class InsightsFailure
{
    public const RATE_LIMITED = 'rate_limited';

    public const TRANSIENT = 'transient';

    public const NOT_FOUND = 'not_found';

    public const PERMANENT = 'permanent';
}
