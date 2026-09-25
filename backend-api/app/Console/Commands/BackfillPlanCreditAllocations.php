<?php

namespace App\Console\Commands;

use App\Models\Account;
use App\Models\CreditLedgerEntry;
use App\Models\Invoice;
use App\Models\Plan;
use App\Services\Credits\PlanCreditAllocator;
use Illuminate\Console\Command;
use Throwable;

/**
 * Phase 8 Task 2 — give EXISTING subscribers their current period's plan
 * credits, once. OWNER-RUN; never scheduled; dry run first.
 *
 *   php artisan credits:backfill-plan-allocation --dry-run
 *   php artisan credits:backfill-plan-allocation [--account=ID]
 *
 * For each account (id order): the latest PAID invoice of a real plan
 * (plan_key resolving to a `plans` row) is the period the subscription is
 * currently running on. If the subscription is still active, that period
 * [expires_at − duration_days, expires_at) is allocated the plan's
 * included_credits — the value captured on the invoice when it has one,
 * otherwise the plan's CURRENT value (the backfill is the owner's decision
 * to give existing subscribers what the plan now includes).
 *
 * Repeat-safe and race-safe with the live payment path: the allocation uses
 * the SAME idempotency key (plan-allocation:{subscription}:{invoice}), so an
 * invoice already allocated — by payment or by an earlier backfill run — is
 * reported as `already_allocated` and never granted twice. Purely additive:
 * manual grants, purchases, adjustments, refunds and earlier periods are not
 * read or changed. Suspended accounts are skipped (reported), as are expired
 * subscriptions, accounts without a paid plan invoice, and zero-credit plans.
 */
class BackfillPlanCreditAllocations extends Command
{
    protected $signature = 'credits:backfill-plan-allocation
        {--dry-run : Report what would be allocated without writing anything}
        {--account= : Restrict the run to a single account id}';

    protected $description = 'Allocate the current period\'s plan credits to existing subscribers (idempotent, additive, owner-run).';

    public function handle(PlanCreditAllocator $allocator): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $stats = [
            'accounts_processed' => 0, 'allocated' => 0, 'credits_allocated' => 0, 'already_allocated' => 0,
            'skipped_inactive_account' => 0, 'skipped_no_paid_plan_invoice' => 0, 'skipped_unknown_plan' => 0,
            'skipped_subscription_not_active' => 0, 'skipped_zero_credit_plan' => 0, 'errors' => 0,
        ];
        $rows = [];

        if ($dryRun) {
            $this->warn('DRY RUN — nothing will be written.');
        }

        $query = Account::query()->orderBy('id');

        if ($this->option('account')) {
            $query->whereKey((int) $this->option('account'));
        }

        $query->chunkById(100, function ($accounts) use ($allocator, $dryRun, &$stats, &$rows) {
            foreach ($accounts as $account) {
                $stats['accounts_processed']++;

                try {
                    [$outcome, $credits] = $this->backfillAccount($account, $allocator, $dryRun);
                } catch (Throwable $e) {
                    $stats['errors']++;
                    $this->error("Account #{$account->id}: ".class_basename($e).' — '.$e->getMessage());

                    continue;
                }

                $stats[$outcome]++;

                if ($outcome === 'allocated') {
                    $stats['credits_allocated'] += $credits;
                    $rows[] = [$account->id, $credits, $dryRun ? 'would allocate' : 'allocated'];
                }
            }
        });

        if ($rows !== []) {
            $this->table(['Account', 'Credits', 'Action'], $rows);
        }

        $this->table(['Metric', 'Count'], collect($stats)->map(fn ($v, $k) => [$k, $v])->values()->all());

        if ($dryRun) {
            $this->warn('DRY RUN complete — nothing was written.');
        }

        return $stats['errors'] === 0 ? self::SUCCESS : self::FAILURE;
    }

    /** @return array{0: string, 1: int} */
    private function backfillAccount(Account $account, PlanCreditAllocator $allocator, bool $dryRun): array
    {
        if (! $account->isAdministrativelyActive()) {
            return ['skipped_inactive_account', 0];
        }

        $planSlugs = Plan::query()->pluck('slug');
        $invoice = Invoice::forAccount($account->id)->where('status', 'paid')->whereIn('plan_key', $planSlugs)
            ->orderByDesc('paid_at')->orderByDesc('id')->first();

        if (! $invoice) {
            return ['skipped_no_paid_plan_invoice', 0];
        }

        $plan = Plan::where('slug', $invoice->plan_key)->first();
        $subscription = $account->currentSubscription;

        if (! $plan) {
            return ['skipped_unknown_plan', 0];
        }

        if (! $subscription || ! $subscription->isActive() || ! $subscription->expires_at || $subscription->expires_at->isPast()) {
            return ['skipped_subscription_not_active', 0];
        }

        $credits = $invoice->plan_included_credits !== null && (int) $invoice->plan_included_credits > 0
            ? (int) $invoice->plan_included_credits
            : (int) $plan->included_credits;

        if ($credits <= 0) {
            return ['skipped_zero_credit_plan', 0];
        }

        $alreadyAllocated = CreditLedgerEntry::query()->forAccount($account->id)
            ->where('idempotency_key', PlanCreditAllocator::key($subscription->id, $invoice->id))->exists();

        if ($alreadyAllocated) {
            return ['already_allocated', 0];
        }

        if ($dryRun) {
            return ['allocated', $credits];
        }

        $days = (int) ($invoice->plan_duration_days ?: $plan->duration_days);
        $end = $subscription->expires_at->copy();
        $result = $allocator->allocate($account, $subscription, $invoice, $credits, $end->copy()->subDays($days), $end, 'backfill');

        return [$result['replayed'] ? 'already_allocated' : 'allocated', $result['replayed'] ? 0 : $result['allocated']];
    }
}
