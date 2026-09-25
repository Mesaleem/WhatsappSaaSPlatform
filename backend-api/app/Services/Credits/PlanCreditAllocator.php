<?php

namespace App\Services\Credits;

use App\Models\Account;
use App\Models\Capability;
use App\Models\CreditLedgerEntry;
use App\Models\Invoice;
use App\Models\Subscription;
use App\Models\UsageQuota;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

/**
 * Phase 8 Task 2 — plan → credit allocation.
 *
 * BILLING-PERIOD MODEL (derived from the existing billing, not invented):
 * an account has ONE subscription row; every paid plan invoice buys one
 * period of `duration_days` and InvoiceCreditService stacks it on the
 * current expiry (renewal / upgrade / downgrade while active) or starts it
 * now (new / lapsed). So the billing period IS the paid invoice:
 *
 *   period_start = the expiry the invoice extended (or now)
 *   period_end   = the subscription's new expires_at
 *
 * One allocation per period = one allocation per (subscription, invoice):
 *
 *   idempotency key  plan-allocation:{subscription_id}:{invoice_id}
 *
 * unique per credit account in the ledger (Task 1), so a duplicate webhook,
 * a retried job, a backfill after the live path, or two processes at once
 * produce ONE `plan_allocation` entry. The period is recorded in the
 * existing Phase 1 `usage_quotas` table (capability `ai`, allocated, period
 * start/end, subscription_id, invoice_id, credit_ledger_entry_id UNIQUE), so
 * old periods stay auditable and every period can be reconstructed from the
 * ledger (reference_type invoice + metadata) alone.
 *
 * AMOUNT: the credits the invoice CAPTURED at order time
 * (invoices.plan_included_credits, P5-4 principle: an order is fulfilled with
 * the terms it was created with). A zero-credit plan allocates nothing and
 * records nothing. Nothing here reads a plan NAME or a provider.
 *
 * NEVER DESTRUCTIVE: an allocation only adds. Earlier allocations, manual
 * grants, purchases, adjustments and refunds are untouched by renewal, plan
 * change, expiry or suspension; there is no rollover logic and no credit
 * expiry (both deferred, see PROJECT_STATE).
 */
final class PlanCreditAllocator
{
    public const SOURCE = 'plan_allocation';

    public function __construct(private readonly CreditService $credits)
    {
    }

    public static function key(int $subscriptionId, int $invoiceId): string
    {
        return "plan-allocation:{$subscriptionId}:{$invoiceId}";
    }

    /**
     * Allocate one period's plan credits. Idempotent; safe inside the
     * payment-fulfilment transaction (it nests) and on its own.
     *
     * @return array{allocated: int, replayed: bool, entry: ?CreditLedgerEntry, quota: ?UsageQuota}
     */
    public function allocate(Account $account, Subscription $subscription, Invoice $invoice, int $credits, CarbonInterface $periodStart, CarbonInterface $periodEnd, string $origin = 'payment'): array
    {
        if ($credits <= 0) {
            return ['allocated' => 0, 'replayed' => false, 'entry' => null, 'quota' => null];
        }

        // Tenant safety: the credits go to the account that PAID the invoice
        // and owns the subscription — never to an Agent that manages it.
        if ((int) $invoice->account_id !== (int) $account->id || (int) $subscription->account_id !== (int) $account->id) {
            throw CreditException::invalid('An allocation must be for the account that owns the invoice and the subscription.');
        }

        return DB::transaction(function () use ($account, $subscription, $invoice, $credits, $periodStart, $periodEnd, $origin) {
            $result = $this->credits->grant($account, $credits, self::key($subscription->id, $invoice->id), [
                'reason' => "Plan '{$invoice->plan_key}' credits for {$periodStart->toDateString()} – {$periodEnd->toDateString()}",
                'reference_type' => 'invoice',
                'reference_id' => $invoice->id,
                'metadata' => [
                    'plan' => $invoice->plan_key,
                    'subscription_id' => $subscription->id,
                    'invoice_id' => $invoice->id,
                    'period_start' => $periodStart->toIso8601String(),
                    'period_end' => $periodEnd->toIso8601String(),
                    'origin' => $origin,
                ],
                'source' => CreditService::SOURCE_SYSTEM,
            ], CreditLedgerEntry::TYPE_PLAN_ALLOCATION);

            // The period row, keyed by the ledger entry (unique): created with
            // the first allocation, found again on any replay.
            // LOCKING read: a replay runs after the winner committed (the
            // credit account lock serializes them), but a plain read would
            // use this transaction's older snapshot and miss its row.
            $quota = UsageQuota::where('credit_ledger_entry_id', $result->entry->id)->lockForUpdate()->first()
                ?? UsageQuota::create(
                ['credit_ledger_entry_id' => $result->entry->id] + [
                    'account_id' => $account->id,
                    'capability_id' => Capability::where('slug', CreditEntitlementService::CAPABILITY)->value('id'),
                    'subscription_id' => $subscription->id,
                    'invoice_id' => $invoice->id,
                    'source' => self::SOURCE,
                    'allocated' => (int) $result->entry->amount,
                    'used' => 0,
                    'period_starts_at' => $periodStart,
                    'period_ends_at' => $periodEnd,
                ],
            );

            return ['allocated' => (int) $result->entry->amount, 'replayed' => $result->replayed, 'entry' => $result->entry, 'quota' => $quota];
        });
    }

    /** The allocation period covering $at for this account, if any. */
    public function currentPeriod(Account $account, ?CarbonInterface $at = null): ?UsageQuota
    {
        $at ??= now();

        return UsageQuota::query()
            ->where('account_id', $account->id)
            ->where('source', self::SOURCE)
            ->where('period_starts_at', '<=', $at)
            ->where('period_ends_at', '>', $at)
            ->orderByDesc('period_starts_at')
            ->orderByDesc('id')
            ->first();
    }
}
