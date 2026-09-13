<?php

namespace App\Services\Social;

use App\Models\Account;
use App\Models\OrganicPost;
use App\Models\SocialAccount;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

/**
 * Social/Ads Launcher Overhaul — Step 3 (Organic Multi-Channel Publishing
 * Engine). Publishes a FREE (non-budget) post to Facebook Page Feed,
 * Instagram, or LinkedIn on the tenant's connected asset, logging the
 * attempt as an OrganicPost row.
 *
 * DISCLOSED — NOT INTEGRATION-TESTED, same standing disclosure as
 * MetaAdsService: no live Meta Business Manager / LinkedIn Company Page
 * exists in this environment to publish a real post against. Every
 * request shape below follows the platforms' PUBLISHED API contracts
 * (Meta Graph API v19.0, LinkedIn UGC Posts API) as accurately as
 * documentation allows, but is [Hypothesis] until verified live.
 *
 * DISCLOSED CRITICAL GAP — LinkedIn OAuth does not exist yet:
 * SocialOAuthProviderFactory::IMPLEMENTED_PROVIDERS = ['meta'] only
 * (verified by reading that class directly). No SocialAccount row with
 * provider='linkedin' can ever be created through this codebase's
 * existing OAuth flow today. publishToLinkedIn() below is therefore
 * structurally correct against LinkedIn's documented UGC Posts API but
 * CANNOT be reached end-to-end until a LinkedIn OAuth provider is
 * implemented (out of this step's scope — LinkedIn OAuth was not part
 * of Step 3's spec). resolveSocialAccount('linkedin', ...) will always
 * throw "not connected" until that gap is closed elsewhere.
 *
 * DISCLOSED DESIGN — logs failures (unlike MetaAdsService::launch(),
 * which persists nothing on partial failure): an OrganicPost row is
 * created at STATUS_PENDING *before* the external call, then updated to
 * published/failed after — see the organic_posts migration's docblock
 * for why this table deliberately diverges from ad_campaigns' "only
 * persist on success" precedent (the feature spec requires visible
 * error logging, not just successes).
 */
class OrganicPublishService
{
    private const GRAPH_API_VERSION = 'v19.0';

    private const LINKEDIN_API_BASE = 'https://api.linkedin.com/v2';

    /** Asset type on social_accounts that each platform publishes through. */
    private const ASSET_TYPE_MAP = [
        'facebook' => 'facebook_page',
        'instagram' => 'instagram',
        'linkedin' => 'linkedin_page',
    ];

    /** OAuth provider each platform's SocialAccount row is stored under. */
    private const PROVIDER_MAP = [
        'facebook' => 'meta',
        'instagram' => 'meta',
        'linkedin' => 'linkedin',
    ];

    /**
     * @param array{platform: string, caption: string, media_url?: string|null, media_type?: string|null} $payload
     *
     * @throws RuntimeException if no connected asset exists for the requested platform
     *         (a pre-flight failure — no OrganicPost row is created for this case,
     *         mirroring MetaAdsService::resolveAdAccount()'s "nothing to log yet" precedent).
     */
    public function publish(Account $account, array $payload): OrganicPost
    {
        $platform = $payload['platform'];

        if (! in_array($platform, OrganicPost::PLATFORMS, true)) {
            throw new RuntimeException("Unsupported platform '{$platform}'.");
        }

        $socialAccount = $this->resolveSocialAccount($account, $platform);

        $post = OrganicPost::create([
            'account_id' => $account->id,
            'social_account_id' => $socialAccount->id,
            'provider' => self::PROVIDER_MAP[$platform],
            'platform' => $platform,
            'caption' => $payload['caption'],
            'media_url' => $payload['media_url'] ?? null,
            'media_type' => $payload['media_type'] ?? null,
            'status' => OrganicPost::STATUS_PENDING,
        ]);

        try {
            $externalId = match ($platform) {
                'facebook' => $this->publishToFacebookPage($socialAccount, $post),
                'instagram' => $this->publishToInstagram($socialAccount, $post),
                'linkedin' => $this->publishToLinkedIn($socialAccount, $post),
            };

            $post->markPublished($externalId);
        } catch (Throwable $e) {
            Log::warning("OrganicPublishService: publish failed for OrganicPost #{$post->id} ({$platform}).", [
                'account_id' => $account->id,
                'exception' => $e->getMessage(),
            ]);

            $post->markFailed($e->getMessage());
        }

        return $post->fresh();
    }

    private function resolveSocialAccount(Account $account, string $platform): SocialAccount
    {
        $socialAccount = SocialAccount::query()
            ->forAccount($account->id)
            ->where('provider', self::PROVIDER_MAP[$platform])
            ->ofAssetType(self::ASSET_TYPE_MAP[$platform])
            ->first();

        if (! $socialAccount) {
            $label = ucfirst($platform);

            throw new RuntimeException("No connected {$label} asset for this tenant. Connect one from the Social Hub first.");
        }

        if (! $socialAccount->access_token) {
            throw new RuntimeException("The connected {$platform} asset has no stored access token. Please reconnect it.");
        }

        return $socialAccount;
    }

    /**
     * Facebook Page Feed — POST /{page-id}/feed with media & caption.
     * [Hypothesis]: image posts go through the documented two-step
     * "unpublished photo" pattern (upload with published=false, then
     * attach it to a /feed post via attached_media) rather than
     * /{page-id}/photos directly, so the result is a normal Feed post
     * (not a bare photo-album entry) carrying the full caption. Video
     * posts go directly through /{page-id}/videos (Meta publishes video
     * uploads as feed-visible posts on their own, no separate /feed
     * call needed) — [Hypothesis], documented Graph API video behavior.
     */
    private function publishToFacebookPage(SocialAccount $page, OrganicPost $post): string
    {
        $accessToken = $page->access_token;

        if ($post->media_type === 'video' && $post->media_url) {
            $response = $this->graphPost($accessToken, "/{$page->provider_id}/videos", [
                'file_url' => $post->media_url,
                'description' => $post->caption,
            ]);

            return (string) $response['id'];
        }

        if ($post->media_url) {
            $photo = $this->graphPost($accessToken, "/{$page->provider_id}/photos", [
                'url' => $post->media_url,
                'published' => 'false',
            ]);

            $mediaFbid = (string) $photo['id'];

            $response = $this->graphPost($accessToken, "/{$page->provider_id}/feed", [
                'message' => $post->caption,
                'attached_media' => json_encode([['media_fbid' => $mediaFbid]]),
            ]);

            return (string) $response['id'];
        }

        $response = $this->graphPost($accessToken, "/{$page->provider_id}/feed", [
            'message' => $post->caption,
        ]);

        return (string) $response['id'];
    }

    /**
     * Instagram Content Publishing API — two-step create-then-publish flow.
     * [Hypothesis]: documented Graph API contract. Instagram has no
     * text-only post type — a caption with no media is rejected here
     * before any external call, same "fail before persisting anything
     * external" discipline as MetaAdsService. Video containers process
     * asynchronously on Meta's side; this polls status_code with a
     * bounded number of attempts (same synchronous-blocking trade-off
     * already disclosed on MetaWebhookController's webhook processing —
     * a production deployment should queue this instead).
     */
    private function publishToInstagram(SocialAccount $ig, OrganicPost $post): string
    {
        if (! $post->media_url) {
            throw new RuntimeException('Instagram requires an image or video — text-only posts are not supported by the Content Publishing API.');
        }

        $accessToken = $ig->access_token;

        $containerBody = $post->media_type === 'video'
            ? ['video_url' => $post->media_url, 'media_type' => 'REELS', 'caption' => $post->caption]
            : ['image_url' => $post->media_url, 'caption' => $post->caption];

        $container = $this->graphPost($accessToken, "/{$ig->provider_id}/media", $containerBody);
        $creationId = (string) $container['id'];

        if ($post->media_type === 'video') {
            $this->waitForContainerReady($accessToken, $creationId);
        }

        $published = $this->graphPost($accessToken, "/{$ig->provider_id}/media_publish", [
            'creation_id' => $creationId,
        ]);

        return (string) $published['id'];
    }

    /**
     * Polls GET /{creation_id}?fields=status_code until FINISHED, ERROR,
     * or a bounded attempt count is exhausted. [Hypothesis]: documented
     * Instagram Content Publishing async-video contract (status_code
     * values IN_PROGRESS/FINISHED/ERROR/EXPIRED).
     */
    private function waitForContainerReady(string $accessToken, string $creationId, int $maxAttempts = 10): void
    {
        for ($attempt = 0; $attempt < $maxAttempts; $attempt++) {
            $response = Http::withToken($accessToken)->timeout(15)->get(
                'https://graph.facebook.com/'.self::GRAPH_API_VERSION."/{$creationId}",
                ['fields' => 'status_code']
            );

            $status = $response->json('status_code');

            if ($status === 'FINISHED') {
                return;
            }

            if (in_array($status, ['ERROR', 'EXPIRED'], true)) {
                throw new RuntimeException("Instagram rejected the video during processing (status: {$status}).");
            }

            sleep(3);
        }

        throw new RuntimeException('Timed out waiting for Instagram to finish processing the video.');
    }

    /**
     * LinkedIn UGC Posts API — POST /v2/ugcPosts. [Hypothesis], and see
     * this class's docblock: UNREACHABLE end-to-end today because no
     * LinkedIn SocialAccount can exist (no OAuth provider implemented).
     * Written to the documented contract for when that gap is closed.
     *
     * Media flow (when media_url is set): LinkedIn requires a separate
     * "register upload" step (POST /v2/assets?action=registerUpload) to
     * get a short-lived upload URL, then the actual binary is PUT to
     * that URL, and the returned asset URN is referenced in the ugcPost
     * body — a fundamentally different shape from Meta's "pass a public
     * URL directly" pattern. This app only stores a durable media URL
     * (SocialMediaController), so the binary is re-fetched here via HTTP
     * before re-uploading to LinkedIn.
     */
    private function publishToLinkedIn(SocialAccount $li, OrganicPost $post): string
    {
        $accessToken = $li->access_token;
        $authorUrn = "urn:li:organization:{$li->provider_id}";

        $shareMediaCategory = 'NONE';
        $media = [];

        if ($post->media_url) {
            $assetUrn = $this->uploadLinkedInAsset($accessToken, $authorUrn, $post->media_url);
            $shareMediaCategory = $post->media_type === 'video' ? 'VIDEO' : 'IMAGE';
            $media = [[
                'status' => 'READY',
                'description' => ['text' => $post->caption],
                'media' => $assetUrn,
            ]];
        }

        $body = [
            'author' => $authorUrn,
            'lifecycleState' => 'PUBLISHED',
            'specificContent' => [
                'com.linkedin.ugc.ShareContent' => [
                    'shareCommentary' => ['text' => $post->caption],
                    'shareMediaCategory' => $shareMediaCategory,
                    ...($media !== [] ? ['media' => $media] : []),
                ],
            ],
            'visibility' => [
                'com.linkedin.ugc.MemberNetworkVisibility' => 'PUBLIC',
            ],
        ];

        try {
            $response = Http::withToken($accessToken)
                ->withHeaders(['X-Restli-Protocol-Version' => '2.0.0'])
                ->timeout(20)
                ->post(self::LINKEDIN_API_BASE.'/ugcPosts', $body);
        } catch (Throwable $e) {
            throw new RuntimeException('Could not reach LinkedIn to publish the post.', previous: $e);
        }

        if ($response->failed()) {
            throw new RuntimeException($response->json('message') ?? 'LinkedIn rejected the post.');
        }

        // LinkedIn returns the created post's URN in the X-RestLi-Id
        // response header, not (necessarily) a JSON body field —
        // [Hypothesis], documented UGC Posts API response contract.
        return $response->header('X-RestLi-Id') ?? (string) ($response->json('id') ?? '');
    }

    /**
     * @return string The registered asset's URN (e.g. "urn:li:digitalmediaAsset:...").
     */
    private function uploadLinkedInAsset(string $accessToken, string $authorUrn, string $mediaUrl): string
    {
        try {
            $register = Http::withToken($accessToken)->timeout(15)->post(
                self::LINKEDIN_API_BASE.'/assets?action=registerUpload',
                [
                    'registerUploadRequest' => [
                        'recipes' => ['urn:li:digitalmediaRecipe:feedshare-image'],
                        'owner' => $authorUrn,
                        'serviceRelationships' => [[
                            'relationshipType' => 'OWNER',
                            'identifier' => 'urn:li:userGeneratedContent',
                        ]],
                    ],
                ]
            );
        } catch (Throwable $e) {
            throw new RuntimeException('Could not reach LinkedIn to register the media upload.', previous: $e);
        }

        if ($register->failed()) {
            throw new RuntimeException($register->json('message') ?? 'LinkedIn rejected the media upload registration.');
        }

        $uploadUrl = $register->json('value.uploadMechanism.com\\.linkedin\\.digitalmedia\\.uploading\\.MediaUploadHttpRequest.uploadUrl');
        $assetUrn = $register->json('value.asset');

        if (! $uploadUrl || ! $assetUrn) {
            throw new RuntimeException('LinkedIn did not return an upload URL/asset URN.');
        }

        // Re-fetch the binary from our own durable media URL
        // (SocialMediaController::show()) and PUT it to LinkedIn's
        // upload URL, since this app only stores a URL, not the raw
        // bytes in memory at this point.
        $binary = Http::timeout(30)->get($mediaUrl)->body();

        $upload = Http::withToken($accessToken)->timeout(30)->withBody($binary, 'application/octet-stream')->put($uploadUrl);

        if ($upload->failed()) {
            throw new RuntimeException('LinkedIn rejected the media binary upload.');
        }

        return (string) $assetUrn;
    }

    /**
     * @param array<string, mixed> $body
     * @return array<string, mixed>
     */
    private function graphPost(string $accessToken, string $path, array $body): array
    {
        try {
            $response = Http::asForm()->withToken($accessToken)->timeout(30)->post(
                'https://graph.facebook.com/'.self::GRAPH_API_VERSION.$path,
                $body
            );
        } catch (Throwable $e) {
            throw new RuntimeException("Could not reach Meta ({$path}).", previous: $e);
        }

        if ($response->failed()) {
            throw new RuntimeException($response->json('error.message') ?? "Meta rejected the request to {$path}.");
        }

        return $response->json() ?? [];
    }
}
