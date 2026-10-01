<?php

namespace App\Console\Commands;

use App\Jobs\CheckSocialConnectionJob;
use App\Services\SocialAuth\SocialConnectionService;
use Illuminate\Console\Command;

/**
 * Phase 9 Task 2 — scheduled social connection health checks.
 *
 * Claims every connected social account that is due (no check attempt and
 * no confirmed status within SOCIAL_CONNECTION_CHECK_INTERVAL_MINUTES) and
 * queues one CheckSocialConnectionJob per claimed row on database:social.
 * Claiming is one conditional UPDATE per row (SocialConnectionService::
 * claimDue()), so the command is safe to run more often than the interval,
 * to overlap with itself, and to run on several servers at once — a row is
 * handed to exactly one job per interval. The provider call happens in the
 * job, never in the scheduler callback.
 */
class CheckSocialConnections extends Command
{
    protected $signature = 'social:check-connections {--limit= : Maximum connections to claim this run (default: SOCIAL_CONNECTION_CHECK_BATCH_SIZE)}';

    protected $description = 'Queue provider health checks for connected social accounts that are due.';

    public function handle(SocialConnectionService $connections): int
    {
        $limit = $this->option('limit') !== null ? max(1, (int) $this->option('limit')) : null;

        $ids = $connections->claimDue($limit);

        foreach ($ids as $id) {
            CheckSocialConnectionJob::dispatch($id)->onConnection('database')->onQueue('social');
        }

        $this->info('Queued '.count($ids).' social connection check(s) (interval '.$connections->intervalMinutes().' min).');

        return self::SUCCESS;
    }
}
