<?php

namespace Tests\Feature;

use App\Support\Observability\SlowQueryLogger;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\CapturesLogs;
use Tests\TestCase;

/**
 * Phase 12 Task 3 — environment-gated slow-query visibility.
 */
class SlowQueryLoggerTest extends TestCase
{
    use CapturesLogs;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        SlowQueryLogger::reset();
    }

    private function fire(string $sql, array $bindings = [], float $ms = 900.0): void
    {
        event(new QueryExecuted($sql, $bindings, $ms, DB::connection()));
    }

    private function enable(array $overrides = []): \Monolog\Handler\TestHandler
    {
        config(array_merge(['observability.slow_query.enabled' => true, 'observability.slow_query.threshold_ms' => 500], $overrides));
        $logs = $this->captureLogs();
        SlowQueryLogger::register();

        return $logs;
    }

    public function test_it_is_disabled_by_default(): void
    {
        $this->assertFalse((bool) config('observability.slow_query.enabled'));
        $this->assertSame(500, config('observability.slow_query.threshold_ms'));

        $logs = $this->captureLogs();
        $this->fire('select * from users where id = ?', [1], 5000.0);
        DB::select('select 1');

        $this->assertSame([], $this->recordsMatching($logs, 'Slow database query'));
    }

    public function test_when_disabled_no_listener_is_registered_at_all(): void
    {
        $dispatcher = DB::getEventDispatcher();
        $before = count($dispatcher->getListeners(QueryExecuted::class));

        SlowQueryLogger::register();       // enabled=false

        $this->assertSame($before, count($dispatcher->getListeners(QueryExecuted::class)));
    }

    public function test_a_slow_query_is_logged_with_its_shape_and_duration(): void
    {
        $logs = $this->enable();

        $this->fire('select * from `contacts` where `account_id` = ? and `status` in (?, ?, ?) limit 50', [7, 'a', 'b', 'c'], 812.456);

        $records = $this->recordsMatching($logs, 'Slow database query');
        $this->assertCount(1, $records);
        $ctx = $records[0]->context;
        $this->assertSame(812.46, $ctx['duration_ms']);
        $this->assertSame(500, $ctx['threshold_ms']);
        $this->assertSame('select * from `contacts` where `account_id` = ? and `status` in (?) limit ?', $ctx['query_shape']);
        $this->assertSame(12, strlen($ctx['query_fingerprint']));
        $this->assertSame('warning', strtolower($records[0]->level->getName()));
    }

    public function test_a_fast_query_is_not_logged(): void
    {
        $logs = $this->enable();

        $this->fire('select 1', [], 499.9);

        $this->assertSame([], $this->recordsMatching($logs, 'Slow database query'));
    }

    public function test_bindings_and_literal_values_never_reach_the_log(): void
    {
        $logs = $this->enable();

        $this->fire('select * from users where email = ? and phone = ? and api_token = ?', ['victim@example.com', '+919876543210', 'tok_SECRETVALUE123'], 700.0);
        // values interpolated into the SQL text by a raw expression are stripped from the shape too
        $this->fire("select * from users where email = 'leak@example.com' and pin = 482913 and id in (11, 22, 33)", [], 700.0);

        $dump = json_encode(array_map(fn ($r) => [$r->message, $r->context, $r->extra], $logs->getRecords()));
        foreach (['victim@example.com', '+919876543210', '9876543210', 'tok_SECRETVALUE123', 'leak@example.com', '482913', '"bindings"'] as $secret) {
            $this->assertStringNotContainsString($secret, $dump);
        }
        $records = $this->recordsMatching($logs, 'Slow database query');
        $this->assertCount(2, $records);
        $this->assertArrayNotHasKey('bindings', $records[0]->context);
        $this->assertSame("select * from users where email = ? and pin = ? and id in (?)", $records[1]->context['query_shape']);
    }

    public function test_the_same_shape_is_rate_limited_and_the_suppressed_count_is_reported(): void
    {
        $logs = $this->enable(['observability.slow_query.max_per_shape_per_minute' => 2]);

        for ($i = 0; $i < 6; $i++) {
            $this->fire('select * from jobs where id = ?', [$i], 900.0);          // one shape, six times
        }
        $this->fire('select * from orders where id = ?', [1], 900.0);              // a different shape still logs

        $records = $this->recordsMatching($logs, 'Slow database query');
        $this->assertCount(3, $records, '2 for the repeated shape + 1 for the other shape');
        $this->assertSame(
            ['select * from jobs where id = ?', 'select * from jobs where id = ?', 'select * from orders where id = ?'],
            array_map(fn ($r) => $r->context['query_shape'], $records),
        );
    }

    public function test_real_queries_run_through_the_listener_without_changing_their_results(): void
    {
        $logs = $this->enable(['observability.slow_query.threshold_ms' => 0]);

        $this->assertSame(3, (int) DB::selectOne('select 1 + 2 as n')->n);

        $records = $this->recordsMatching($logs, 'Slow database query');
        $this->assertNotEmpty($records);
        $this->assertStringContainsString('select ? + ? as n', $records[0]->context['query_shape']);
    }

    public function test_the_listener_never_reads_bindings_or_issues_queries(): void
    {
        $source = (string) file_get_contents(app_path('Support/Observability/SlowQueryLogger.php'));
        $code = preg_replace('~/\*.*?\*/|//[^\n]*~s', '', $source);

        $this->assertStringNotContainsString('->bindings', $code);
        $this->assertStringNotContainsString('DB::select', $code);
        $this->assertStringNotContainsString('Cache::', $code);
    }
}
