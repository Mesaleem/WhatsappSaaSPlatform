<?php

namespace App\Services;

use App\Models\Account;
use Illuminate\Validation\ValidationException;

/**
 * 3-Tier Hierarchy & Agent-Client Scope Engine (Phase 4) — Agent Quota
 * Pool & Allocation.
 *
 * Model: an Agent's own current_subscription.total_allocated_messages is
 * treated as a shared CAPACITY pool that its Sub-Clients' individual
 * fixed allocations are drawn from. This validates allocation (capacity
 * handed out), not usage — nothing here changes how used_messages is
 * debited anywhere else in the app; a Sub-Client can still be exhausted
 * or under-used independently of this check.
 *
 * [Disclosed, scoped]: only meaningful against Sub-Clients on a numeric
 * (non-'unlimited') quota. A Sub-Client already set to 'unlimited'
 * billing (total_allocated_messages === null, via the pre-existing
 * broader PUT .../subscription endpoint, unchanged by this phase) is
 * excluded from the "already committed" sum below rather than treated
 * as consuming the whole pool — this endpoint never creates that
 * situation itself (AccountController::updateQuota() only ever writes a
 * required positive integer), so it is a pre-existing modeling gap this
 * phase surfaces but does not attempt to solve; flagged here rather than
 * silently pretending an unlimited Sub-Client is pool-bounded.
 */
class QuotaService
{
    /**
     * Sum of every OTHER Sub-Client's current numeric allocation under
     * this Agent (excludes $excludeAccountId — the Sub-Client actually
     * being edited, since its existing allocation is being replaced, not
     * added to).
     */
    private function othersAllocated(Account $agentAccount, ?int $excludeAccountId): int
    {
        // [Bugfix, found via isolated functional testing before this ever
        // reached the user]: an earlier version of this method combined
        // whereHas('currentSubscription', ...) + a column-restricted
        // with(['currentSubscription:id,account_id,...']) — Subscription::
        // currentSubscription() is a hasOne(...)->ofMany('starts_at','max'),
        // whose own query is already a multi-level self-join/subquery on
        // account_id; layering whereHas()'s EXISTS-subquery construction
        // and a column-restricted eager load on top of that produced an
        // "ambiguous column name: account_id" SQL error on sqlite (the
        // ofMany subquery's own internal aliasing collided with it) —
        // confirmed via a real isolated migrate+seed+tinker-style run, not
        // assumed. Filtering in PHP after a plain, unrestricted eager load
        // sidesteps the whole problem; the extra rows fetched are not a
        // real cost at Sub-Client-per-Agent scale.
        return Account::query()
            ->where('agent_id', $agentAccount->id)
            ->when($excludeAccountId !== null, fn ($q) => $q->where('id', '!=', $excludeAccountId))
            ->with('currentSubscription')
            ->get()
            ->reduce(function (int $sum, Account $a) {
                $subscription = $a->currentSubscription;

                if ($subscription && $subscription->billing_model !== 'unlimited' && $subscription->total_allocated_messages !== null) {
                    return $sum + $subscription->total_allocated_messages;
                }

                return $sum;
            }, 0);
    }

    /**
     * @throws ValidationException (renders as HTTP 422 automatically —
     *         Laravel's default exception handler converts any
     *         ValidationException to a 422 JSON {message, errors} body
     *         for an API request, whether it came from $request->validate()
     *         or, as here, was thrown directly) when $requestedTotal would
     *         push this Agent's Sub-Clients' combined numeric allocation
     *         past the Agent's own pool.
     */
    public function assertWithinPool(Account $agentAccount, int $requestedTotal, ?int $excludeAccountId): void
    {
        $agentSubscription = $agentAccount->currentSubscription;

        // No pool ceiling to check against — mirrors Subscription::
        // remainingQuota()'s own "unlimited, or capped with no cap set
        // yet, means no ceiling" convention exactly.
        if (! $agentSubscription || $agentSubscription->billing_model === 'unlimited' || $agentSubscription->total_allocated_messages === null) {
            return;
        }

        $poolLimit = $agentSubscription->total_allocated_messages;
        $othersAllocated = $this->othersAllocated($agentAccount, $excludeAccountId);

        if ($othersAllocated + $requestedTotal > $poolLimit) {
            throw ValidationException::withMessages([
                'total_allocated_messages' => [sprintf(
                    'This would allocate %s messages, exceeding your agent pool: %s of %s already committed to your other Sub-Clients, leaving only %s unallocated.',
                    number_format($requestedTotal),
                    number_format($othersAllocated),
                    number_format($poolLimit),
                    number_format(max(0, $poolLimit - $othersAllocated)),
                )],
            ]);
        }
    }

    /**
     * Remaining unallocated pool for display (frontend's "Remaining
     * Unallocated Agent Pool"). Null = no ceiling (Agent's own pool is
     * 'unlimited' or has no cap set).
     */
    public function remainingPool(Account $agentAccount, ?int $excludeAccountId = null): ?int
    {
        $agentSubscription = $agentAccount->currentSubscription;

        if (! $agentSubscription || $agentSubscription->billing_model === 'unlimited' || $agentSubscription->total_allocated_messages === null) {
            return null;
        }

        return max(0, $agentSubscription->total_allocated_messages - $this->othersAllocated($agentAccount, $excludeAccountId));
    }
}
