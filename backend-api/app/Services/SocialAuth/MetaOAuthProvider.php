<?php

namespace App\Services\SocialAuth;

use App\Models\Account;
use App\Models\SocialAccount;
use App\Models\SocialProviderConfig;
use App\Services\SocialAuth\Contracts\SocialOAuthProviderInterface;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

/**
 * Social Media Marketing & Meta Ads Automation Expansion — Phase 1.
 * Meta (Facebook Login for Business / Graph API) OAuth driver — Pages,
 * their linked Instagram Business Accounts, and Ad Accounts.
 *
 * Deliberately separate from MetaConfigController/MetaWebhookController
 * (Module 5): those integrate the WhatsApp Cloud API using a tenant's own
 * long-lived System User token pasted in by hand; this integrates
 * Facebook Login for Business using a platform-level Meta App
 * (social_provider_configs) that every tenant's social_marketer
 * authorizes against via a real OAuth handshake. Same Graph API host,
 * unrelated products.
 *
 * Phase 9 Task 1 — every Meta-specific decision now lives here, behind
 * SocialOAuthProviderInterface: which tenants may connect (allow_facebook /
 * allow_instagram), cancellation detection, the long-lived user-token
 * exchange (fb_exchange_token, ~60 days — Meta offers no refresh without
 * the user, so an expired token means "reconnect"), the per-Page token
 * (credentialsForAsset — moved out of SocialAuthController), the
 * connection check (Graph error 190 → expired/revoked) and the revoke
 * policy. The existing Graph calls are unchanged.
 */
class MetaOAuthProvider implements SocialOAuthProviderInterface
{
    private const API_VERSION = 'v18.0';

    /** A grant whose token lives longer than this is treated as long-lived (Meta: ~60 days; short-lived: ~1–2 hours). */
    private const LONG_LIVED_SECONDS = 86400;

    public function key(): string
    {
        return 'meta';
    }

    public function label(): string
    {
        return 'Meta (Facebook & Instagram)';
    }

    public function assetTypes(): array
    {
        return ['facebook_page', 'instagram', 'meta_ad_account'];
    }

    public function capabilities(): array
    {
        return [
            'facebook_page' => ['organic_publishing', 'lead_ads', 'comments', 'inbox'],
            'instagram' => ['organic_publishing', 'comments', 'inbox'],
            'meta_ad_account' => ['paid_ads'],
        ];
    }

    public function isEnabledFor(Account $account): bool
    {
        return $account->isPlatformAccount() || (bool) ($account->allow_facebook || $account->allow_instagram);
    }

    public function callbackError(array $query): ?array
    {
        $error = $query['error'] ?? null;

        if ($error === null || $error === '') {
            return null;
        }

        $cancelled = $error === 'access_denied' || ($query['error_reason'] ?? null) === 'user_denied';

        return [
            'cancelled' => $cancelled,
            'message' => $cancelled
                ? 'The Meta connection was cancelled. Nothing was connected.'
                : 'Meta could not complete the authorization. Please try connecting again.',
        ];
    }

    /**
     * Scopes requested for the asset inventory (Phase 1: list Pages, read
     * their basic engagement data, resolve a Page's linked Instagram
     * Business Account, and list Ad Accounts) plus `ads_management`
     * (Phase 3 — Meta Ads Launcher: required to actually CREATE/PAUSE
     * campaigns via MetaAdsService, not just list accounts via ads_read).
     *
     * DISCLOSED — Phase 3 re-auth requirement: a tenant who connected
     * their Meta Ad Account BEFORE this scope was added holds a token
     * that was never granted `ads_management`. Meta scopes a token to
     * whatever the user consented to AT THE TIME of that OAuth grant —
     * adding a scope here does not retroactively upgrade tokens already
     * issued. Such a tenant must disconnect and reconnect (Social Hub ->
     * Connect Meta Account again) before MetaAdsService::launch()/pause()/
     * resume() will succeed for them; see the Phase 3 audit report.
     */
    private const CORE_SCOPES = [
        'pages_show_list',
        'pages_read_engagement',
        'ads_read',
        'ads_management',
        'business_management',
    ];

    /**
     * Permissions that Meta only grants through a use case on the platform Meta app
     * ("Manage messaging & content on Instagram", "Engage with customers on Messenger from Meta").
     * Meta rejects the WHOLE login with "Invalid Scopes: …" when any requested scope is not granted
     * by a use case configured on the app, so they are requested only when the platform owner has
     * enabled them (config/social.php → meta_oauth, env META_OAUTH_INSTAGRAM_SCOPES /
     * META_OAUTH_MESSAGING_SCOPES) after adding the use case in the Meta Developer Dashboard.
     * A tenant connected before a group was enabled must reconnect to grant it.
     */
    private const OPTIONAL_SCOPE_GROUPS = [
        // Instagram Business account profile/media through the linked Page.
        'instagram_scopes' => ['instagram_basic'],
        // Phase 4 — Unified Social Inbox (Messenger / Instagram DMs) and private replies to comments.
        'messaging_scopes' => ['pages_messaging', 'instagram_manage_messages'],
    ];

    /** @return list<string> the scopes sent to Meta on connect */
    public function scopes(): array
    {
        $scopes = self::CORE_SCOPES;

        foreach (self::OPTIONAL_SCOPE_GROUPS as $flag => $group) {
            if ((bool) config("social.meta_oauth.{$flag}", false)) {
                array_push($scopes, ...$group);
            }
        }

        return $scopes;
    }

    public function buildAuthorizationUrl(SocialProviderConfig $config, string $state): string
    {
        $query = http_build_query([
            'client_id' => $config->client_id,
            'redirect_uri' => $config->redirect_uri,
            'state' => $state,
            'scope' => implode(',', $this->scopes()),
            'response_type' => 'code',
        ]);

        return 'https://www.facebook.com/'.self::API_VERSION."/dialog/oauth?{$query}";
    }

    public function exchangeCodeForToken(SocialProviderConfig $config, string $code): array
    {
        try {
            $response = Http::timeout(15)->get(
                'https://graph.facebook.com/'.self::API_VERSION.'/oauth/access_token',
                [
                    'client_id' => $config->client_id,
                    'client_secret' => $config->client_secret,
                    'redirect_uri' => $config->redirect_uri,
                    'code' => $code,
                ]
            );
        } catch (Throwable $e) {
            throw new RuntimeException('Could not reach Meta to exchange the authorization code.', previous: $e);
        }

        if ($response->failed()) {
            throw new RuntimeException(
                $response->json('error.message') ?? 'Meta rejected the authorization code.'
            );
        }

        $shortLived = [
            'access_token' => (string) $response->json('access_token'),
            // Meta issues no refresh_token: a long-lived user token (below)
            // is the longest-lived credential, and renewing it needs the user.
            'refresh_token' => null,
            'expires_in' => is_numeric($response->json('expires_in')) ? (int) $response->json('expires_in') : null,
        ];

        return $this->exchangeForLongLivedToken($config, $shortLived);
    }

    /**
     * Phase 9 Task 1 — the short-lived user token (~1–2 h) is exchanged for
     * a long-lived one (~60 days) via grant_type=fb_exchange_token, so the
     * Ad Account / Instagram connections do not expire within hours, and a
     * Page token requested with it does not expire at all. On any failure
     * the short-lived token is kept (logged without the token).
     *
     * @param  array{access_token: string, refresh_token: null, expires_in: int|null}  $shortLived
     * @return array{access_token: string, refresh_token: null, expires_in: int|null}
     */
    private function exchangeForLongLivedToken(SocialProviderConfig $config, array $shortLived): array
    {
        try {
            $response = Http::timeout(15)->get('https://graph.facebook.com/'.self::API_VERSION.'/oauth/access_token', [
                'grant_type' => 'fb_exchange_token',
                'client_id' => $config->client_id,
                'client_secret' => $config->client_secret,
                'fb_exchange_token' => $shortLived['access_token'],
            ]);
        } catch (Throwable $e) {
            Log::warning('Meta long-lived token exchange unreachable; keeping the short-lived token.', ['exception' => class_basename($e)]);

            return $shortLived;
        }

        $token = $response->json('access_token');

        if ($response->failed() || ! is_string($token) || $token === '') {
            Log::warning('Meta long-lived token exchange failed; keeping the short-lived token.', ['status' => $response->status()]);

            return $shortLived;
        }

        return [
            'access_token' => $token,
            'refresh_token' => null,
            'expires_in' => is_numeric($response->json('expires_in')) ? (int) $response->json('expires_in') : null,
        ];
    }

    public function credentialsForAsset(array $grant, array $asset): array
    {
        $expiresIn = $grant['expires_in'] ?? null;
        $grantExpiry = $expiresIn ? now()->addSeconds((int) $expiresIn) : null;

        if ($asset['asset_type'] === 'facebook_page') {
            $pageToken = $this->exchangePageAccessToken($grant['access_token'], $asset['provider_id']);

            if ($pageToken !== null) {
                // A Page token obtained with a long-lived user token has no
                // expiry; one obtained with a short-lived token expires with it.
                $longLived = $expiresIn === null || (int) $expiresIn > self::LONG_LIVED_SECONDS;

                return ['access_token' => $pageToken, 'refresh_token' => null, 'expires_at' => $longLived ? null : $grantExpiry];
            }

            // Moved from SocialAuthController (Phase 2 behaviour kept): fall
            // back to the user token rather than failing the whole bind.
            Log::warning('Meta Page Access Token exchange failed; falling back to the User Access Token.', ['page_id' => $asset['provider_id']]);
        }

        return ['access_token' => $grant['access_token'], 'refresh_token' => $grant['refresh_token'] ?? null, 'expires_at' => $grantExpiry];
    }

    /**
     * GET /me with the stored token (a Page token answers as the Page).
     * Graph error 190 = the token is invalid: subcode 463 means it expired,
     * any other 190 means Meta no longer accepts it (password change, app
     * removed, permissions revoked) → revoked. 10 / 200-299 = a permission
     * the connection needs was removed → revoked. Anything else (network,
     * 5xx, rate limit) → unknown: the stored state is left alone.
     */
    public function checkConnection(SocialAccount $socialAccount): ConnectionCheck
    {
        $token = $socialAccount->access_token;

        if (! is_string($token) || $token === '') {
            return ConnectionCheck::revoked('No access token is stored for this connection. Reconnect it.');
        }

        try {
            $response = Http::withToken($token)->timeout(10)->get('https://graph.facebook.com/'.self::API_VERSION.'/me', ['fields' => 'id']);
        } catch (Throwable) {
            return ConnectionCheck::unknown('Meta could not be reached to check the connection. Try again later.');
        }

        if ($response->successful()) {
            return ConnectionCheck::connected();
        }

        return $this->classifyApiFailure($response->status(), (array) ($response->json() ?? []))
            ?? ConnectionCheck::unknown('Meta could not confirm the connection right now. Try again later.');
    }

    /**
     * Phase 9 Task 2 — the one place a Meta Graph error is read as a
     * connection problem (checkConnection() above and every feature that
     * calls Graph with a stored token go through it). Graph error 190 = the
     * token is invalid: subcode 463 means it expired, any other 190 means
     * Meta no longer accepts it → revoked. 10 / 200-299 = a permission the
     * connection needs was removed → revoked. Everything else (validation,
     * rate limits 4/17/32/613, transient 1/2, 5xx) → null: not a connection
     * problem, the stored state is left alone.
     */
    public function classifyApiFailure(int $httpStatus, array $errorBody): ?ConnectionCheck
    {
        $error = is_array($errorBody['error'] ?? null) ? $errorBody['error'] : [];
        $code = (int) ($error['code'] ?? 0);
        $subcode = (int) ($error['error_subcode'] ?? 0);

        return match (true) {
            $code === 190 && $subcode === 463 => ConnectionCheck::expired('Meta reports that the access token has expired. Reconnect the account.'),
            $code === 190 => ConnectionCheck::revoked('Meta no longer accepts this connection (the permission was removed or the password changed). Reconnect the account.'),
            $code === 10 || ($code >= 200 && $code <= 299) => ConnectionCheck::revoked('A Meta permission this connection needs was removed. Reconnect the account and grant it again.'),
            default => null,
        };
    }

    /**
     * Not done for Meta, deliberately: Meta revokes per (Facebook user, app)
     * — DELETE /{user}/permissions would cut off EVERY Page, Instagram and
     * Ad Account that user granted, in every tenant they connected (an
     * agency user connects many clients). Disconnecting one asset therefore
     * only deletes its stored credentials here; a user who wants to revoke
     * the app itself does so in their Facebook settings.
     */
    public function revoke(SocialAccount $socialAccount): bool
    {
        return false;
    }

    /**
     * Social Media Marketing & Meta Ads Automation Expansion — Phase 2.
     * Exchanges the USER access token for the PAGE's own long-lived Page
     * Access Token (Graph API `GET /{page-id}?fields=access_token`) —
     * closes the "KNOWN GAP" disclosed in the social_accounts migration
     * for facebook_page assets specifically. Meta scopes a Page Access
     * Token to whichever user token requested it and the permissions
     * that token holds (pages_show_list/pages_read_engagement, already
     * requested in SCOPES), so no extra OAuth scope is needed for this.
     *
     * Returns null on failure rather than throwing — bind() falls back
     * to the user token and logs a warning, since Instagram/Ad Account
     * binding must not be blocked by one Page's token exchange failing.
     */
    public function exchangePageAccessToken(string $userAccessToken, string $pageId): ?string
    {
        try {
            $response = Http::withToken($userAccessToken)->timeout(15)->get(
                'https://graph.facebook.com/'.self::API_VERSION."/{$pageId}",
                ['fields' => 'access_token']
            );
        } catch (Throwable) {
            return null;
        }

        if ($response->failed()) {
            return null;
        }

        $pageAccessToken = $response->json('access_token');

        return is_string($pageAccessToken) && $pageAccessToken !== '' ? $pageAccessToken : null;
    }

    public function fetchAssets(string $accessToken): array
    {
        $assets = [];

        // The linked Instagram Business account is read through the Page, which Meta ties to
        // `instagram_basic`. If the app was not granted it, a failing expansion must not block
        // the Facebook Pages and Ad Accounts: retry once without it.
        $pages = null;
        foreach (['id,name,picture,instagram_business_account{id,username,profile_picture_url}', 'id,name,picture'] as $fields) {
            try {
                $pages = Http::withToken($accessToken)->timeout(15)->get(
                    'https://graph.facebook.com/'.self::API_VERSION.'/me/accounts',
                    ['fields' => $fields]
                );
            } catch (Throwable $e) {
                throw new RuntimeException('Could not reach Meta to list Facebook Pages.', previous: $e);
            }

            if (! $pages->failed()) {
                break;
            }
        }

        if ($pages->failed()) {
            throw new RuntimeException($pages->json('error.message') ?? 'Meta rejected the Pages request.');
        }

        foreach ($pages->json('data', []) as $page) {
            $assets[] = [
                'asset_type' => 'facebook_page',
                'provider_id' => (string) $page['id'],
                'name' => $page['name'] ?? null,
                'avatar_url' => $page['picture']['data']['url'] ?? null,
            ];

            $instagram = $page['instagram_business_account'] ?? null;
            if ($instagram) {
                $assets[] = [
                    'asset_type' => 'instagram',
                    'provider_id' => (string) $instagram['id'],
                    'name' => $instagram['username'] ?? null,
                    'avatar_url' => $instagram['profile_picture_url'] ?? null,
                    // Phase 9 Task 1 — non-secret operating data: the Page this IG account is linked to.
                    'metadata' => ['linked_page_id' => (string) $page['id']],
                ];
            }
        }

        try {
            $adAccounts = Http::withToken($accessToken)->timeout(15)->get(
                'https://graph.facebook.com/'.self::API_VERSION.'/me/adaccounts',
                ['fields' => 'id,name,account_id']
            );
        } catch (Throwable $e) {
            throw new RuntimeException('Could not reach Meta to list Ad Accounts.', previous: $e);
        }

        if ($adAccounts->failed()) {
            throw new RuntimeException($adAccounts->json('error.message') ?? 'Meta rejected the Ad Accounts request.');
        }

        foreach ($adAccounts->json('data', []) as $adAccount) {
            $assets[] = [
                'asset_type' => 'meta_ad_account',
                'provider_id' => (string) $adAccount['id'],
                'name' => $adAccount['name'] ?? null,
                'avatar_url' => null,
                'metadata' => ['ad_account_number' => isset($adAccount['account_id']) ? (string) $adAccount['account_id'] : null],
            ];
        }

        return $assets;
    }
}
