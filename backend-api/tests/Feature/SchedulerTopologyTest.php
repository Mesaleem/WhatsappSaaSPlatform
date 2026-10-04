<?php

namespace Tests\Feature;

use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Console\Scheduling\CacheSchedulingMutex;
use Illuminate\Contracts\Console\Kernel;
use Tests\TestCase;

/**
 * Phase 12 Task 1 — scheduler multi-instance safety. `schedule:run` may run on several instances; a singleton
 * operation must then be taken by exactly one of them per tick (->onOneServer()), while queue drains stay
 * parallel-safe by design (see the block comment in routes/console.php).
 */
class SchedulerTopologyTest extends TestCase
{
    /** Singleton operations: dispatchers, sweepers, settlers, provider pollers. */
    private const SINGLETONS = [
        'ads:check-performance-rules',
        'journeys:resume-due',
        'group-dispatch:recover-stale',
        'credits:release-expired-reservations',
        'ai:settle-operations',
        'knowledge:recover-documents',
        'social:check-connections',
        'social:publish-due',
        'sanctum:prune-expired',
        'ops:prune-retention',
        'ops:scheduler-heartbeat',
        'social:refresh-insights',
    ];

    /** @return list<Event> */
    private function events(): array
    {
        app(Kernel::class)->all(); // loads routes/console.php

        return app(Schedule::class)->events();
    }

    private function commandName(Event $event): string
    {
        return trim((string) preg_replace('/^.*artisan[\'"]?\s+/', '', (string) $event->command));
    }

    private function isQueueDrain(Event $event): bool
    {
        return str_contains((string) $event->command, 'queue:work');
    }

    public function test_every_singleton_scheduled_command_has_the_distributed_scheduler_lock(): void
    {
        $singletons = [];
        foreach ($this->events() as $event) {
            if ($this->isQueueDrain($event)) {
                continue;
            }
            $name = $this->commandName($event);
            $singletons[] = explode(' ', $name)[0];
            $this->assertTrue($event->onOneServer, "'{$name}' is a singleton scheduled task but is missing ->onOneServer()");
        }

        sort($singletons);
        $expected = self::SINGLETONS;
        sort($expected);
        $this->assertSame($expected, $singletons, 'a scheduled command was added or removed: classify it as a singleton (onOneServer) or a queue drain, and update this list');
    }

    public function test_queue_drains_are_deliberately_parallel_safe_and_not_one_server(): void
    {
        $drains = array_values(array_filter($this->events(), fn (Event $e) => $this->isQueueDrain($e)));

        // sync (the suite's default) skips the plan-reconciliation drain via ->when(); the other four are always present.
        $this->assertGreaterThanOrEqual(4, count($drains));
        foreach ($drains as $event) {
            $this->assertFalse($event->onOneServer, 'queue drains must stay free to run on every instance: '.$event->command);
            $this->assertStringContainsString('--stop-when-empty', (string) $event->command);
            $this->assertStringContainsString('--max-time=55', (string) $event->command);
            $this->assertTrue($event->withoutOverlapping, 'a drain must not stack on a still-running one: '.$event->command);
        }
    }

    public function test_every_scheduled_task_keeps_its_overlap_guard(): void
    {
        foreach ($this->events() as $event) {
            $this->assertTrue($event->withoutOverlapping, 'onOneServer must be an addition to withoutOverlapping, never a replacement: '.$event->command);
        }
    }

    public function test_the_one_server_lock_lets_only_one_instance_run_each_singleton_per_tick(): void
    {
        $mutex = app(CacheSchedulingMutex::class);
        $tick = now()->startOfMinute();

        foreach ($this->events() as $event) {
            if ($this->isQueueDrain($event)) {
                continue;
            }
            $this->assertTrue($mutex->create($event, $tick), 'first instance should win: '.$event->command);
            $this->assertFalse($mutex->create($event, $tick), 'second instance must lose: '.$event->command);
            $this->assertTrue($mutex->create($event, $tick->copy()->addMinute()), 'the next tick is a fresh election: '.$event->command);
        }
    }

    public function test_one_instances_lock_does_not_block_a_different_task_in_the_same_tick(): void
    {
        $mutex = app(CacheSchedulingMutex::class);
        $tick = now()->startOfMinute();
        $singletons = array_values(array_filter($this->events(), fn (Event $e) => ! $this->isQueueDrain($e)));

        $this->assertTrue($mutex->create($singletons[0], $tick));
        $this->assertTrue($mutex->create($singletons[1], $tick), 'locks are per task');
    }
}
