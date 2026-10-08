<?php

namespace App\Services\ApiAccess;

use App\Models\ApiKey;
use RuntimeException;

/**
 * Phase 4 Task 9 — thrown by ApiKeyBindingService when an INDEPENDENT
 * live-binding-creation path (provision()/registerServer(), the
 * request side of requestChange(), or an admin rebind()/approve() that
 * is itself blocked by a cooldown left by an EARLIER operation) is
 * attempted for an API key whose cooldown_until is still in the
 * future.
 *
 * This is never thrown for the cooldown a transfer/rebind/revoke is
 * itself in the process of SETTING: createLiveBindingEnforced() reads
 * cooldown_until once, at the top of its transaction, before any write
 * — including its own end-of-transaction cooldown write — so a
 * successful one-for-one replacement can never observe (and therefore
 * can never be rejected by) the very cooldown it is about to start.
 * See that method's docblock for the exact ordering.
 *
 * Same convention as InstallationAllowanceExceededException: a stable,
 * machine-readable `reason` plus a message safe to surface to an API
 * caller (the key id and the cooldown deadline only, never anything
 * secret).
 */
class ApiKeyCooldownActiveException extends RuntimeException
{
    public const COOLDOWN_ACTIVE = 'api_key_cooldown_active';

    public function __construct(
        public readonly string $reason,
        string $message,
        public readonly int $apiKeyId,
        public readonly \DateTimeInterface $cooldownUntil,
    ) {
        parent::__construct($message);
    }

    public static function active(ApiKey $key, \DateTimeInterface $cooldownUntil): self
    {
        $until = \Illuminate\Support\Carbon::instance($cooldownUntil)->toIso8601String();

        return new self(
            self::COOLDOWN_ACTIVE,
            "API key #{$key->id} is in cooldown until {$until} and cannot be bound to a new server yet.",
            $key->id,
            $cooldownUntil,
        );
    }
}
