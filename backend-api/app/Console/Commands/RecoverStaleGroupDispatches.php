<?php

namespace App\Console\Commands;

use App\Models\MessageDispatchLog;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Phase 5 fix P5-3 — recovery for a group batch whose job died without
 * settling it (worker killed, process crash, lost job). Scheduled every
 * five minutes (routes/console.php).
 *
 * A queued group batch is STALE when:
 *   - it is claimed and its owner has not heartbeated for
 *     MessageDispatchLog::GROUP_CLAIM_STALE_AFTER_SECONDS (900 s). A live
 *     owner heartbeats before every send (at most ~24 s apart) and no run
 *     may exceed GROUP_JOB_TIMEOUT_SECONDS (85 s); or
 *   - it is unclaimed (never started, or waiting for its next slice) and
 *     nothing has happened to it for GROUP_UNCLAIMED_STALE_AFTER_SECONDS
 *     (3600 s), which tolerates ordinary queue latency.
 *
 * A fresh queued batch is never touched. Each candidate is re-checked
 * under its row lock and settled through
 * MessageDispatchLog::settleGroupDispatchFromRecipients(): terminal status,
 * delivered counted from its recipient rows, refund = reserved - delivered
 * through the existing single resolution path. Running this twice changes
 * nothing the second time (the batch is no longer 'queued'), and a job
 * that arrives for a recovered batch cannot claim it, so it never sends.
 */
class RecoverStaleGroupDispatches extends Command
{
    protected $signature = 'group-dispatch:recover-stale {--limit=200 : Maximum batches to settle per run}';

    protected $description = 'Settle and refund group message batches whose job died without completing';

    public function handle(): int
    {
        $limit = max(1, (int) $this->option('limit'));

        $ids = MessageDispatchLog::query()
            ->staleGroupDispatches()
            ->orderBy('id')
            ->limit($limit)
            ->pluck('id');

        $settled = 0;

        foreach ($ids as $id) {
            $log = MessageDispatchLog::find($id);

            if ($log && $log->settleGroupDispatchFromRecipients(
                'Group batch was not completed by its job (worker stopped or job lost); settled by recovery.',
                onlyIfStale: true,
            )) {
                $settled++;

                Log::warning('Recovered a stale group dispatch.', [
                    'dispatch_log_id' => $log->id,
                    'account_id' => $log->account_id,
                    'reserved' => $log->recipient_count,
                    'delivered' => $log->success_count,
                    'refunded' => $log->failure_count,
                ]);
            }
        }

        $this->info("Settled {$settled} of {$ids->count()} stale group dispatch(es).");

        return self::SUCCESS;
    }
}
