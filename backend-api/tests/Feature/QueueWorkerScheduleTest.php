<?php

namespace Tests\Feature;

use App\Jobs\ProcessKnowledgeDocumentJob;
use App\Jobs\PublishScheduledPostJob;
use App\Jobs\RefreshPostInsightsJob;
use App\Jobs\ReconcilePlanAccountsJob;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Console\Kernel;
use ReflectionClass;
use Tests\TestCase;

/**
 * Phase 12 Task 1 — the SCHEDULED queue workers (routes/console.php) drain their queues on a connection whose
 * retry_after outlives the longest job on that queue. Runs with QUEUE_CONNECTION=database, set in the
 * environment BEFORE the app boots: the schedule is built when routes/console.php loads, and the suite's own
 * default (`sync`) would otherwise skip the plan-reconciliation worker by design.
 */
class QueueWorkerScheduleTest extends TestCase
{
    private ?string $previous = null;

    protected function setUp(): void
    {
        $this->previous = getenv('QUEUE_CONNECTION') === false ? null : (string) getenv('QUEUE_CONNECTION');
        putenv('QUEUE_CONNECTION=database');
        $_ENV['QUEUE_CONNECTION'] = $_SERVER['QUEUE_CONNECTION'] = 'database';
        parent::setUp();
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        if ($this->previous === null) {
            putenv('QUEUE_CONNECTION');
            unset($_ENV['QUEUE_CONNECTION'], $_SERVER['QUEUE_CONNECTION']);
        } else {
            putenv('QUEUE_CONNECTION='.$this->previous);
            $_ENV['QUEUE_CONNECTION'] = $_SERVER['QUEUE_CONNECTION'] = $this->previous;
        }
    }

    private function timeoutOf(string $class): int
    {
        $ref = new ReflectionClass($class);
        $declared = $ref->hasProperty('timeout') ? $ref->getProperty('timeout')->getDefaultValue() : null;

        return is_int($declared) ? $declared : 60; // queue:work --timeout default
    }

    public function test_every_scheduled_queue_worker_uses_a_connection_that_outlives_its_queues_jobs(): void
    {
        $this->assertSame('database', config('queue.default'), 'the environment override did not reach the app');
        app(Kernel::class)->all(); // loads routes/console.php

        $jobsByQueue = [
            'whatsapp-bulk' => ['App\\Jobs\\SendWhatsAppTemplateJob'],
            'journeys' => ['App\\Jobs\\ResumeJourneySessionJob', 'App\\Jobs\\ProcessDeferredInboundMessageJob'],
            'knowledge' => [ProcessKnowledgeDocumentJob::class],
            'social' => [PublishScheduledPostJob::class, RefreshPostInsightsJob::class, 'App\\Jobs\\CheckSocialConnectionJob', 'App\\Jobs\\CompleteInstagramVideoPostJob'],
            ReconcilePlanAccountsJob::QUEUE => [ReconcilePlanAccountsJob::class],
        ];

        $seen = [];
        foreach (app(Schedule::class)->events() as $event) {
            if (! str_contains((string) $event->command, 'queue:work')) {
                continue;
            }
            $this->assertSame(1, preg_match('/queue:work\s+(?:([a-z_]+)\s+)?--queue=([a-z-]+)/', (string) $event->command, $m), 'unparseable worker: '.$event->command);
            $connection = $m[1] !== '' ? $m[1] : (string) config('queue.default');
            $queue = $m[2];
            $seen[$queue] = $connection;

            $this->assertArrayHasKey($queue, $jobsByQueue, "scheduled worker for queue '{$queue}': add its jobs to this test's map");
            $retryAfter = config("queue.connections.{$connection}.retry_after");
            $this->assertNotNull($retryAfter, "worker connection '{$connection}' has no retry_after");
            $longest = max(array_map(fn ($class) => $this->timeoutOf($class), $jobsByQueue[$queue]));
            $this->assertGreaterThan($longest, (int) $retryAfter, "worker for '{$queue}' on '{$connection}'");
        }

        ksort($seen);
        $this->assertSame([
            'journeys' => 'database',
            'knowledge' => 'database_long',
            'plan-reconciliation' => 'database_long',
            'social' => 'database_long',
            'whatsapp-bulk' => 'database',
        ], $seen);
    }
}
