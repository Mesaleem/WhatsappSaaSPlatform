<?php

namespace App\Support\Observability;

use Illuminate\Contracts\Cache\LockProvider;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Phase 12 Task 3 — what GET /ready checks. Only dependencies the API needs in order to serve traffic:
 *
 *   database   `select 1` on the default connection
 *   cache      write + read-back on the default cache store, and a lock acquire/release when the store has locks
 *              (rate limiting, onOneServer and idempotency claims all depend on it)
 *   queue      only when the default queue connection is `database` — a read of the jobs table; `sync` needs
 *              nothing and `redis` is not probed (no Redis client is assumed to exist)
 *   scheduler  the heartbeat written by `ops:scheduler-heartbeat` (cache key, no table). Gating only when
 *              observability.readiness.scheduler.enforce is true; otherwise reported, never fatal
 *
 * Each check yields a fixed status word — never an exception message, DSN, host or path. The cause of a failure is
 * logged server-side (class name only) for the operator.
 */
final class ReadinessProbe
{
    public const HEARTBEAT_KEY = 'ops:scheduler:heartbeat';

    /** @return array{ready: bool, checks: array<string, array<string, mixed>>} */
    public function run(): array
    {
        $checks = [
            'database' => $this->guard('database', fn () => DB::select('select 1') ? 'ok' : 'fail'),
            'cache' => $this->guard('cache', fn () => $this->cache()),
            'queue' => $this->guard('queue', fn () => $this->queue()),
            'scheduler' => $this->scheduler(),
        ];

        $ready = true;
        foreach ($checks as $name => $check) {
            if ($check['status'] === 'fail' || ($name === 'scheduler' && ($check['gating'] ?? false) && $check['status'] !== 'ok')) {
                $ready = false;
            }
        }

        return ['ready' => $ready, 'checks' => $checks];
    }

    /** @return array{status: string} */
    private function guard(string $name, \Closure $check): array
    {
        try {
            return ['status' => $check()];
        } catch (Throwable $e) {
            Log::warning('Readiness check failed.', ['check' => $name, 'exception_class' => get_class($e)]);

            return ['status' => 'fail'];
        }
    }

    private function cache(): string
    {
        $key = 'ops:ready-probe';
        $token = bin2hex(random_bytes(4));
        Cache::put($key, $token, 10);
        if (Cache::get($key) !== $token) {
            return 'fail';
        }

        $store = Cache::getStore();
        if ($store instanceof LockProvider) {
            $lock = Cache::lock('ops:ready-lock', 5);
            if (! $lock->get()) {
                return 'fail';
            }
            $lock->release();
        }

        return 'ok';
    }

    private function queue(): string
    {
        $default = (string) config('queue.default');
        $driver = (string) config("queue.connections.{$default}.driver");

        if ($driver !== 'database') {
            return 'not_required';
        }

        $connection = config("queue.connections.{$default}.connection");
        DB::connection($connection ?: null)->table((string) config("queue.connections.{$default}.table", 'jobs'))->limit(1)->value('id');

        return 'ok';
    }

    /** @return array<string, mixed> */
    private function scheduler(): array
    {
        $enforce = (bool) config('observability.readiness.scheduler.enforce', false);
        $maxAge = max(1, (int) config('observability.readiness.scheduler.max_age_seconds', 180));

        try {
            $beat = Cache::get(self::HEARTBEAT_KEY);
        } catch (Throwable $e) {
            return ['status' => 'unknown', 'gating' => $enforce];
        }

        if (! is_int($beat) && ! (is_string($beat) && ctype_digit($beat))) {
            return ['status' => 'unknown', 'gating' => $enforce];     // never beat: not the same as stale
        }

        $age = max(0, time() - (int) $beat);

        return ['status' => $age > $maxAge ? 'stale' : 'ok', 'age_seconds' => $age, 'max_age_seconds' => $maxAge, 'gating' => $enforce];
    }
}
