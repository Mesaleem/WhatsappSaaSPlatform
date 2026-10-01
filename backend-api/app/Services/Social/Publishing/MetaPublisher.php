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
        if ($request->platform === 'instagram' && ! $request->mediaUrl) {
            return 'Instagram requires an image or video — text-only posts are not supported by the Content Publishing API.';
        }

        return null;
    }

    public function publish(SocialAccount $target, PublishRequest $request): PublishOutcome
    {
        return match ($request->platform) {
            'facebook' => $this->publishToFacebookPage($target, $request),
            'instagram' => $this->publishToInstagram($target, $request),
        };
    }

    public function checkProcessing(SocialAccount $target, string $containerId): PublishOutcome
    {
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
        $containerBody = $request->mediaType === 'video'
            ? ['video_url' => $request->mediaUrl, 'media_type' => 'REELS', 'caption' => $request->caption]
            : ['image_url' => $request->mediaUrl, 'caption' => $request->caption];

        $container = $this->graphPost((string) $ig->access_token, "/{$ig->provider_id}/media", $containerBody);
        $creationId = (string) $container['id'];

        if ($request->mediaType === 'video') {
            return PublishOutcome::processing($creationId);
        }

        return PublishOutcome::published($this->publishInstagramContainer($ig, $creationId));
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
