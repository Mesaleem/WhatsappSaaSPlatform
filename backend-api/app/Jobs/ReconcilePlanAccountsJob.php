<?php

namespace App\Jobs;

use App\Models\Account;
use App\Services\Access\PlanEntitlementReconciliationService;
use App\Support\EntitlementAuditContext;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
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
class ReconcilePlanAccountsJob implements ShouldQueue, ShouldBeUniqueUntilProcessing
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    /**
     * F-5.1 — the named queue this job runs on.
     *
     * Production workers in this codebase are started per named queue
     * (routes/console.php: whatsapp-bulk, journeys, knowledge, social); a
     * job pushed to the unnamed `default` queue has no worker and stays
     * pending forever. This queue has its own worker line there. The
     * connection is NOT forced: the job follows QUEUE_CONNECTION (database,
     * redis, …), and under `sync` (local development, the test suite) it
     * runs inline exactly as before.
     */
    public const QUEUE = 'plan-reconciliation';

    /** Re-running is safe (the service is idempotent); retry a crashed run a few times, with a pause. */
    public int $tries = 3;

    /** @var array<int, int> */
    public array $backoff = [60, 300];

    /**
     * A whole-fleet run may take minutes; without this the worker's own
     * 60 s default would kill it part-way and mark it failed. Per-account
     * isolation already inside handle() keeps a partial run recoverable.
     */
    public int $timeout = 900;

    /**
     * No duplicate pending reconciliation for one plan. UNTIL PROCESSING:
     * the lock is released the moment a worker starts the job, so a plan
     * edited WHILE a run is in flight queues one more run (which re-reads
     * the then-current bundle) instead of being dropped.
     */
    public int $uniqueFor = 3600;

    public function __construct(
        private readonly string $planSlug,
        private readonly ?int $actorUserId = null,
    ) {
        $this->onConnection(self::resolveConnection());
        $this->onQueue(self::QUEUE);
    }

    /**
     * Phase 12 Task 1 — the connection this job (and its scheduled worker) uses. Still follows
     * QUEUE_CONNECTION, but the 900 s timeout exceeds a default connection's 90 s retry_after, so the
     * long-retry sibling is used when one is configured (`database` → `database_long`, `redis` →
     * `redis_long`). `sync` and any driver without a `_long` sibling keep the default connection, so
     * local development and the test suite still run inline exactly as before.
     */
    public static function resolveConnection(?string $default = null): string
    {
        $default ??= (string) config('queue.default');
        $long = $default.'_long';

        return config("queue.connections.{$long}") !== null ? $long : $default;
    }

    public function uniqueId(): string
    {
        return $this->planSlug;
    }

    public function failed(Throwable $e): void
    {
        // Plan slug + exception class only: operational data, never tenant content.
        Log::error('ReconcilePlanAccountsJob failed.', ['plan' => $this->planSlug, 'exception' => class_basename($e)]);
    }

    public function handle(PlanEntitlementReconciliationService $reconciler): void
    {
        $candidates = $reconciler->candidateAccountIdsForPlan($this->planSlug);

        $granted = 0;
        $revoked = 0;
        $restored = 0;
        $failed = 0;

        // F-5.2 — a queue worker has no authenticated user; label the audit
        // rows this run writes with the process that acted and the
        // administrator who triggered it (never as that user).
        EntitlementAuditContext::run('queue:plan_reconciliation', function () use ($candidates, $reconciler, &$granted, &$revoked, &$restored, &$failed) {
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
        }, $this->actorUserId);

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
