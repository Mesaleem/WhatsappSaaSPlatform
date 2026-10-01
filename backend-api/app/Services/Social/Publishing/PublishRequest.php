<?php

namespace App\Services\Social\Publishing;

/** Phase 9 Task 3 — what to publish (never who: the target connection is passed separately). */
final class PublishRequest
{
    public function __construct(
        public readonly string $platform,
        public readonly string $caption,
        public readonly ?string $mediaUrl = null,
        public readonly ?string $mediaType = null,
    ) {
    }
}
