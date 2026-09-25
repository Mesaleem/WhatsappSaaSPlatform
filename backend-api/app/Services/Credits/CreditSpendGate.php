<?php

namespace App\Services\Credits;

use App\Models\Account;

/**
 * Phase 8 Task 3 — a PRODUCT precondition for committing credits (reserve /
 * direct spend), kept out of the generic ledger. CreditService calls it
 * inside the credit-account lock, AFTER the idempotency lookup (so a retry
 * of an already-applied request replays even if the account was blocked
 * since) and BEFORE anything is written.
 *
 * It answers only "may this account commit credits for this purpose?". It
 * must not check the balance (CreditService does that under the lock) and
 * must not write anything. It throws CreditException (entitlement_blocked /
 * account_blocked) to refuse.
 *
 * Settling or releasing an EXISTING reservation is never gated: that
 * accounts for work already done, or gives credits back.
 */
interface CreditSpendGate
{
    public function assertMaySpend(Account $account, int $amount): void;
}
