<?php

namespace App\Console\Commands;

use App\Jobs\RefreshPostInsightsJob;
use App\Services\Social\Insights\PostInsightsService;
use Illuminate\Console\Command;

/**
 * Phase 9 Task 4 — queues insights refreshes for published organic posts
 * whose snapshot is missing or due (freshness window / back-off elapsed),
 * published within SOCIAL_INSIGHTS_MAX_POST_AGE_DAYS. The provider call
 * happens in RefreshPostInsightsJob, never here; a duplicate queue entry is
 * harmless (the job re-checks due-ness and the per-post lease).
 */
class RefreshSocialPostInsights extends Command
{
    protected $signature = 'social:refresh-insights {--limit= : Maximum posts to queue this run (default: SOCIAL_INSIGHTS_BATCH_SIZE)}';

    protected $description = 'Queue insights refreshes for published social posts that are due.';

    public function handle(PostInsightsService $insights): int
    {
        $limit = $this->option('limit') !== null ? max(1, (int) $this->option('limit')) : null;
        $ids = $insights->dueForRefresh($limit);

        foreach ($ids as $id) {
            RefreshPostInsightsJob::dispatch($id)->onConnection(RefreshPostInsightsJob::CONNECTION)->onQueue('social');
        }

        $this->info('Queued '.count($ids).' insights refresh(es).');

        return self::SUCCESS;
    }
}
