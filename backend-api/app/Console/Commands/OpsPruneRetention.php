<?php

namespace App\Console\Commands;

use App\Services\Ops\RetentionPruner;
use Illuminate\Console\Command;

/**
 * Phase 12 Task 5 — `php artisan ops:prune-retention`: bounded retention for operational tables (see
 * App\Services\Ops\RetentionPruner for the audit and config/retention.php for the periods).
 *
 * Deletes ONLY when --force is given, or when retention.enforce (RETENTION_PRUNE_ENFORCE) is true; otherwise it
 * counts and logs what it would delete. --dry-run always wins. Exit 1 if any category failed (the rest still ran).
 */
class OpsPruneRetention extends Command
{
    protected $signature = 'ops:prune-retention
        {--force : Delete even when retention.enforce is false}
        {--dry-run : Count only; never delete}
        {--only=* : Limit to these categories (repeatable)}
        {--json : Machine-readable output}';

    protected $description = 'Prune old operational rows (request logs, webhook deliveries, journey trail, failed jobs) within bounded batches';

    public function handle(RetentionPruner $pruner): int
    {
        $only = array_values(array_filter((array) $this->option('only')));
        $unknown = array_diff($only, $pruner->categories());
        if ($unknown !== []) {
            $this->error('Unknown category: '.implode(', ', $unknown).'. Known: '.implode(', ', $pruner->categories()));

            return self::INVALID;
        }

        $delete = ! $this->option('dry-run') && ($this->option('force') || (bool) config('retention.enforce'));
        $result = $pruner->run($delete, ['only' => $only ?: null]);

        if ($this->option('json')) {
            $this->line(json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return $result['failed'] ? self::FAILURE : self::SUCCESS;
        }

        if ($result['locked']) {
            $this->warn('Another retention run is in progress; nothing done.');

            return self::SUCCESS;
        }

        $this->line($delete ? 'Retention prune (deleting):' : 'Retention prune — DRY RUN (nothing deleted; pass --force or set RETENTION_PRUNE_ENFORCE=true):');
        $this->table(
            ['category', 'scope', 'days', $delete ? 'deleted' : 'would delete', 'status'],
            array_map(fn ($r) => [$r['category'], $r['scope'], $r['days'] ?: '-', $delete ? $r['deleted'] : $r['candidates'], $r['status'].(isset($r['error']) ? ' ('.class_basename($r['error']).')' : '')], $result['rows']),
        );

        return $result['failed'] ? self::FAILURE : self::SUCCESS;
    }
}
