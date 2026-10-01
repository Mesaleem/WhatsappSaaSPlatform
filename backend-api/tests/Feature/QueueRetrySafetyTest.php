<?php

namespace Tests\Feature;

use App\Jobs\ProcessGroupDirectMessageJob;
use App\Jobs\ProcessGroupDispatchJob;
use App\Jobs\ProcessKnowledgeDocumentJob;
use App\Jobs\PublishScheduledPostJob;
use App\Jobs\ReconcilePlanAccountsJob;
use App\Jobs\RefreshPostInsightsJob;
use App\Models\MessageDispatchLog;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use ReflectionClass;
use Tests\TestCase;

/**
 * Phase 12 Task 1 — queue retry safety. A reserved job is handed to ANY worker again once the connection's
 * `retry_after` passes; a job whose timeout is >= that window can therefore run twice at once as soon as a
 * second worker exists. These tests fail when a job (or a scheduled worker) is put on a connection whose
 * retry_after it can outlive.
 */
class QueueRetrySafetyTest extends TestCase
{
    use RefreshDatabase;

    /** Laravel's `queue:work --timeout` default: the effective timeout of a job that declares none. */
    private const WORKER_DEFAULT_TIMEOUT = 60;

    /** @return list<class-string> */
    private function queuedJobs(): array
    {
        $classes = [];
        foreach (glob(app_path('Jobs/*.php')) as $file) {
            $class = 'App\\Jobs\\'.basename($file, '.php');
            if (is_subclass_of($class, ShouldQueue::class)) {
                $classes[] = $class;
            }
        }

        return $classes;
    }

    private function timeoutOf(string $class): int
    {
        $ref = new ReflectionClass($class);
        $declared = $ref->hasProperty('timeout') ? $ref->getProperty('timeout')->getDefaultValue() : null;

        return is_int($declared) ? $declared : self::WORKER_DEFAULT_TIMEOUT;
    }

    private function retryAfter(string $connection): int
    {
        $value = config("queue.connections.{$connection}.retry_after");
        $this->assertNotNull($value, "queue connection '{$connection}' has no retry_after");

        return (int) $value;
    }

    /**
     * The connection(s) a job can run on, computed independently of App\Support\Topology: a CONNECTION constant,
     * else resolveConnection() for each supported default driver, else the plain connections.
     *
     * @return list<string>
     */
    private function connectionsOf(string $class): array
    {
        $ref = new ReflectionClass($class);
        if ($ref->hasConstant('CONNECTION')) {
            return [(string) $ref->getConstant('CONNECTION')];
        }
        if ($ref->hasMethod('resolveConnection')) {
            return array_map(fn ($default) => $class::resolveConnection($default), ['database', 'redis']);
        }

        return ['database', 'redis'];
    }

    public function test_no_configured_job_timeout_reaches_the_retry_after_of_its_connection(): void
    {
        $jobs = $this->queuedJobs();
        $this->assertGreaterThanOrEqual(15, count($jobs), 'the job scan found too few jobs to be meaningful');

        $violations = [];
        foreach ($jobs as $class) {
            foreach ($this->connectionsOf($class) as $connection) {
                if ($this->timeoutOf($class) >= $this->retryAfter($connection)) {
                    $violations[] = class_basename($class).' timeout '.$this->timeoutOf($class)." >= {$connection} retry_after ".$this->retryAfter($connection);
                }
            }
        }

        $this->assertSame([], $violations);
    }

    public function test_the_named_long_jobs_run_on_a_long_retry_connection(): void
    {
        $this->assertSame(600, (new ReflectionClass(ProcessKnowledgeDocumentJob::class))->getProperty('timeout')->getDefaultValue());
        $this->assertSame(900, (new ReflectionClass(ReconcilePlanAccountsJob::class))->getProperty('timeout')->getDefaultValue());

        $this->assertSame('database_long', ProcessKnowledgeDocumentJob::CONNECTION);
        $this->assertSame('database_long', PublishScheduledPostJob::CONNECTION);
        $this->assertSame('database_long', RefreshPostInsightsJob::CONNECTION);
        $this->assertGreaterThan(600, $this->retryAfter(ProcessKnowledgeDocumentJob::CONNECTION));
        $this->assertGreaterThan(900, $this->retryAfter(ReconcilePlanAccountsJob::resolveConnection('database')));
        $this->assertGreaterThan(900, $this->retryAfter(ReconcilePlanAccountsJob::resolveConnection('redis')));
    }

    public function test_the_plan_reconciliation_job_still_follows_the_default_connection(): void
    {
        $this->assertSame('database_long', ReconcilePlanAccountsJob::resolveConnection('database'));
        $this->assertSame('redis_long', ReconcilePlanAccountsJob::resolveConnection('redis'));
        // sync (local development, the test suite) and a driver without a long sibling keep the default.
        $this->assertSame('sync', ReconcilePlanAccountsJob::resolveConnection('sync'));
        $this->assertSame('beanstalkd', ReconcilePlanAccountsJob::resolveConnection('beanstalkd'));

        config(['queue.default' => 'database']);
        $this->assertSame('database_long', (new ReconcilePlanAccountsJob('growth'))->connection);
        $this->assertSame(ReconcilePlanAccountsJob::QUEUE, (new ReconcilePlanAccountsJob('growth'))->queue);

        config(['queue.default' => 'sync']);
        $this->assertSame('sync', (new ReconcilePlanAccountsJob('growth'))->connection, 'sync stays inline, as before');
    }

    public function test_the_default_database_connection_is_unchanged_and_the_group_jobs_still_fit_it(): void
    {
        $this->assertSame(90, $this->retryAfter('database'));
        $this->assertSame(85, MessageDispatchLog::GROUP_JOB_TIMEOUT_SECONDS);
        $this->assertLessThan($this->retryAfter('database'), $this->timeoutOf(ProcessGroupDispatchJob::class));
        $this->assertLessThan($this->retryAfter('database'), $this->timeoutOf(ProcessGroupDirectMessageJob::class));
    }

    public function test_the_long_connections_share_the_table_and_database_of_their_base_connection(): void
    {
        foreach (['database' => 'database_long', 'redis' => 'redis_long'] as $base => $long) {
            $a = config("queue.connections.{$base}");
            $b = config("queue.connections.{$long}");
            $this->assertSame($a['driver'], $b['driver']);
            foreach (['connection', 'table', 'queue'] as $key) {
                $this->assertSame($a[$key] ?? null, $b[$key] ?? null, "{$long}.{$key} must match {$base}.{$key} so a worker on either drains the same jobs");
            }
            $this->assertGreaterThan($a['retry_after'], $b['retry_after']);
        }

        $longest = max(array_map(fn ($class) => $this->timeoutOf($class), $this->queuedJobs()));
        $this->assertGreaterThan($longest, $this->retryAfter('database_long'));
    }

    public function test_every_dispatch_of_a_long_job_names_its_long_connection(): void
    {
        foreach ([ProcessKnowledgeDocumentJob::class, PublishScheduledPostJob::class, RefreshPostInsightsJob::class] as $class) {
            $short = class_basename($class);
            $seen = 0;
            foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(app_path())) as $file) {
                if ($file->getExtension() !== 'php' || str_contains($file->getPathname(), DIRECTORY_SEPARATOR.'Jobs'.DIRECTORY_SEPARATOR)) {
                    continue;
                }
                $source = file_get_contents($file->getPathname());
                if (preg_match_all('/'.$short.'::dispatch\(.{0,260}/s', $source, $matches)) {
                    foreach ($matches[0] as $statement) {
                        $seen++;
                        $this->assertStringContainsString("->onConnection({$short}::CONNECTION)", $statement, "{$short} dispatched in {$file->getFilename()} without its long-retry connection");
                    }
                }
            }
            $this->assertGreaterThanOrEqual(1, $seen, "no dispatch site found for {$short}");
        }
    }

    public function test_the_long_connection_really_keeps_a_reserved_job_away_from_other_workers(): void
    {
        config(['queue.default' => 'database']);
        Queue::connection('database_long')->push(new RetryWindowProbeJob);
        $this->assertSame(1, DB::table('jobs')->count());

        $this->assertNotNull(Queue::connection('database_long')->pop(), 'first worker reserves it');

        // 100 s later: still inside database_long's window, but past `database`'s 90 s.
        $this->travel(100)->seconds();
        $this->assertNull(Queue::connection('database_long')->pop(), 'a second worker on database_long must NOT get a job reserved 100 s ago');
        $redelivered = Queue::connection('database')->pop();
        $this->assertNotNull($redelivered, 'the 90 s connection hands the same job out again — the hazard the long connection removes');

        // Past database_long's window the job is redelivered.
        $this->travel(config('queue.connections.database_long.retry_after') + 5)->seconds();
        $this->assertNotNull(Queue::connection('database_long')->pop());
    }
}

class RetryWindowProbeJob implements ShouldQueue
{
    use Dispatchable, Queueable;

    public function handle(): void
    {
    }
}
