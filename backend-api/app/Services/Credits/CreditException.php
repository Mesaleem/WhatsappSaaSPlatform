<?php

namespace App\Services\Credits;

use RuntimeException;

/**
 * Phase 8 Task 1 — a credit operation that was refused. Nothing was written
 * (the transaction rolled back or never started). `reason` is a stable,
 * machine-readable code; the message never contains anything but amounts
 * and ids.
 *
 * Phase 8 Task 3 — the spending contract's failure categories:
 *
 *   insufficient_credits           not enough AVAILABLE (reserve/spend) credits
 *   invalid_amount                 amount < 1, > MAX_AMOUNT, > what the
 *                                  reservation still holds, or a release
 *                                  amount that is not the remaining hold
 *   reservation_not_found          no such reservation
 *   tenant_mismatch                the reservation belongs to another account
 *   reservation_already_terminal   consumed/released: never mutated again
 *   invalid_reservation            open but unusable (its expires_at passed)
 *   idempotency_conflict           key reused with different parameters
 *   entitlement_blocked            product gate: capability missing
 *   account_blocked                product gate: account suspended /
 *                                  subscription not current
 *   invalid_operation              anything else (bad key, bad type, a
 *                                  refund of something that is not this
 *                                  account's consumption)
 *
 * Suggested HTTP mapping for future callers (no spending endpoint exists):
 * conflict 409; not_found / tenant_mismatch 404 (never reveal another
 * tenant's reservation); *_blocked 403; the rest 422.
 */
class CreditException extends RuntimeException
{
    public const INSUFFICIENT_CREDITS = 'insufficient_credits';

    public const IDEMPOTENCY_CONFLICT = 'idempotency_conflict';

    public const RESERVATION_ALREADY_TERMINAL = 'reservation_already_terminal';

    /** Task 1 name of RESERVATION_ALREADY_TERMINAL (same code). */
    public const RESERVATION_STATE = self::RESERVATION_ALREADY_TERMINAL;

    public const INVALID_OPERATION = 'invalid_operation';

    public const INVALID_AMOUNT = 'invalid_amount';

    public const RESERVATION_NOT_FOUND = 'reservation_not_found';

    public const INVALID_RESERVATION = 'invalid_reservation';

    public const TENANT_MISMATCH = 'tenant_mismatch';

    public const ENTITLEMENT_BLOCKED = 'entitlement_blocked';

    public const ACCOUNT_BLOCKED = 'account_blocked';

    public function __construct(public readonly string $reason, string $message)
    {
        parent::__construct($message);
    }

    public static function insufficient(int $requested, int $available): self
    {
        return new self(self::INSUFFICIENT_CREDITS, "Insufficient credits: {$requested} requested, {$available} available.");
    }

    public static function conflict(string $key): self
    {
        return new self(self::IDEMPOTENCY_CONFLICT, 'This idempotency key was already used for a different credit operation.');
    }

    public static function reservationState(int $reservationId, string $status, string $attempted): self
    {
        return new self(self::RESERVATION_ALREADY_TERMINAL, "Reservation #{$reservationId} is {$status}; it cannot be {$attempted}.");
    }

    public static function invalid(string $message): self
    {
        return new self(self::INVALID_OPERATION, $message);
    }

    public static function amount(string $message): self
    {
        return new self(self::INVALID_AMOUNT, $message);
    }

    public static function reservationNotFound(int $reservationId): self
    {
        return new self(self::RESERVATION_NOT_FOUND, "Reservation #{$reservationId} does not exist.");
    }

    public static function tenantMismatch(int $reservationId): self
    {
        return new self(self::TENANT_MISMATCH, "Reservation #{$reservationId} does not belong to this account.");
    }

    public static function reservationExpired(int $reservationId): self
    {
        return new self(self::INVALID_RESERVATION, "Reservation #{$reservationId} has expired; it can only be released.");
    }

    public static function entitlementBlocked(string $detail): self
    {
        return new self(self::ENTITLEMENT_BLOCKED, "Credit use is not permitted for this account: {$detail}.");
    }

    public static function accountBlocked(string $detail): self
    {
        return new self(self::ACCOUNT_BLOCKED, "Credit use is not permitted for this account: {$detail}.");
    }
}
