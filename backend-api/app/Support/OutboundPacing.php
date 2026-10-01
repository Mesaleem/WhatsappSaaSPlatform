<?php

namespace App\Support;

/**
 * Phase 5 fix P5-9 — the anti-ban spacing that used to be a blocking
 * sleep(random_int(3, 8)) in ProcessGroupDispatchJob,
 * ProcessGroupDirectMessageJob and ProcessPaymentAlertJob.
 *
 * Only computes HOW LONG the next send should wait; callers turn that into
 * a queue delay (a delayed job or a delayed continuation slice). Nothing
 * here, or in its callers, blocks a worker or a request.
 */
final class OutboundPacing
{
    /** Seconds before the next paced send: random in [min, max], or 0 when pacing is off. */
    public static function delaySeconds(): int
    {
        $max = (int) config('messaging.outbound_pacing.max_seconds', 8);

        if ($max <= 0) {
            return 0;
        }

        $min = max(0, min($max, (int) config('messaging.outbound_pacing.min_seconds', 3)));

        return random_int($min, $max);
    }
}
