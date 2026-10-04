<?php

namespace Tests\Feature;

use App\Support\ThrowawayDatabaseGuard;
use Illuminate\Support\Facades\Artisan;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\Yaml\Yaml;
use Tests\TestCase;

/**
 * Phase 12 Task 6 — the CI workflow must keep running every check the project requires locally, and must only ever
 * touch throwaway databases. The workflow lives at the repository root, so a deployment that ships backend-api alone
 * skips these (the checks are about the repository, not the application).
 */
#[Group('release-safety')]
class ReleasePipelineTopologyTest extends TestCase
{
    private array $workflow = [];

    protected function setUp(): void
    {
        parent::setUp();
        $path = base_path('../.github/workflows/ci.yml');
        if (! is_file($path)) {
            $this->markTestSkipped('.github/workflows/ci.yml is not present next to backend-api.');
        }
        $this->workflow = Yaml::parseFile($path);
    }

    /** @return list<array<string, mixed>> */
    private function steps(string $job): array
    {
        $this->assertArrayHasKey($job, $this->workflow['jobs'], "CI job '{$job}' is missing");

        return $this->workflow['jobs'][$job]['steps'];
    }

    private function runs(string $job): string
    {
        return collect($this->steps($job))->pluck('run')->filter()->implode("\n");
    }

    private function stepRunning(string $job, string $needle): array
    {
        $step = collect($this->steps($job))->first(fn ($s) => isset($s['run']) && str_contains($s['run'], $needle));
        $this->assertNotNull($step, "CI job '{$job}' has no step running '{$needle}'");

        return $step;
    }

    public function test_the_expected_jobs_exist_and_the_qr_engine_job_is_untouched(): void
    {
        $this->assertEqualsCanonicalizing(['backend-sqlite', 'backend-mariadb', 'frontend-app', 'qr-engine-service'], array_keys($this->workflow['jobs']));

        $qr = $this->runs('qr-engine-service');
        $this->assertStringContainsString('npm ci', $qr);
        $this->assertStringContainsString("find src -name '*.js' -print0 | xargs -0 -n1 node --check", $qr);
    }

    public function test_it_runs_on_pull_requests_and_pushes_to_main(): void
    {
        $on = $this->workflow['on'] ?? $this->workflow[true] ?? [];
        $this->assertArrayHasKey('pull_request', $on);
        $this->assertSame(['main'], $on['push']['branches']);
    }

    public function test_the_sqlite_job_runs_the_gate_the_full_suite_and_a_migration_rehearsal(): void
    {
        $runs = $this->runs('backend-sqlite');
        $this->assertStringContainsString('php artisan test --group=release-safety', $runs);
        $this->assertMatchesRegularExpression('/^php artisan test$/m', $runs, 'the full SQLite suite must run');
        $this->assertStringContainsString('ops:rehearse-migrations --seed', $runs);

        $setup = collect($this->steps('backend-sqlite'))->first(fn ($s) => str_contains($s['uses'] ?? '', 'setup-php'));
        $this->assertGreaterThanOrEqual(8.4, (float) $setup['with']['php-version'], 'CI must use the PHP version the project is verified on');
    }

    public function test_the_mariadb_job_runs_the_full_suite_rehearsal_restore_and_all_three_probes(): void
    {
        $job = $this->workflow['jobs']['backend-mariadb'];
        $this->assertStringStartsWith('mariadb:10.11', $job['services']['mariadb']['image']);

        $full = $this->stepRunning('backend-mariadb', 'php artisan test');
        $this->assertSame('mariadb', $full['env']['DB_CONNECTION']);

        $runs = $this->runs('backend-mariadb');
        foreach (['ops:rehearse-migrations --seed', 'mysqldump', 'ops:verify-restore --database=', 'ops:check-recovery',
            'tests/Probes/journey_concurrency_probe.php', 'tests/Probes/inbound_gate_nonblocking_probe.php', 'tests/Probes/outbound_pacing_probe.php'] as $needle) {
            $this->assertStringContainsString($needle, $runs, "missing from the MariaDB job: {$needle}");
        }

        $this->assertSame('sync', $this->stepRunning('backend-mariadb', 'journey_concurrency_probe')['env']['QUEUE_CONNECTION']);
        $this->assertSame('database', $this->stepRunning('backend-mariadb', 'outbound_pacing_probe')['env']['QUEUE_CONNECTION']);
    }

    public function test_the_frontend_job_runs_tsc_vitest_and_the_build(): void
    {
        $runs = $this->runs('frontend-app');
        foreach (['npm ci', 'npx tsc --noEmit', 'npx vitest run', 'npm run build'] as $needle) {
            $this->assertStringContainsString($needle, $runs);
        }
    }

    public function test_every_database_the_workflow_destroys_or_migrates_is_a_throwaway_one(): void
    {
        foreach (['backend-sqlite', 'backend-mariadb'] as $job) {
            $jobEnv = $this->workflow['jobs'][$job]['env'] ?? [];
            foreach ($this->steps($job) as $step) {
                $run = $step['run'] ?? '';
                if (! preg_match('/migrate|rehearse|Probes\//', $run)) {
                    continue;
                }
                $db = $step['env']['DB_DATABASE'] ?? $jobEnv['DB_DATABASE'] ?? null;
                $this->assertNotNull($db, "step '".($step['name'] ?? $run)."' migrates without naming its database");
                $this->assertTrue(ThrowawayDatabaseGuard::isThrowaway((string) $db), "step '".($step['name'] ?? '')."' migrates '{$db}', which is not a throwaway database");
            }
        }
    }

    public function test_the_workflow_uses_no_repository_secrets_and_no_external_database(): void
    {
        $raw = (string) file_get_contents(base_path('../.github/workflows/ci.yml'));
        $this->assertStringNotContainsString('secrets.', $raw, 'CI needs no secret: APP_KEY is generated per run');
        $this->assertStringNotContainsString('DB_HOST: db.', $raw);
        $this->assertSame('127.0.0.1', $this->workflow['jobs']['backend-mariadb']['env']['DB_HOST']);
    }

    public function test_each_probe_is_given_the_exact_database_name_its_own_guard_requires(): void
    {
        foreach (['journey_concurrency_probe', 'inbound_gate_nonblocking_probe', 'outbound_pacing_probe'] as $probe) {
            $source = (string) file_get_contents(base_path("tests/Probes/{$probe}.php"));
            $this->assertMatchesRegularExpression("/getDatabaseName\\(\\) !== '([a-z_]+)'/", $source, "{$probe} has no database-name guard");
            preg_match("/getDatabaseName\\(\\) !== '([a-z_]+)'/", $source, $m);
            $this->assertSame($m[1], $this->stepRunning('backend-mariadb', $probe)['env']['DB_DATABASE'], "{$probe} would refuse to run in CI");
            $this->assertStringContainsString($m[1], (string) $this->stepRunning('backend-mariadb', 'CREATE DATABASE')['run'], "CI never creates {$m[1]}");
        }
    }

    public function test_every_command_and_probe_the_workflow_calls_exists(): void
    {
        $commands = array_keys(Artisan::all());
        foreach (['ops:rehearse-migrations', 'ops:verify-restore', 'ops:check-recovery'] as $c) {
            $this->assertContains($c, $commands);
        }
        foreach (['journey_concurrency_probe', 'inbound_gate_nonblocking_probe', 'outbound_pacing_probe'] as $p) {
            $this->assertFileExists(base_path("tests/Probes/{$p}.php"));
        }
    }

    public function test_the_release_safety_group_is_not_empty_and_the_runbook_describes_the_pipeline(): void
    {
        $tagged = 0;
        foreach (glob(base_path('tests/Feature/*.php')) as $file) {
            if (str_contains((string) file_get_contents($file), "#[Group('release-safety')]")) {
                $tagged++;
            }
        }
        $this->assertGreaterThanOrEqual(7, $tagged, 'the fast release gate would run nothing');

        $doc = (string) file_get_contents(base_path('docs/RELEASE_AND_RECOVERY.md'));
        foreach (['backend-sqlite', 'backend-mariadb', 'frontend', 'ops:verify-restore'] as $needle) {
            $this->assertStringContainsString($needle, $doc);
        }
    }
}
