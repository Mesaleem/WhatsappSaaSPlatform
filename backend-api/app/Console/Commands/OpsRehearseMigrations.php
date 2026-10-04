<?php

namespace App\Console\Commands;

use App\Support\ThrowawayDatabaseGuard;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * Phase 12 Task 6 — `php artisan ops:rehearse-migrations`: rehearses the whole migration chain on a THROWAWAY database.
 *
 * It runs migrate:fresh (which DROPS EVERY TABLE), so it refuses unless the DEFAULT connection's database is
 * recognisably a throwaway one (ThrowawayDatabaseGuard) and the environment is not production. Steps: fresh migrate →
 * every shipped migration recorded → (optional) seed → rollback the last N migrations → re-apply → the schema is
 * identical to the first full migration. Exit 1 if any step fails; the failing step is named.
 */
class OpsRehearseMigrations extends Command
{
    protected $signature = 'ops:rehearse-migrations
        {--steps=32 : How many of the most recent migrations to roll back and re-apply}
        {--seed : Seed reference data before the rollback so data-touching down() methods run against rows}
        {--json : Machine-readable output}';

    protected $description = 'Rehearse migrate:fresh → rollback → re-migrate on a THROWAWAY database (refuses anything else)';

    public function handle(): int
    {
        $default = (string) config('database.default');
        $database = (string) config("database.connections.{$default}.database");

        try {
            if (app()->environment('production')) {
                throw new \RuntimeException('Refusing to rehearse migrations in the production environment.');
            }
            ThrowawayDatabaseGuard::assertThrowaway($database, 'migration rehearsal (migrate:fresh drops every table)');
        } catch (\RuntimeException $e) {
            $this->error($e->getMessage());

            return 2;
        }

        $steps = max(1, (int) $this->option('steps'));
        $files = array_map(fn ($f) => basename($f, '.php'), glob(database_path('migrations/*.php')) ?: []);
        sort($files);
        $report = [];
        $record = function (string $step, bool $ok, string $detail) use (&$report) {
            $report[] = ['step' => $step, 'ok' => $ok, 'detail' => $detail];

            return $ok;
        };

        try {
            $started = microtime(true);
            Artisan::call('migrate:fresh', ['--force' => true]);
            $ran = $this->ran();
            $okFresh = $record('migrate:fresh', $ran === $files, count($ran).' of '.count($files).' migrations recorded in '.round(microtime(true) - $started, 2).'s');

            if ($okFresh && $this->option('seed')) {
                Artisan::call('db:seed', ['--class' => \Database\Seeders\RolePermissionSeeder::class, '--force' => true]);
                Artisan::call('db:seed', ['--class' => \Database\Seeders\Phase1FoundationSeeder::class, '--force' => true]);
                $record('seed', true, 'reference data seeded');
            }

            $before = $this->schemaFingerprint();
            if ($okFresh) {
                Artisan::call('migrate:rollback', ['--step' => $steps, '--force' => true]);
                $after = $this->ran();
                $expected = array_slice($files, 0, max(0, count($files) - $steps));
                $record('rollback', $after === $expected, 'rolled back '.(count($files) - count($after)).' migration(s); '.count($after).' remain');

                Artisan::call('migrate', ['--force' => true]);
                $record('re-migrate', $this->ran() === $files, count($this->ran()).' of '.count($files).' migrations recorded again');
                $record('schema-symmetry', $this->schemaFingerprint() === $before, 'schema after rollback + re-migrate '.($this->schemaFingerprint() === $before ? 'is identical to' : 'DIFFERS from').' the first full migration');
            }
        } catch (Throwable $e) {
            $record('exception', false, class_basename($e));
        }

        $ok = collect($report)->every(fn ($r) => $r['ok']);

        if ($this->option('json')) {
            $this->line(json_encode(['ok' => $ok, 'steps' => $report], JSON_PRETTY_PRINT));
        } else {
            $this->table(['step', 'result', 'detail'], array_map(fn ($r) => [$r['step'], $r['ok'] ? 'OK' : 'FAIL', $r['detail']], $report));
            $ok ? $this->info('Migration rehearsal passed.') : $this->error('Migration rehearsal FAILED');
        }

        return $ok ? self::SUCCESS : self::FAILURE;
    }

    /** @return list<string> */
    private function ran(): array
    {
        $ran = DB::table('migrations')->pluck('migration')->all();
        sort($ran);

        return $ran;
    }

    /** Tables, columns (name/type/nullable) and index names — enough to detect an asymmetric down(). */
    private function schemaFingerprint(): string
    {
        $out = [];
        foreach (Schema::getTables() as $t) {
            $name = $t['name'];
            $out[$name] = [
                'columns' => collect(Schema::getColumns($name))->map(fn ($c) => $c['name'].':'.$c['type_name'].':'.($c['nullable'] ? 'n' : 'x'))->sort()->values()->all(),
                'indexes' => collect(Schema::getIndexes($name))->map(fn ($i) => $i['name'].':'.implode(',', $i['columns']).':'.($i['unique'] ? 'u' : 'i'))->sort()->values()->all(),
            ];
        }
        ksort($out);

        return hash('sha256', json_encode($out));
    }
}
