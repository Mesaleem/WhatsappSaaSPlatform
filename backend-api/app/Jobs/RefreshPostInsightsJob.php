<?php

namespace App\Jobs;

use App\Models\OrganicPost;
use App\Services\Social\Insights\PostInsightsService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/**
 * Phase 9 Task 4 — one worker refresh of one post's insights (queued by
 * social:refresh-insights on database:social). A no-op when the post is no
 * longer eligible, not due, or already being refreshed; failures are
 * scheduled for retry by PostInsightsService (back-off), never by queue
 * redelivery ($tries = 1).
 */
class RefreshPostInsightsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    /** Phase 12 Task 1 — a 90 s timeout is not below the default connection's retry_after (90 s): long-retry connection. */
    public const CONNECTION = 'database_long';

    public int $tries = 1;

    public int $timeout = 90;

    public function __construct(public readonly int $organicPostId)
    {
    }

    public function handle(PostInsightsService $insights): void
    {
        $post = OrganicPost::query()->find($this->organicPostId);

        if (! $post || $insights->ineligibility($post) !== null) {
            return;
        }

        $insights->refresh($post, explicit: false);
    }
}
