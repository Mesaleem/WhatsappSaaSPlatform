<?php

namespace App\Services\Credits;

use App\Models\Account;
use App\Services\Access\AccessControlService;

/**
 * Phase 8 Task 2 — the relationship between the `ai` CAPABILITY and CREDITS.
 *
 * Two different questions, both required, never merged:
 *
 *   capability  "may this account use AI at all?"   account_entitlements
 *               (AccessControlService::canTenant(account, 'ai')) — what the
 *               plan sells or a Super Admin/Agent grants.
 *   credits     "does it have credits available?"   the Task 1 ledger
 *               (CreditService::balance()['available']).
 *
 * `credits > 0` is NOT an AI entitlement: an account can hold credits
 * without the capability (granted manually, or kept after a downgrade that
 * revoked `ai`) and an AI-entitled account can have zero credits. A future
 * AI feature (Task 3+) must pass `usable()` — it adds the existing account
 * gates the rest of the platform already applies to paid features: the
 * account is administratively active and its subscription is current
 * (not expired — an exhausted MESSAGE quota does not count; expired or
 * suspended → not usable; credits are KEPT, never
 * removed, and become usable again on reactivation/renewal).
 *
 * This class only answers questions. It never grants, consumes or reserves.
 *
 * Phase 8 Task 3 — it is also the AI CreditSpendGate: an AI caller passes it
 * to CreditConsumptionService::reserve()/spend(), which evaluates it inside
 * the credit lock. The gate is the PRODUCT half only (capability, account,
 * subscription); the FINANCIAL half (available credits) is decided by
 * CreditService under the lock (insufficient_credits).
 */
final class CreditEntitlementService implements CreditSpendGate
{
    public const CAPABILITY = 'ai';

    public function __construct(
        private readonly AccessControlService $access,
        private readonly CreditService $credits,
    ) {
    }

    /**
     * @return array{ai_capability: bool, account_active: bool, subscription_active: bool, balance: int, reserved: int, available: int, can_use_ai_credits: bool, reason: ?string}
     */
    public function status(Account $account): array
    {
        [$capability, $accountActive, $subscriptionActive] = $this->productState($account);
        $balance = $this->credits->balance($account);

        $reason = match (true) {
            ! $capability => 'ai_capability_missing',
            ! $accountActive => 'account_suspended',
            ! $subscriptionActive => 'subscription_inactive',
            $balance['available'] < 1 => 'no_available_credits',
            default => null,
        };

        return [
            'ai_capability' => $capability,
            'account_active' => $accountActive,
            'subscription_active' => $subscriptionActive,
        ] + $balance + [
            'can_use_ai_credits' => $reason === null,
            'reason' => $reason,
        ];
    }

    /**
     * CreditSpendGate — the product half of "may this account spend AI
     * credits". Reads the CURRENT account row (a caller's model may predate
     * a suspension). Never checks the balance, never writes.
     */
    public function assertMaySpend(Account $account, int $amount): void
    {
        [$capability, $accountActive, $subscriptionActive] = $this->productState(Account::query()->findOrFail($account->id));

        if (! $capability) {
            throw CreditException::entitlementBlocked('ai_capability_missing');
        }

        if (! $accountActive) {
            throw CreditException::accountBlocked('account_suspended');
        }

        if (! $subscriptionActive) {
            throw CreditException::accountBlocked('subscription_inactive');
        }
    }

    /** @return array{0: bool, 1: bool, 2: bool} [ai capability, account active, subscription current] */
    private function productState(Account $account): array
    {
        $capability = $this->access->canTenant($account, self::CAPABILITY);
        $accountActive = $account->isAdministrativelyActive();
        // "Active" here = a current (unexpired) subscription. Deliberately NOT
        // hasActiveSubscription(): that also turns false when the MESSAGE
        // quota is exhausted, and running out of messages must not switch
        // AI credits off — they are a separate allowance.
        $subscription = $account->currentSubscription;
        $subscriptionActive = $subscription !== null && ($subscription->expires_at === null || $subscription->expires_at->isFuture());

        return [$capability, $accountActive, $subscriptionActive];
    }

    /** Both checks (+ account/subscription state) for $amount credits. */
    public function usable(Account $account, int $amount = 1): bool
    {
        $status = $this->status($account);

        return $status['ai_capability'] && $status['account_active'] && $status['subscription_active'] && $amount > 0 && $status['available'] >= $amount;
    }
}
