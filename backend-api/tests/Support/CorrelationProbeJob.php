<?php

namespace Tests\Support;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;

/** Test-only job: logs, optionally fails. Carries one business value so tests can prove the payload is intact. */
class CorrelationProbeJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 1;

    public function __construct(public string $business = 'unchanged', public bool $fail = false) {}

    public function handle(): void
    {
        Log::info('probe job running', ['business' => $this->business]);

        if ($this->fail) {
            throw new \RuntimeException('probe job exploded');
        }
    }
}
