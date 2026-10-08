<?php

namespace App\Services\ApiAccess;

use RuntimeException;

/**
 * Phase 4 Task 6 — thrown by ApiKeyBindingService's single enforcement
 * seam (createLiveBindingEnforced()) when an account's current live
 * installation count already meets or exceeds its resolved
 * api_installations allowance (InstallationAllowanceResolver). Nothing
 * is written when this is thrown: it is raised BEFORE the binding
 * INSERT, inside the same locked transaction that computed the count,
 * so a caller never has to unwind a partially-created binding or key.
 *
 * Same convention as the other per-domain *Exception classes in this
 * codebase (CreditException, BatchException, …): a stable,
 * machine-readable `reason` plus a message safe to surface to an API
 * caller (ids and counts only, never anything secret).
 */
class InstallationAllowanceExceededException extends RuntimeException
{
    public const ALLOWANCE_EXCEEDED = 'installation_allowance_exceeded';

    public function __construct(public readonly string $reason, string $message, public readonly int $accountId, public readonly int $allowance, public readonly int $currentCount)
    {
        parent::__construct($message);
    }

    public static function exceeded(int $accountId, int $allowance, int $currentCount): self
    {
        return new self(
            self::ALLOWANCE_EXCEEDED,
            "Account #{$accountId} has reached its installation allowance ({$currentCount}/{$allowance}). Remove or replace an existing authorized server before adding another.",
            $accountId,
            $allowance,
            $currentCount,
        );
    }
}
