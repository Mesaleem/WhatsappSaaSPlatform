<?php

namespace App\Services\Credits;

use App\Models\CreditLedgerEntry;
use App\Models\CreditReservation;

/**
 * Phase 8 Task 1 — the outcome of one credit operation. `replayed` is true
 * when the idempotency key had already been applied: nothing new was
 * written and `entry` / `reservation` are the ORIGINAL ones.
 */
final class CreditOperationResult
{
    public function __construct(
        public readonly CreditLedgerEntry $entry,
        public readonly bool $replayed,
        public readonly ?CreditReservation $reservation = null,
    ) {
    }
}
