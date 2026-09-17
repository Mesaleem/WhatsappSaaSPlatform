<?php

namespace App\Services\Templates;

use App\Models\Account;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Cache;

/**
 * Strict Bulk Messaging Limits & Tier-Based Cooldown — the anti-spam-ban
 * gate that sits IN FRONT OF BulkMessageDispatcher: caps a single bulk
 * request at MAX_RECIPIENTS_PER_BATCH and, once an account actually
 * sends a full batch at that cap, locks out further bulk dispatches for
 * a tier-dependent cooldown window.
 *
 * "group_messaging_permission" (as the feature request that introduced
 * this named it) maps to this app's PRE-EXISTING `contact_groups`
 * module flag (Account::hasModuleEnabled('contact_groups'), the same
 * paid-addon gate ContactGroupController/GroupMessageDispatcher/
 * Api\V1\GroupController already enforce for Group Messaging itself) --
 * there is no separate spatie permission by that literal name in this
 * codebase (checked via grep before writing this), and inventing a new,
 * parallel permission slug that means the same thing as an existing
 * module flag would be exactly the kind of two-systems drift this
 * codebase's own docblocks elsewhere warn against. An account with the
 * contact_groups module enabled already has real Group Messaging
 * available to it (a better tool for a >150-recipient list than looping
 * individual sends), which is also why that account gets the SHORTER
 * cooldown here.
 *
 * Storage: Laravel's Cache facade (CACHE_STORE=database in this
 * environment, not literally Redis — the facade is driver-agnostic, so
 * this works unchanged if that's ever switched to redis) under the key
 * `bulk_cooldown_{account_id}`. The cached VALUE is the cooldown's
 * absolute expiry instant (a Carbon timestamp), not just a boolean flag
 * -- some cache drivers (database, file) have no "remaining TTL" query,
 * so storing the expiry instant directly is what makes
 * remainingSeconds() below driver-agnostic.
 */
class BulkMessageCooldown
{
    /** Strict, hard ceiling on recipients in a single bulk request. */
    public const MAX_RECIPIENTS_PER_BATCH = 150;

    /** Cooldown after a full 150-recipient batch, account WITHOUT the contact_groups module. */
    public const COOLDOWN_HOURS_WITHOUT_GROUP_PERMISSION = 6;

    /** Cooldown after a full 150-recipient batch, account WITH the contact_groups module. */
    public const COOLDOWN_HOURS_WITH_GROUP_PERMISSION = 4;

    public static function cacheKey(int $accountId): string
    {
        return "bulk_cooldown_{$accountId}";
    }

    /**
     * Seconds remaining on this account's active cooldown, or 0 if none
     * is active (never negative — a stale/just-expired cache entry
     * reads as "no cooldown", the cache driver's own TTL will clear the
     * key on its own).
     */
    public static function remainingSeconds(int $accountId): int
    {
        $expiresAt = Cache::get(self::cacheKey($accountId));

        // isPast() check first, deliberately, rather than relying on
        // diffInSeconds()'s signed/unsigned argument -- that sign
        // convention has flipped between Carbon major versions in the
        // past, so this reads unambiguously instead: once we know
        // $expiresAt is still in the future, the ABSOLUTE difference
        // from now() is exactly the remaining seconds.
        if (! $expiresAt instanceof CarbonInterface || $expiresAt->isPast()) {
            return 0;
        }

        return (int) now()->diffInSeconds($expiresAt);
    }

    public static function isActive(int $accountId): bool
    {
        return self::remainingSeconds($accountId) > 0;
    }

    /**
     * Starts (or refreshes) this account's cooldown, at the tier-
     * appropriate duration -- called ONLY after a bulk dispatch that
     * actually used the full MAX_RECIPIENTS_PER_BATCH, never for a
     * smaller bulk send (see MessageTemplateController::sendBulk()'s
     * own call site for exactly where this fires).
     */
    public static function lock(Account $account): void
    {
        $hours = $account->hasModuleEnabled('contact_groups')
            ? self::COOLDOWN_HOURS_WITH_GROUP_PERMISSION
            : self::COOLDOWN_HOURS_WITHOUT_GROUP_PERMISSION;

        $expiresAt = now()->addHours($hours);

        Cache::put(self::cacheKey($account->id), $expiresAt, $expiresAt);
    }

    /**
     * "3h 45m" / "3h" / "45m" style remaining-time text, for both the
     * 429 error message below and (via the same math, client-side) the
     * Send Alert page's countdown banner.
     */
    public static function formatRemaining(int $seconds): string
    {
        $hours = intdiv($seconds, 3600);
        $minutes = intdiv($seconds % 3600, 60);

        if ($hours > 0 && $minutes > 0) {
            return "{$hours}h {$minutes}m";
        }

        if ($hours > 0) {
            return "{$hours}h";
        }

        // Rounds up to 1m rather than showing "0m" in the last seconds
        // of a cooldown -- a caller polling this shouldn't see "0m
        // remaining" and then get a 429 anyway.
        return max(1, $minutes).'m';
    }
}
