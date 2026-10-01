<?php

namespace App\Jobs;

use App\Models\OrganicPost;
use App\Services\Social\OrganicPublishService;
use App\Support\QueueDelay;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Phase 5 fix P5-9 — the Instagram video wait, moved out of the request.
 *
 * OrganicPublishService::publishToInstagram() used to poll the video
 * container's status_code inline: up to MAX_ATTEMPTS status calls with a
 * blocking sleep(3) between them, holding the tenant's HTTP request (and a
 * PHP worker) for the whole Meta-side processing time. Same bounds now, as
 * a chain of queued attempts: each run makes ONE status check
 * (OrganicPublishService::advanceInstagramVideo()); while Meta reports the
 * container not ready, it queues the next attempt POLL_INTERVAL_SECONDS
 * later. After MAX_ATTEMPTS unready checks the post fails with the same
 * message the inline poll used.
 *
 * Exactly one attempt per job ($tries = 1) and a strictly linear chain
 * (each run queues at most one successor), so media_publish is called at
 * most once per post; a run that dies is settled by failed().
 */
class CompleteInstagramVideoPostJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /** Same bound as the former inline poll (10 status checks). */
    public const MAX_ATTEMPTS = 10;

    /** Same spacing as the former sleep(3), now a queue delay. */
    public const POLL_INTERVAL_SECONDS = 3;

    public const TIMEOUT_MESSAGE = 'Timed out waiting for Instagram to finish processing the video.';

    public int $tries = 1;

    public function __construct(
        public readonly int $organicPostId,
        public readonly string $creationId,
        public readonly int $attempt = 1,
    ) {
    }

    public function handle(OrganicPublishService $service): void
    {
        $post = OrganicPost::find($this->organicPostId);

        if (! $post || $post->status !== OrganicPost::STATUS_PENDING) {
            return;
        }

        try {
            $outcome = $service->advanceInstagramVideo($post, $this->creationId);
        } catch (Throwable $e) {
            Log::warning("CompleteInstagramVideoPostJob: publish failed for OrganicPost #{$post->id} (instagram).", [
                'account_id' => $post->account_id,
                'exception' => $e->getMessage(),
            ]);

            $post->markFailed($e->getMessage());

            return;
        }

        if ($outcome !== 'pending') {
            return;
        }

        if ($this->attempt >= self::MAX_ATTEMPTS) {
            $post->markFailed(self::TIMEOUT_MESSAGE);

            return;
        }

        static::dispatch($this->organicPostId, $this->creationId, $this->attempt + 1)
            ->onConnection($this->connection)
            ->onQueue($this->queue)
            ->delay(QueueDelay::after(self::POLL_INTERVAL_SECONDS));
    }

    /** A run that died outside handle() (timeout, redelivery past $tries): never leave the post pending forever. */
    public function failed(?Throwable $exception = null): void
    {
        $post = OrganicPost::find($this->organicPostId);

        if ($post && $post->status === OrganicPost::STATUS_PENDING) {
            $post->markFailed('Publishing did not complete: '.($exception?->getMessage() ?? 'unknown error'));
        }
    }
}
