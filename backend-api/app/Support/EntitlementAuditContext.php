<?php

namespace App\Support;

use Illuminate\Support\Facades\Auth;

/**
 * F-5.2 — who/what is performing an entitlement mutation.
 *
 * LogsActivity only knows "an authenticated HTTP user". Entitlements are
 * also changed by a queue worker (plan reconciliation), a payment
 * fulfilment (webhook or gateway callback), and artisan commands
 * (backfill / reconcile). Those paths wrap their work in run()/runDefault()
 * so the audit row says WHICH automated process acted, instead of the row
 * being skipped. Nothing here fakes a user: the audited `user_id` stays
 * Auth::id() (null for every automated actor); the human who merely
 * TRIGGERED a queued run (e.g. the administrator who edited the plan) is
 * recorded separately as `initiated_by_user_id`.
 *
 * A context holds only a source label (a code-owned slug) and an optional
 * initiating user id — no request data, no secrets.
 */
final class EntitlementAuditContext
{
    /** @var list<array{source: string, initiated_by_user_id: ?int}> */
    private static array $stack = [];

    /**
     * Run $fn with $source as the acting process, overriding any outer context.
     *
     * @template T
     *
     * @param  callable(): T  $fn
     * @return T
     */
    public static function run(string $source, callable $fn, ?int $initiatedByUserId = null): mixed
    {
        self::$stack[] = ['source' => $source, 'initiated_by_user_id' => $initiatedByUserId];

        try {
            return $fn();
        } finally {
            array_pop(self::$stack);
        }
    }

    /**
     * Like run(), but an already-active outer context wins (the outer caller
     * knows more: e.g. payment fulfilment wraps the reconciliation service).
     * Only an initiating user the outer context lacks is added.
     *
     * @template T
     *
     * @param  callable(): T  $fn
     * @return T
     */
    public static function runDefault(string $source, callable $fn, ?int $initiatedByUserId = null): mixed
    {
        $outer = self::current();

        if ($outer === null) {
            return self::run($source, $fn, $initiatedByUserId);
        }

        return self::run($outer['source'], $fn, $outer['initiated_by_user_id'] ?? $initiatedByUserId);
    }

    /** @return array{source: string, initiated_by_user_id: ?int}|null */
    public static function current(): ?array
    {
        return self::$stack === [] ? null : self::$stack[array_key_last(self::$stack)];
    }

    /**
     * The acting source for an audit row: the explicit context, else an
     * authenticated request (`http`), else a console run (`console`), else
     * `system`.
     *
     * @return array{actor_type: 'user'|'system', source: string, initiated_by_user_id: ?int}
     */
    public static function describe(): array
    {
        $context = self::current();
        $authenticated = Auth::id() !== null;

        return [
            'actor_type' => $authenticated ? 'user' : 'system',
            'source' => $context['source'] ?? ($authenticated ? 'http' : (app()->runningInConsole() ? 'console' : 'system')),
            'initiated_by_user_id' => $context['initiated_by_user_id'] ?? null,
        ];
    }

    /** Test helper: drop any context left by a failed test. */
    public static function reset(): void
    {
        self::$stack = [];
    }
}
