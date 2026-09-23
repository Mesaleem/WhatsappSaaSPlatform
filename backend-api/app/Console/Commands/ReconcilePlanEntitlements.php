<?php

namespace App\Console\Commands;

use App\Models\Account;
use App\Services\Access\PlanEntitlementReconciliationService;
use Illuminate\Console\Command;
use Throwable;

/**
 * Phase 5 Task 10 — operator-facing plan entitlement reconciliation.
 *
 * Companion to `entitlements:backfill-plan` (Task 9), and deliberately
 * NOT a replacement for it. The backfill only ever ADDS what a plan
 * bundles; this reconciles in both directions, so it also revokes
 * source='plan' entitlements a plan no longer includes. They share one
 * implementation — PlanEntitlementReconciliationService — so the two
 * commands cannot drift apart.
 *
 *   php artisan entitlements:reconcile-plan                # every account
 *   php artisan entitlements:reconcile-plan growth         # accounts on a plan
 *   php artisan entitlements:reconcile-plan --account=42   # one account
 *   php artisan entitlements:reconcile-plan --dry-run      # report only
 */
class ReconcilePlanEntitlements extends Command
{
    protected $signature = 'entitlements:reconcile-plan
        {plan? : Restrict to accounts that have paid for this plan slug}
        {--account= : Restrict the run to a single account id}
        {--dry-run : Report what would change without writing anything}';

    protected $description = 'Reconcile source=plan entitlements against each account\'s current plan (grants, restores and revokes).';

    public function handle(PlanEntitlementReconciliationService $reconciler): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $planSlug = $this->argument('plan');
        $onlyAccount = $this->option('account');

        if ($dryRun) {
            $this->warn('DRY RUN — no rows will be written.');
        }

        $stats = [
            'accounts_processed' => 0,
            'accounts_skipped_no_paid_invoice' => 0,
            'accounts_skipped_inactive' => 0,
            'accounts_skipped_unknown_plan' => 0,
            'granted' => 0,
            'restored' => 0,
            'revoked' => 0,
            'skipped_provider_incompatible' => 0,
            'skipped_manual_revoked' => 0,
            'errors' => 0,
        ];

        $query = Account::query()->orderBy('id');

        if ($onlyAccount) {
            $query->where('id', (int) $onlyAccount);
        } elseif ($planSlug) {
            $query->whereIn('id', $reconciler->candidateAccountIdsForPlan($planSlug));
        }

        $query->chunkById(100, function ($accounts) use ($reconciler, $dryRun, &$stats) {
            foreach ($accounts as $account) {
                try {
                    $result = $reconciler->reconcile($account, null, $dryRun);

                    match ($result['reason']) {
                        'no_paid_invoice' => $stats['accounts_skipped_no_paid_invoice']++,
                        'account_inactive' => $stats['accounts_skipped_inactive']++,
                        'unknown_plan' => $stats['accounts_skipped_unknown_plan']++,
                        default => $stats['accounts_processed']++,
                    };

                    $stats['granted'] += count($result['granted']);
                    $stats['restored'] += count($result['restored']);
                    $stats['revoked'] += count($result['revoked']);
                    $stats['skipped_provider_incompatible'] += count($result['skipped_provider']);
                    $stats['skipped_manual_revoked'] += count($result['skipped_manual_revoked']);
                } catch (Throwable $e) {
                    $stats['errors']++;
                    // Account id only — no tenant content, no credentials.
                    $this->error("Account #{$account->id}: {$e->getMessage()}");
                }
            }
        });

        $this->newLine();
        $this->table(['Metric', 'Count'], collect($stats)->map(fn ($v, $k) => [$k, $v])->values()->all());

        if ($dryRun) {
            $this->warn('DRY RUN complete — nothing was written.');
        }

        return self::SUCCESS;
    }
}
