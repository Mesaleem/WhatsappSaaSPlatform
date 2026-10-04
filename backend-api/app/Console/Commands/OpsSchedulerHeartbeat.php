<?php

namespace App\Console\Commands;

use App\Support\Observability\ReadinessProbe;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

/**
 * Phase 12 Task 3 — writes "the scheduler ticked at <unix time>" to the cache. Scheduled every minute; read by
 * /ready and ops reports. Cache only — no table, no migration.
 */
class OpsSchedulerHeartbeat extends Command
{
    protected $signature = 'ops:scheduler-heartbeat';

    protected $description = 'Record a scheduler heartbeat in the cache (read by GET /ready)';

    public function handle(): int
    {
        Cache::put(ReadinessProbe::HEARTBEAT_KEY, time(), 3600);

        return self::SUCCESS;
    }
}
