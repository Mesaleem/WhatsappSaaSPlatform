<?php

namespace Tests\Feature;

use App\Support\Observability\ReadinessProbe;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Phase 12 Task 3 — GET /ready (readiness) next to the unchanged GET /up (liveness).
 */
class ReadinessEndpointTest extends TestCase
{
    use RefreshDatabase;

    private const TOKEN = 'ops-detail-token-for-tests';

    private string $originalDefaultConnection;

    protected function setUp(): void
    {
        parent::setUp();
        $this->originalDefaultConnection = (string) config('database.default');
    }

    protected function tearDown(): void
    {
        // RefreshDatabase rolls back on the default connection: put it back before the framework tears down
        config(['database.default' => $this->originalDefaultConnection]);

        parent::tearDown();
    }

    private function ready(array $headers = [])
    {
        return $this->withHeaders($headers)->getJson('/ready');
    }

    public function test_a_healthy_instance_is_ready_with_a_status_only_public_body(): void
    {
        $response = $this->ready();

        $response->assertOk();
        $this->assertSame(['status' => 'ready'], $response->json());
        $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
    }

    public function test_an_unavailable_database_is_a_503_that_leaks_nothing(): void
    {
        config([
            'database.connections.broken' => ['driver' => 'sqlite', 'database' => '/nonexistent-dir-xyz/secret-host.sqlite', 'prefix' => '', 'foreign_key_constraints' => true],
            'database.default' => 'broken',
        ]);
        DB::purge('broken');

        $response = $this->ready();

        $response->assertStatus(503);
        $this->assertSame(['status' => 'unavailable'], $response->json());
        $body = $response->getContent();
        foreach (['nonexistent-dir-xyz', 'secret-host', 'SQLSTATE', 'sqlite', 'PDOException', 'vendor/', '.php', 'Stack trace'] as $leak) {
            $this->assertStringNotContainsString($leak, $body);
        }
    }

    public function test_an_unavailable_cache_is_a_503_that_leaks_nothing(): void
    {
        Cache::shouldReceive('put')->andThrow(new \RuntimeException('redis://user:p4ssw0rd@10.0.0.5:6379 connection refused'));

        $response = $this->ready();

        $response->assertStatus(503);
        $this->assertSame(['status' => 'unavailable'], $response->json());
        $this->assertStringNotContainsString('p4ssw0rd', $response->getContent());
        $this->assertStringNotContainsString('10.0.0.5', $response->getContent());
    }

    public function test_a_cache_that_does_not_return_what_was_written_is_not_ready(): void
    {
        config(['cache.default' => 'null', 'cache.stores.null' => ['driver' => 'null']]);
        $this->app['cache']->forgetDriver('null');

        $this->ready()->assertStatus(503);
    }

    public function test_the_database_queue_table_is_checked_only_when_the_database_queue_is_the_default(): void
    {
        config(['queue.default' => 'sync', 'observability.readiness.detail_token' => self::TOKEN]);
        $this->assertSame('not_required', $this->ready(['X-Ops-Token' => self::TOKEN])->json('checks.queue.status'));

        config(['queue.default' => 'database']);
        $this->assertSame('ok', $this->ready(['X-Ops-Token' => self::TOKEN])->json('checks.queue.status'));

        config(['queue.connections.database.table' => 'jobs_table_that_does_not_exist']);
        $broken = $this->ready();
        $broken->assertStatus(503);
        $this->assertStringNotContainsString('jobs_table_that_does_not_exist', $broken->getContent());
    }

    public function test_a_stale_scheduler_heartbeat_makes_it_unready_only_when_enforced(): void
    {
        config(['observability.readiness.detail_token' => self::TOKEN, 'observability.readiness.scheduler.max_age_seconds' => 180]);
        Cache::put(ReadinessProbe::HEARTBEAT_KEY, time() - 1000, 3600);

        // default: reported, not gating — an API node can serve traffic without the scheduler
        $reported = $this->ready(['X-Ops-Token' => self::TOKEN]);
        $reported->assertOk();
        $this->assertSame('stale', $reported->json('checks.scheduler.status'));
        $this->assertGreaterThanOrEqual(1000, $reported->json('checks.scheduler.age_seconds'));

        config(['observability.readiness.scheduler.enforce' => true]);
        $this->ready()->assertStatus(503);

        Cache::put(ReadinessProbe::HEARTBEAT_KEY, time() - 30, 3600);
        $this->ready()->assertOk();
        $this->assertSame('ok', $this->ready(['X-Ops-Token' => self::TOKEN])->json('checks.scheduler.status'));
    }

    public function test_a_scheduler_that_never_beat_is_unknown_not_stale(): void
    {
        config(['observability.readiness.detail_token' => self::TOKEN]);
        Cache::forget(ReadinessProbe::HEARTBEAT_KEY);

        $this->assertSame('unknown', $this->ready(['X-Ops-Token' => self::TOKEN])->json('checks.scheduler.status'));
        $this->ready()->assertOk();

        config(['observability.readiness.scheduler.enforce' => true]);
        $this->ready()->assertStatus(503);      // enforcing operators asked for a heartbeat; none exists
    }

    public function test_the_heartbeat_command_writes_the_cache_key_the_probe_reads(): void
    {
        Cache::forget(ReadinessProbe::HEARTBEAT_KEY);

        $this->artisan('ops:scheduler-heartbeat')->assertSuccessful();

        $this->assertEqualsWithDelta(time(), (int) Cache::get(ReadinessProbe::HEARTBEAT_KEY), 5);
        $this->assertSame(0, DB::table('migrations')->where('migration', 'like', '%heartbeat%')->count(), 'no migration for the heartbeat');
    }

    public function test_per_check_detail_is_only_for_the_ops_token(): void
    {
        config(['observability.readiness.detail_token' => self::TOKEN]);

        $this->assertArrayNotHasKey('checks', $this->ready()->json());
        $this->assertArrayNotHasKey('checks', $this->ready(['X-Ops-Token' => 'wrong-token'])->json());
        $this->assertArrayNotHasKey('checks', $this->withToken(self::TOKEN)->getJson('/ready')->json(), 'a bearer token is not the ops token');

        $detail = $this->ready(['X-Ops-Token' => self::TOKEN]);
        $detail->assertOk();
        $this->assertSame(['database', 'cache', 'queue', 'scheduler'], array_keys($detail->json('checks')));
        $this->assertSame('ok', $detail->json('checks.database.status'));
        $this->assertSame('ok', $detail->json('checks.cache.status'));
        // fixed status words only: no message/exception/host fields anywhere
        $this->assertStringNotContainsString('exception', strtolower($detail->getContent()));
        $this->assertStringNotContainsString('message', strtolower($detail->getContent()));
    }

    public function test_detail_is_never_returned_when_no_token_is_configured(): void
    {
        config(['observability.readiness.detail_token' => null]);

        $this->assertArrayNotHasKey('checks', $this->ready(['X-Ops-Token' => ''])->json());
        $this->assertArrayNotHasKey('checks', $this->ready(['X-Ops-Token' => 'anything'])->json());
    }

    public function test_up_stays_a_dependency_free_liveness_probe(): void
    {
        // the framework's own /up answers even when the database is broken
        config([
            'database.connections.broken' => ['driver' => 'sqlite', 'database' => '/nonexistent-dir-xyz/x.sqlite', 'prefix' => ''],
            'database.default' => 'broken',
        ]);
        DB::purge('broken');

        $this->get('/up')->assertOk();
        $this->getJson('/ready')->assertStatus(503);       // while readiness correctly disagrees
    }

    public function test_readiness_is_unauthenticated_and_adds_no_headers_that_identify_the_stack(): void
    {
        $response = $this->get('/ready');

        $response->assertOk();
        $response->assertHeaderMissing('X-Powered-By');
        $response->assertHeaderMissing('Set-Cookie');
    }
}
