<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Phase 12 Task 3 — `php artisan ops:queue-status` is read-only and distinguishes "unavailable" from zero.
 */
class OpsQueueStatusTest extends TestCase
{
    use RefreshDatabase;

    private function job(string $queue, int $availableAt, ?int $reservedAt = null, string $payload = '{"secret":"PAYLOAD_SECRET_DO_NOT_PRINT"}'): void
    {
        DB::table('jobs')->insert([
            'queue' => $queue, 'payload' => $payload, 'attempts' => 0,
            'reserved_at' => $reservedAt, 'available_at' => $availableAt, 'created_at' => $availableAt,
        ]);
    }

    private function failedJob(string $queue = 'journeys'): void
    {
        DB::table('failed_jobs')->insert([
            'uuid' => (string) \Illuminate\Support\Str::uuid(), 'connection' => 'database', 'queue' => $queue,
            'payload' => '{"secret":"FAILED_PAYLOAD_SECRET"}', 'exception' => 'RuntimeException: boom at /srv/app/secret.php', 'failed_at' => now(),
        ]);
    }

    /** @return array<string, mixed> */
    private function statusJson(): array
    {
        $this->assertSame(0, Artisan::call('ops:queue-status', ['--json' => true]));

        return json_decode(Artisan::output(), true, 512, JSON_THROW_ON_ERROR);
    }

    public function test_it_reports_depth_per_queue_and_the_oldest_pending_age(): void
    {
        config(['queue.default' => 'database']);
        $now = time();
        $this->job('journeys', $now - 300);                 // ready, waiting 5 min
        $this->job('journeys', $now - 60);                  // ready
        $this->job('journeys', $now + 600);                 // delayed, not yet due
        $this->job('whatsapp-bulk', $now - 10, $now - 5);   // reserved (running)
        $this->job('whatsapp-bulk', $now - 20);             // ready
        $this->failedJob();
        $this->failedJob('social');

        $report = $this->statusJson();
        $rows = collect($report['queues']['rows'])->keyBy('queue');

        $this->assertSame('database', $report['driver']);
        $this->assertTrue($report['queues']['available']);
        $this->assertSame(2, $rows['journeys']['ready']);
        $this->assertSame(1, $rows['journeys']['delayed']);
        $this->assertSame(0, $rows['journeys']['reserved']);
        $this->assertEqualsWithDelta(300, $rows['journeys']['oldest_ready_age_seconds'], 3);
        $this->assertSame(1, $rows['whatsapp-bulk']['ready']);
        $this->assertSame(1, $rows['whatsapp-bulk']['reserved']);
        $this->assertEqualsWithDelta(20, $rows['whatsapp-bulk']['oldest_ready_age_seconds'], 3);
        $this->assertSame(['available' => true, 'count' => 2], $report['failed_jobs']);
    }

    public function test_an_empty_queue_is_zero_and_a_queue_with_nothing_ready_has_no_age_rather_than_zero(): void
    {
        config(['queue.default' => 'database']);

        $empty = $this->statusJson();
        $this->assertTrue($empty['queues']['available']);
        $this->assertSame([], $empty['queues']['rows']);
        $this->assertSame(0, $empty['failed_jobs']['count']);

        $this->job('social', time() + 3600);                // only a delayed job
        $row = $this->statusJson()['queues']['rows'][0];
        $this->assertSame(0, $row['ready']);
        $this->assertNull($row['oldest_ready_age_seconds'], '"nothing is waiting" is not "waited 0 seconds"');
    }

    public function test_unavailable_metrics_are_reported_as_unavailable_not_zero(): void
    {
        config(['queue.default' => 'sync', 'queue.failed.driver' => 'null']);

        $report = $this->statusJson();

        $this->assertFalse($report['queues']['available']);
        $this->assertNull($report['queues']['rows']);
        $this->assertStringContainsString("'sync'", $report['queues']['reason']);
        $this->assertFalse($report['failed_jobs']['available']);
        $this->assertNull($report['failed_jobs']['count']);

        Artisan::call('ops:queue-status');
        $text = Artisan::output();
        $this->assertStringContainsString('unavailable', $text);
        $this->assertStringNotContainsString('failed jobs:   0', $text);
    }

    public function test_a_redis_queue_is_reported_unavailable_without_connecting(): void
    {
        config(['queue.default' => 'redis']);

        $report = $this->statusJson();

        $this->assertFalse($report['queues']['available']);
        $this->assertStringContainsString("'redis'", $report['queues']['reason']);
    }

    public function test_an_unreadable_jobs_table_fails_the_command_and_leaks_no_detail(): void
    {
        config(['queue.default' => 'database', 'queue.connections.database.table' => 'jobs_missing_table']);

        $this->assertSame(1, Artisan::call('ops:queue-status', ['--json' => true]));
        $output = Artisan::output();

        $this->assertStringContainsString('"available": false', $output);
        $this->assertStringNotContainsString('SQLSTATE', $output);
        $this->assertStringNotContainsString('select', strtolower($output));
    }

    public function test_it_never_modifies_prints_or_retries_anything(): void
    {
        config(['queue.default' => 'database']);
        $this->job('journeys', time() - 100);
        $this->job('journeys', time() - 100, time() - 50);
        $this->failedJob();

        $before = [DB::table('jobs')->get()->all(), DB::table('failed_jobs')->get()->all()];

        Artisan::call('ops:queue-status');
        $text = Artisan::output();
        Artisan::call('ops:queue-status', ['--json' => true]);
        $json = Artisan::output();

        $after = [DB::table('jobs')->get()->all(), DB::table('failed_jobs')->get()->all()];
        $this->assertEquals($before, $after, 'the command changed queue state');
        foreach ([$text, $json] as $output) {
            $this->assertStringNotContainsString('PAYLOAD_SECRET_DO_NOT_PRINT', $output);
            $this->assertStringNotContainsString('FAILED_PAYLOAD_SECRET', $output);
            $this->assertStringNotContainsString('secret.php', $output);
        }
    }

    public function test_the_command_source_contains_only_read_operations(): void
    {
        $source = (string) file_get_contents(app_path('Console/Commands/OpsQueueStatus.php'));
        $code = preg_replace('~/\*.*?\*/|//[^\n]*~s', '', $source);

        foreach (['->delete(', '->update(', '->insert(', '->truncate(', 'queue:retry', 'queue:flush', 'queue:forget', 'Artisan::call', '->release(', '->pop('] as $write) {
            $this->assertStringNotContainsString($write, $code);
        }
    }
}
