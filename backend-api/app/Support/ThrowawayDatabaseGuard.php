<?php

namespace App\Support;

use RuntimeException;

/**
 * Phase 12 Task 6 — the one rule for "this database may be used for a rehearsal / restore verification".
 *
 * A name qualifies only if it matches config('recovery.throwaway_name_pattern') (throwaway, rehearsal, verify, scratch,
 * *_test, test_*), or is SQLite's in-memory database. An empty name never qualifies. This is a refusal rule, not a
 * permission system: it exists so that a mistyped --database, or a rehearsal run with the production .env loaded,
 * stops before touching anything.
 */
final class ThrowawayDatabaseGuard
{
    public static function isThrowaway(string $database): bool
    {
        $database = trim($database);
        if ($database === '') {
            return false;
        }
        if ($database === ':memory:') {
            return true;
        }

        // A SQLite file path is judged by its file name only: a throwaway-looking directory must not whitelist a real file.
        $name = str_contains($database, DIRECTORY_SEPARATOR) || str_contains($database, '/') ? basename(str_replace('\\', '/', $database)) : $database;
        $name = preg_replace('/\.(sqlite3?|db)$/i', '', $name);

        $pattern = (string) config('recovery.throwaway_name_pattern');

        return $pattern !== '' && @preg_match($pattern, $name) === 1;
    }

    public static function assertThrowaway(string $database, string $what = 'this operation'): void
    {
        if (! self::isThrowaway($database)) {
            throw new RuntimeException("Refusing {$what}: the database '".self::label($database)."' is not recognised as a throwaway database (its name must match the recovery.throwaway_name_pattern).");
        }
    }

    /** The target must also not be the database the application itself is configured to use. */
    public static function assertDistinctFromApplicationDatabase(string $database): void
    {
        $default = (string) config('database.default');
        $own = (string) config("database.connections.{$default}.database");

        if ($own !== '' && $own !== ':memory:' && self::normalise($own) === self::normalise($database)) {
            throw new RuntimeException('Refusing to verify the application\'s own database: pass a separately restored throwaway database.');
        }
    }

    private static function normalise(string $v): string
    {
        return strtolower(str_replace('\\', '/', trim($v)));
    }

    /** Names are not secret, but never echo anything that looks like a DSN or credentials. */
    private static function label(string $database): string
    {
        return preg_match('/[@:]\/\/|password=|pwd=/i', $database) ? '[redacted]' : $database;
    }
}
