<?php

namespace App\Console\Commands;

use App\Services\Ops\RecoveryReadiness;
use Illuminate\Console\Command;

/**
 * Phase 12 Task 6 — `php artisan ops:check-recovery`: detects missing or invalid recovery configuration (APP_KEY,
 * APP_PREVIOUS_KEYS, the escrow fingerprint, provider credentials, the runbook, decryptability of stored secrets)
 * WITHOUT revealing any secret: output is variable names, statuses and counts. Read-only.
 * Exit 1 on any failure; --strict also fails on warnings.
 */
class OpsCheckRecovery extends Command
{
    protected $signature = 'ops:check-recovery
        {--strict : Treat warnings as failures}
        {--skip-database : Do not sample encrypted columns}
        {--json : Machine-readable output}';

    protected $description = 'Read-only: verify APP_KEY / provider-credential recovery configuration without printing any secret';

    public function handle(RecoveryReadiness $readiness): int
    {
        $checks = $readiness->report(! $this->option('skip-database'));
        $failed = RecoveryReadiness::failed($checks, (bool) $this->option('strict'));

        if ($this->option('json')) {
            $this->line(json_encode(['ok' => ! $failed, 'checks' => $checks], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return $failed ? self::FAILURE : self::SUCCESS;
        }

        $this->table(['check', 'status', 'detail'], array_map(fn ($c) => [$c['name'], strtoupper($c['status']), $c['detail']], $checks));
        $failed ? $this->error('Recovery configuration check FAILED') : $this->info('Recovery configuration check passed.');

        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
