<?php

namespace App\Services\SocialAuth;

use App\Models\SocialProviderConfig;
use App\Services\SocialAuth\Contracts\SocialOAuthProviderInterface;
use InvalidArgumentException;
use RuntimeException;

/**
 * Social Media Marketing & Meta Ads Automation Expansion — Phase 1.
 * Resolves the driver AND the runtime credentials for a given provider
 * slug — same "factory reads a DB-configured settings row" shape as
 * App\Services\Payment\PaymentGatewayFactory, but for OAuth apps instead
 * of payment gateways.
 */
class SocialOAuthProviderFactory
{
    /** @var list<string> Providers with a real driver wired up today. */
    public const IMPLEMENTED_PROVIDERS = ['meta'];

    /**
     * Phase 9 Task 1 — every implemented driver (the provider list the UI
     * offers). Adding a provider = one driver class + one line here.
     *
     * @return list<SocialOAuthProviderInterface>
     */
    public static function all(): array
    {
        return array_map(fn (string $key) => self::make($key), self::IMPLEMENTED_PROVIDERS);
    }

    /** Is there a real driver for this slug (unknown and not-yet-built slugs are both "no")? */
    public static function isImplemented(string $provider): bool
    {
        return in_array($provider, self::IMPLEMENTED_PROVIDERS, true);
    }

    /**
     * The active credentials row, or null — the non-throwing twin of
     * configFor(), for status displays.
     */
    public static function configIfReady(string $provider): ?SocialProviderConfig
    {
        try {
            return self::configFor($provider);
        } catch (\Throwable) {
            return null;
        }
    }

    public static function make(string $provider): SocialOAuthProviderInterface
    {
        return match ($provider) {
            'meta' => app(MetaOAuthProvider::class),
            'linkedin', 'google' => throw new InvalidArgumentException(
                "The {$provider} OAuth driver is not implemented yet — Phase 1 ships Meta only; LinkedIn/Google are a disclosed Phase 2 follow-up."
            ),
            default => throw new InvalidArgumentException("Unknown social OAuth provider: {$provider}."),
        };
    }

    /**
     * The active, fully-configured credentials row for $provider, or an
     * exception with a message safe to surface to a Super Admin/social_marketer.
     */
    public static function configFor(string $provider): SocialProviderConfig
    {
        if (! in_array($provider, SocialProviderConfig::PROVIDERS, true)) {
            throw new InvalidArgumentException("Unknown social OAuth provider: {$provider}.");
        }

        $config = SocialProviderConfig::findByProviderCached($provider);

        if (! $config || ! $config->is_active || ! $config->isFullyConfigured()) {
            throw new RuntimeException(
                ucfirst($provider).' is not configured or enabled yet. Ask a Super Admin to set it up in Social Gateway Settings.'
            );
        }

        return $config;
    }
}
