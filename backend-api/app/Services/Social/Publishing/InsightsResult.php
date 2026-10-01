<?php

namespace App\Services\Social\Publishing;

/**
 * Phase 9 Task 4 — normalised insights for one post.
 *
 * $metrics:     metric => int (0 means the provider reported zero)
 * $unavailable: metric => reason (not_supported | permission | unsupported_metric | not_reported)
 * A metric appears in exactly one of the two maps.
 */
final class InsightsResult
{
    public const FOUND = 'found';

    public const NOT_FOUND = 'not_found';

    public const REASON_NOT_SUPPORTED = 'not_supported';

    public const REASON_PERMISSION = 'permission';

    public const REASON_UNSUPPORTED_METRIC = 'unsupported_metric';

    public const REASON_NOT_REPORTED = 'not_reported';

    /**
     * @param  array<string, int>  $metrics
     * @param  array<string, string>  $unavailable
     */
    private function __construct(
        public readonly string $status,
        public readonly array $metrics = [],
        public readonly array $unavailable = [],
    ) {
    }

    /**
     * @param  array<string, int>  $metrics
     * @param  array<string, string>  $unavailable
     */
    public static function found(array $metrics, array $unavailable): self
    {
        return new self(self::FOUND, $metrics, array_diff_key($unavailable, $metrics));
    }

    /** The provider says the post no longer exists (deleted on the platform). */
    public static function notFound(): self
    {
        return new self(self::NOT_FOUND);
    }
}
