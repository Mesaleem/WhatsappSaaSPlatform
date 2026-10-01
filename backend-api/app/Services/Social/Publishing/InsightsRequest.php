<?php

namespace App\Services\Social\Publishing;

/** Phase 9 Task 4 — which published post to report on. */
final class InsightsRequest
{
    public function __construct(
        public readonly string $platform,
        public readonly string $providerPostId,
        public readonly ?string $mediaType = null,
    ) {
    }
}
