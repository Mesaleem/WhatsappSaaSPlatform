<?php

namespace App\Services\Billing;

use App\Models\Account;
use App\Models\Invoice;
use App\Models\Plan;
use Illuminate\Support\Collection;

/**
 * Plan purchase rules (owner, 2026-10-14) — the ONE place that decides what an account
 * may buy, used by the plans list (which button each plan shows) and by both checkouts
 * (online create-order and manual invoice request), so the UI and the server agree.
 *
 *   one request at a time  a pending plan invoice must be paid or cancelled before
 *                          another plan is requested (any plan)
 *   no plan running        any plan                                    -> `new`
 *   plan running, same     refused: renew only after it ends           -> `renew_after_expiry`
 *   plan running, higher   allowed, with the warning that the running
 *                          plan ends at payment                        -> `upgrade`
 *   plan running, lower    refused until the running plan ends         -> `downgrade_after_expiry`
 *   plan ended, same       allowed                                     -> `renew`
 *
 * "Higher / lower" compares the plans' current monthly prices. The running plan is the
 * one of the latest PAID plan invoice; a plan set by hand (no invoice) is unknown, so
 * any plan counts as an upgrade there.
 */
class PlanChangePolicy
{
    public function __construct(
        private readonly PlanRepository $plans,
        private readonly ManualPaymentService $payments,
    ) {
    }

    /**
     * @return array{running: bool, expires_at: ?string, plan_key: ?string, plan_label: ?string, pending: ?Invoice}
     */
    public function state(Account $account): array
    {
        $subscription = $account->currentSubscription;
        $running = $subscription !== null && $subscription->status === 'active'
            && ($subscription->expires_at === null || $subscription->expires_at->isFuture());

        $planInvoices = Invoice::query()->where('account_id', $account->id)->orderByDesc('id')->get()
            ->filter(fn (Invoice $i) => $this->payments->kindOf($i) === 'plan');

        // The last plan paid for: the running plan when one runs, else the one that ended
        // (so buying it again is a renewal).
        $current = $planInvoices->first(fn (Invoice $i) => $i->status === 'paid');
        $pending = $planInvoices->first(fn (Invoice $i) => $i->status === 'pending');

        return [
            'running' => $running,
            'expires_at' => $subscription?->expires_at?->toIso8601String(),
            'plan_key' => $current?->plan_key,
            'plan_label' => $current?->plan_label,
            'pending' => $pending,
        ];
    }

    /**
     * What buying $plan would be right now.
     *
     * @return array{action: string, allowed: bool, message: ?string}
     */
    public function decide(Account $account, Plan $plan, ?array $state = null): array
    {
        $state ??= $this->state($account);

        if ($state['pending'] !== null) {
            return ['action' => 'pending', 'allowed' => false, 'message' => 'You already requested the '.$state['pending']->plan_label.' plan. Pay that invoice or cancel the request first.'];
        }
        if (! $state['running']) {
            return ['action' => $state['plan_key'] === $plan->slug ? 'renew' : 'new', 'allowed' => true, 'message' => null];
        }

        $until = $state['expires_at'] ? date('d M Y', strtotime($state['expires_at'])) : 'it ends';
        if ($state['plan_key'] === $plan->slug) {
            return ['action' => 'renew_after_expiry', 'allowed' => false, 'message' => "This plan is already running until {$until}. You can renew it after it ends."];
        }

        $currentPlan = $state['plan_key'] ? $this->plans->findPurchasable($state['plan_key']) : null;
        if ($currentPlan !== null && (float) $plan->price <= (float) $currentPlan->price) {
            return ['action' => 'downgrade_after_expiry', 'allowed' => false, 'message' => "Your current plan runs until {$until}. A smaller plan can be chosen after it ends."];
        }

        return [
            'action' => 'upgrade',
            'allowed' => true,
            'message' => "Upgrading ends your current plan as soon as this payment is made (it was valid until {$until}); the new plan starts then. To keep the current plan to its end, upgrade after it ends.",
        ];
    }

    /** The plan_change stored on a new invoice for an allowed decision. */
    public static function invoiceChange(string $action): string
    {
        return in_array($action, ['upgrade', 'renew'], true) ? $action : 'new';
    }

    /** @return Collection<int, array{key: string, action: string, allowed: bool, message: ?string}> */
    public function forAllPlans(Account $account): Collection
    {
        $state = $this->state($account);

        return $this->plans->purchasable()->map(fn (Plan $p) => ['key' => $p->slug] + $this->decide($account, $p, $state))->values();
    }
}
