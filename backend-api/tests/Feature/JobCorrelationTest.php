<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Tests\Support\CapturesLogs;
use Tests\Support\CorrelationProbeJob;
use Tests\TestCase;

/**
 * Phase 12 Task 3 — the originating request id travels with a job a request dispatches, a job with no request
 * (scheduler/CLI) still runs without one, and failure logs carry the id. Uses the real `database` queue and
 * `queue:work --once`; Context is flushed before the worker runs to stand in for the separate worker process.
 */
class JobCorrelationTest extends TestCase
{
    use CapturesLogs;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['queue.default' => 'database']);

        Route::middleware('api')->prefix('api')->post('_t/dispatch-job', function () {
            CorrelationProbeJob::dispatch('from-request');

            return response()->json(['queued' => true]);
        });
        Route::middleware('api')->prefix('api')->post('_t/dispatch-failing-job', function () {
            CorrelationProbeJob::dispatch('from-request', true);

            return response()->json(['queued' => true]);
        });
    }

    private function runWorkerOnce(): void
    {
        Context::flush();                     // a worker is a different process: nothing carries over in memory
        Artisan::call('queue:work', ['connection' => 'database', '--once' => true, '--quiet' => true]);
    }

    private function storedPayload(): array
    {
        return json_decode(DB::table('jobs')->orderByDesc('id')->value('payload'), true);
    }

    public function test_the_request_id_is_stored_with_the_job_and_reaches_its_log_lines(): void
    {
        $response = $this->withHeaders(['X-Request-Id' => 'origin-request-77'])->postJson('/api/_t/dispatch-job');
        $response->assertOk();

        $payload = $this->storedPayload();
        $stored = array_map('unserialize', $payload['illuminate:log:context']['data'] ?? []);
        $this->assertSame('origin-request-77', $stored['request_id'] ?? null);

        $logs = $this->captureLogs();
        $this->runWorkerOnce();

        $record = $this->recordsMatching($logs, 'probe job running')[0];
        $this->assertSame('origin-request-77', $record->extra['request_id']);
        $this->assertSame(CorrelationProbeJob::class, $record->extra['job']['class']);
        $this->assertSame('database', $record->extra['job']['connection']);
        $this->assertSame(1, $record->extra['job']['attempt']);
        $this->assertSame(0, DB::table('jobs')->count(), 'the job ran and was consumed');
    }

    public function test_the_job_business_payload_is_unchanged_by_correlation(): void
    {
        // the same job dispatched with and without a request id: identical command/data; only the extra key differs
        Context::flush();
        CorrelationProbeJob::dispatch('same-business');
        $without = $this->storedPayload();
        DB::table('jobs')->delete();

        Context::add('request_id', 'some-request-id-9');
        CorrelationProbeJob::dispatch('same-business');
        $with = $this->storedPayload();

        $this->assertArrayNotHasKey('illuminate:log:context', $without);
        $this->assertArrayHasKey('illuminate:log:context', $with);
        unset($with['illuminate:log:context']);
        unset($with['uuid'], $without['uuid'], $with['id'], $without['id']);
        $this->assertSame($without, $with);
    }

    public function test_a_job_with_no_request_origin_runs_normally_without_a_request_id(): void
    {
        Context::flush();
        CorrelationProbeJob::dispatch('from-cli');                       // scheduler/CLI: no HTTP request involved
        $this->assertArrayNotHasKey('illuminate:log:context', $this->storedPayload());

        $logs = $this->captureLogs();
        $this->runWorkerOnce();

        $records = $this->recordsMatching($logs, 'probe job running');
        $this->assertCount(1, $records);
        $this->assertArrayNotHasKey('request_id', $records[0]->extra);
        $this->assertSame('from-cli', $records[0]->context['business']);
        $this->assertSame(CorrelationProbeJob::class, $records[0]->extra['job']['class']);   // job info still present
    }

    public function test_a_failed_job_logs_the_originating_request_id(): void
    {
        $this->withHeaders(['X-Request-Id' => 'origin-request-fail'])->postJson('/api/_t/dispatch-failing-job')->assertOk();

        $logs = $this->captureLogs();
        $this->runWorkerOnce();

        $failure = $this->recordsMatching($logs, 'Queued job failed.');
        $this->assertCount(1, $failure);
        $this->assertSame('origin-request-fail', $failure[0]->extra['request_id']);
        $this->assertSame(CorrelationProbeJob::class, $failure[0]->context['job_class']);
        $this->assertSame(\RuntimeException::class, $failure[0]->context['exception_class']);
        $this->assertSame('database', $failure[0]->context['connection']);
        $this->assertSame(1, DB::table('failed_jobs')->count());
    }

    public function test_job_context_does_not_leak_into_the_request_after_a_synchronous_job(): void
    {
        config(['queue.default' => 'sync']);
        $logs = $this->captureLogs();

        Route::middleware('api')->prefix('api')->get('_t/sync-then-log', function () {
            CorrelationProbeJob::dispatch('inline');
            \Illuminate\Support\Facades\Log::info('after the inline job');

            return response()->json(['ok' => true]);
        });

        $response = $this->getJson('/api/_t/sync-then-log');

        $after = $this->recordsMatching($logs, 'after the inline job')[0];
        $this->assertArrayNotHasKey('job', $after->extra, 'job context must be cleared once the job is done');
        $this->assertSame($response->headers->get('X-Request-Id'), $after->extra['request_id']);
        $inside = $this->recordsMatching($logs, 'probe job running')[0];
        $this->assertSame($response->headers->get('X-Request-Id'), $inside->extra['request_id']);
    }
}
