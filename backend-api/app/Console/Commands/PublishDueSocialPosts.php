<?php

namespace App\Console\Commands;

use App\Jobs\PublishScheduledPostJob;
use App\Services\Social\OrganicPublishService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Phase 9 Task 3 — scheduled organic publishing.
 *
 * Each run: settles stale claims (OrganicPublishService::recoverStale()),
 * fails posts missed beyond the grace window, claims due `scheduled` posts
 * with one conditional UPDATE each (so overlapping runs or several servers
 * never claim a post twice) and queues one PublishScheduledPostJob per
 * claim on database:social. The provider call happens in the job, never in
 * the scheduler callback.
 */
class PublishDueSocialPosts extends Command
{
    protected $signature = 'social:publish-due {--limit= : Maximum posts to claim this run (default: SOCIAL_PUBLISH_BATCH_SIZE)}';

    protected $description = 'Queue scheduled social posts that are due for publishing.';

    public function handle(OrganicPublishService $service): int
    {
        $recovered = $service->recoverStale();
        $limit = $this->option('limit') !== null ? max(1, (int) $this->option('limit')) : null;

        $queued = 0;

        foreach ($service->claimDue($limit) as $claim) {
            try {
                PublishScheduledPostJob::dispatch($claim['id'], $claim['token'])->onConnection(PublishScheduledPostJob::CONNECTION)->onQueue('social');
                $queued++;
            } catch (Throwable $e) {
                Log::warning("social:publish-due could not queue OrganicPost #{$claim['id']}.", ['exception' => class_basename($e)]);
                $service->releaseUndispatched($claim['id'], $claim['token']);
            }
        }

        $this->info("Queued {$queued} scheduled post(s); settled {$recovered} stale claim(s).");

        return self::SUCCESS;
    }
}
