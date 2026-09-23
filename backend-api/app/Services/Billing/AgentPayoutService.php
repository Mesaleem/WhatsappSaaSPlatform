<?php

namespace App\Services\Billing;

use App\Models\AgentCommission;
use App\Models\AgentCommissionPayout;
use App\Models\AgentCommissionPayoutItem;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Internal Agent Commission payout/settlement ledger. Deliberately no
 * gateway/bank/UPI integration and no automatic scheduling — every
 * payout is created and moved through its lifecycle exclusively by a
 * Super Admin action (BillingController::storePayout()/
 * updatePayoutStatus()), the same "internal ledger only" scope
 * InvoiceCreditService's own commission generation already models for
 * the commission side.
 */
class AgentPayoutService
{
    /**
     * Creates one payout batch for $agentAccountId out of $commissionIds.
     * Only commissions that are (a) actually owned by this Agent, (b)
     * currently 'confirmed' (never 'reversed'), and (c) not already
     * claimed by a pending/processing/paid payout are included — any
     * other id in $commissionIds is silently dropped rather than
     * trusted, mirroring how InvoiceCreditService's own
     * grantAgentCommission()/reverseAgentCommission() treat an
     * unresolvable id as a no-op rather than an error. Locks every
     * candidate commission row (lockForUpdate) for the duration of the
     * transaction so two concurrent payout requests can never both claim
     * the same commission.
     *
     * @param  list<int>  $commissionIds
     * @return AgentCommissionPayout|null null if no eligible commission
     *         remained after filtering (nothing to pay out).
     */
    public function createPayout(int $agentAccountId, array $commissionIds, ?string $note = null): ?AgentCommissionPayout
    {
        return DB::transaction(function () use ($agentAccountId, $commissionIds, $note) {
            $eligible = $this->lockEligibleCommissions($agentAccountId, $commissionIds);

            if ($eligible->isEmpty()) {
                return null;
            }

            $gross = $eligible->sum(fn (AgentCommission $c) => (float) $c->amount);

            $payout = AgentCommissionPayout::create([
                'agent_account_id' => $agentAccountId,
                'status' => 'pending',
                'gross_amount' => $gross,
                'net_amount' => $gross,
                'note' => $note,
            ]);

            foreach ($eligible as $commission) {
                AgentCommissionPayoutItem::create([
                    'agent_commission_payout_id' => $payout->id,
                    'agent_commission_id' => $commission->id,
                    'amount_snapshot' => $commission->amount,
                ]);
            }

            return $payout;
        });
    }

    /**
     * Moves $payoutId to $status. Terminal statuses (paid/failed/
     * cancelled) are final — once reached, a second call is a no-op
     * (returns false), the same lockForUpdate + current-state re-check
     * idempotency idiom InvoiceCreditService::reverseAgentCommission()
     * already uses. $reference/$note are only ever written when
     * provided; paid_at is stamped only on a transition INTO 'paid'.
     */
    public function updateStatus(int $payoutId, string $status, ?string $reference = null, ?string $note = null): bool
    {
        return DB::transaction(function () use ($payoutId, $status, $reference, $note) {
            $payout = AgentCommissionPayout::query()->lockForUpdate()->find($payoutId);

            if (! $payout) {
                return false;
            }

            if (in_array($payout->status, AgentCommissionPayout::TERMINAL_STATUSES, true)) {
                return false;
            }

            $payout->status = $status;

            if ($reference !== null) {
                $payout->payout_reference = $reference;
            }

            if ($note !== null) {
                $payout->note = $note;
            }

            if ($status === 'paid') {
                $payout->paid_at = now();
            }

            $payout->save();

            return true;
        });
    }

    /**
     * @param  list<int>  $commissionIds
     * @return Collection<int, AgentCommission>
     */
    private function lockEligibleCommissions(int $agentAccountId, array $commissionIds): Collection
    {
        if (empty($commissionIds)) {
            return collect();
        }

        $candidates = AgentCommission::query()
            ->lockForUpdate()
            ->where('agent_account_id', $agentAccountId)
            ->where('status', 'confirmed')
            ->whereIn('id', $commissionIds)
            ->get();

        if ($candidates->isEmpty()) {
            return $candidates;
        }

        // Exclude any commission already claimed by a payout that is
        // still pending/processing, or already paid — a failed/cancelled
        // payout's items are ignored, releasing that commission back to
        // the eligible pool (see the payout_items migration's docblock).
        $lockedCommissionIds = AgentCommissionPayoutItem::query()
            ->whereIn('agent_commission_id', $candidates->pluck('id'))
            ->whereHas('payout', fn ($q) => $q->whereIn('status', ['pending', 'processing', 'paid']))
            ->pluck('agent_commission_id')
            ->all();

        return $candidates->reject(fn (AgentCommission $c) => in_array($c->id, $lockedCommissionIds, true))->values();
    }
}
