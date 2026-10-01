<?php

namespace App\Support;

use Illuminate\Support\Carbon;

/**
 * Phase 5 fix P5-9 — the moment a delayed job may run, for delays that
 * replace a former sleep() and must never come out SHORTER than asked.
 *
 * Laravel stores a job's available_at in whole seconds, truncating the
 * target time, so `now()->addSeconds(3)` taken at hh:mm:10.9 becomes
 * available at :13 — only 2.1 s later. Rounding the target UP to the next
 * whole second makes the real wait always >= $seconds (and < $seconds + 1).
 * Verified on the database queue by tests/Probes/outbound_pacing_probe.php.
 */
final class QueueDelay
{
    public static function after(int $seconds): Carbon
    {
        return now()->addSeconds($seconds)->ceilSecond();
    }
}
