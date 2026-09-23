<?php

namespace App\Jobs;

use App\Models\Account;
use App\Services\Access\PlanEntitlementReconciliationService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Phase 5 Task 10 — reconciles every account on a plan after that plan's
 * capability bundle changed.
 *
 * WHY A JOB: a plan edit is one administrator action that can change
 * authorization for an unbounded number of tenants. Task 10 forbids an
 * unbounded tenant loop inside an HTTP request, and this codebase
 * already uses queued jobs for exactly this shape of work (see
 * ProcessGroupDispatchJob and friends).
 *
 * PER-ACCOUNT ISOLATION: each account is reconciled in its own
 * transaction inside the service, and a failure on one account is logged
 * and stepped over rather than aborting the run. A half-finished fleet
 * reconciliation is recoverable (the service is idempotent — re-running
 * finishes the job); a fleet-wide abort on one bad row is not.
 *
 * The candidate set is a superset by design; reconcile() re-resolves
 * each account's own current plan, so an account that has since moved
 * to another plan is reconciled against that one instead. See
 * PlanEntitlementReconciliationService::candidateAccountIdsForPlan().
 */
class ReconcilePlanAccountsJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public function __construct(
        private readonly string $planSlug,
        private readonly ?int $actorUserId = null,
    ) {
    }

    public function handle(PlanEntitlementReconciliationService $reconciler): void
    {
        $candidates = $reconciler->candidateAccountIdsForPlan($this->planSlug);

        $granted = 0;
        $revoked = 0;
        $restored = 0;
        $failed = 0;

        foreach ($candidates->chunk(100) as $chunk) {
            foreach (Account::whereIn('id', $chunk)->get() as $account) {
                try {
                    $result = $reconciler->reconcile($account, $this->actorUserId);

                    $granted += count($result['granted']);
                    $revoked += count($result['revoked']);
                    $restored += count($result['restored']);
                } catch (Throwable $e) {
                    $failed++;
                    // Account id only — operational data, never tenant
                    // content and never a credential.
                    Log::error("ReconcilePlanAccountsJob: account #{$account->id} failed: {$e->getMessage()}");
                }
            }
        }

        Log::info('ReconcilePlanAccountsJob completed.', [
            'plan' => $this->planSlug,
            'accounts_considered' => $candidates->count(),
            'granted' => $granted,
            'revoked' => $revoked,
            'restored' => $restored,
            'failed' => $failed,
        ]);
    }
}
