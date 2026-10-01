<?php

namespace App\Services\SocialAuth;

use App\Models\SocialAccount;

/**
 * Phase 9 Task 1 — the social connection lifecycle.
 *
 * Stored per connected asset (social_accounts.health_status — its three
 * existing values are kept, so nothing that already reads them changes):
 *
 *   connected   health_status = connected and the token has not expired
 *   expired     health_status = token_expired, or token_expires_at passed —
 *               the user must reconnect (Meta long-lived user tokens cannot
 *               be refreshed without the user)
 *   revoked     health_status = reauth_required — the provider no longer
 *               accepts the grant (permission removed, password changed,
 *               app removed)
 *
 * Client-side only (never stored): not_connected (no row), connecting
 * (popup open), failed (the OAuth callback reported an error — no row is
 * written), disconnecting (the delete request is in flight).
 */
final class SocialConnectionStatus
{
    public const CONNECTED = 'connected';

    public const EXPIRED = 'expired';

    public const REVOKED = 'revoked';

    /** A check that could not reach the provider: nothing about the stored state changes. */
    public const UNKNOWN = 'unknown';

    /** The health_status value a checked lifecycle status is stored as. */
    public static function healthFor(string $status): ?string
    {
        return match ($status) {
            self::CONNECTED => SocialAccount::HEALTH_CONNECTED,
            self::EXPIRED => SocialAccount::HEALTH_TOKEN_EXPIRED,
            self::REVOKED => SocialAccount::HEALTH_REAUTH_REQUIRED,
            default => null,
        };
    }
}
