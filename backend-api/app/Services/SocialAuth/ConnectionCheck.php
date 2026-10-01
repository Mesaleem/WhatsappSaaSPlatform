<?php

namespace App\Services\SocialAuth;

/**
 * Phase 9 Task 1 — a provider's answer to "is this stored connection still
 * usable?". $reason is short and safe to show a user (never a token, never
 * a raw provider response body).
 */
final class ConnectionCheck
{
    public function __construct(
        public readonly string $status,
        public readonly ?string $reason = null,
    ) {
    }

    public static function connected(): self
    {
        return new self(SocialConnectionStatus::CONNECTED);
    }

    public static function expired(string $reason): self
    {
        return new self(SocialConnectionStatus::EXPIRED, $reason);
    }

    public static function revoked(string $reason): self
    {
        return new self(SocialConnectionStatus::REVOKED, $reason);
    }

    public static function unknown(string $reason): self
    {
        return new self(SocialConnectionStatus::UNKNOWN, $reason);
    }
}
