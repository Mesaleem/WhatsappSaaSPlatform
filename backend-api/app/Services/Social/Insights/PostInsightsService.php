<?php

namespace App\Services\Social\Insights;

use App\Models\OrganicPost;
use App\Models\OrganicPostInsight;
use App\Models\SocialAccount;
use App\Services\Social\Publishing\Contracts\SocialInsightsProvider;
use App\Services\Social\Publishing\InsightsFailure;
use App\Services\Social\Publishing\InsightsRequest;
use App\Services\Social\Publishing\InsightsResult;
use App\Services\Social\Publishing\PublishUnreachable;
use App\Services\Social\Publishing\SocialPublisherFactory;
use App\Services\SocialAuth\Exceptions\ProviderRequestFailed;
use App\Services\SocialAuth\Exceptions\SocialConnectionException;
use App\Services\SocialAuth\SocialConnectionService;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * Phase 9 Task 4 — organic post insights: eligibility, the stored snapshot
 * (organic_post_insights) and the controlled refresh.
 *
 * Reads never call the provider. A refresh (explicit, or the worker's
 * `social:refresh-insights`) for one post:
 *   1. only a published post with a provider post id on a platform whose
 *      provider implements SocialInsightsProvider is eligible;
 *   2. explicit refreshes of a snapshot younger than min_refresh_minutes,
 *      and any refresh during a back-off, return the stored snapshot;
 *   3. one refresh per post at a time (conditional-UPDATE lease);
 *   4. the connection must still belong to the post's account and pass
 *      SocialConnectionService::assertUsable();
 *   5. the provider fetches and normalises; a failure goes FIRST through
 *      SocialConnectionService::observe() (expired/revoked persisted →
 *      reconnect_required); otherwise the provider classifies it: rate limit /
 *      temporary → back-off (the connection is untouched), post deleted →
 *      post_not_found (no further refreshes), anything else → provider_error.
 * The last successful metrics are kept whatever the later attempts return.
 *
 * Authorization (target account, module, capability, permission, post
 * ownership) is the caller's job — see OrganicPostInsightsController; this
 * service additionally never uses a connection of another account.
 */
class PostInsightsService
{
    public const OPERATION = 'organic.insights';

    public const REFRESHED = 'refreshed';

    public const RECENTLY_REFRESHED = 'recently_refreshed';

    public const BACKING_OFF = 'backing_off';

    public const IN_PROGRESS = 'in_progress';

    public const NOT_DUE = 'not_due';

    public function __construct(
        private readonly SocialPublisherFactory $publishers,
        private readonly SocialConnectionService $connections,
    ) {
    }

    /**
     * Why a post has no insights, or null when it can have them.
     *
     * @return 'not_published'|'failed'|'cancelled'|'outcome_unknown'|'missing_provider_post_id'|'platform_unsupported'|null
     */
    public function ineligibility(OrganicPost $post): ?string
    {
        if ($post->status !== OrganicPost::STATUS_PUBLISHED) {
            return match (true) {
                $post->status === OrganicPost::STATUS_CANCELLED => 'cancelled',
                $post->failure_code === 'outcome_unknown' => 'outcome_unknown',
                in_array($post->status, [OrganicPost::STATUS_FAILED, OrganicPost::STATUS_RECONNECT_REQUIRED], true) => 'failed',
                default => 'not_published',
            };
        }

        if (! is_string($post->external_post_id) || trim($post->external_post_id) === '') {
            return 'missing_provider_post_id';
        }

        return $this->publishers->insightsFor((string) $post->platform) ? null : 'platform_unsupported';
    }

    /**
     * @return array{outcome: string, insight: OrganicPostInsight}
     *
     * @throws \LogicException for an ineligible post (check ineligibility() first)
     */
    public function refresh(OrganicPost $post, bool $explicit = true): array
    {
        if ($reason = $this->ineligibility($post)) {
            throw new \LogicException("Organic post #{$post->id} has no insights ({$reason}).");
        }

        $provider = $this->publishers->insightsFor((string) $post->platform);
        $insight = $this->snapshotFor($post);
        $now = now();

        if ($insight->state === OrganicPostInsight::STATE_POST_NOT_FOUND && ! $explicit) {
            return ['outcome' => self::NOT_DUE, 'insight' => $insight];
        }

        if ($insight->next_refresh_at && $insight->next_refresh_at->isFuture()
            && in_array($insight->state, [OrganicPostInsight::STATE_RATE_LIMITED, OrganicPostInsight::STATE_PROVIDER_ERROR], true)
            && $insight->error_code !== 'permanent') {
            return ['outcome' => self::BACKING_OFF, 'insight' => $insight];
        }

        if ($explicit && $insight->metrics_fetched_at
            && $insight->metrics_fetched_at->gt($now->copy()->subMinutes(max(0, (int) config('social.insights.min_refresh_minutes', 5))))) {
            return ['outcome' => self::RECENTLY_REFRESHED, 'insight' => $insight];
        }

        if (! $explicit && $insight->next_refresh_at && $insight->next_refresh_at->isFuture()) {
            return ['outcome' => self::NOT_DUE, 'insight' => $insight];
        }

        $token = Str::random(40);

        if (! $this->claim($insight, $token)) {
            return ['outcome' => self::IN_PROGRESS, 'insight' => $insight->fresh()];
        }

        $this->settle($insight, $token, $this->attempt($post, $insight, $provider));

        return ['outcome' => self::REFRESHED, 'insight' => $insight->fresh()];
    }

    /**
     * The normalised, safe API view of a post's insights. Metric status:
     * `available` (value is what the provider reported — 0 is a real zero),
     * `unavailable` (the provider did not report it; `reason` says why),
     * `not_fetched` (no successful fetch yet). Never a token, claim or raw
     * provider payload.
     *
     * @return array<string, mixed>
     */
    public function present(OrganicPost $post, ?OrganicPostInsight $insight): array
    {
        $reason = $this->ineligibility($post);
        $fetched = $insight?->metrics_fetched_at !== null;
        $unavailable = (array) ($insight?->unavailable_metrics ?? []);

        $metrics = [];
        foreach (OrganicPostInsight::METRICS as $metric) {
            $value = $insight?->{$metric};
            $metrics[$metric] = match (true) {
                ! $fetched => ['value' => null, 'status' => 'not_fetched', 'reason' => null],
                $value !== null => ['value' => (int) $value, 'status' => 'available', 'reason' => null],
                default => ['value' => null, 'status' => 'unavailable', 'reason' => $unavailable[$metric] ?? InsightsResult::REASON_NOT_REPORTED],
            };
        }

        $state = match (true) {
            $reason !== null => 'not_applicable',
            $insight === null => OrganicPostInsight::STATE_NOT_FETCHED,
            default => $insight->state,
        };

        $minutes = max(0, (int) config('social.insights.min_refresh_minutes', 5));

        return [
            'post_id' => $post->id,
            'platform' => $post->platform,
            'post_status' => $post->status,
            'state' => $state,
            'not_applicable_reason' => $reason,
            'fetched_at' => $insight?->metrics_fetched_at?->toIso8601String(),
            'last_attempted_at' => $insight?->last_attempted_at?->toIso8601String(),
            'next_refresh_at' => $insight?->next_refresh_at?->toIso8601String(),
            'error_code' => $reason === null ? $insight?->error_code : null,
            'error_message' => $reason === null ? $insight?->error_message : null,
            'refresh_in_progress' => $insight?->claim_token !== null
                && $insight->claimed_at?->gt(now()->subSeconds(max(10, (int) config('social.insights.lease_seconds', 120)))),
            'can_refresh' => $reason === null,
            'refresh_available_at' => $reason === null && $insight?->metrics_fetched_at
                ? $insight->metrics_fetched_at->copy()->addMinutes($minutes)->toIso8601String()
                : null,
            'metrics' => $metrics,
        ];
    }

    /**
     * Posts the worker should refresh now (published, recent, provider
     * supports insights, snapshot missing or due).
     *
     * @return list<int>
     */
    public function dueForRefresh(?int $limit = null): array
    {
        $limit ??= max(1, (int) config('social.insights.batch_size', 50));
        $platforms = array_values(array_filter(OrganicPost::PLATFORMS, fn ($p) => $this->publishers->insightsFor($p) !== null));

        return OrganicPost::query()
            ->where('status', OrganicPost::STATUS_PUBLISHED)
            ->whereNotNull('external_post_id')
            ->where('external_post_id', '!=', '')
            ->whereIn('platform', $platforms)
            ->where('published_at', '>=', now()->subDays(max(1, (int) config('social.insights.max_post_age_days', 30))))
            ->where(fn ($q) => $q
                ->whereDoesntHave('insight')
                ->orWhereHas('insight', fn ($i) => $i
                    ->where('state', '!=', OrganicPostInsight::STATE_POST_NOT_FOUND)
                    ->where(fn ($d) => $d->whereNull('next_refresh_at')->orWhere('next_refresh_at', '<=', now()))))
            ->orderBy('published_at')
            ->limit($limit)
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();
    }

    // ================================================================ internals

    private function snapshotFor(OrganicPost $post): OrganicPostInsight
    {
        $existing = OrganicPostInsight::query()->where('organic_post_id', $post->id)->first();

        if ($existing) {
            return $existing;
        }

        try {
            return OrganicPostInsight::create([
                'account_id' => $post->account_id,
                'organic_post_id' => $post->id,
                'social_account_id' => $post->social_account_id,
                'provider' => $post->provider,
                'platform' => $post->platform,
                'provider_post_id' => (string) $post->external_post_id,
                'state' => OrganicPostInsight::STATE_NOT_FETCHED,
            ]);
        } catch (UniqueConstraintViolationException) {
            // A concurrent refresh created it first.
            return OrganicPostInsight::query()->where('organic_post_id', $post->id)->firstOrFail();
        }
    }

    private function claim(OrganicPostInsight $insight, string $token): bool
    {
        $leaseExpired = now()->subSeconds(max(10, (int) config('social.insights.lease_seconds', 120)));

        return OrganicPostInsight::query()
            ->whereKey($insight->id)
            ->where(fn ($q) => $q->whereNull('claim_token')->orWhere('claimed_at', '<', $leaseExpired))
            ->update(['claim_token' => $token, 'claimed_at' => now(), 'updated_at' => now()]) === 1;
    }

    /**
     * One provider round trip; returns the columns to write.
     *
     * @return array<string, mixed>
     */
    private function attempt(OrganicPost $post, OrganicPostInsight $insight, SocialInsightsProvider $provider): array
    {
        $socialAccount = SocialAccount::query()->forAccount($post->account_id)->find($post->social_account_id);

        if (! $socialAccount || ! $socialAccount->access_token || $socialAccount->provider !== $post->provider) {
            return $this->failure($insight, OrganicPostInsight::STATE_RECONNECT_REQUIRED, 'connection_missing',
                'The connected '.$post->platform.' account is no longer available. Reconnect it in Social Accounts.', null);
        }

        try {
            $this->connections->assertUsable($socialAccount, self::OPERATION);
        } catch (SocialConnectionException $e) {
            return $this->failure($insight, OrganicPostInsight::STATE_RECONNECT_REQUIRED, strtolower($e->errorCode()), $e->getMessage(), null);
        }

        try {
            $result = $provider->fetchInsights($socialAccount, new InsightsRequest((string) $post->platform, (string) $post->external_post_id, $post->media_type));
        } catch (ProviderRequestFailed $e) {
            return $this->providerFailure($insight, $provider, $socialAccount, $e);
        } catch (PublishUnreachable $e) {
            $this->log($post, 'unreachable', $e);

            return $this->backoff($insight, OrganicPostInsight::STATE_PROVIDER_ERROR, 'transient', 'The platform could not be reached. Insights will be retried later.', null);
        } catch (Throwable $e) {
            $this->log($post, 'unexpected', $e);

            return $this->backoff($insight, OrganicPostInsight::STATE_PROVIDER_ERROR, 'transient', 'Insights could not be fetched. They will be retried later.', null);
        }

        if ($result->status === InsightsResult::NOT_FOUND) {
            return [
                'state' => OrganicPostInsight::STATE_POST_NOT_FOUND,
                'error_code' => 'post_not_found',
                'error_message' => 'The platform no longer has this post (it may have been deleted). Last known metrics are kept.',
                'attempts' => 0,
                'next_refresh_at' => null,
            ];
        }

        $columns = [];
        foreach (OrganicPostInsight::METRICS as $metric) {
            $columns[$metric] = $result->metrics[$metric] ?? null;
        }

        $unavailable = array_intersect_key($result->unavailable, array_flip(OrganicPostInsight::METRICS));
        foreach (OrganicPostInsight::METRICS as $metric) {
            if (! array_key_exists($metric, $result->metrics) && ! isset($unavailable[$metric])) {
                $unavailable[$metric] = InsightsResult::REASON_NOT_REPORTED;
            }
        }

        $partial = array_intersect($unavailable, [InsightsResult::REASON_PERMISSION, InsightsResult::REASON_NOT_REPORTED, InsightsResult::REASON_UNSUPPORTED_METRIC]) !== [];

        return $columns + [
            'state' => $partial ? OrganicPostInsight::STATE_PARTIAL : OrganicPostInsight::STATE_OK,
            'unavailable_metrics' => json_encode($unavailable),
            'metrics_fetched_at' => now(),
            'attempts' => 0,
            'next_refresh_at' => now()->addMinutes(max(1, (int) config('social.insights.freshness_minutes', 360))),
            'error_code' => null,
            'error_message' => null,
            'metadata' => null,
        ];
    }

    /** @return array<string, mixed> */
    private function providerFailure(OrganicPostInsight $insight, SocialInsightsProvider $provider, SocialAccount $socialAccount, ProviderRequestFailed $e): array
    {
        // Connection problems first, through the one place that persists connection health.
        if ($check = $this->connections->observe($socialAccount, $e, self::OPERATION)) {
            $message = SocialConnectionException::for($socialAccount, $check->status, $check->reason)->getMessage();

            return $this->failure($insight, OrganicPostInsight::STATE_RECONNECT_REQUIRED, 'connection_'.$check->status, $message, $e);
        }

        $post = $insight->post;
        $this->log($post, 'failed', $e);

        return match ($provider->classifyInsightsFailure($e)) {
            InsightsFailure::RATE_LIMITED => $this->backoff($insight, OrganicPostInsight::STATE_RATE_LIMITED, 'rate_limited', 'The platform is rate-limiting requests. Insights will be retried later.', $e),
            InsightsFailure::TRANSIENT => $this->backoff($insight, OrganicPostInsight::STATE_PROVIDER_ERROR, 'transient', 'The platform had a temporary problem. Insights will be retried later.', $e),
            InsightsFailure::NOT_FOUND => [
                'state' => OrganicPostInsight::STATE_POST_NOT_FOUND,
                'error_code' => 'post_not_found',
                'error_message' => 'The platform no longer has this post (it may have been deleted). Last known metrics are kept.',
                'attempts' => 0,
                'next_refresh_at' => null,
                'metadata' => $this->errorMetadata($e),
            ],
            default => $this->failure($insight, OrganicPostInsight::STATE_PROVIDER_ERROR, 'permanent',
                'The platform refused the insights request'.(($code = $this->providerCode($e)) ? " (error {$code})" : '').'.', $e),
        };
    }

    /** @return array<string, mixed> */
    private function backoff(OrganicPostInsight $insight, string $state, string $code, string $message, ?ProviderRequestFailed $e): array
    {
        $attempts = (int) $insight->attempts + 1;
        $steps = array_values((array) config('social.insights.backoff_minutes', [5, 30, 120]));
        $minutes = (int) ($steps[min($attempts - 1, count($steps) - 1)] ?? 30);

        return [
            'state' => $state,
            'error_code' => $code,
            'error_message' => $message,
            'attempts' => $attempts,
            'next_refresh_at' => now()->addMinutes($minutes),
            'metadata' => $this->errorMetadata($e),
        ];
    }

    /**
     * A failure that retrying soon will not fix: the worker waits a full
     * freshness window (a reconnect or an explicit refresh can come sooner).
     *
     * @return array<string, mixed>
     */
    private function failure(OrganicPostInsight $insight, string $state, string $code, string $message, ?ProviderRequestFailed $e): array
    {
        return [
            'state' => $state,
            'error_code' => mb_substr($code, 0, 64),
            'error_message' => mb_substr($message, 0, 255),
            'attempts' => (int) $insight->attempts + 1,
            'next_refresh_at' => now()->addMinutes(max(1, (int) config('social.insights.freshness_minutes', 360))),
            'metadata' => $this->errorMetadata($e),
        ];
    }

    /** @param array<string, mixed> $columns */
    private function settle(OrganicPostInsight $insight, string $token, array $columns): void
    {
        if (array_key_exists('metadata', $columns) && is_array($columns['metadata'])) {
            $columns['metadata'] = json_encode($columns['metadata']);
        }

        OrganicPostInsight::query()
            ->whereKey($insight->id)
            ->where('claim_token', $token)
            ->update($columns + [
                'last_attempted_at' => now(),
                'claim_token' => null,
                'claimed_at' => null,
                'updated_at' => now(),
            ]);
    }

    /** @return array<string, int>|null */
    private function errorMetadata(?ProviderRequestFailed $e): ?array
    {
        if (! $e) {
            return null;
        }

        return array_filter(['provider_http_status' => $e->httpStatus, 'provider_error_code' => $this->providerCode($e)], fn ($v) => $v !== null);
    }

    private function providerCode(ProviderRequestFailed $e): ?int
    {
        $code = $e->errorBody['error']['code'] ?? null;

        return is_numeric($code) ? (int) $code : null;
    }

    /** Safe log line: never the token or the provider's raw message. */
    private function log(OrganicPost $post, string $kind, Throwable $e): void
    {
        Log::warning("PostInsightsService: insights {$kind} for OrganicPost #{$post->id} ({$post->platform}).", [
            'account_id' => $post->account_id,
            'exception' => class_basename($e),
            'http_status' => $e instanceof ProviderRequestFailed ? $e->httpStatus : null,
            'provider_error_code' => $e instanceof ProviderRequestFailed ? $this->providerCode($e) : null,
        ]);
    }
}
