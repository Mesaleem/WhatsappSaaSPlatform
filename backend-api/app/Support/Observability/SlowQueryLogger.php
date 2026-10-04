<?php

namespace App\Support\Observability;

use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Phase 12 Task 3 — environment-gated slow-query visibility. Diagnostics only: it never changes a query.
 *
 *  - Off by default. When SLOW_QUERY_LOG_ENABLED is false NO listener is registered, so there is no per-query cost.
 *  - It logs the query SHAPE (literals and IN-lists collapsed to `?`) and the duration. It never reads
 *    `$event->bindings`, so bound values (emails, phone numbers, tokens) cannot reach the log; values that were
 *    interpolated into the SQL text by raw expressions are stripped by the shape normaliser.
 *  - Noise control: per process, the same shape is logged at most `max_per_shape_per_minute` times a minute;
 *    suppressed repeats are counted and reported on the next line that is written for that shape.
 *  - The listener only formats and logs; it issues no query, takes no lock and touches no cache.
 */
final class SlowQueryLogger
{
    private const MAX_TRACKED_SHAPES = 200;

    /** @var array<string, array{window: int, logged: int, suppressed: int}> */
    private static array $seen = [];

    public static function register(): void
    {
        if (! (bool) config('observability.slow_query.enabled', false)) {
            return;
        }

        DB::listen(fn (QueryExecuted $query) => self::handle($query));
    }

    public static function handle(QueryExecuted $query): void
    {
        $threshold = (float) config('observability.slow_query.threshold_ms', 500);
        if ($query->time < $threshold) {
            return;
        }

        $shape = self::shape($query->sql);
        $fingerprint = substr(sha1($shape), 0, 12);
        $now = time();
        $max = max(1, (int) config('observability.slow_query.max_per_shape_per_minute', 3));

        if (! isset(self::$seen[$fingerprint]) && count(self::$seen) >= self::MAX_TRACKED_SHAPES) {
            self::$seen = [];   // bounded memory in a long-lived worker
        }
        $slot = self::$seen[$fingerprint] ?? ['window' => $now, 'logged' => 0, 'suppressed' => 0];
        if ($now - $slot['window'] >= 60) {
            $slot = ['window' => $now, 'logged' => 0, 'suppressed' => $slot['suppressed']];
        }

        if ($slot['logged'] >= $max) {
            $slot['suppressed']++;
            self::$seen[$fingerprint] = $slot;

            return;
        }

        $context = [
            'connection' => $query->connectionName,
            'duration_ms' => round($query->time, 2),
            'threshold_ms' => (int) $threshold,
            'query_shape' => $shape,
            'query_fingerprint' => $fingerprint,
        ];
        if ($slot['suppressed'] > 0) {
            $context['suppressed_repeats'] = $slot['suppressed'];
            $slot['suppressed'] = 0;
        }
        $slot['logged']++;
        self::$seen[$fingerprint] = $slot;

        Log::warning('Slow database query', $context);
    }

    /** Collapses literals so the logged text is a shape, not data. */
    public static function shape(string $sql): string
    {
        $sql = preg_replace("/'(?:[^'\\\\]|\\\\.|'')*'/s", '?', $sql) ?? $sql;
        $sql = preg_replace('/\b0x[0-9a-fA-F]+\b/', '?', $sql) ?? $sql;
        $sql = preg_replace('/(?<![\w`.])-?\d+(?:\.\d+)?\b/', '?', $sql) ?? $sql;
        $sql = preg_replace('/\b(in|not in)\s*\(\s*\?(?:\s*,\s*\?)+\s*\)/i', '$1 (?)', $sql) ?? $sql;
        $sql = preg_replace('/\s+/', ' ', trim($sql)) ?? $sql;

        return mb_substr($sql, 0, 600);
    }

    /** @internal tests */
    public static function reset(): void
    {
        self::$seen = [];
    }
}
