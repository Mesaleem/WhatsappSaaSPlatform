<?php

namespace App\Support\Topology;

use Illuminate\Contracts\Queue\ShouldQueue;
use ReflectionClass;

/**
 * Phase 12 Task 1 — which queue connection(s) each queued job can run on, and whether the connection's
 * `retry_after` outlives the job's timeout.
 *
 * Why it matters: a reserved job becomes available to ANY worker again once `retry_after` passes. A job whose
 * timeout is >= that window can be picked up a second time while it is still running — harmless with one
 * worker (the scheduler guard keeps it to one), a duplicate execution with two.
 *
 *  - timeout  = the job's declared `$timeout`, else the worker default (60 s — `queue:work --timeout`).
 *  - connection(s) = the job's `CONNECTION` constant when it names one; else `resolveConnection()` evaluated
 *    for every base driver (the job "follows QUEUE_CONNECTION"); else any base connection it may be
 *    dispatched to (see baseConnections()).
 */
final class JobTimeoutInventory
{
    /** Laravel's `queue:work --timeout` default, used for a job that declares no `$timeout`. */
    public const WORKER_DEFAULT_TIMEOUT = 60;

    /** @return list<class-string> */
    public function jobClasses(): array
    {
        $classes = [];
        foreach (glob(app_path('Jobs/*.php')) ?: [] as $file) {
            $class = 'App\\Jobs\\'.basename($file, '.php');
            if (class_exists($class) && is_subclass_of($class, ShouldQueue::class) && ! (new ReflectionClass($class))->isAbstract()) {
                $classes[] = $class;
            }
        }
        sort($classes);

        return $classes;
    }

    /** @param class-string $class */
    public function timeout(string $class): int
    {
        $ref = new ReflectionClass($class);
        if ($ref->hasProperty('timeout')) {
            $default = $ref->getProperty('timeout')->getDefaultValue();
            if (is_int($default)) {
                return $default;
            }
        }

        return self::WORKER_DEFAULT_TIMEOUT;
    }

    /**
     * @param  class-string  $class
     * @return list<string>
     */
    public function connections(string $class): array
    {
        $ref = new ReflectionClass($class);
        if ($ref->hasConstant('CONNECTION')) {
            return [(string) $ref->getConstant('CONNECTION')];
        }

        $base = $this->baseConnections();
        if ($ref->hasMethod('resolveConnection') && $ref->getMethod('resolveConnection')->isStatic()) {
            return array_values(array_unique(array_map(fn (string $default) => $class::resolveConnection($default), $base)));
        }

        return $base;
    }

    /**
     * The connections a job that "follows QUEUE_CONNECTION" can end up on: the CURRENT default plus every
     * connection that has a `_long` sibling (database, redis — the drivers this app is documented for). A
     * stock connection the app never selects (beanstalkd, sqs) is not a candidate unless it IS the default.
     *
     * @return list<string>
     */
    public function baseConnections(): array
    {
        $names = [];
        $default = (string) config('queue.default');
        foreach ((array) config('queue.connections', []) as $name => $connection) {
            $name = (string) $name;
            if (($connection['driver'] ?? null) === 'sync' || ! isset($connection['retry_after']) || str_ends_with($name, '_long')) {
                continue;
            }
            if ($name === $default || config("queue.connections.{$name}_long") !== null) {
                $names[] = $name;
            }
        }

        return $names;
    }

    public function retryAfter(string $connection): ?int
    {
        $value = config("queue.connections.{$connection}.retry_after");

        return $value === null ? null : (int) $value;
    }

    /** @return list<array{job: string, timeout: int, connection: string, retry_after: int|null}> */
    public function violations(): array
    {
        $found = [];
        foreach ($this->jobClasses() as $class) {
            $timeout = $this->timeout($class);
            foreach ($this->connections($class) as $connection) {
                $retryAfter = $this->retryAfter($connection);
                if ($retryAfter === null || $timeout >= $retryAfter) {
                    $found[] = ['job' => class_basename($class), 'timeout' => $timeout, 'connection' => $connection, 'retry_after' => $retryAfter];
                }
            }
        }

        return $found;
    }
}
