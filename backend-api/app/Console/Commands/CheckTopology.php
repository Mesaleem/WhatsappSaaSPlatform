<?php

namespace App\Console\Commands;

use App\Support\Topology\JobTimeoutInventory;
use Illuminate\Console\Command;
use Illuminate\Console\Scheduling\Schedule;

/**
 * Phase 12 Task 1 — `php artisan ops:check-topology`: a READ-ONLY report of configuration that only works on
 * one node. It never writes, migrates, connects to Redis, or changes any setting.
 *
 * Each check ends in one of:
 *   ok               — fine on one node and on many
 *   single-node-only — fine for the current single-node deployment, WRONG once several app/queue/scheduler
 *                      instances run (blocking in multi-instance mode)
 *   unsafe           — wrong even on one node (a configuration defect)
 *   info             — a fact worth knowing; never blocking
 *
 * Mode: `topology.multi_instance` (env TOPOLOGY_MULTI_INSTANCE) or `--multi-instance`. Exit code 1 when any
 * check is `unsafe`, or — in multi-instance mode — `single-node-only`; otherwise 0. No infrastructure is
 * required or recommended here beyond a cache and queue store the instances SHARE (the database works).
 */
class CheckTopology extends Command
{
    protected $signature = 'ops:check-topology
        {--multi-instance : Evaluate as a multi-instance deployment (default: config topology.multi_instance / TOPOLOGY_MULTI_INSTANCE)}
        {--json : Machine-readable output}';

    protected $description = 'Read-only: report configuration that is safe on one node but unsafe with several app/queue/scheduler instances';

    /** Cache/session/failed-job drivers whose state lives in one process or one machine's disk. */
    private const NODE_LOCAL_CACHE = ['array', 'file', 'null'];

    private const NODE_LOCAL_SESSION = ['array', 'file'];

    public function handle(Schedule $schedule, JobTimeoutInventory $inventory): int
    {
        $multi = (bool) $this->option('multi-instance') || (bool) config('topology.multi_instance');

        $checks = [
            $this->queueDriver(),
            $this->cacheStore(),
            $this->sessionDriver(),
            $this->failedJobs(),
            $this->mediaDisk(),
            $this->retrySafety($inventory),
            $this->schedulerSingletons($schedule),
            $this->qrEngine(),
            $this->logging(),
        ];

        $unsafe = array_values(array_filter($checks, fn ($c) => $c['status'] === 'unsafe'));
        $singleNode = array_values(array_filter($checks, fn ($c) => $c['status'] === 'single-node-only'));
        $blocking = $multi ? array_merge($unsafe, $singleNode) : $unsafe;

        $report = [
            'mode' => $multi ? 'multi-instance' : 'single-node',
            'safe_for_single_node' => $unsafe === [],
            'safe_for_multi_instance' => $unsafe === [] && $singleNode === [],
            'blocking' => count($blocking),
            'checks' => $checks,
        ];

        if ($this->option('json')) {
            $this->line(json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return $blocking === [] ? self::SUCCESS : self::FAILURE;
        }

        $this->line('Topology check — mode: '.$report['mode'].($multi ? '' : ' (set TOPOLOGY_MULTI_INSTANCE=true or pass --multi-instance to evaluate for several instances)'));
        $this->newLine();
        foreach ($checks as $check) {
            $tag = match ($check['status']) {
                'ok' => '<info>[ok]              </info>',
                'single-node-only' => $multi ? '<error>[single-node-only]</error>' : '<comment>[single-node-only]</comment>',
                'unsafe' => '<error>[unsafe]           </error>',
                default => '[info]             ',
            };
            $this->line("{$tag} {$check['label']}: {$check['value']}");
            if ($check['detail'] !== '') {
                $this->line('                      '.$check['detail']);
            }
        }
        $this->newLine();
        $this->line('Safe for the current single-node deployment: '.($report['safe_for_single_node'] ? 'yes' : 'NO'));
        $this->line('Safe for multi-instance: '.($report['safe_for_multi_instance'] ? 'yes' : 'NO ('.count($unsafe).' unsafe, '.count($singleNode).' single-node-only)'));

        return $blocking === [] ? self::SUCCESS : self::FAILURE;
    }

    /** @return array{id: string, label: string, value: string, status: string, detail: string} */
    private function check(string $id, string $label, string $value, string $status, string $detail = ''): array
    {
        return compact('id', 'label', 'value', 'status', 'detail');
    }

    private function queueDriver(): array
    {
        $name = (string) config('queue.default');
        $driver = (string) config("queue.connections.{$name}.driver", 'unknown');

        if ($driver === 'sync') {
            return $this->check('queue.default', 'Default queue connection', "{$name} (driver sync)", 'single-node-only',
                'Jobs run inline inside the request and queue delays are ignored (pacing/jitter disappear). Use database or redis plus a worker for production (README §7.1).');
        }
        if ($driver === 'unknown') {
            return $this->check('queue.default', 'Default queue connection', "{$name} (not defined in config/queue.php)", 'unsafe');
        }

        return $this->check('queue.default', 'Default queue connection', "{$name} (driver {$driver})", 'ok',
            'Named queues (journeys, knowledge, social, whatsapp-bulk) always use the database connection regardless of this setting.');
    }

    private function cacheStore(): array
    {
        $store = (string) config('cache.default');
        $driver = (string) config("cache.stores.{$store}.driver", 'unknown');
        $why = 'The scheduler locks (onOneServer, withoutOverlapping), rate limiters, Account::findCached and unique-job locks all live in this store; they only coordinate instances when it is shared.';

        if (in_array($driver, self::NODE_LOCAL_CACHE, true)) {
            return $this->check('cache.default', 'Cache store', "{$store} (driver {$driver})", 'single-node-only', $why.' Use database, redis or memcached.');
        }
        if ($driver === 'unknown') {
            return $this->check('cache.default', 'Cache store', "{$store} (not defined)", 'unsafe');
        }

        return $this->check('cache.default', 'Cache store', "{$store} (driver {$driver})", 'ok');
    }

    private function sessionDriver(): array
    {
        $driver = (string) config('session.driver');

        if (in_array($driver, self::NODE_LOCAL_SESSION, true)) {
            return $this->check('session.driver', 'Session driver', $driver, 'single-node-only',
                'Sessions are kept in this process / on this disk. The SPA authenticates with bearer tokens, so only web/session routes are affected; use database, redis or cookie.');
        }

        return $this->check('session.driver', 'Session driver', $driver, 'ok');
    }

    private function failedJobs(): array
    {
        $driver = (string) config('queue.failed.driver');

        if ($driver === 'file') {
            return $this->check('queue.failed', 'Failed-job store', 'file', 'single-node-only', 'Failed jobs would be written to one machine\'s disk; use database-uuids.');
        }

        return $this->check('queue.failed', 'Failed-job store', $driver === '' ? 'null (failures are not recorded)' : $driver, $driver === '' || $driver === 'null' ? 'info' : 'ok');
    }

    private function mediaDisk(): array
    {
        $name = (string) config('social.media.disk', 'public');
        $disk = config("filesystems.disks.{$name}");

        if (! is_array($disk)) {
            return $this->check('social.media.disk', 'Social upload disk', "{$name} (not defined in config/filesystems.php)", 'unsafe');
        }
        $driver = (string) ($disk['driver'] ?? 'unknown');
        if ($driver === 'local') {
            return $this->check('social.media.disk', 'Social upload disk', "{$name} (driver local, root ".($disk['root'] ?? '?').')', 'single-node-only',
                'Uploaded creatives sit on this machine\'s disk, so another instance cannot serve them. Point SOCIAL_MEDIA_DISK at a shared disk, or mount the same volume on every instance and accept this finding.');
        }

        return $this->check('social.media.disk', 'Social upload disk', "{$name} (driver {$driver})", 'ok');
    }

    private function retrySafety(JobTimeoutInventory $inventory): array
    {
        $violations = $inventory->violations();
        if ($violations === []) {
            return $this->check('queue.retry_after', 'Job timeout vs queue retry_after', count($inventory->jobClasses()).' queued jobs checked', 'ok',
                'Every job timeout is below the retry_after of the connection it runs on.');
        }
        $list = implode('; ', array_map(fn ($v) => "{$v['job']} timeout {$v['timeout']}s on {$v['connection']} retry_after ".($v['retry_after'] ?? 'unset'), $violations));

        return $this->check('queue.retry_after', 'Job timeout vs queue retry_after', count($violations).' violation(s)', 'unsafe',
            $list.' — a second worker could run the job again while it is still running.');
    }

    private function schedulerSingletons(Schedule $schedule): array
    {
        $unguarded = [];
        foreach ($schedule->events() as $event) {
            if (str_contains((string) $event->command, 'queue:work')) {
                continue; // queue drains are parallel-safe by design (see routes/console.php)
            }
            if (! $event->onOneServer) {
                $unguarded[] = trim(preg_replace('/^.*artisan[\'"]?\s+/', '', (string) $event->command));
            }
        }
        if ($unguarded === []) {
            return $this->check('schedule.one_server', 'Scheduled singleton commands', 'all use onOneServer()', 'ok', 'Needs the shared cache store checked above to take effect.');
        }

        return $this->check('schedule.one_server', 'Scheduled singleton commands', count($unguarded).' without onOneServer()', 'single-node-only', implode(', ', $unguarded));
    }

    private function qrEngine(): array
    {
        return $this->check('qr_engine', 'QR / Baileys engine', (string) config('services.qr_engine.url'), 'info',
            'Single instance by design: sockets live in that process\'s memory and credentials in its local sessions/ directory. API, queue and scheduler instances may all point at the one engine; do not run two. Not evaluated by this command.');
    }

    private function logging(): array
    {
        return $this->check('logging', 'Log channel', (string) config('logging.default'), 'info',
            'Default channels write to each instance\'s own storage/logs; aggregation is out of scope for this check.');
    }
}
