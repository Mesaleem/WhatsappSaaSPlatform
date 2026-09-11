<?php

namespace App\Services\SocialAuth;

use App\Models\SocialProviderConfig;
use App\Services\SocialAuth\Contracts\SocialOAuthProviderInterface;
use Illuminate\Support\Facades\Http;
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
 */
class MetaOAuthProvider implements SocialOAuthProviderInterface
{
    private const API_VERSION = 'v18.0';

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
    private const SCOPES = [
        'pages_show_list',
        'pages_read_engagement',
        'instagram_basic',
        'ads_read',
        'ads_management',
        'business_management',
        // Phase 4 — Unified Social Inbox (SocialInboxController) and Ad
        // Comment Auto-Responder (CommentAutomationService: public
        // replies use pages_read_engagement/instagram_basic already
        // above, but private_replies and inbox conversations need these).
        // Same re-auth consequence as `ads_management` in Phase 3: a
        // tenant connected before this change must reconnect.
        'pages_messaging',
        'instagram_manage_messages',
    ];

    public function buildAuthorizationUrl(SocialProviderConfig $config, string $state): string
    {
        $query = http_build_query([
            'client_id' => $config->client_id,
            'redirect_uri' => $config->redirect_uri,
            'state' => $state,
            'scope' => implode(',', self::SCOPES),
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

        return [
            'access_token' => (string) $response->json('access_token'),
            // Meta's short-lived user access token flow used here does not
            // return a refresh_token (it returns a longer-lived token via a
            // SEPARATE exchange call). Long-lived-token exchange + refresh
            // is a disclosed Phase 2 item — see the migration's Page
            // Access Token gap note.
            'refresh_token' => null,
            'expires_in' => $response->json('expires_in'),
        ];
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

        try {
            $pages = Http::withToken($accessToken)->timeout(15)->get(
                'https://graph.facebook.com/'.self::API_VERSION.'/me/accounts',
                ['fields' => 'id,name,picture,instagram_business_account{id,username,profile_picture_url}']
            );
        } catch (Throwable $e) {
            throw new RuntimeException('Could not reach Meta to list Facebook Pages.', previous: $e);
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
            ];
        }

        return $assets;
    }
}
