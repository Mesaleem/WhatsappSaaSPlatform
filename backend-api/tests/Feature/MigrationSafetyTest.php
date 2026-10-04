<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

/**
 * Phase 12 Task 6 — migrations 111-142: every one is classified, the classification cannot silently understate what
 * the file does, and the whole sequence rehearses (fresh -> seed -> rollback -> re-migrate) on a throwaway database.
 * No RefreshDatabase: the rehearsal itself runs migrate:fresh on the in-memory test database.
 */
#[Group('release-safety')]
class MigrationSafetyTest extends TestCase
{
    private const FIRST = 111;
    private const LAST = 142;
    private const RISKS = ['schema-only', 'additive', 'locking', 'data-changing'];
    private const ROLLBACKS = ['clean', 'conditional', 'data-loss', 'none'];

    /** @return list<string> migration basenames sorted exactly as Laravel orders them */
    private function files(): array
    {
        $files = array_map(fn ($f) => basename($f, '.php'), glob(database_path('migrations/*.php')) ?: []);
        sort($files);

        return $files;
    }

    /** @return array<string, array{risk: string, rollback: string, note: string}> */
    private function manifest(): array
    {
        return require database_path('migration_risk.php');
    }

    /** @return list<string> */
    private function range(): array
    {
        return array_slice($this->files(), self::FIRST - 1, self::LAST - self::FIRST + 1);
    }

    private function body(string $name, string $method): string
    {
        $src = (string) file_get_contents(database_path("migrations/{$name}.php"));
        if (! preg_match('/function\s+'.$method.'\s*\([^)]*\)[^{]*\{/', $src, $m, PREG_OFFSET_CAPTURE)) {
            return '';
        }
        $start = $m[0][1] + strlen($m[0][0]);
        $depth = 1;
        for ($i = $start, $n = strlen($src); $i < $n && $depth > 0; $i++) {
            $depth += $src[$i] === '{' ? 1 : ($src[$i] === '}' ? -1 : 0);
        }

        return substr($src, $start, $i - $start - 1);
    }

    /** The command runs nested Artisan calls, so capture its own output instead of Artisan::output(). */
    private function rehearse(array $args): array
    {
        $buffer = new \Symfony\Component\Console\Output\BufferedOutput;
        $code = Artisan::call('ops:rehearse-migrations', $args, $buffer);

        return [$code, $buffer->fetch()];
    }

    // ---- sequence ---------------------------------------------------------------------------------------------

    public function test_the_migration_sequence_is_well_formed(): void
    {
        $files = $this->files();
        $this->assertGreaterThanOrEqual(self::LAST, count($files));
        $this->assertSame($files, array_values(array_unique($files)));
        foreach ($files as $f) {
            $this->assertMatchesRegularExpression('/^\d{4}_\d{2}_\d{2}_\d{6}_[a-z0-9_]+$/', $f, 'migration file name');
        }
        $this->assertSame(self::LAST - self::FIRST + 1, count($this->range()));
        $this->assertSame('2026_09_23_130000_create_super_admin_platform_crm_account', $this->range()[0]);
        $this->assertSame('2026_10_04_100000_create_education_attendances_table', $this->range()[31]);
    }

    public function test_every_migration_defines_up_and_down(): void
    {
        foreach ($this->files() as $f) {
            $src = (string) file_get_contents(database_path("migrations/{$f}.php"));
            $this->assertMatchesRegularExpression('/function\s+up\s*\(/', $src, "{$f}: up()");
            $this->assertMatchesRegularExpression('/function\s+down\s*\(/', $src, "{$f}: down()");
        }
    }

    // ---- classification covers exactly 111-142 --------------------------------------------------------------------

    public function test_the_manifest_classifies_exactly_migrations_111_to_142(): void
    {
        $manifest = $this->manifest();
        $this->assertEqualsCanonicalizing($this->range(), array_keys($manifest), 'manifest and migrations 111-142 differ');
        $this->assertCount(32, $manifest);

        foreach ($manifest as $name => $e) {
            $this->assertContains($e['risk'], self::RISKS, $name);
            $this->assertContains($e['rollback'], self::ROLLBACKS, $name);
            $this->assertGreaterThan(5, strlen($e['note']), "{$name}: explain the classification");
        }
        $counts = array_count_values(array_column($manifest, 'risk'));
        ksort($counts);
        $this->assertSame(['additive' => 7, 'data-changing' => 6, 'locking' => 6, 'schema-only' => 13], $counts);
    }

    // ---- classification cannot understate -------------------------------------------------------------------------

    public function test_data_writes_are_never_classified_below_data_changing(): void
    {
        $writes = '/DB::table\([^)]*\)(?:->[a-zA-Z]+\([^;]*?\))*?->(update|delete|insert|updateOrInsert|upsert)\(|DB::(update|delete|insert)\(|(?<!Schema)::(firstOrCreate|updateOrCreate|updateOrInsert)\(|->(update|delete)\(\s*\[?/s';
        foreach ($this->manifest() as $name => $e) {
            if (preg_match($writes, $this->body($name, 'up'))) {
                $this->assertSame('data-changing', $e['risk'], "{$name}: up() writes rows but is classified {$e['risk']}");
            }
        }
    }

    public function test_alters_of_existing_tables_are_never_classified_schema_only(): void
    {
        foreach ($this->manifest() as $name => $e) {
            $up = $this->body($name, 'up');
            if (preg_match('/Schema::table\(/', $up)) {
                $this->assertNotSame('schema-only', $e['risk'], "{$name}: alters an existing table but is classified schema-only");
            }
            if (preg_match('/->change\(\)/', $up)) {
                $this->assertContains($e['risk'], ['locking', 'data-changing'], "{$name}: ->change() rewrites a column but is classified {$e['risk']}");
            }
            if (preg_match('/ALTER TABLE/i', $up) && ! preg_match('/Schema::create\(/', $up)) {
                $this->assertNotSame('schema-only', $e['risk'], "{$name}: raw ALTER classified schema-only");
            }
        }
    }

    public function test_rollback_classification_matches_what_down_does(): void
    {
        foreach ($this->manifest() as $name => $e) {
            $down = trim((string) preg_replace(['#/\*.*?\*/#s', '#//[^\n]*#'], '', $this->body($name, 'down')));
            if ($down === '') {
                $this->assertSame('none', $e['rollback'], "{$name}: down() is empty but rollback is '{$e['rollback']}'");
            } else {
                $this->assertNotSame('none', $e['rollback'], "{$name}: down() does something but rollback is 'none'");
            }
            if (preg_match('/->delete\(/', $down)) {
                $this->assertContains($e['rollback'], ['data-loss', 'conditional'], "{$name}: down() deletes rows");
            }
            if (preg_match('/->change\(\)/', $down)) {
                $this->assertSame('conditional', $e['rollback'], "{$name}: down() narrows a column");
            }
        }
    }

    public function test_irreversible_and_data_loss_rollbacks_are_documented_in_the_runbook(): void
    {
        $doc = (string) file_get_contents(config('recovery.runbook_path'));
        $classes = array_unique(array_column($this->manifest(), 'rollback'));
        $this->assertContains('data-loss', $classes);
        $this->assertContains('none', $classes);
        foreach (['data-loss', '`none`', 'ops:rehearse-migrations', 'backup'] as $needle) {
            $this->assertStringContainsString($needle, $doc, "runbook must explain '{$needle}'");
        }
        foreach (['136', '139', '141'] as $position) {
            $this->assertStringContainsString($position, $doc, "runbook must name data-loss migration {$position}");
        }
    }

    // ---- rehearsal --------------------------------------------------------------------------------------------------

    public function test_the_full_sequence_rehearses_on_a_throwaway_database(): void
    {
        [$code, $out] = $this->rehearse(['--seed' => true, '--json' => true]);
        $r = json_decode($out, true);

        $this->assertSame(0, $code, $out);
        $this->assertTrue($r['ok']);
        $this->assertSame(['migrate:fresh', 'seed', 'rollback', 're-migrate', 'schema-symmetry'], array_column($r['steps'], 'step'));
        $this->assertTrue(collect($r['steps'])->every(fn ($s) => $s['ok']));
        $this->assertCount(count($this->files()), \DB::table('migrations')->pluck('migration'));
        $this->assertTrue(Schema::hasTable('education_attendances'), 'the last migration is applied after re-migrate');
    }

    public function test_a_single_step_rehearsal_rolls_back_only_the_last_migration(): void
    {
        [$code, $out] = $this->rehearse(['--steps' => 1, '--json' => true]);
        $r = json_decode($out, true);

        $this->assertSame(0, $code);
        $rollback = collect($r['steps'])->firstWhere('step', 'rollback');
        $this->assertStringContainsString('rolled back 1 migration', $rollback['detail']);
    }

    public function test_rehearsal_refuses_a_database_that_is_not_a_throwaway(): void
    {
        $default = config('database.default');
        $original = config("database.connections.{$default}.database");
        config(["database.connections.{$default}.database" => 'wa_saas_platform']);

        [$code, $out] = $this->rehearse(['--json' => true]);
        config(["database.connections.{$default}.database" => $original]);

        $this->assertSame(2, $code);
        $this->assertStringContainsString('Refusing', $out);
        $this->assertStringNotContainsString('"steps"', $out, 'nothing may run before the refusal');
    }

    public function test_rehearsal_refuses_the_production_environment(): void
    {
        $this->app['env'] = 'production';
        [$code, $out] = $this->rehearse([]);
        $this->app['env'] = 'testing';

        $this->assertSame(2, $code);
        $this->assertStringContainsString('production', $out);
    }
}
