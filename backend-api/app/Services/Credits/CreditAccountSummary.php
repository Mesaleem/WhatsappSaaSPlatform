<?php

namespace App\Services\Credits;

use App\Models\Account;
use App\Models\Plan;
use App\Services\Access\PlanEntitlementReconciliationService;

/**
 * Phase 8 Task 2 — what an account (or an administrator looking at it) may
 * see about its credits: balances, the plan and period behind them, and the
 * two separate checks (AI capability, available credits). Read-only; no
 * ledger internals (idempotency keys, hashes) and nothing the caller could
 * submit back as a balance.
 */
final class CreditAccountSummary
{
    public function __construct(
        private readonly CreditEntitlementService $entitlement,
        private readonly PlanCreditAllocator $allocator,
        private readonly PlanEntitlementReconciliationService $plans,
    ) {
    }

    /** @return array<string, mixed> */
    public function for(Account $account): array
    {
        $status = $this->entitlement->status($account);
        $slug = $this->plans->currentPlanSlug($account);
        $plan = $slug ? Plan::where('slug', $slug)->first() : null;
        $subscription = $account->currentSubscription;
        $period = $this->allocator->currentPeriod($account);

        return [
            'account_id' => $account->id,
            'balance' => $status['balance'],
            'reserved' => $status['reserved'],
            'available' => $status['available'],
            'plan' => $plan ? [
                'slug' => $plan->slug,
                'label' => $plan->label,
                'included_credits' => (int) $plan->included_credits,
            ] : null,
            'subscription' => $subscription ? [
                'status' => $subscription->status,
                'starts_at' => $subscription->starts_at?->toIso8601String(),
                'expires_at' => $subscription->expires_at?->toIso8601String(),
            ] : null,
            'current_period' => $period ? [
                'starts_at' => $period->period_starts_at?->toIso8601String(),
                'ends_at' => $period->period_ends_at?->toIso8601String(),
                'allocated' => (int) $period->allocated,
            ] : null,
            'ai_capability' => $status['ai_capability'],
            'can_use_ai_credits' => $status['can_use_ai_credits'],
            'reason' => $status['reason'],
        ];
    }
}
