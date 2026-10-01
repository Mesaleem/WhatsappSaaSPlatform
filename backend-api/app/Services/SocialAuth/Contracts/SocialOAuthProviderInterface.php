<?php

namespace App\Services\SocialAuth\Contracts;

use App\Models\Account;
use App\Models\SocialAccount;
use App\Models\SocialProviderConfig;
use App\Services\SocialAuth\ConnectionCheck;
use Carbon\CarbonInterface;

/**
 * The provider-agnostic social account contract (Phase 1 of the Social
 * expansion introduced it with the three OAuth calls; Phase 9 Task 1 moved
 * every remaining provider-specific decision behind it, so
 * SocialAuthController and the rest of the app only ever talk to this
 * interface — MetaOAuthProvider today, a LinkedIn/Google driver later).
 *
 * Responsibilities:
 *   identity/capabilities  key(), label(), assetTypes(), capabilities()
 *   availability           isEnabledFor() — whether a tenant may connect
 *                          this provider at all (Super Admin platform flags)
 *   connect                buildAuthorizationUrl()
 *   callback               callbackError(), exchangeCodeForToken()
 *   discovery              fetchAssets()
 *   credentials            credentialsForAsset() — the token/expiry to store
 *                          for ONE bound asset (e.g. Meta's per-Page token)
 *   connection state       checkConnection(), classifyApiFailure()
 *   disconnect/revoke      revoke()
 *
 * Credentials always come from the SocialProviderConfig row passed in,
 * never from config/.env, so a Super Admin rotates them at runtime.
 * Implementations report failures as RuntimeException with a message that
 * is safe to log (never a token); the controller shows users a generic one.
 */
interface SocialOAuthProviderInterface
{
    /** The provider slug used in routes and social_accounts.provider (e.g. "meta"). */
    public function key(): string;

    public function label(): string;

    /** @return list<string> the social_accounts.asset_type values this provider can bind */
    public function assetTypes(): array;

    /**
     * What each asset type can be used for in this app (informational, for
     * the UI; authorization never reads it).
     *
     * @return array<string, list<string>>
     */
    public function capabilities(): array;

    /** May this tenant connect this provider (the platform flags a Super Admin sets per account)? */
    public function isEnabledFor(Account $account): bool;

    /**
     * The provider's consent-screen URL for the popup. $state is an opaque,
     * encrypted backend token that must be passed through unmodified.
     */
    public function buildAuthorizationUrl(SocialProviderConfig $config, string $state): string;

    /**
     * The provider's error in the callback query, if any. `cancelled` is
     * true when the user declined/closed the consent screen (not a failure).
     *
     * @param  array<string, mixed>  $query
     * @return array{cancelled: bool, message: string}|null
     */
    public function callbackError(array $query): ?array;

    /**
     * Exchanges the redirect `code` for the grant's token (the longest-lived
     * one the provider issues).
     *
     * @return array{access_token: string, refresh_token: string|null, expires_in: int|null}
     */
    public function exchangeCodeForToken(SocialProviderConfig $config, string $code): array;

    /**
     * Every bindable asset this grant can see.
     *
     * @return list<array{asset_type: string, provider_id: string, name: string|null, avatar_url: string|null, metadata?: array<string, scalar|null>}>
     */
    public function fetchAssets(string $accessToken): array;

    /**
     * The credentials to store for ONE asset bound from a grant.
     *
     * @param  array{access_token: string, refresh_token: string|null, expires_in: int|null}  $grant
     * @param  array{asset_type: string, provider_id: string}  $asset
     * @return array{access_token: string, refresh_token: string|null, expires_at: CarbonInterface|null}
     */
    public function credentialsForAsset(array $grant, array $asset): array;

    /** Asks the provider whether a stored connection is still usable. Never throws. */
    public function checkConnection(SocialAccount $socialAccount): ConnectionCheck;

    /**
     * Phase 9 Task 2 — interprets a FAILED provider API response made with a
     * stored connection's credentials (by any feature: ads, publishing,
     * inbox). Returns `expired` / `revoked` when the failure means the
     * connection itself is no longer usable, and null for every other
     * failure (validation, rate limit, outage), which must never change the
     * stored connection state. $errorBody is the decoded response body.
     * Provider error codes are interpreted only here, never in generic code.
     *
     * @param  array<string, mixed>  $errorBody
     */
    public function classifyApiFailure(int $httpStatus, array $errorBody): ?ConnectionCheck;

    /**
     * Revokes the stored grant at the provider when that can be done for
     * this ONE connection without affecting others. Returns whether it was
     * revoked there; the local disconnect happens either way. Never throws.
     */
    public function revoke(SocialAccount $socialAccount): bool;
}
