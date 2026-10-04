<?php

namespace App\Console\Commands;

use App\Services\Ops\RecoveryReadiness;
use App\Services\Ops\RestoreVerifier;
use Illuminate\Console\Command;
use RuntimeException;

/**
 * Phase 12 Task 6 — `php artisan ops:verify-restore --database=<throwaway>`: READ-ONLY verification that a database
 * restored from a backup is usable by this application (see App\Services\Ops\RestoreVerifier). It never writes,
 * never migrates, refuses any database that is not recognisably a throwaway one, refuses the application's own
 * database, and prints check names and counts only. Exit 0 = every check passed (warnings allowed); 1 = a check failed;
 * 2 = refused (bad target).
 */
class OpsVerifyRestore extends Command
{
    protected $signature = 'ops:verify-restore
        {--database= : Name of the throwaway database the backup was restored into (SQLite: the file path)}
        {--json : Machine-readable output}';

    protected $description = 'Read-only: verify that a backup restored into a THROWAWAY database is usable by the application';

    public function handle(RestoreVerifier $verifier): int
    {
        $database = (string) $this->option('database');
        if ($database === '') {
            $this->error('Pass --database=<throwaway database name>. See docs/RELEASE_AND_RECOVERY.md.');

            return 2;
        }

        try {
            $checks = $verifier->verify($database);
        } catch (RuntimeException $e) {
            $this->error($e->getMessage());

            return 2;
        }

        $failed = RecoveryReadiness::failed($checks);

        if ($this->option('json')) {
            $this->line(json_encode(['ok' => ! $failed, 'checks' => $checks], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return $failed ? self::FAILURE : self::SUCCESS;
        }

        $this->line('Restore verification (read-only):');
        $this->table(['check', 'status', 'detail'], array_map(fn ($c) => [$c['name'], strtoupper($c['status']), $c['detail']], $checks));
        $failed ? $this->error('RESTORE VERIFICATION FAILED') : $this->info('Restore verification passed.');

        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
