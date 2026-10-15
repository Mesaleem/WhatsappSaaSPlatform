<?php

namespace App\Services\Social\Publishing;

use App\Models\SocialAccount;
use App\Services\Social\Publishing\Contracts\SocialInsightsProvider;
use App\Services\Social\Publishing\Contracts\SocialPublisher;
use App\Services\SocialAuth\Exceptions\ProviderRequestFailed;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Phase 9 Task 3 — Facebook Page and Instagram publishing through the
 * Graph API. The request shapes are the ones OrganicPublishService used
 * since Phase 1 (moved here unchanged so every Graph publishing call lives
 * in the provider layer):
 *
 *   facebook  video → POST /{page}/videos; image → unpublished /{page}/photos
 *             then /{page}/feed with attached_media; text → /{page}/feed
 *   instagram POST /{ig}/media (image_url or REELS video_url) → image:
 *             /{ig}/media_publish at once; video: processing, checked with
 *             GET /{container}?fields=status_code, then media_publish
 *
 * Phase 9 Task 4 — also the Meta insights provider (same Graph transport,
 * no second client):
 *
 *   facebook  post:  GET /{post}?fields=reactions/comments summaries,shares
 *                    + GET /{post}/insights?metric=post_impressions,post_impressions_unique,post_clicks
 *             video: GET /{video}?fields=reactions/comments summaries
 *                    + GET /{video}/video_insights?metric=total_video_impressions,…_unique,total_video_views,total_video_avg_time_watched
 *   instagram GET /{media}?fields=like_count,comments_count,media_type,media_product_type
 *                    + GET /{media}/insights?metric=impressions,reach,saved,shares (image)
 *                                              reach,saved,shares,views,ig_reels_avg_watch_time (video)
 *
 * The object call decides "post exists"; the insights edge is best-effort:
 * a missing insights permission (code 10 / 200-299 on that edge) or an
 * unsupported metric (code 100) makes those metrics unavailable — it never
 * reaches SocialConnectionService, so a Page is not marked revoked because
 * read_insights / instagram_manage_insights were not granted. Rate limits,
 * transient errors and token errors (190) on either call are re-thrown.
 *
 * 2026-10-15 — formats beyond a normal post (PublishRequest::$format):
 *
 *   facebook  reel     POST /{page}/video_reels upload_phase=start → video_id;
 *                      POST rupload.facebook.com/video-upload/{v}/{video_id} with
 *                      header file_url (Meta fetches the hosted file);
 *                      POST /{page}/video_reels upload_phase=finish, video_state=PUBLISHED
 *             story    image: unpublished /{page}/photos → POST /{page}/photo_stories {photo_id};
 *                      video: /{page}/video_stories start → rupload → finish
 *             carousel 2–10 IMAGES: unpublished /{page}/photos each → /{page}/feed with
 *                      attached_media (a multi-photo post; Pages do not take videos here)
 *   instagram reel     /{ig}/media media_type=REELS (share_to_feed) → processing → media_publish
 *             story    /{ig}/media media_type=STORIES (image_url | video_url; Meta takes no
 *                      caption on a story) → image: publish now, video: processing
 *             carousel each item /{ig}/media is_carousel_item=true (video: media_type=VIDEO);
 *                      images only → /{ig}/media media_type=CAROUSEL children=… → publish;
 *                      with videos → processing "carousel:{ids}" until every item is
 *                      FINISHED, then the CAROUSEL container (with the caption) → processing → publish
 *
 * [Hypothesis] — documented Graph API contract, not verified against a
 * live Page (same standing disclosure as before).
 */
class MetaPublisher implements SocialPublisher, SocialInsightsProvider
{
    private const GRAPH_API_VERSION = 'v19.0';

    /**
     * Graph error codes that mean "try again later": 1/2 unknown/temporary,
     * 4/17/32/613 rate limits, 341 application limit.
     */
    private const TRANSIENT_CODES = [1, 2, 4, 17, 32, 341, 613];

    public function key(): string
    {
        return 'meta';
    }

    public function platforms(): array
    {
        return ['facebook' => 'facebook_page', 'instagram' => 'instagram'];
    }

    public function validationError(PublishRequest $request): ?string
    {
        $isVideo = $request->mediaType === 'video';

        return match ($request->format) {
            'reel' => $request->mediaUrl && $isVideo ? null : 'A Reel needs a video.',
            'story' => $request->mediaUrl ? null : 'A Story needs an image or a video.',
            'carousel' => $this->carouselError($request),
            'post' => $request->platform === 'instagram' && ! $request->mediaUrl
                ? 'Instagram requires an image or video — text-only posts are not supported by the Content Publishing API.'
                : null,
            default => 'Unknown post format.',
        };
    }

    private function carouselError(PublishRequest $request): ?string
    {
        $count = count($request->mediaItems);
        if ($count < 2 || $count > 10) {
            return 'A carousel needs 2 to 10 images or videos.';
        }
        foreach ($request->mediaItems as $item) {
            if (empty($item['url']) || ! in_array($item['type'] ?? null, ['image', 'video'], true)) {
                return 'Every carousel item needs a media URL and a type (image or video).';
            }
            if ($request->platform === 'facebook' && $item['type'] === 'video') {
                return 'A Facebook carousel can hold images only. Post videos as a Reel or a separate post.';
            }
        }

        return null;
    }

    public function publish(SocialAccount $target, PublishRequest $request): PublishOutcome
    {
        return match ($request->platform) {
            'facebook' => match ($request->format) {
                'reel' => $this->publishFacebookReel($target, $request),
                'story' => $this->publishFacebookStory($target, $request),
                'carousel' => $this->publishFacebookAlbum($target, $request),
                default => $this->publishToFacebookPage($target, $request),
            },
            'instagram' => match ($request->format) {
                'carousel' => $this->publishInstagramCarousel($target, $request),
                default => $this->publishToInstagram($target, $request),
            },
        };
    }

    public function checkProcessing(SocialAccount $target, string $containerId, ?PublishRequest $request = null): PublishOutcome
    {
        // An Instagram carousel whose video items are still processing: once every item is
        // FINISHED, the carousel container is created and then checked like any other container.
        if (str_starts_with($containerId, 'carousel:')) {
            return $this->advanceInstagramCarousel($target, substr($containerId, 9), $request);
        }

        $response = $this->send(fn () => Http::withToken((string) $target->access_token)->timeout(15)->get(
            'https://graph.facebook.com/'.self::GRAPH_API_VERSION."/{$containerId}",
            ['fields' => 'status_code']
        ), 'Meta rejected the video status request.');

        $status = $response->json('status_code');

        if ($status === 'FINISHED') {
            return PublishOutcome::published($this->publishInstagramContainer($target, $containerId));
        }

        if (in_array($status, ['ERROR', 'EXPIRED'], true)) {
            return PublishOutcome::failed("Instagram rejected the video during processing (status: {$status}).");
        }

        return PublishOutcome::processing($containerId);
    }

    public function isTransient(ProviderRequestFailed $failure): bool
    {
        $error = $failure->errorBody['error'] ?? [];

        return $failure->httpStatus >= 500
            || $failure->httpStatus === 429
            || (bool) ($error['is_transient'] ?? false)
            || in_array((int) ($error['code'] ?? 0), self::TRANSIENT_CODES, true);
    }

    public function failureMessage(ProviderRequestFailed $failure): string
    {
        $code = (int) ($failure->errorBody['error']['code'] ?? 0);

        return 'Meta rejected the post'.($code ? " (error {$code})" : '').'. Check the content and media, then try again.';
    }

    // ================================================================ insights

    private const RATE_LIMIT_CODES = [4, 17, 32, 613];

    public function insightsPlatforms(): array
    {
        return ['facebook', 'instagram'];
    }

    public function fetchInsights(SocialAccount $target, InsightsRequest $request): InsightsResult
    {
        return match ($request->platform) {
            'facebook' => $this->facebookInsights($target, $request),
            'instagram' => $this->instagramInsights($target, $request),
        };
    }

    public function classifyInsightsFailure(ProviderRequestFailed $failure): string
    {
        $error = $failure->errorBody['error'] ?? [];
        $code = (int) ($error['code'] ?? 0);
        $subcode = (int) ($error['error_subcode'] ?? 0);

        return match (true) {
            $failure->httpStatus === 429 || in_array($code, self::RATE_LIMIT_CODES, true) || ($code >= 80000 && $code <= 80014) => InsightsFailure::RATE_LIMITED,
            $failure->httpStatus >= 500 || in_array($code, [1, 2], true) || (bool) ($error['is_transient'] ?? false) => InsightsFailure::TRANSIENT,
            $this->isNotFound($failure) => InsightsFailure::NOT_FOUND,
            default => InsightsFailure::PERMANENT,
        };
    }

    private function facebookInsights(SocialAccount $page, InsightsRequest $request): InsightsResult
    {
        $token = (string) $page->access_token;
        $id = $request->providerPostId;
        $isVideo = $request->mediaType === 'video';

        $object = $this->insightsObject($token, "/{$id}", [
            'fields' => 'reactions.summary(total_count).limit(0),comments.summary(total_count).limit(0)'.($isVideo ? '' : ',shares'),
        ]);

        if ($object === null) {
            return InsightsResult::notFound();
        }

        $metrics = [];
        $unavailable = ['saves' => InsightsResult::REASON_NOT_SUPPORTED];
        $this->takeInt($metrics, $unavailable, 'reactions', data_get($object, 'reactions.summary.total_count'));
        $this->takeInt($metrics, $unavailable, 'comments', data_get($object, 'comments.summary.total_count'));

        if ($isVideo) {
            $unavailable['shares'] = InsightsResult::REASON_NOT_SUPPORTED;
            $unavailable['clicks'] = InsightsResult::REASON_NOT_SUPPORTED;
            $this->collectInsights($token, "/{$id}/video_insights", [
                'total_video_impressions' => 'impressions',
                'total_video_impressions_unique' => 'reach',
                'total_video_views' => 'video_views',
                'total_video_avg_time_watched' => 'video_avg_watch_time_ms',
            ], $metrics, $unavailable);
        } else {
            // Meta omits `shares` when there are none; not reported ≠ zero, so it stays unavailable.
            $this->takeInt($metrics, $unavailable, 'shares', data_get($object, 'shares.count'));
            $unavailable['video_views'] = InsightsResult::REASON_NOT_SUPPORTED;
            $unavailable['video_avg_watch_time_ms'] = InsightsResult::REASON_NOT_SUPPORTED;
            $this->collectInsights($token, "/{$id}/insights", [
                'post_impressions' => 'impressions',
                'post_impressions_unique' => 'reach',
                'post_clicks' => 'clicks',
            ], $metrics, $unavailable);
        }

        return InsightsResult::found($metrics, $unavailable);
    }

    private function instagramInsights(SocialAccount $ig, InsightsRequest $request): InsightsResult
    {
        $token = (string) $ig->access_token;
        $id = $request->providerPostId;

        $object = $this->insightsObject($token, "/{$id}", ['fields' => 'like_count,comments_count,media_type,media_product_type']);

        if ($object === null) {
            return InsightsResult::notFound();
        }

        $isVideo = $request->mediaType === 'video' || ($object['media_type'] ?? null) === 'VIDEO' || ($object['media_product_type'] ?? null) === 'REELS';

        $metrics = [];
        $unavailable = ['clicks' => InsightsResult::REASON_NOT_SUPPORTED];
        // like_count is absent when the owner hides like counts.
        $this->takeInt($metrics, $unavailable, 'reactions', $object['like_count'] ?? null);
        $this->takeInt($metrics, $unavailable, 'comments', $object['comments_count'] ?? null);

        if ($isVideo) {
            $unavailable['impressions'] = InsightsResult::REASON_NOT_SUPPORTED;
            $map = ['reach' => 'reach', 'saved' => 'saves', 'shares' => 'shares', 'views' => 'video_views', 'ig_reels_avg_watch_time' => 'video_avg_watch_time_ms'];
        } else {
            $unavailable['video_views'] = InsightsResult::REASON_NOT_SUPPORTED;
            $unavailable['video_avg_watch_time_ms'] = InsightsResult::REASON_NOT_SUPPORTED;
            $map = ['impressions' => 'impressions', 'reach' => 'reach', 'saved' => 'saves', 'shares' => 'shares'];
        }

        $this->collectInsights($token, "/{$id}/insights", $map, $metrics, $unavailable);

        return InsightsResult::found($metrics, $unavailable);
    }

    /**
     * The post object itself. Null when Meta says it no longer exists; any
     * other failure is re-thrown for the caller to classify.
     *
     * @param  array<string, string>  $query
     * @return array<string, mixed>|null
     */
    private function insightsObject(string $token, string $path, array $query): ?array
    {
        try {
            return $this->graphGet($token, $path, $query);
        } catch (ProviderRequestFailed $e) {
            if ($this->isNotFound($e)) {
                return null;
            }

            throw $e;
        }
    }

    /**
     * Best-effort insights edge: fills $metrics for what Meta reports and
     * $unavailable (with a reason) for the rest.
     *
     * @param  array<string, string>  $map  provider metric => normalised metric
     * @param  array<string, int>  $metrics
     * @param  array<string, string>  $unavailable
     */
    private function collectInsights(string $token, string $path, array $map, array &$metrics, array &$unavailable): void
    {
        try {
            $this->readInsightValues($this->graphGet($token, $path, ['metric' => implode(',', array_keys($map))]), $map, $metrics, $unavailable);

            return;
        } catch (ProviderRequestFailed $e) {
            $reason = $this->insightsEdgeReason($e);

            if ($reason !== InsightsResult::REASON_UNSUPPORTED_METRIC || count($map) === 1) {
                foreach ($map as $normalised) {
                    $unavailable[$normalised] = $reason;
                }

                return;
            }
        }

        // One metric Meta does not accept for this object fails the whole
        // request: ask for each metric on its own, so the others still count.
        foreach ($map as $providerMetric => $normalised) {
            $this->collectInsights($token, $path, [$providerMetric => $normalised], $metrics, $unavailable);
        }
    }

    /**
     * Why an insights-edge failure makes metrics unavailable; re-throws what
     * the caller must handle (token errors, rate limits, transient errors).
     */
    private function insightsEdgeReason(ProviderRequestFailed $e): string
    {
        $code = (int) ($e->errorBody['error']['code'] ?? 0);

        if ($code === 190 || in_array($this->classifyInsightsFailure($e), [InsightsFailure::RATE_LIMITED, InsightsFailure::TRANSIENT], true)) {
            throw $e;
        }

        return match (true) {
            $code === 10 || ($code >= 200 && $code <= 299) => InsightsResult::REASON_PERMISSION,
            $code === 100 => InsightsResult::REASON_UNSUPPORTED_METRIC,
            default => InsightsResult::REASON_NOT_REPORTED,
        };
    }

    /**
     * @param  array<string, mixed>  $response
     * @param  array<string, string>  $map
     * @param  array<string, int>  $metrics
     * @param  array<string, string>  $unavailable
     */
    private function readInsightValues(array $response, array $map, array &$metrics, array &$unavailable): void
    {
        $values = [];

        foreach ((array) ($response['data'] ?? []) as $item) {
            $name = is_array($item) ? ($item['name'] ?? null) : null;
            $value = $item['values'][0]['value'] ?? $item['total_value']['value'] ?? null;

            if (is_string($name) && isset($map[$name])) {
                $values[$map[$name]] = $value;
            }
        }

        foreach ($map as $normalised) {
            $this->takeInt($metrics, $unavailable, $normalised, $values[$normalised] ?? null);
        }
    }

    /**
     * @param  array<string, int>  $metrics
     * @param  array<string, string>  $unavailable
     */
    private function takeInt(array &$metrics, array &$unavailable, string $metric, mixed $value): void
    {
        if (is_int($value) || is_float($value) || (is_string($value) && ctype_digit($value))) {
            $metrics[$metric] = max(0, (int) round((float) $value));
            unset($unavailable[$metric]);

            return;
        }

        $unavailable[$metric] ??= InsightsResult::REASON_NOT_REPORTED;
    }

    private function isNotFound(ProviderRequestFailed $failure): bool
    {
        $error = $failure->errorBody['error'] ?? [];
        $code = (int) ($error['code'] ?? 0);

        return $failure->httpStatus === 404 || ($code === 100 && (int) ($error['error_subcode'] ?? 0) === 33) || $code === 803;
    }

    /**
     * @param  array<string, string>  $query
     * @return array<string, mixed>
     */
    private function graphGet(string $accessToken, string $path, array $query): array
    {
        $response = $this->send(fn () => Http::withToken($accessToken)->timeout(20)->get(
            'https://graph.facebook.com/'.self::GRAPH_API_VERSION.$path,
            $query
        ), "Meta rejected the request to {$path}.");

        return $response->json() ?? [];
    }

    // ================================================================ publishing internals

    private function publishToFacebookPage(SocialAccount $page, PublishRequest $request): PublishOutcome
    {
        $token = (string) $page->access_token;

        if ($request->mediaType === 'video' && $request->mediaUrl) {
            $response = $this->graphPost($token, "/{$page->provider_id}/videos", [
                'file_url' => $request->mediaUrl,
                'description' => $request->caption,
            ]);

            return PublishOutcome::published((string) $response['id']);
        }

        if ($request->mediaUrl) {
            $photo = $this->graphPost($token, "/{$page->provider_id}/photos", [
                'url' => $request->mediaUrl,
                'published' => 'false',
            ]);

            $response = $this->graphPost($token, "/{$page->provider_id}/feed", [
                'message' => $request->caption,
                'attached_media' => json_encode([['media_fbid' => (string) $photo['id']]]),
            ]);

            return PublishOutcome::published((string) $response['id']);
        }

        $response = $this->graphPost($token, "/{$page->provider_id}/feed", [
            'message' => $request->caption,
        ]);

        return PublishOutcome::published((string) $response['id']);
    }

    private function publishToInstagram(SocialAccount $ig, PublishRequest $request): PublishOutcome
    {
        $isVideo = $request->mediaType === 'video';
        $containerBody = match (true) {
            // A story takes no caption on Instagram.
            $request->format === 'story' => ['media_type' => 'STORIES', ($isVideo ? 'video_url' : 'image_url') => $request->mediaUrl],
            $request->format === 'reel' => ['video_url' => $request->mediaUrl, 'media_type' => 'REELS', 'caption' => $request->caption, 'share_to_feed' => 'true'],
            $isVideo => ['video_url' => $request->mediaUrl, 'media_type' => 'REELS', 'caption' => $request->caption],
            default => ['image_url' => $request->mediaUrl, 'caption' => $request->caption],
        };

        $container = $this->graphPost((string) $ig->access_token, "/{$ig->provider_id}/media", $containerBody);
        $creationId = (string) $container['id'];

        if ($request->mediaType === 'video') {
            return PublishOutcome::processing($creationId);
        }

        return PublishOutcome::published($this->publishInstagramContainer($ig, $creationId));
    }

    /** Instagram carousel: one container per item, then the CAROUSEL container. */
    private function publishInstagramCarousel(SocialAccount $ig, PublishRequest $request): PublishOutcome
    {
        $token = (string) $ig->access_token;
        $children = [];
        $hasVideo = false;

        foreach ($request->mediaItems as $item) {
            $isVideo = $item['type'] === 'video';
            $hasVideo = $hasVideo || $isVideo;
            $child = $this->graphPost($token, "/{$ig->provider_id}/media", $isVideo
                ? ['video_url' => $item['url'], 'media_type' => 'VIDEO', 'is_carousel_item' => 'true']
                : ['image_url' => $item['url'], 'is_carousel_item' => 'true']);
            $children[] = (string) $child['id'];
        }

        // Video items process first; the carousel itself is created once they are ready.
        if ($hasVideo) {
            return PublishOutcome::processing('carousel:'.implode(',', $children));
        }

        $carousel = $this->createInstagramCarousel($ig, $children, $request->caption);

        return PublishOutcome::published($this->publishInstagramContainer($ig, $carousel));
    }

    private function advanceInstagramCarousel(SocialAccount $ig, string $childList, ?PublishRequest $request): PublishOutcome
    {
        $children = array_values(array_filter(explode(',', $childList)));

        foreach ($children as $child) {
            $status = $this->graphGet((string) $ig->access_token, "/{$child}", ['fields' => 'status_code'])['status_code'] ?? null;
            if (in_array($status, ['ERROR', 'EXPIRED'], true)) {
                return PublishOutcome::failed("Instagram rejected a carousel item during processing (status: {$status}).");
            }
            if ($status !== 'FINISHED') {
                return PublishOutcome::processing('carousel:'.$childList);
            }
        }

        // Every item is ready: the carousel container, then the usual container check publishes it.
        return PublishOutcome::processing($this->createInstagramCarousel($ig, $children, $request?->caption ?? ''));
    }

    /** @param  list<string>  $children */
    private function createInstagramCarousel(SocialAccount $ig, array $children, string $caption): string
    {
        $container = $this->graphPost((string) $ig->access_token, "/{$ig->provider_id}/media", [
            'media_type' => 'CAROUSEL',
            'children' => implode(',', $children),
            'caption' => $caption,
        ]);

        return (string) $container['id'];
    }

    // ---------------------------------------------------------------- Facebook formats

    /** Facebook Reel: start an upload session, let Meta fetch the hosted video, then publish. */
    private function publishFacebookReel(SocialAccount $page, PublishRequest $request): PublishOutcome
    {
        $token = (string) $page->access_token;
        $start = $this->graphPost($token, "/{$page->provider_id}/video_reels", ['upload_phase' => 'start']);
        $videoId = (string) $start['video_id'];

        $this->uploadHostedVideo($token, $videoId, (string) $request->mediaUrl);

        $this->graphPost($token, "/{$page->provider_id}/video_reels", [
            'upload_phase' => 'finish',
            'video_id' => $videoId,
            'video_state' => 'PUBLISHED',
            'description' => $request->caption,
        ]);

        return PublishOutcome::published($videoId);
    }

    /** Facebook Page Story: an unpublished photo, or a video upload session. */
    private function publishFacebookStory(SocialAccount $page, PublishRequest $request): PublishOutcome
    {
        $token = (string) $page->access_token;

        if ($request->mediaType === 'video') {
            $start = $this->graphPost($token, "/{$page->provider_id}/video_stories", ['upload_phase' => 'start']);
            $videoId = (string) $start['video_id'];
            $this->uploadHostedVideo($token, $videoId, (string) $request->mediaUrl);
            $finish = $this->graphPost($token, "/{$page->provider_id}/video_stories", ['upload_phase' => 'finish', 'video_id' => $videoId]);

            return PublishOutcome::published((string) ($finish['post_id'] ?? $videoId));
        }

        $photo = $this->graphPost($token, "/{$page->provider_id}/photos", ['url' => $request->mediaUrl, 'published' => 'false']);
        $story = $this->graphPost($token, "/{$page->provider_id}/photo_stories", ['photo_id' => (string) $photo['id']]);

        return PublishOutcome::published((string) ($story['post_id'] ?? $photo['id']));
    }

    /** Facebook multi-photo post: each image uploaded unpublished, then one feed post with all of them. */
    private function publishFacebookAlbum(SocialAccount $page, PublishRequest $request): PublishOutcome
    {
        $token = (string) $page->access_token;
        $attached = [];

        foreach ($request->mediaItems as $item) {
            $photo = $this->graphPost($token, "/{$page->provider_id}/photos", ['url' => $item['url'], 'published' => 'false']);
            $attached[] = ['media_fbid' => (string) $photo['id']];
        }

        $response = $this->graphPost($token, "/{$page->provider_id}/feed", [
            'message' => $request->caption,
            'attached_media' => json_encode($attached),
        ]);

        return PublishOutcome::published((string) $response['id']);
    }

    /** Meta's resumable-upload host fetches a hosted video (header file_url) into an upload session. */
    private function uploadHostedVideo(string $accessToken, string $videoId, string $fileUrl): void
    {
        $this->send(fn () => Http::withHeaders(['Authorization' => 'OAuth '.$accessToken, 'file_url' => $fileUrl])
            ->timeout(60)
            ->post('https://rupload.facebook.com/video-upload/'.self::GRAPH_API_VERSION."/{$videoId}"), 'Meta could not fetch the video.');
    }

    private function publishInstagramContainer(SocialAccount $ig, string $creationId): string
    {
        $published = $this->graphPost((string) $ig->access_token, "/{$ig->provider_id}/media_publish", [
            'creation_id' => $creationId,
        ]);

        return (string) $published['id'];
    }

    /**
     * @param  array<string, mixed>  $body
     * @return array<string, mixed>
     */
    private function graphPost(string $accessToken, string $path, array $body): array
    {
        $response = $this->send(fn () => Http::asForm()->withToken($accessToken)->timeout(30)->post(
            'https://graph.facebook.com/'.self::GRAPH_API_VERSION.$path,
            $body
        ), "Meta rejected the request to {$path}.");

        return $response->json() ?? [];
    }

    /**
     * @param  callable(): Response  $call
     *
     * @throws ProviderRequestFailed|PublishUnreachable
     */
    private function send(callable $call, string $fallbackMessage): Response
    {
        try {
            $response = $call();
        } catch (Throwable $e) {
            throw new PublishUnreachable('Could not reach Meta to publish the post.', previous: $e);
        }

        if ($response->failed()) {
            throw ProviderRequestFailed::fromResponse($response, $fallbackMessage);
        }

        return $response;
    }
}
