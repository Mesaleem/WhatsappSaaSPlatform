<?php

namespace App\Support\Observability;

use App\Logging\Redactor;
use Illuminate\Queue\Events\JobExceptionOccurred;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;

/**
 * Phase 12 Task 3 — makes job identity and the originating request id available to everything a job logs.
 *
 * The request id itself needs no code here: Laravel's log Context is serialised into the payload of every
 * dispatched job (under `illuminate:log:context`, beside — never inside — the job's own data) and hydrated again
 * before the job runs, so a job dispatched from an HTTP request logs that request's id, and a job dispatched by
 * the scheduler/CLI simply has none. This class adds the `job` context while a job runs, and one concise
 * failure line (class names only — the worker's own exception report is untouched).
 */
final class JobLogging
{
    public static function register(): void
    {
        Event::listen(JobProcessing::class, function (JobProcessing $e) {
            Context::add('job', [
                'class' => $e->job->resolveName(),
                'queue' => $e->job->getQueue(),
                'connection' => $e->connectionName,
                'attempt' => $e->job->attempts(),
                'uuid' => method_exists($e->job, 'uuid') ? $e->job->uuid() : null,
            ]);
        });

        Event::listen(JobFailed::class, function (JobFailed $e) {
            Log::error('Queued job failed.', [
                'job_class' => $e->job->resolveName(),
                'queue' => $e->job->getQueue(),
                'connection' => $e->connectionName,
                'attempts' => $e->job->attempts(),
                'exception_class' => get_class($e->exception),
                'exception_message' => mb_substr((string) Redactor::scrubText($e->exception->getMessage()), 0, 300),
            ]);
            Context::forget('job');
        });

        Event::listen(JobProcessed::class, fn () => Context::forget('job'));
        Event::listen(JobExceptionOccurred::class, fn () => Context::forget('job'));
    }
}
