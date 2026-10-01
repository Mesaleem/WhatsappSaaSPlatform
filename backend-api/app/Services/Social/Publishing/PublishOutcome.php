<?php

namespace App\Services\Social\Publishing;

/**
 * Phase 9 Task 3 — a provider's answer to a publish (or processing check):
 * published (with the provider's post id), processing (the provider is
 * still preparing it — containerId to check again) or failed (a safe,
 * user-facing reason that is not a connection problem, e.g. a rejected video).
 */
final class PublishOutcome
{
    public const PUBLISHED = 'published';

    public const PROCESSING = 'processing';

    public const FAILED = 'failed';

    private function __construct(
        public readonly string $status,
        public readonly ?string $externalId = null,
        public readonly ?string $containerId = null,
        public readonly ?string $message = null,
    ) {
    }

    public static function published(string $externalId): self
    {
        return new self(self::PUBLISHED, externalId: $externalId);
    }

    public static function processing(string $containerId): self
    {
        return new self(self::PROCESSING, containerId: $containerId);
    }

    public static function failed(string $message): self
    {
        return new self(self::FAILED, message: $message);
    }
}
