<?php

namespace App\Services\SocialAuth\Contracts;

use App\Models\SocialProviderConfig;

/**
 * Social Media Marketing & Meta Ads Automation Expansion — Phase 1.
 * One implementation per OAuth provider (MetaOAuthProvider today;
 * LinkedIn/Google are a disclosed Phase 2 follow-up — see
 * SocialOAuthProviderFactory). Credentials always come from the
 * SocialProviderConfig row passed in, never from config/services.php or
 * .env, so a Super Admin can rotate them at runtime with zero deploy.
 */
interface SocialOAuthProviderInterface
{
    /**
     * The provider's own OAuth consent-screen URL the frontend popup is
     * pointed at. $state is an opaque, backend-generated, encrypted
     * token — implementations must pass it through unmodified so
     * SocialAuthController::callback() can verify it came from a request
     * this backend actually issued.
     */
    public function buildAuthorizationUrl(SocialProviderConfig $config, string $state): string;

    /**
     * Exchanges the provider's redirect `code` for an access token.
     *
     * @return array{access_token: string, refresh_token: string|null, expires_in: int|null}
     */
    public function exchangeCodeForToken(SocialProviderConfig $config, string $code): array;

    /**
     * Lists every bindable asset (Page / Ad Account / IG Business
     * Account / ...) this access token can see, for the Asset Selection
     * Modal to present.
     *
     * @return list<array{asset_type: string, provider_id: string, name: string|null, avatar_url: string|null}>
     */
    public function fetchAssets(string $accessToken): array;
}
