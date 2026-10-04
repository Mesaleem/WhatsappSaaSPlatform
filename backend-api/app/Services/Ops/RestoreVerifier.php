<?php

namespace App\Services\Ops;

use App\Models\Account;
use App\Models\User;
use App\Support\ThrowawayDatabaseGuard;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Throwable;

/**
 * Phase 12 Task 6 — read-only verification that a RESTORED database is usable by this application.
 *
 * The operator restores a backup into a throwaway database; this class connects to it (never to the application's own
 * database, and only if the name passes ThrowawayDatabaseGuard) and issues SELECTs and schema reads only. It writes
 * nothing, runs no migration and prints no row value or secret: results are check names, table names and counts.
 */
class RestoreVerifier
{
    public const CONNECTION = 'restore_verify';

    public function __construct(private readonly RecoveryReadiness $readiness)
    {
    }

    /** @return list<array{name: string, status: string, detail: string}> */
    public function verify(string $database): array
    {
        ThrowawayDatabaseGuard::assertThrowaway($database, 'restore verification');
        ThrowawayDatabaseGuard::assertDistinctFromApplicationDatabase($database);

        $default = (string) config('database.default');
        $base = (array) config("database.connections.{$default}");
        if ($base === []) {
            throw new RuntimeException('The default database connection is not configured.');
        }
        config(['database.connections.'.self::CONNECTION => array_merge($base, ['database' => $database])]);
        DB::purge(self::CONNECTION);

        $checks = [];

        try {
            DB::connection(self::CONNECTION)->select('select 1');
            $checks[] = $this->c('connect', 'pass', 'Connected to the restored database.');
        } catch (Throwable $e) {
            return [$this->c('connect', 'fail', 'Could not connect to the restored database ('.class_basename($e).').')];
        }

        $schema = Schema::connection(self::CONNECTION);

        $checks[] = $this->migrations($schema);
        array_push($checks, ...$this->criticalSchema($schema));
        array_push($checks, ...$this->seededData($schema));
        array_push($checks, ...$this->references($schema));
        $checks[] = $this->readiness->databaseDecryptability(self::CONNECTION);
        $checks[] = $this->applicationUsable($schema);

        DB::disconnect(self::CONNECTION);

        return $checks;
    }

    /** The migrations table lists exactly the migration files this release ships. */
    private function migrations($schema): array
    {
        if (! $schema->hasTable('migrations')) {
            return $this->c('migrations.complete', 'fail', 'The migrations table is missing.');
        }

        $ran = DB::connection(self::CONNECTION)->table('migrations')->pluck('migration')->all();
        $files = array_map(fn ($f) => basename($f, '.php'), glob(database_path('migrations/*.php')) ?: []);
        $missing = array_values(array_diff($files, $ran));
        $unknown = array_values(array_diff($ran, $files));

        if ($missing === [] && $unknown === []) {
            return $this->c('migrations.complete', 'pass', count($ran).' migrations recorded, matching this release exactly.');
        }

        $parts = [];
        if ($missing !== []) {
            $parts[] = count($missing).' not yet applied (first: '.$missing[0].') — run migrations on a COPY first, never on the live database';
        }
        if ($unknown !== []) {
            $parts[] = count($unknown).' recorded but not shipped in this release (first: '.$unknown[0].') — the backup is from a newer release';
        }

        return $this->c('migrations.complete', 'fail', implode('; ', $parts).'.');
    }

    /** @return list<array{name: string, status: string, detail: string}> */
    private function criticalSchema($schema): array
    {
        $absentTables = [];
        $absentColumns = [];
        foreach ((array) config('recovery.critical_tables') as $table => $columns) {
            if (! $schema->hasTable($table)) {
                $absentTables[] = $table;

                continue;
            }
            foreach ($columns as $column) {
                if (! $schema->hasColumn($table, $column)) {
                    $absentColumns[] = "{$table}.{$column}";
                }
            }
        }

        return [
            $absentTables === []
                ? $this->c('schema.tables', 'pass', count((array) config('recovery.critical_tables')).' critical tables present.')
                : $this->c('schema.tables', 'fail', 'Missing table(s): '.implode(', ', $absentTables).'.'),
            $absentColumns === []
                ? $this->c('schema.columns', 'pass', 'All critical columns present.')
                : $this->c('schema.columns', 'fail', 'Missing column(s): '.implode(', ', $absentColumns).'.'),
        ];
    }

    /** @return list<array{name: string, status: string, detail: string}> */
    private function seededData($schema): array
    {
        $db = DB::connection(self::CONNECTION);
        $out = [];

        $empty = [];
        foreach ((array) config('recovery.non_empty_tables') as $table) {
            if (! $schema->hasTable($table) || ! $db->table($table)->exists()) {
                $empty[] = $table;
            }
        }
        $out[] = $empty === []
            ? $this->c('data.seeded', 'pass', 'Permissions, capabilities and plans are populated.')
            : $this->c('data.seeded', 'fail', 'Empty or missing: '.implode(', ', $empty).'.');

        if ($schema->hasTable('roles')) {
            $have = $db->table('roles')->pluck('name')->all();
            $lacking = array_values(array_diff((array) config('recovery.required_roles'), $have));
            $out[] = $lacking === []
                ? $this->c('data.roles', 'pass', 'Required roles present.')
                : $this->c('data.roles', 'fail', 'Missing role(s): '.implode(', ', $lacking).'.');
        }

        if ($schema->hasTable('model_has_roles') && $schema->hasTable('roles')) {
            $supers = $db->table('model_has_roles')->join('roles', 'roles.id', '=', 'model_has_roles.role_id')->where('roles.name', 'super_admin')->count();
            $out[] = $supers > 0
                ? $this->c('data.super_admin', 'pass', "{$supers} Super Admin user(s) present.")
                : $this->c('data.super_admin', 'fail', 'No Super Admin user survived the restore; nobody could administer the platform.');
        }

        return $out;
    }

    /** @return list<array{name: string, status: string, detail: string}> */
    private function references($schema): array
    {
        $db = DB::connection(self::CONNECTION);
        $orphans = [];

        foreach ((array) config('recovery.reference_checks') as [$child, $column, $parent]) {
            if (! $schema->hasTable($child) || ! $schema->hasTable($parent) || ! $schema->hasColumn($child, $column)) {
                continue;
            }
            $n = $db->table($child)->whereNotNull($column)
                ->whereNotExists(fn ($q) => $q->selectRaw('1')->from($parent)->whereColumn("{$parent}.id", "{$child}.{$column}"))
                ->count();
            if ($n > 0) {
                $orphans[] = "{$child}.{$column} ({$n})";
            }
        }

        return [$orphans === []
            ? $this->c('integrity.references', 'pass', 'No orphaned rows in the checked relationships.')
            : $this->c('integrity.references', 'fail', 'Orphaned rows: '.implode(', ', $orphans).'.')];
    }

    /** The application's own models read the restored database. */
    private function applicationUsable($schema): array
    {
        try {
            $users = User::on(self::CONNECTION)->count();
            $account = Account::on(self::CONNECTION)->with('currentSubscription')->first();
            $account?->hasActiveSubscription();
        } catch (Throwable $e) {
            return $this->c('application.models', 'fail', 'The application models could not read the restored database ('.class_basename($e).').');
        }

        return $this->c('application.models', 'pass', "Application models read the restored database ({$users} user row(s)).");
    }

    /** @return array{name: string, status: string, detail: string} */
    private function c(string $name, string $status, string $detail): array
    {
        return ['name' => $name, 'status' => $status, 'detail' => $detail];
    }
}
