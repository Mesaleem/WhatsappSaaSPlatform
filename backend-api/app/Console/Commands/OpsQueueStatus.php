<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Phase 12 Task 3 — `php artisan ops:queue-status`: a READ-ONLY queue report. Only SELECTs; it never reserves,
 * releases, retries, deletes or flushes anything, and never prints a payload, a connection string or a credential
 * (it reads counts, queue names and timestamps only).
 *
 * A metric that cannot be read is reported as `unavailable` (null in --json) with the reason — never as 0.
 * Only the `database` queue driver is inspected; a `redis` queue is reported unavailable rather than connected to.
 *
 * Per queue: ready (due, unreserved), delayed (not yet due), reserved (running now), oldest_ready_age_seconds
 * (how long the oldest ready job has been waiting past its due time). Exit 0 when the figures were read;
 * 1 when the queue storage could not be read.
 */
class OpsQueueStatus extends Command
{
    protected $signature = 'ops:queue-status {--json : Machine-readable output}';

    protected $description = 'Read-only: queue depth, oldest pending job age and failed-job count (no jobs are touched)';

    public function handle(): int
    {
        $default = (string) config('queue.default');
        $driver = (string) config("queue.connections.{$default}.driver");

        $report = [
            'connection' => $default,
            'driver' => $driver,
            'queues' => $this->queues($default, $driver),
            'failed_jobs' => $this->failed(),
        ];

        $readFailed = ($report['queues']['available'] ?? true) === false && ($report['queues']['read_error'] ?? false);

        if ($this->option('json')) {
            $this->line(json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return $readFailed ? self::FAILURE : self::SUCCESS;
        }

        $this->line("Queue status — connection: {$default} (driver: {$driver})");
        $q = $report['queues'];
        if (! $q['available']) {
            $this->line("  queue depth:   unavailable ({$q['reason']})");
        } elseif ($q['rows'] === []) {
            $this->line('  queue depth:   0 (no jobs on any queue)');
        } else {
            $this->table(
                ['queue', 'ready', 'delayed', 'reserved', 'oldest ready (s)'],
                array_map(fn ($r) => [$r['queue'], $r['ready'], $r['delayed'], $r['reserved'], $r['oldest_ready_age_seconds'] ?? '-'], $q['rows']),
            );
        }
        $f = $report['failed_jobs'];
        $this->line('  failed jobs:   '.($f['available'] ? $f['count'] : "unavailable ({$f['reason']})"));

        return $readFailed ? self::FAILURE : self::SUCCESS;
    }

    /** @return array<string, mixed> */
    private function queues(string $default, string $driver): array
    {
        if ($driver !== 'database') {
            return ['available' => false, 'reason' => "driver '{$driver}' is not inspected by this command", 'rows' => null];
        }

        try {
            $now = time();
            $conn = DB::connection(config("queue.connections.{$default}.connection") ?: null);
            $table = (string) config("queue.connections.{$default}.table", 'jobs');

            // aliases go through the grammar's quoting: `delayed` is a reserved word on MySQL/MariaDB
            $q = fn (string $name): string => $conn->getQueryGrammar()->wrap($name);

            $rows = $conn->table($table)
                ->selectRaw($q('queue').', '
                    .'SUM(CASE WHEN reserved_at IS NULL AND available_at <= ? THEN 1 ELSE 0 END) AS '.$q('ready').', '
                    .'SUM(CASE WHEN reserved_at IS NULL AND available_at > ? THEN 1 ELSE 0 END) AS '.$q('delayed').', '
                    .'SUM(CASE WHEN reserved_at IS NOT NULL THEN 1 ELSE 0 END) AS '.$q('reserved').', '
                    .'MIN(CASE WHEN reserved_at IS NULL AND available_at <= ? THEN available_at END) AS '.$q('oldest_due'), [$now, $now, $now])
                ->groupBy('queue')
                ->orderBy('queue')
                ->get();
        } catch (Throwable $e) {
            return ['available' => false, 'read_error' => true, 'reason' => 'the jobs table could not be read ('.class_basename($e).')', 'rows' => null];
        }

        return [
            'available' => true,
            'rows' => $rows->map(fn ($r) => [
                'queue' => (string) $r->queue,
                'ready' => (int) $r->ready,
                'delayed' => (int) $r->delayed,
                'reserved' => (int) $r->reserved,
                // null (not 0) when nothing is ready: "no waiting job" is not "a job waited 0 seconds"
                'oldest_ready_age_seconds' => $r->oldest_due !== null ? max(0, $now - (int) $r->oldest_due) : null,
            ])->all(),
        ];
    }

    /** @return array<string, mixed> */
    private function failed(): array
    {
        $driver = (string) config('queue.failed.driver');
        if (! in_array($driver, ['database', 'database-uuids'], true)) {
            return ['available' => false, 'count' => null, 'reason' => "failed-job driver '{$driver}' is not inspected by this command"];
        }

        try {
            $count = DB::connection(config('queue.failed.database') ?: null)->table((string) config('queue.failed.table', 'failed_jobs'))->count();
        } catch (Throwable $e) {
            return ['available' => false, 'count' => null, 'reason' => 'the failed_jobs table could not be read ('.class_basename($e).')'];
        }

        return ['available' => true, 'count' => $count];
    }
}
