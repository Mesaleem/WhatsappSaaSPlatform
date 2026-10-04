<?php

namespace Tests\Support;

use Illuminate\Support\Facades\DB;

/**
 * Phase 12 Task 4 — deterministic query accounting for request-path tests.
 *
 * `captureQueries()` runs a closure and returns every SQL statement it executed, each tagged with a coarse
 * category derived from the tables it touches, plus the repeated (identical SQL + bindings) statements.
 * Counts are taken from DB::listen on the default connection, so they do not depend on timing.
 */
trait CountsQueries
{
    /** @var array<string, list<string>> category => table names */
    private const QUERY_CATEGORIES = [
        'authorization' => ['roles', 'permissions', 'model_has_roles', 'model_has_permissions', 'role_has_permissions'],
        'subscription_entitlement' => ['subscriptions', 'plans', 'plan_capabilities', 'account_entitlements', 'capabilities', 'plan_entitlements'],
        'account_tenant' => ['accounts', 'users', 'personal_access_tokens', 'account_industries'],
    ];

    /**
     * @return array{count: int, queries: list<array{sql: string, bindings: array, time: float, category: string}>, duplicates: array<string, int>, by_category: array<string, int>}
     */
    protected function captureQueries(callable $run): array
    {
        $queries = [];
        DB::listen(function ($q) use (&$queries) {
            $queries[] = ['sql' => $q->sql, 'bindings' => $q->bindings, 'time' => $q->time, 'category' => $this->categoriseQuery($q->sql)];
        });
        DB::flushQueryLog();

        $run();

        $seen = [];
        foreach ($queries as $q) {
            $key = $q['sql'].'|'.json_encode($q['bindings']);
            $seen[$key] = ($seen[$key] ?? 0) + 1;
        }
        $byCategory = [];
        foreach ($queries as $q) {
            $byCategory[$q['category']] = ($byCategory[$q['category']] ?? 0) + 1;
        }

        return [
            'count' => count($queries),
            'queries' => $queries,
            'duplicates' => array_filter($seen, fn ($n) => $n > 1),
            'by_category' => $byCategory,
        ];
    }

    private function categoriseQuery(string $sql): string
    {
        if (! preg_match('/\b(?:from|into|update|join)\s+[`"\[]?([a-z0-9_]+)/i', $sql, $m)) {
            return 'other';
        }
        foreach (self::QUERY_CATEGORIES as $category => $tables) {
            if (in_array(strtolower($m[1]), $tables, true)) {
                return $category;
            }
        }

        return 'business_data';
    }
}
