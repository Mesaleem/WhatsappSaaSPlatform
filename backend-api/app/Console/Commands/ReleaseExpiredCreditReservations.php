<?php

namespace App\Console\Commands;

use App\Services\Credits\CreditConsumptionService;
use Illuminate\Console\Command;

/**
 * Phase 8 Task 3 — release credit reservations whose caller-set expires_at
 * has passed (a caller that crashed between reserve and settle must not
 * strand credits). Scheduled every five minutes (routes/console.php).
 *
 *   php artisan credits:release-expired-reservations [--dry-run] [--limit=500]
 *
 * Only reservations that are still OPEN and carry an expires_at in the past
 * are touched; reservations without expires_at (NULL = never) are never
 * read. Each release is an ordinary CreditService release under the
 * credit-account lock with the derived key release:{id}, so an overlapping
 * run, a caller's own release or a concurrent settle can never produce a
 * second effect. This is NOT credit expiry or rollover: balances are only
 * ever un-held, never removed.
 */
class ReleaseExpiredCreditReservations extends Command
{
    protected $signature = 'credits:release-expired-reservations
        {--dry-run : Report how many are due without releasing anything}
        {--limit=500 : Maximum reservations handled in one run}';

    protected $description = 'Release open credit reservations whose expiry has passed (idempotent, concurrency-safe).';

    public function handle(CreditConsumptionService $consumption): int
    {
        $limit = (int) $this->option('limit');

        if ($limit < 1) {
            $this->error('--limit must be at least 1.');

            return self::INVALID;
        }

        $stats = $consumption->releaseExpired($limit, (bool) $this->option('dry-run'));

        if ($this->option('dry-run')) {
            $this->warn('DRY RUN — nothing was released.');
        }

        $this->table(['due', 'released', 'skipped'], [[$stats['due'], $stats['released'], $stats['skipped']]]);

        return self::SUCCESS;
    }
}
