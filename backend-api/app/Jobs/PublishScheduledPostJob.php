<?php

namespace App\Jobs;

use App\Models\OrganicPost;
use App\Services\Social\OrganicPublishService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Throwable;

/**
 * Phase 9 Task 3 — sends ONE scheduled organic post that social:publish-due
 * claimed (status `publishing`, this claim token). A no-op when the row was
 * cancelled, re-claimed or settled meanwhile. Exactly one attempt per job
 * ($tries = 1): temporary provider failures are re-scheduled by
 * OrganicPublishService (back-off, then claimed again), never by queue
 * redelivery, so the provider is called at most once per claim.
 */
class PublishScheduledPostJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 1;

    public int $timeout = 120;

    public function __construct(
        public readonly int $organicPostId,
        public readonly string $claimToken,
    ) {
    }

    public function handle(OrganicPublishService $service): void
    {
        $post = OrganicPost::query()->find($this->organicPostId);

        if (! $post || $post->status !== OrganicPost::STATUS_PUBLISHING) {
            return;
        }

        $service->execute($post, $this->claimToken, viaWorker: true);
    }

    /** Died outside handle() (timeout, worker crash): settle the claim, never leave it publishing. */
    public function failed(?Throwable $exception = null): void
    {
        app(OrganicPublishService::class)->abandonClaim($this->organicPostId, $this->claimToken);
    }
}
