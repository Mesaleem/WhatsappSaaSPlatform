<?php

namespace App\Services\Social\Publishing;

use App\Services\Social\Publishing\Contracts\SocialInsightsProvider;
use App\Services\Social\Publishing\Contracts\SocialPublisher;
use InvalidArgumentException;

/**
 * Phase 9 Task 3 — resolves the provider publisher for a platform. The
 * orchestrator (OrganicPublishService) only ever talks to SocialPublisher;
 * adding a provider means adding a class here, not touching the lifecycle.
 */
class SocialPublisherFactory
{
    /** @var array<string, class-string<SocialPublisher>> platform => publisher */
    private const PLATFORM_PUBLISHERS = [
        'facebook' => MetaPublisher::class,
        'instagram' => MetaPublisher::class,
        'linkedin' => LinkedInPublisher::class,
    ];

    public function forPlatform(string $platform): SocialPublisher
    {
        $class = self::PLATFORM_PUBLISHERS[$platform] ?? null;

        if (! $class) {
            throw new InvalidArgumentException("Unsupported platform '{$platform}'.");
        }

        return app($class);
    }

    /** Phase 9 Task 4 — the insights provider for a platform, or null when none reports insights for it. */
    public function insightsFor(string $platform): ?SocialInsightsProvider
    {
        if (! $this->supports($platform)) {
            return null;
        }

        $publisher = $this->forPlatform($platform);

        return $publisher instanceof SocialInsightsProvider && in_array($platform, $publisher->insightsPlatforms(), true) ? $publisher : null;
    }

    public function supports(string $platform): bool
    {
        return isset(self::PLATFORM_PUBLISHERS[$platform]);
    }
}
