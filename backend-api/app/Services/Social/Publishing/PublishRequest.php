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
        // 2026-10-15 — 'post' | 'reel' | 'story' | 'carousel' (OrganicPost::FORMATS).
        public readonly string $format = 'post',
        /** @var list<array{url: string, type: string}> a carousel's items */
        public readonly array $mediaItems = [],
    ) {
    }
}
