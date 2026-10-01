<?php

namespace App\Services\Social;

use App\Jobs\CompleteInstagramVideoPostJob;
use App\Models\Account;
use App\Models\OrganicPost;
use App\Models\User;
use App\Models\SocialAccount;
use App\Services\Social\Publishing\Contracts\SocialPublisher;
use App\Services\Social\Publishing\Exceptions\PublishingDenied;
use App\Services\Social\Publishing\PublishOutcome;
use App\Services\Social\Publishing\PublishRequest;
use App\Services\Social\Publishing\PublishUnreachable;
use App\Services\Social\Publishing\SocialPublisherFactory;
use App\Services\SocialAuth\Exceptions\ProviderRequestFailed;
use App\Services\SocialAuth\Exceptions\SocialConnectionException;
use App\Services\SocialAuth\SocialConnectionService;
use Carbon\CarbonInterface;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use LogicException;
use RuntimeException;
use Throwable;

/**
 * Organic publishing — the ONE lifecycle for manual ("publish now") and
 * scheduled posts (Phase 9 Task 3; the Step 3 engine it replaces published
 * inline only).
 *
 * This class owns state, authorization re-checks and connection health; it
 * makes NO provider call itself. Every Graph / LinkedIn request is behind
 * SocialPublisher (App\Services\Social\Publishing\*), chosen by platform.
 *
 * Lifecycle (organic_posts.status; OrganicPost::TRANSITIONS):
 *
 *   manual:    created as `publishing` (claimed by the request) → execute()
 *   scheduled: created as `scheduled`; social:publish-due claims due rows
 *              (conditional UPDATE scheduled → publishing + claim token)
 *              and queues PublishScheduledPostJob → execute()
 *
 *   execute() (same code for both), on the claimed row only:
 *     connection missing / target no longer allowed  → failed
 *     connection expired/revoked (known or reported) → reconnect_required
 *     temporary provider error, worker, attempts left → scheduled (+ back-off)
 *     provider rejection                              → failed (safe message)
 *     provider unreachable (no answer)                → failed `outcome_unknown`
 *                                                       — never re-sent automatically
 *     published                                       → published
 *     accepted, still processing (Instagram video)    → pending → CompleteInstagramVideoPostJob
 *
 * Every state change is `UPDATE ... WHERE id = ? AND status = <from>
 * [AND claim_token = ?]`: a cancelled, re-claimed or already settled row is
 * never overwritten, and a due post is sent by at most one worker.
 *
 * Authorization: the controller's route middleware checks the caller;
 * this service re-checks the TARGET account (SocialTargetGate — active,
 * subscription, social_accounts module, `social` capability; no Super Admin
 * bypass) when a post is created or retried AND again right before a
 * scheduled post is sent. The social account is always resolved with
 * forAccount(<post's account>). `origin` is informational only.
 *
 * [Hypothesis] — provider request shapes are the documented contracts, not
 * verified against a live Page (standing disclosure). LinkedIn remains
 * unreachable (no LinkedIn OAuth provider).
 */
class OrganicPublishService
{
    public const OPERATION = 'organic.publish';

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

    public const OUTCOME_UNKNOWN_MESSAGE = 'The platform could not be reached, so it is not known whether the post was published. Check the page before retrying.';

    public function __construct(
        private readonly SocialPublisherFactory $publishers,
        private readonly SocialConnectionService $connections,
        private readonly SocialTargetGate $gate,
    ) {
    }

    // ================================================================ create

    /**
     * Backward-compatible entry point (manual publish when no scheduled_at).
     *
     * @param  array{platform: string, caption: string, media_url?: string|null, media_type?: string|null, social_account_id?: int|null, scheduled_at?: CarbonInterface|string|null, idempotency_key?: string|null, created_by?: int|null}  $payload
     *
     * @throws RuntimeException          pre-flight (no row): unsupported platform, invalid content, no connected asset
     * @throws SocialConnectionException the connection is expired/revoked (known before, or reported mid-publish)
     * @throws PublishingDenied          target not allowed (403) / idempotency key reused (409)
     */
    public function publish(Account $account, array $payload): OrganicPost
    {
        return $this->submit($account, $payload)['post'];
    }

    /**
     * @return array{post: OrganicPost, replayed: bool}
     */
    public function submit(Account $account, array $payload): array
    {
        $platform = (string) ($payload['platform'] ?? '');

        if (! in_array($platform, OrganicPost::PLATFORMS, true) || ! $this->publishers->supports($platform)) {
            throw new RuntimeException("Unsupported platform '{$platform}'.");
        }

        $publisher = $this->publishers->forPlatform($platform);
        $request = new PublishRequest($platform, (string) $payload['caption'], $payload['media_url'] ?? null, $payload['media_type'] ?? null);

        if ($error = $publisher->validationError($request)) {
            throw new RuntimeException($error);
        }

        $scheduledAt = isset($payload['scheduled_at']) && $payload['scheduled_at'] !== null && $payload['scheduled_at'] !== ''
            ? Carbon::parse($payload['scheduled_at'])
            : null;

        if ($scheduledAt && $scheduledAt->lte(now())) {
            throw new RuntimeException('The scheduled time must be in the future.');
        }

        $key = isset($payload['idempotency_key']) && $payload['idempotency_key'] !== '' ? (string) $payload['idempotency_key'] : null;
        $fingerprint = $this->fingerprint($platform, $request, $payload['social_account_id'] ?? null, $scheduledAt);

        if ($key && ($existing = $this->replay($account, $key, $fingerprint))) {
            return ['post' => $existing, 'replayed' => true];
        }

        $socialAccount = $this->resolveSocialAccount($account, $platform, $payload['social_account_id'] ?? null);

        if ($denial = $this->gate->denial($account, 'publish social posts')) {
            throw PublishingDenied::target($denial);
        }

        // A connection already known to be expired/revoked is refused before a row or a provider call.
        $this->connections->assertUsable($socialAccount, self::OPERATION);

        $token = $scheduledAt ? null : $this->newToken();

        try {
            $post = OrganicPost::create([
                'account_id' => $account->id,
                'social_account_id' => $socialAccount->id,
                'provider' => self::PROVIDER_MAP[$platform],
                'platform' => $platform,
                'caption' => $request->caption,
                'media_url' => $request->mediaUrl,
                'media_type' => $request->mediaType,
                'status' => $scheduledAt ? OrganicPost::STATUS_SCHEDULED : OrganicPost::STATUS_PUBLISHING,
                'scheduled_at' => $scheduledAt,
                'origin' => $scheduledAt ? OrganicPost::ORIGIN_SCHEDULED : OrganicPost::ORIGIN_MANUAL,
                'idempotency_key' => $key,
                'created_by_user_id' => $payload['created_by'] ?? null,
                'attempts' => $scheduledAt ? 0 : 1,
                'claim_token' => $token,
                'claimed_at' => $scheduledAt ? null : now(),
                'metadata' => ['request_fingerprint' => $fingerprint],
            ]);
        } catch (UniqueConstraintViolationException $e) {
            // A concurrent request with the same key won the insert.
            if ($key && ($existing = $this->replay($account, $key, $fingerprint))) {
                return ['post' => $existing, 'replayed' => true];
            }

            throw $e;
        }

        if ($scheduledAt) {
            return ['post' => $post->fresh(), 'replayed' => false];
        }

        $post = $this->execute($post, $token);

        if ($post->status === OrganicPost::STATUS_RECONNECT_REQUIRED) {
            // Same 409 contract as before Task 3: the row keeps the safe message.
            $socialAccount->refresh();

            throw SocialConnectionException::for($socialAccount, $socialAccount->connectionStatus(), $socialAccount->status_reason);
        }

        return ['post' => $post, 'replayed' => false];
    }

    // ================================================================ execute

    /**
     * Sends a claimed post. A no-op unless the row is still `publishing`
     * with this claim token (cancelled, re-claimed or settled meanwhile).
     */
    public function execute(OrganicPost $post, string $token, bool $viaWorker = false): OrganicPost
    {
        $post = OrganicPost::query()->find($post->id);

        if (! $post || $post->status !== OrganicPost::STATUS_PUBLISHING || ! hash_equals((string) $post->claim_token, $token)) {
            return $post ?? throw new RuntimeException('Organic post not found.');
        }

        $publisher = $this->publishers->forPlatform($post->platform);
        $socialAccount = $this->ownedConnection($post, $publisher);

        if (! $socialAccount) {
            return $this->fail($post, $token, 'The connected '.$post->platform.' asset is no longer available. Please reconnect it.', 'connection_missing');
        }

        $account = Account::query()->find($post->account_id);
        // A post a Super Admin created keeps their plan bypass when the worker sends it later.
        $denial = $account
            ? $this->gate->denial($account, 'publish social posts', (bool) auth()->user()?->isSuperAdmin() || $this->createdBySuperAdmin($post))
            : ['code' => 'CLIENT_ACCOUNT_SUSPENDED', 'message' => 'This account no longer exists.'];

        if ($denial) {
            return $this->fail($post, $token, $denial['message'], strtolower($denial['code']));
        }

        try {
            $this->connections->assertUsable($socialAccount, self::OPERATION);
        } catch (SocialConnectionException $e) {
            return $this->reconnectRequired($post, $token, $e);
        }

        if (! $this->settle($post, OrganicPost::STATUS_PUBLISHING, OrganicPost::STATUS_PUBLISHING, $token, ['provider_called_at' => now()])) {
            return $post->fresh();
        }

        try {
            $outcome = $publisher->publish($socialAccount, new PublishRequest($post->platform, (string) $post->caption, $post->media_url, $post->media_type));
        } catch (ProviderRequestFailed $e) {
            return $this->providerFailed($post, $token, $publisher, $socialAccount, $e, $viaWorker);
        } catch (PublishUnreachable $e) {
            $this->logFailure($post, 'unreachable', $e);

            return $this->fail($post, $token, self::OUTCOME_UNKNOWN_MESSAGE, 'outcome_unknown');
        } catch (Throwable $e) {
            $this->logFailure($post, 'unexpected', $e);

            return $this->fail($post, $token, self::OUTCOME_UNKNOWN_MESSAGE, 'outcome_unknown');
        }

        return match ($outcome->status) {
            PublishOutcome::PUBLISHED => $this->published($post, OrganicPost::STATUS_PUBLISHING, $token, (string) $outcome->externalId),
            PublishOutcome::PROCESSING => $this->processing($post, $token, (string) $outcome->containerId),
            default => $this->fail($post, $token, (string) $outcome->message, 'provider_rejected'),
        };
    }

    /**
     * Phase 5 fix P5-9 — ONE status check of a queued Instagram video
     * container (CompleteInstagramVideoPostJob, one attempt per run).
     * Only a still-'pending' post of the SAME tenant as its social account
     * is ever touched.
     *
     * @return 'published'|'failed'|'pending'|'skipped'|'reconnect_required'
     */
    public function advanceInstagramVideo(OrganicPost $post, string $creationId): string
    {
        if ($post->status !== OrganicPost::STATUS_PENDING || $post->platform !== 'instagram') {
            return 'skipped';
        }

        $publisher = $this->publishers->forPlatform('instagram');
        $ig = $this->ownedConnection($post, $publisher);

        if (! $ig) {
            $post->markFailed('The connected instagram asset is no longer available. Please reconnect it.', 'connection_missing');

            return 'failed';
        }

        try {
            $outcome = $publisher->checkProcessing($ig, $creationId);
        } catch (ProviderRequestFailed $e) {
            if ($result = $this->connections->observe($ig, $e, 'organic.instagram_video')) {
                $message = SocialConnectionException::for($ig, $result->status, $result->reason)->getMessage();
                $this->settle($post, OrganicPost::STATUS_PENDING, OrganicPost::STATUS_RECONNECT_REQUIRED, null, [
                    'error_message' => $message, 'failure_code' => 'connection_'.$result->status,
                ]);

                return 'reconnect_required';
            }

            if ($publisher->isTransient($e)) {
                return 'pending';
            }

            $this->settle($post, OrganicPost::STATUS_PENDING, OrganicPost::STATUS_FAILED, null, [
                'error_message' => $publisher->failureMessage($e), 'failure_code' => 'provider_rejected',
                'metadata' => $this->mergedMetadata($post, ['provider_error_code' => $this->providerErrorCode($e)]),
            ]);

            return 'failed';
        } catch (PublishUnreachable $e) {
            $this->logFailure($post, 'unreachable', $e);
            $this->settle($post, OrganicPost::STATUS_PENDING, OrganicPost::STATUS_FAILED, null, [
                'error_message' => self::OUTCOME_UNKNOWN_MESSAGE, 'failure_code' => 'outcome_unknown',
            ]);

            return 'failed';
        }

        if ($outcome->status === PublishOutcome::PUBLISHED) {
            $this->published($post, OrganicPost::STATUS_PENDING, null, (string) $outcome->externalId);

            return 'published';
        }

        if ($outcome->status === PublishOutcome::FAILED) {
            $this->settle($post, OrganicPost::STATUS_PENDING, OrganicPost::STATUS_FAILED, null, [
                'error_message' => $outcome->message, 'failure_code' => 'provider_rejected',
            ]);

            return 'failed';
        }

        return 'pending';
    }

    // ================================================================ scheduler

    /**
     * Claims due scheduled posts for this run. Each claim is a conditional
     * UPDATE (still `scheduled`, still due) that sets a fresh claim token, so
     * overlapping runs or several servers never claim the same post twice.
     * A post whose FIRST pick-up is later than the grace window is failed as
     * missed instead of published late.
     *
     * @return list<array{id: int, token: string}>
     */
    public function claimDue(?int $limit = null): array
    {
        $now = now();
        $limit ??= max(1, (int) config('social.publishing.batch_size', 50));
        $grace = $now->copy()->subHours(max(1, (int) config('social.publishing.late_grace_hours', 24)));

        OrganicPost::query()
            ->where('status', OrganicPost::STATUS_SCHEDULED)
            ->where('attempts', 0)
            ->whereNull('next_attempt_at')
            ->where('scheduled_at', '<', $grace)
            ->update([
                'status' => OrganicPost::STATUS_FAILED,
                'failure_code' => 'missed_schedule',
                'error_message' => 'The post was not published because its scheduled time passed more than '.(int) config('social.publishing.late_grace_hours', 24).' hours ago. Retry it to publish now.',
                'updated_at' => $now,
            ]);

        $ids = $this->dueQuery($now)->orderBy('scheduled_at')->orderBy('id')->limit($limit)->pluck('id');

        $claimed = [];

        foreach ($ids as $id) {
            $token = $this->newToken();

            $won = $this->dueQuery($now)->whereKey($id)->update([
                'status' => OrganicPost::STATUS_PUBLISHING,
                'claim_token' => $token,
                'claimed_at' => $now,
                'provider_called_at' => null,
                'attempts' => DB::raw('attempts + 1'),
                'updated_at' => $now,
            ]);

            if ($won === 1) {
                $claimed[] = ['id' => (int) $id, 'token' => $token];
            }
        }

        return $claimed;
    }

    /**
     * Claims whose worker died. Provider already called → `outcome_unknown`
     * (never re-sent automatically: it may have been published). Not called
     * → `interrupted` (nothing was sent; safe to retry).
     */
    public function recoverStale(): int
    {
        $cutoff = now()->subMinutes(max(1, (int) config('social.publishing.stale_after_minutes', 15)));
        $stale = fn () => OrganicPost::query()->where('status', OrganicPost::STATUS_PUBLISHING)->where('claimed_at', '<', $cutoff);

        $unknown = $stale()->whereNotNull('provider_called_at')->update([
            'status' => OrganicPost::STATUS_FAILED, 'failure_code' => 'outcome_unknown',
            'error_message' => self::OUTCOME_UNKNOWN_MESSAGE, 'claim_token' => null, 'updated_at' => now(),
        ]);

        $interrupted = $stale()->whereNull('provider_called_at')->update([
            'status' => OrganicPost::STATUS_FAILED, 'failure_code' => 'interrupted',
            'error_message' => 'Publishing was interrupted before the post was sent. Retry it to publish.', 'claim_token' => null, 'updated_at' => now(),
        ]);

        return $unknown + $interrupted;
    }

    /** A claimed post whose job could not be queued: nothing was sent. */
    public function releaseUndispatched(int $postId, string $token): void
    {
        $post = OrganicPost::query()->find($postId);

        if ($post) {
            $this->fail($post, $token, 'Publishing could not be queued. Retry it to publish.', 'dispatch_failed');
        }
    }

    /** A worker job that died outside execute(): settle the claim it held. */
    public function abandonClaim(int $postId, string $token): void
    {
        $post = OrganicPost::query()->find($postId);

        if (! $post || $post->status !== OrganicPost::STATUS_PUBLISHING || ! hash_equals((string) $post->claim_token, $token)) {
            return;
        }

        $post->provider_called_at
            ? $this->fail($post, $token, self::OUTCOME_UNKNOWN_MESSAGE, 'outcome_unknown')
            : $this->fail($post, $token, 'Publishing was interrupted before the post was sent. Retry it to publish.', 'interrupted');
    }

    // ================================================================ user actions

    /** Cancel a post that has not started publishing. False when it no longer can be. */
    private function createdBySuperAdmin(OrganicPost $post): bool
    {
        return $post->created_by_user_id !== null
            && (bool) User::query()->find($post->created_by_user_id)?->isSuperAdmin();
    }

    public function cancel(OrganicPost $post): bool
    {
        return OrganicPost::query()
            ->whereKey($post->id)
            ->where('account_id', $post->account_id)
            ->whereIn('status', [OrganicPost::STATUS_SCHEDULED, OrganicPost::STATUS_RECONNECT_REQUIRED])
            ->update([
                'status' => OrganicPost::STATUS_CANCELLED,
                'cancelled_at' => now(),
                'claim_token' => null,
                'next_attempt_at' => null,
                'updated_at' => now(),
            ]) === 1;
    }

    /**
     * Retry a failed / reconnect-required post: re-checks the target and the
     * connection, then queues it for the scheduler to send now.
     *
     * @throws PublishingDenied|SocialConnectionException|RuntimeException
     */
    public function retry(Account $account, OrganicPost $post): OrganicPost
    {
        if ((int) $post->account_id !== (int) $account->id) {
            throw new LogicException('retry() called with a post of another account.');
        }

        if (! $post->isRetryable()) {
            throw PublishingDenied::conflict('Only a failed post or one waiting for a reconnect can be retried.', 'POST_NOT_RETRYABLE');
        }

        if ($denial = $this->gate->denial($account, 'publish social posts')) {
            throw PublishingDenied::target($denial);
        }

        $socialAccount = $this->ownedConnection($post, $this->publishers->forPlatform($post->platform));

        if (! $socialAccount) {
            throw new RuntimeException('The connected '.$post->platform.' asset is no longer available. Reconnect it, then create the post again.');
        }

        $this->connections->assertUsable($socialAccount, self::OPERATION);

        $moved = OrganicPost::query()
            ->whereKey($post->id)
            ->where('status', $post->status)
            ->update([
                'status' => OrganicPost::STATUS_SCHEDULED,
                'scheduled_at' => now(),
                'next_attempt_at' => null,
                'attempts' => 0,
                'claim_token' => null,
                'claimed_at' => null,
                'provider_called_at' => null,
                'error_message' => null,
                'failure_code' => null,
                'updated_at' => now(),
            ]);

        if ($moved !== 1) {
            throw PublishingDenied::conflict('The post changed while you were retrying it. Refresh and try again.', 'POST_STATE_CHANGED');
        }

        return $post->fresh();
    }

    // ================================================================ internals

    private function resolveSocialAccount(Account $account, string $platform, mixed $socialAccountId): SocialAccount
    {
        $query = SocialAccount::query()
            ->forAccount($account->id)
            ->where('provider', self::PROVIDER_MAP[$platform])
            ->ofAssetType(self::ASSET_TYPE_MAP[$platform]);

        $socialAccount = $socialAccountId ? $query->whereKey((int) $socialAccountId)->first() : $query->orderBy('id')->first();

        if (! $socialAccount) {
            $label = ucfirst($platform);

            throw new RuntimeException($socialAccountId
                ? "The selected {$label} asset is not connected to this account."
                : "No connected {$label} asset for this tenant. Connect one from the Social Hub first.");
        }

        if (! $socialAccount->access_token) {
            throw new RuntimeException("The connected {$platform} asset has no stored access token. Please reconnect it.");
        }

        return $socialAccount;
    }

    /** The post's connection, only if it still belongs to the post's account and matches the publisher. */
    private function ownedConnection(OrganicPost $post, SocialPublisher $publisher): ?SocialAccount
    {
        $socialAccount = SocialAccount::query()->forAccount($post->account_id)->find($post->social_account_id);

        if (! $socialAccount || ! $socialAccount->access_token
            || $socialAccount->provider !== $publisher->key()
            || $socialAccount->asset_type !== ($publisher->platforms()[$post->platform] ?? null)) {
            return null;
        }

        return $socialAccount;
    }

    private function providerFailed(OrganicPost $post, string $token, SocialPublisher $publisher, SocialAccount $socialAccount, ProviderRequestFailed $e, bool $viaWorker): OrganicPost
    {
        if ($result = $this->connections->observe($socialAccount, $e, self::OPERATION)) {
            return $this->reconnectRequired($post, $token, SocialConnectionException::for($socialAccount, $result->status, $result->reason));
        }

        $transient = $publisher->isTransient($e);
        $metadata = $this->mergedMetadata($post, ['provider_error_code' => $this->providerErrorCode($e), 'provider_http_status' => $e->httpStatus]);
        $this->logFailure($post, $transient ? 'transient' : 'rejected', $e);

        $maxAttempts = max(1, (int) config('social.publishing.max_attempts', 3));

        if ($transient && $viaWorker && $post->attempts < $maxAttempts) {
            $backoff = array_values((array) config('social.publishing.backoff_minutes', [1, 5, 15]));
            $minutes = (int) ($backoff[min(max($post->attempts - 1, 0), count($backoff) - 1)] ?? 5);

            $this->settle($post, OrganicPost::STATUS_PUBLISHING, OrganicPost::STATUS_SCHEDULED, $token, [
                'next_attempt_at' => now()->addMinutes($minutes),
                'claim_token' => null,
                'provider_called_at' => null,
                'error_message' => 'The platform had a temporary problem. Another attempt is scheduled.',
                'failure_code' => 'provider_transient',
                'metadata' => $metadata,
            ]);

            return $post->fresh();
        }

        return $this->fail($post, $token, $publisher->failureMessage($e), $transient ? 'provider_transient' : 'provider_rejected', $metadata);
    }

    private function reconnectRequired(OrganicPost $post, string $token, SocialConnectionException $e): OrganicPost
    {
        $this->settle($post, OrganicPost::STATUS_PUBLISHING, OrganicPost::STATUS_RECONNECT_REQUIRED, $token, [
            'claim_token' => null,
            'error_message' => $e->getMessage(),
            'failure_code' => strtolower($e->errorCode()),
        ]);

        return $post->fresh();
    }

    private function published(OrganicPost $post, string $from, ?string $token, string $externalId): OrganicPost
    {
        $this->settle($post, $from, OrganicPost::STATUS_PUBLISHED, $token, [
            'external_post_id' => $externalId,
            'published_at' => now(),
            'error_message' => null,
            'failure_code' => null,
            'claim_token' => null,
            'next_attempt_at' => null,
        ]);

        return $post->fresh();
    }

    private function processing(OrganicPost $post, string $token, string $containerId): OrganicPost
    {
        $moved = $this->settle($post, OrganicPost::STATUS_PUBLISHING, OrganicPost::STATUS_PENDING, $token, [
            'claim_token' => null,
            'metadata' => $this->mergedMetadata($post, ['container_id' => $containerId]),
        ]);

        if ($moved) {
            // Phase 5 fix P5-9 — unchanged: the video wait is a queued chain.
            CompleteInstagramVideoPostJob::dispatch($post->id, $containerId);
        }

        return $post->fresh();
    }

    /** @param array<string, mixed>|null $metadata */
    private function fail(OrganicPost $post, string $token, string $message, string $code, ?array $metadata = null): OrganicPost
    {
        $this->settle($post, OrganicPost::STATUS_PUBLISHING, OrganicPost::STATUS_FAILED, $token, array_filter([
            'claim_token' => null,
            'next_attempt_at' => null,
            'error_message' => $message,
            'failure_code' => $code,
            'metadata' => $metadata,
        ], fn ($v, $k) => $v !== null || in_array($k, ['claim_token', 'next_attempt_at'], true), ARRAY_FILTER_USE_BOTH));

        return $post->fresh();
    }

    /**
     * The only way execute()/advanceInstagramVideo() change a row: a
     * conditional UPDATE on the expected status (and claim token).
     *
     * @param  array<string, mixed>  $attributes
     */
    private function settle(OrganicPost $post, string $from, string $to, ?string $token, array $attributes): bool
    {
        if ($from !== $to && ! OrganicPost::canTransition($from, $to)) {
            throw new LogicException("Illegal organic post transition {$from} → {$to}.");
        }

        if (array_key_exists('metadata', $attributes) && is_array($attributes['metadata'])) {
            $attributes['metadata'] = json_encode($attributes['metadata']);
        }

        $query = OrganicPost::query()->whereKey($post->id)->where('status', $from);

        if ($token !== null) {
            $query->where('claim_token', $token);
        }

        return $query->update(['status' => $to, 'updated_at' => now()] + $attributes) === 1;
    }

    /**
     * @param  array<string, mixed>  $extra
     * @return array<string, mixed>
     */
    private function mergedMetadata(OrganicPost $post, array $extra): array
    {
        $current = OrganicPost::query()->whereKey($post->id)->value('metadata');
        $current = is_string($current) ? (json_decode($current, true) ?: []) : (is_array($current) ? $current : []);

        return array_merge($current, array_filter($extra, fn ($v) => $v !== null));
    }

    private function replay(Account $account, string $key, string $fingerprint): ?OrganicPost
    {
        $existing = OrganicPost::query()->forAccount($account->id)->where('idempotency_key', $key)->first();

        if (! $existing) {
            return null;
        }

        if (($existing->metadata['request_fingerprint'] ?? null) !== $fingerprint) {
            throw PublishingDenied::conflict('This idempotency key was already used for a different post.', 'IDEMPOTENCY_KEY_REUSED');
        }

        return $existing;
    }

    private function fingerprint(string $platform, PublishRequest $request, mixed $socialAccountId, ?CarbonInterface $scheduledAt): string
    {
        return hash('sha256', json_encode([
            $platform, $request->caption, $request->mediaUrl, $request->mediaType,
            $socialAccountId ? (int) $socialAccountId : null,
            $scheduledAt?->copy()->utc()->format('Y-m-d H:i'),
        ]));
    }

    private function providerErrorCode(ProviderRequestFailed $e): ?int
    {
        $code = $e->errorBody['error']['code'] ?? null;

        return is_numeric($code) ? (int) $code : null;
    }

    private function dueQuery(CarbonInterface $now)
    {
        return OrganicPost::query()
            ->where('status', OrganicPost::STATUS_SCHEDULED)
            ->where('scheduled_at', '<=', $now)
            ->where(fn ($q) => $q->whereNull('next_attempt_at')->orWhere('next_attempt_at', '<=', $now));
    }

    private function newToken(): string
    {
        return Str::random(40);
    }

    /** Safe log line: never the token, the caption, or the provider's raw message. */
    private function logFailure(OrganicPost $post, string $kind, Throwable $e): void
    {
        Log::warning("OrganicPublishService: publish {$kind} for OrganicPost #{$post->id} ({$post->platform}).", [
            'account_id' => $post->account_id,
            'exception' => class_basename($e),
            'http_status' => $e instanceof ProviderRequestFailed ? $e->httpStatus : null,
            'provider_error_code' => $e instanceof ProviderRequestFailed ? $this->providerErrorCode($e) : null,
        ]);
    }
}
