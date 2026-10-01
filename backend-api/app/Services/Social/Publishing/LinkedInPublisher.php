<?php

namespace App\Services\Social\Publishing;

use App\Models\SocialAccount;
use App\Services\Social\Publishing\Contracts\SocialPublisher;
use App\Services\SocialAuth\Exceptions\ProviderRequestFailed;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Phase 9 Task 3 — the LinkedIn UGC Posts code that lived in
 * OrganicPublishService since Step 3, moved here unchanged in shape so
 * every provider call sits behind SocialPublisher.
 *
 * DISCLOSED — STILL UNREACHABLE end-to-end: no LinkedIn OAuth provider is
 * implemented (SocialOAuthProviderFactory implements 'meta' only), so no
 * SocialAccount with provider='linkedin' can exist and this publisher is
 * never selected for a real post. [Hypothesis] — written to LinkedIn's
 * documented UGC Posts / registerUpload contract, never verified live.
 * LinkedIn expansion is out of Task 3's scope; nothing here was extended.
 */
class LinkedInPublisher implements SocialPublisher
{
    private const API_BASE = 'https://api.linkedin.com/v2';

    public function key(): string
    {
        return 'linkedin';
    }

    public function platforms(): array
    {
        return ['linkedin' => 'linkedin_page'];
    }

    public function validationError(PublishRequest $request): ?string
    {
        return null;
    }

    public function publish(SocialAccount $target, PublishRequest $request): PublishOutcome
    {
        $accessToken = (string) $target->access_token;
        $authorUrn = "urn:li:organization:{$target->provider_id}";

        $shareMediaCategory = 'NONE';
        $media = [];

        if ($request->mediaUrl) {
            $assetUrn = $this->uploadAsset($accessToken, $authorUrn, $request->mediaUrl);
            $shareMediaCategory = $request->mediaType === 'video' ? 'VIDEO' : 'IMAGE';
            $media = [[
                'status' => 'READY',
                'description' => ['text' => $request->caption],
                'media' => $assetUrn,
            ]];
        }

        $body = [
            'author' => $authorUrn,
            'lifecycleState' => 'PUBLISHED',
            'specificContent' => [
                'com.linkedin.ugc.ShareContent' => [
                    'shareCommentary' => ['text' => $request->caption],
                    'shareMediaCategory' => $shareMediaCategory,
                    ...($media !== [] ? ['media' => $media] : []),
                ],
            ],
            'visibility' => ['com.linkedin.ugc.MemberNetworkVisibility' => 'PUBLIC'],
        ];

        try {
            $response = Http::withToken($accessToken)
                ->withHeaders(['X-Restli-Protocol-Version' => '2.0.0'])
                ->timeout(20)
                ->post(self::API_BASE.'/ugcPosts', $body);
        } catch (Throwable $e) {
            throw new PublishUnreachable('Could not reach LinkedIn to publish the post.', previous: $e);
        }

        if ($response->failed()) {
            throw ProviderRequestFailed::fromResponse($response, 'LinkedIn rejected the post.', 'message');
        }

        // The created post's URN comes back in the X-RestLi-Id header.
        return PublishOutcome::published($response->header('X-RestLi-Id') ?: (string) ($response->json('id') ?? ''));
    }

    public function checkProcessing(SocialAccount $target, string $containerId): PublishOutcome
    {
        return PublishOutcome::failed('LinkedIn posts have no processing step.');
    }

    public function isTransient(ProviderRequestFailed $failure): bool
    {
        return $failure->httpStatus >= 500 || $failure->httpStatus === 429;
    }

    public function failureMessage(ProviderRequestFailed $failure): string
    {
        return 'LinkedIn rejected the post (HTTP '.$failure->httpStatus.'). Check the content and media, then try again.';
    }

    private function uploadAsset(string $accessToken, string $authorUrn, string $mediaUrl): string
    {
        try {
            $register = Http::withToken($accessToken)->timeout(15)->post(self::API_BASE.'/assets?action=registerUpload', [
                'registerUploadRequest' => [
                    'recipes' => ['urn:li:digitalmediaRecipe:feedshare-image'],
                    'owner' => $authorUrn,
                    'serviceRelationships' => [[
                        'relationshipType' => 'OWNER',
                        'identifier' => 'urn:li:userGeneratedContent',
                    ]],
                ],
            ]);
        } catch (Throwable $e) {
            throw new PublishUnreachable('Could not reach LinkedIn to register the media upload.', previous: $e);
        }

        if ($register->failed()) {
            throw ProviderRequestFailed::fromResponse($register, 'LinkedIn rejected the media upload registration.', 'message');
        }

        $uploadUrl = $register->json('value.uploadMechanism.com\\.linkedin\\.digitalmedia\\.uploading\\.MediaUploadHttpRequest.uploadUrl');
        $assetUrn = $register->json('value.asset');

        if (! $uploadUrl || ! $assetUrn) {
            throw new ProviderRequestFailed('LinkedIn did not return an upload URL/asset URN.', 502);
        }

        try {
            // Re-fetch the binary from the app's own durable media URL, then PUT it to LinkedIn.
            $binary = Http::timeout(30)->get($mediaUrl)->body();
            $upload = Http::withToken($accessToken)->timeout(30)->withBody($binary, 'application/octet-stream')->put($uploadUrl);
        } catch (Throwable $e) {
            throw new PublishUnreachable('Could not upload the media to LinkedIn.', previous: $e);
        }

        if ($upload->failed()) {
            throw ProviderRequestFailed::fromResponse($upload, 'LinkedIn rejected the media binary upload.', 'message');
        }

        return (string) $assetUrn;
    }
}
