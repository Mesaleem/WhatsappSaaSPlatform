<?php

namespace Tests\Feature;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use League\Flysystem\Filesystem;
use League\Flysystem\Local\LocalFilesystemAdapter;
use Tests\TestCase;

/**
 * Phase 12 Task 1 — `php artisan ops:check-topology`: read-only, distinguishes "safe for the current
 * single-node deployment" from "unsafe once several instances run", needs no infrastructure.
 */
class TopologyCommandTest extends TestCase
{
    use RefreshDatabase;

    /** Every setting a clustered deployment is expected to have. */
    private function clusterSafeConfig(): void
    {
        Storage::extend('shared_probe', function ($app, array $config) {
            $adapter = new LocalFilesystemAdapter($config['root']);

            return new FilesystemAdapter(new Filesystem($adapter, $config), $adapter, $config);
        });
        config([
            'queue.default' => 'database',
            'cache.default' => 'database',
            'session.driver' => 'database',
            'queue.failed.driver' => 'database-uuids',
            'filesystems.disks.shared_probe' => ['driver' => 'shared_probe', 'root' => sys_get_temp_dir().'/topology-probe', 'throw' => false],
            'social.media.disk' => 'shared_probe',
        ]);
    }

    /** @return array{0: int, 1: array<string, mixed>} */
    private function run_(array $options = []): array
    {
        $exit = Artisan::call('ops:check-topology', $options + ['--json' => true]);
        $report = json_decode(Artisan::output(), true);
        $this->assertIsArray($report, 'the command must emit valid JSON with --json');

        return [$exit, $report];
    }

    /** @return array<string, string> check id => status */
    private function statuses(array $report): array
    {
        return array_column($report['checks'], 'status', 'id');
    }

    public function test_the_suites_own_single_node_defaults_are_safe_now_and_unsafe_for_a_cluster(): void
    {
        // phpunit.xml: sync queue, array cache, array session, the local `public` upload disk.
        [$exit, $report] = $this->run_();

        $this->assertSame(0, $exit, 'single-node mode tolerates single-node-only settings');
        $this->assertSame('single-node', $report['mode']);
        $this->assertTrue($report['safe_for_single_node']);
        $this->assertFalse($report['safe_for_multi_instance']);
        $this->assertSame(0, $report['blocking']);

        $status = $this->statuses($report);
        $this->assertSame('single-node-only', $status['queue.default']);
        $this->assertSame('single-node-only', $status['cache.default']);
        $this->assertSame('single-node-only', $status['session.driver']);
        $this->assertSame('single-node-only', $status['social.media.disk']);
        $this->assertSame('ok', $status['queue.retry_after']);
        $this->assertSame('ok', $status['schedule.one_server']);
        $this->assertSame('info', $status['qr_engine']);
    }

    public function test_multi_instance_mode_turns_those_findings_into_a_failure(): void
    {
        [$exit, $report] = $this->run_(['--multi-instance' => true]);
        $this->assertSame(1, $exit);
        $this->assertSame('multi-instance', $report['mode']);
        $this->assertGreaterThan(0, $report['blocking']);

        // the same switch from configuration (TOPOLOGY_MULTI_INSTANCE)
        config(['topology.multi_instance' => true]);
        [$exit, $report] = $this->run_();
        $this->assertSame(1, $exit);
        $this->assertSame('multi-instance', $report['mode']);
    }

    /** @return array<string, array{0: array<string, mixed>, 1: string}> override => [config overrides, check id] */
    public static function singleNodeAssumptions(): array
    {
        return [
            'sync queue' => [['queue.default' => 'sync'], 'queue.default'],
            'file cache' => [['cache.default' => 'file'], 'cache.default'],
            'array cache' => [['cache.default' => 'array'], 'cache.default'],
            'file sessions' => [['session.driver' => 'file'], 'session.driver'],
            'array sessions' => [['session.driver' => 'array'], 'session.driver'],
            'file failed-job store' => [['queue.failed.driver' => 'file'], 'queue.failed'],
            'local upload disk' => [['social.media.disk' => 'public'], 'social.media.disk'],
        ];
    }

    /**
     * @dataProvider singleNodeAssumptions
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('singleNodeAssumptions')]
    public function test_each_single_node_assumption_is_named(array $override, string $checkId): void
    {
        $this->clusterSafeConfig();
        config($override);

        [$exit, $report] = $this->run_(['--multi-instance' => true]);

        $this->assertSame(1, $exit);
        $this->assertSame('single-node-only', $this->statuses($report)[$checkId]);
        $this->assertSame([$checkId], array_keys(array_filter($this->statuses($report), fn ($s) => $s === 'single-node-only')), 'only the changed setting is flagged');

        // ...and the same setting is tolerated on a single node
        [$exit] = $this->run_();
        $this->assertSame(0, $exit);
    }

    public function test_a_cluster_safe_configuration_passes_in_multi_instance_mode(): void
    {
        $this->clusterSafeConfig();

        [$exit, $report] = $this->run_(['--multi-instance' => true]);

        $this->assertSame(0, $exit, json_encode($report['checks']));
        $this->assertTrue($report['safe_for_single_node']);
        $this->assertTrue($report['safe_for_multi_instance']);
        $this->assertSame(0, $report['blocking']);
        $this->assertSame([], array_keys(array_filter($this->statuses($report), fn ($s) => in_array($s, ['unsafe', 'single-node-only'], true))));
    }

    public function test_redis_is_not_required(): void
    {
        $this->clusterSafeConfig();
        $this->assertSame('database', config('queue.default'));
        $this->assertSame('database', config('cache.default'));

        [$exit] = $this->run_(['--multi-instance' => true]);
        $this->assertSame(0, $exit, 'a database queue + database cache deployment passes without Redis');
    }

    public function test_a_defect_is_unsafe_even_on_a_single_node(): void
    {
        $this->clusterSafeConfig();
        config(['queue.connections.database_long.retry_after' => 100]); // below ProcessKnowledgeDocumentJob's 600 s

        [$exit, $report] = $this->run_();

        $this->assertSame(1, $exit, 'unsafe fails regardless of mode');
        $this->assertSame('single-node', $report['mode']);
        $this->assertFalse($report['safe_for_single_node']);
        $this->assertSame('unsafe', $this->statuses($report)['queue.retry_after']);
        $detail = collect($report['checks'])->firstWhere('id', 'queue.retry_after')['detail'];
        $this->assertStringContainsString('ProcessKnowledgeDocumentJob', $detail);
        $this->assertStringContainsString('database_long', $detail);
    }

    public function test_an_undefined_upload_disk_is_unsafe(): void
    {
        config(['social.media.disk' => 'does_not_exist']);

        [$exit, $report] = $this->run_();

        $this->assertSame(1, $exit);
        $this->assertSame('unsafe', $this->statuses($report)['social.media.disk']);
    }

    public function test_a_scheduled_singleton_without_the_distributed_lock_is_reported(): void
    {
        $this->clusterSafeConfig();
        app(Schedule::class)->command('inspire')->hourly(); // no onOneServer()

        [$exit, $report] = $this->run_(['--multi-instance' => true]);

        $this->assertSame(1, $exit);
        $this->assertSame('single-node-only', $this->statuses($report)['schedule.one_server']);
        $this->assertStringContainsString('inspire', collect($report['checks'])->firstWhere('id', 'schedule.one_server')['detail']);
    }

    public function test_the_command_is_read_only(): void
    {
        $this->clusterSafeConfig();
        $writes = [];
        DB::listen(function ($query) use (&$writes) {
            if (preg_match('/^\s*(insert|update|delete|create|alter|drop|replace|truncate)\b/i', $query->sql)) {
                $writes[] = $query->sql;
            }
        });
        $filesBefore = glob(storage_path('app/public/*')) ?: [];

        $this->run_(['--multi-instance' => true]);
        Artisan::call('ops:check-topology');

        $this->assertSame([], $writes, 'the topology check must not write to the database');
        $this->assertSame($filesBefore, glob(storage_path('app/public/*')) ?: []);
    }

    public function test_human_readable_output_names_the_mode_and_the_verdicts(): void
    {
        $this->artisan('ops:check-topology')
            ->expectsOutputToContain('mode: single-node')
            ->expectsOutputToContain('[single-node-only]')
            ->expectsOutputToContain('Safe for the current single-node deployment: yes')
            ->expectsOutputToContain('Safe for multi-instance: NO')
            ->assertExitCode(0);

        $this->artisan('ops:check-topology', ['--multi-instance' => true])
            ->expectsOutputToContain('mode: multi-instance')
            ->assertExitCode(1);
    }
}
