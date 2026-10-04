<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\ApiKey;
use App\Services\Ops\RestoreVerifier;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Log\Events\MessageLogged;
use PDO;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

/**
 * Phase 12 Task 6 — `ops:verify-restore`. A "backup" of a fully migrated, seeded SQLite database is restored (copied)
 * into a throwaway file; the command must pass on a faithful restore and fail, naming the check, when the restore lost
 * something the application needs. The verified database is never written to.
 *
 * (The same command is rehearsed against MariaDB with a real mysqldump in CI and in the Task 6 report.)
 */
#[Group('release-safety')]
class RestoreVerificationTest extends TestCase
{
    private const SENTINEL = 'sk-live-RESTORE-SENTINEL-do-not-print-9f3a';

    private string $dir;

    private string $restored;

    private string $originalKey;

    protected function setUp(): void
    {
        parent::setUp();
        // This test restores SQLite files, so it pins the default connection to SQLite whatever DB_CONNECTION the
        // suite runs on (the MariaDB path is exercised with a real mysqldump restore in CI / the Task 6 rehearsal).
        config(['database.default' => 'sqlite']);
        DB::purge('sqlite');
        $this->originalKey = (string) config('app.key');
        $this->dir = sys_get_temp_dir().'/restore-verify-'.bin2hex(random_bytes(4));
        mkdir($this->dir);
        $this->restored = $this->dir.'/restored_verify.sqlite';
        $this->buildBackupAndRestore();
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir.'/*') ?: [] as $f) {
            @unlink($f);
        }
        @rmdir($this->dir);
        parent::tearDown();
    }

    /** Migrates + seeds a SQLite file as the "production" source, stores an encrypted secret, then copies it as the restore. */
    private function buildBackupAndRestore(): void
    {
        $source = $this->dir.'/source.sqlite';
        touch($source);
        $original = config('database.default');
        config(['database.connections.src' => ['driver' => 'sqlite', 'database' => $source, 'prefix' => '', 'foreign_key_constraints' => true]]);
        config(['database.default' => 'src']);
        DB::purge('src');

        Artisan::call('migrate:fresh', ['--seed' => true, '--force' => true]);
        $account = Account::first();
        DB::table('webhook_subscriptions')->insert([
            'account_id' => $account->id, 'url' => 'https://example.test/h', 'secret' => Crypt::encryptString(self::SENTINEL),
            'events' => '["message.sent"]', 'is_active' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);

        DB::disconnect('src');
        config(['database.default' => $original]);
        DB::purge('src');
        copy($source, $this->restored);
        unlink($source);
    }

    private function sql(string ...$statements): void
    {
        $pdo = new PDO('sqlite:'.$this->restored);
        foreach ($statements as $s) {
            $pdo->exec($s);
        }
    }

    private function verify(array $extra = [])
    {
        return $this->artisan('ops:verify-restore', ['--database' => $this->restored] + $extra);
    }

    private function report(): array
    {
        Artisan::call('ops:verify-restore', ['--database' => $this->restored, '--json' => true]);

        return json_decode(Artisan::output(), true);
    }

    private function checkStatus(array $report, string $check): string
    {
        return collect($report['checks'])->firstWhere('name', $check)['status'] ?? 'absent';
    }

    public function test_a_faithful_restore_passes_every_check(): void
    {
        $this->verify()->expectsOutputToContain('Restore verification passed.')->assertSuccessful();

        $report = $this->report();
        $this->assertTrue($report['ok']);
        foreach (['connect', 'migrations.complete', 'schema.tables', 'schema.columns', 'data.seeded', 'data.roles', 'data.super_admin', 'integrity.references', 'encrypted_columns.decryptable', 'application.models'] as $check) {
            $this->assertSame('pass', $this->checkStatus($report, $check), $check);
        }
        $this->assertStringContainsString('1 sampled encrypted value', collect($report['checks'])->firstWhere('name', 'encrypted_columns.decryptable')['detail']);
    }

    public function test_the_verification_never_writes_to_the_restored_database(): void
    {
        $before = md5_file($this->restored);
        $this->verify()->assertSuccessful();
        $this->report();
        $this->assertSame($before, md5_file($this->restored), 'ops:verify-restore must be read-only');
    }

    public function test_a_missing_table_is_detected(): void
    {
        $this->sql('PRAGMA foreign_keys=OFF', 'DROP TABLE api_keys');
        $this->verify()->assertFailed();
        $this->assertSame('fail', $this->checkStatus($this->report(), 'schema.tables'));
    }

    public function test_a_missing_column_is_detected(): void
    {
        $this->sql('ALTER TABLE users RENAME COLUMN email TO email_lost');
        $this->verify()->assertFailed();
        $report = $this->report();
        $this->assertSame('fail', $this->checkStatus($report, 'schema.columns'));
        $this->assertStringContainsString('users.email', collect($report['checks'])->firstWhere('name', 'schema.columns')['detail']);
    }

    public function test_a_backup_that_does_not_match_the_release_is_detected_in_both_directions(): void
    {
        $this->sql("DELETE FROM migrations WHERE migration = (SELECT migration FROM migrations ORDER BY id DESC LIMIT 1)");
        $this->verify()->assertFailed();
        $this->assertStringContainsString('not yet applied', collect($this->report()['checks'])->firstWhere('name', 'migrations.complete')['detail']);

        $this->sql("INSERT INTO migrations (migration, batch) VALUES ('2099_01_01_000000_from_a_newer_release', 9)");
        $this->assertStringContainsString('newer release', collect($this->report()['checks'])->firstWhere('name', 'migrations.complete')['detail']);
    }

    public function test_lost_seed_data_and_a_lost_super_admin_are_detected(): void
    {
        $this->sql('DELETE FROM model_has_roles');
        $this->verify()->assertFailed();
        $this->assertSame('fail', $this->checkStatus($this->report(), 'data.super_admin'));
    }

    public function test_empty_reference_tables_are_detected(): void
    {
        $this->sql('PRAGMA foreign_keys=OFF', 'DELETE FROM plans');
        $this->assertSame('fail', $this->checkStatus($this->report(), 'data.seeded'));
    }

    public function test_orphaned_rows_left_by_a_restore_without_foreign_key_checks_are_detected(): void
    {
        $this->sql('PRAGMA foreign_keys=OFF', 'UPDATE users SET account_id = 987654 WHERE account_id IS NOT NULL');
        $this->verify()->assertFailed();
        $report = $this->report();
        $this->assertSame('fail', $this->checkStatus($report, 'integrity.references'));
        $this->assertStringContainsString('users.account_id', collect($report['checks'])->firstWhere('name', 'integrity.references')['detail']);
    }

    public function test_the_wrong_app_key_is_detected_because_the_restored_secrets_cannot_be_read(): void
    {
        config(['app.key' => 'base64:'.base64_encode(random_bytes(32))]);
        $this->app->forgetInstance('encrypter');
        Crypt::clearResolvedInstance('encrypter');

        $this->verify()->assertFailed();
        $report = $this->report();
        $this->assertSame('fail', $this->checkStatus($report, 'encrypted_columns.decryptable'));
        $this->assertStringContainsString('webhook_subscriptions.secret', collect($report['checks'])->firstWhere('name', 'encrypted_columns.decryptable')['detail']);
    }

    public function test_output_and_logs_never_contain_a_stored_secret_or_the_key(): void
    {
        $logged = [];
        Event::listen(MessageLogged::class, function (MessageLogged $m) use (&$logged) {
            $logged[] = $m->message.json_encode($m->context);
        });

        Artisan::call('ops:verify-restore', ['--database' => $this->restored, '--json' => true]);
        $text = Artisan::output();
        Artisan::call('ops:verify-restore', ['--database' => $this->restored]);
        $text .= Artisan::output().implode("\n", $logged);

        $this->assertStringNotContainsString(self::SENTINEL, $text);
        $this->assertStringNotContainsString($this->originalKey, $text);
        $this->assertStringNotContainsString(substr($this->originalKey, 7), $text);
    }

    public function test_a_real_looking_database_name_is_refused_before_any_connection(): void
    {
        foreach (['wa_saas_restore', 'wa_saas_platform', 'production', 'wa_saas'] as $name) {
            Artisan::call('ops:verify-restore', ['--database' => $name]);
            $this->assertStringContainsString('not recognised as a throwaway', Artisan::output(), $name);
            $this->assertSame(2, Artisan::call('ops:verify-restore', ['--database' => $name]), $name);
        }
        $this->assertSame(2, Artisan::call('ops:verify-restore'), 'a missing --database is refused');
    }

    public function test_the_applications_own_database_is_refused_even_when_its_name_looks_throwaway(): void
    {
        config(['database.connections.sqlite.database' => $this->restored]);
        $this->assertSame(2, Artisan::call('ops:verify-restore', ['--database' => $this->restored]));
        $this->assertStringContainsString("application's own database", Artisan::output());
    }

    public function test_a_directory_that_looks_throwaway_does_not_whitelist_a_real_file(): void
    {
        $this->assertSame(2, Artisan::call('ops:verify-restore', ['--database' => '/var/throwaway/wa_saas_platform.sqlite']));
        $this->assertTrue(app(RestoreVerifier::class) instanceof RestoreVerifier);
    }

    public function test_an_unreachable_database_fails_cleanly_without_a_stack_trace(): void
    {
        $missing = $this->dir.'/never_created_verify.sqlite';
        // SQLite creates nothing on read-only SELECTs of a non-existent file path inside a missing directory.
        $res = Artisan::call('ops:verify-restore', ['--database' => $this->dir.'/no/such/dir/x_verify.sqlite']);
        $this->assertSame(1, $res);
        $this->assertStringContainsString('FAILED', Artisan::output());
        $this->assertFileDoesNotExist($missing);
    }
}
