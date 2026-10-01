<?php

use App\Jobs\ProcessKnowledgeDocumentJob;
use App\Jobs\PublishScheduledPostJob;
use App\Jobs\ReconcilePlanAccountsJob;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

/*
 * Phase 12 Task 1 — multi-instance scheduler rules.
 *
 * `schedule:run` may run on SEVERAL app instances at once (each has the crontab line). Two kinds of entry:
 *
 *  1. Singleton operations — a dispatcher, a sweeper, a settler, a provider poller. Running one on two
 *     instances in the same minute is wasted work at best and a double provider call / double alert at
 *     worst. These carry ->onOneServer(): one instance wins an atomic cache lock per tick. That needs a
 *     cache store that is SHARED by the instances and supports locks (`database`, `redis`, …) — NOT
 *     `array`/`file`; `php artisan ops:check-topology` reports it. ->withoutOverlapping() stays: it guards a
 *     slow run against the NEXT tick, onOneServer guards against another INSTANCE in the same tick.
 *
 *  2. Queue drains (`queue:work … --stop-when-empty --max-time=55`) — deliberately NOT onOneServer. A
 *     worker takes jobs through an atomic reservation, so any number of workers is safe by design and more
 *     than one is welcome; withoutOverlapping() merely avoids stacking a new minute-worker on a still-
 *     running one. Each names the connection whose retry_after outlives the longest job on that queue
 *     (config/queue.php `database_long`); see tests/Feature/QueueRetrySafetyTest and
 *     tests/Feature/SchedulerTopologyTest, which fail when either rule is broken by a new entry.
 */

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Social Media Marketing & Meta Ads Automation Expansion — Phase 3.
// Auto-Budget Guard. Spec asked for "every 15-30 minutes" — 15 minutes
// chosen (the tighter end of that range) since the rule this guards
// against is uncontrolled ad spend; a shorter poll interval bounds the
// worst-case overspend window before an auto-pause fires. Laravel 11 has
// no app/Console/Kernel.php — this file (routes/console.php) is the
// framework's own replacement location for schedule registration.
// withoutOverlapping() guards against a slow Meta API response on one
// run still executing when the next 15-minute tick fires.
// Phase 10 Task 6 — the overlap mutex expires after 30 minutes (two ticks). The default is 24 HOURS: a
// run killed mid-way (deploy, OOM, container restart) would otherwise leave the guard switched off for a day.
Schedule::command('ads:check-performance-rules')
    ->everyFifteenMinutes()
    ->onOneServer()
    ->withoutOverlapping(30);

// Anti-Spam Bulk Dispatch -- SendWhatsAppTemplateJob is enqueued onto
// the 'database' connection's 'whatsapp-bulk' queue (never the app's
// default 'sync' connection -- see that Job's own docblock), so
// nothing drains it without this. `--stop-when-empty` processes every
// currently-due job then exits rather than running forever as a
// daemon -- there is no persistent queue-worker process defined
// anywhere in this repo (checked before choosing this design), so
// this scheduled command IS the worker: each minute's tick picks up
// whatever became due since the last one. --max-time=55 keeps one
// tick safely inside its own minute, on top of withoutOverlapping()
// below. This assumes `php artisan schedule:run` is already invoked
// once a minute by an external cron/deployment process -- the same
// pre-existing assumption ads:check-performance-rules above already
// depends on, not a new one introduced here.
Schedule::command('queue:work database --queue=whatsapp-bulk --stop-when-empty --max-time=55')
    ->everyMinute()
    ->withoutOverlapping();

// Phase 7 Task 1 — Journey temporal backbone. journeys:resume-due finds
// sessions whose `delay` has elapsed and queues one ResumeJourneySessionJob
// each on database:journeys; the worker line below drains that queue the
// same way the whatsapp-bulk worker above drains its own (same external
// `schedule:run` cron assumption, no new infrastructure). The session row
// is the source of truth and every job claims it atomically, so an
// overlapping tick, a duplicate job or a restarted worker cannot run a
// step twice.
Schedule::command('journeys:resume-due')
    ->everyMinute()
    ->onOneServer()
    ->withoutOverlapping();

Schedule::command('queue:work database --queue=journeys --stop-when-empty --max-time=55')
    ->everyMinute()
    ->withoutOverlapping();

// Phase 5 fix P5-3 — settles (and refunds) group message batches whose job
// died without completing them. Same external `schedule:run` cron
// assumption as every entry above. Only batches past the stale thresholds
// in MessageDispatchLog are touched; each is re-checked under its row lock,
// so an overlapping or repeated run is harmless.
Schedule::command('group-dispatch:recover-stale')
    ->everyFiveMinutes()
    ->onOneServer()
    ->withoutOverlapping();

// Phase 8 Task 3 — releases credit reservations whose caller-set expires_at
// has passed, so a caller that died between reserve and settle cannot
// strand credits. Reservations without an expiry are never touched; each
// release is an idempotent CreditService release under the credit-account
// lock, so an overlapping or repeated run is harmless. Same external
// `schedule:run` cron assumption as every entry above.
Schedule::command('credits:release-expired-reservations')
    ->everyFiveMinutes()
    ->onOneServer()
    ->withoutOverlapping();

// Phase 8 Task 5 — completes AI usage charges whose ledger write failed
// in-line, and releases the credit holds of failed / abandoned AI
// operations. Idempotent (CreditService consume/release keys + conditional
// status updates); same `schedule:run` cron assumption as above.
Schedule::command('ai:settle-operations')
    ->everyFiveMinutes()
    ->onOneServer()
    ->withoutOverlapping();

// Phase 8 Task 9 — knowledge-base document processing. Jobs are queued on
// database:knowledge by KnowledgeBaseService; the worker below drains them
// (same external `schedule:run` cron assumption as every entry above), and
// knowledge:recover-documents re-dispatches a lost job or a document whose
// worker died. Every job claims its document row atomically.
Schedule::command('queue:work '.ProcessKnowledgeDocumentJob::CONNECTION.' --queue=knowledge --stop-when-empty --max-time=55')
    ->everyMinute()
    ->withoutOverlapping();

Schedule::command('knowledge:recover-documents')
    ->everyFiveMinutes()
    ->onOneServer()
    ->withoutOverlapping();

// Phase 9 Task 2 — social connection health. social:check-connections
// claims every connected social account whose last check attempt AND last
// confirmed status are older than SOCIAL_CONNECTION_CHECK_INTERVAL_MINUTES
// (default 60) and queues one CheckSocialConnectionJob each on
// database:social; the worker below drains it (same external
// `schedule:run` cron assumption as every entry above). Each row is
// claimed with a conditional UPDATE, so running every 15 minutes, an
// overlapping tick or several servers never check a connection twice
// within the interval.
Schedule::command('social:check-connections')
    ->everyFifteenMinutes()
    ->onOneServer()
    ->withoutOverlapping();

// Phase 9 Task 3 — scheduled organic publishing. social:publish-due claims
// due `scheduled` organic posts (one conditional UPDATE each, so a post is
// sent by at most one worker) and queues PublishScheduledPostJob on
// database:social, drained by the worker below.
Schedule::command('social:publish-due')
    ->everyMinute()
    ->onOneServer()
    ->withoutOverlapping();

// Phase 9 Task 4 — organic post insights. social:refresh-insights queues
// RefreshPostInsightsJob (database:social) for published posts whose stored
// snapshot is missing or due; pages only read the stored snapshot.
Schedule::command('social:refresh-insights')
    ->everyThirtyMinutes()
    ->onOneServer()
    ->withoutOverlapping();

Schedule::command('queue:work '.PublishScheduledPostJob::CONNECTION.' --queue=social --stop-when-empty --max-time=55')
    ->everyMinute()
    ->withoutOverlapping();

// F-5.1 — plan-bundle reconciliation. PlanManagementService::modify()
// queues ReconcilePlanAccountsJob on the named queue `plan-reconciliation`
// (default connection) whenever a plan's capability bundle changes; this
// worker drains it, same external `schedule:run` cron assumption as every
// entry above. No connection argument on purpose: it follows
// QUEUE_CONNECTION, like the dispatch does. Skipped under the `sync`
// driver (local development / tests), where the job already ran inline
// and there is nothing to drain.
Schedule::command('queue:work '.ReconcilePlanAccountsJob::resolveConnection().' --queue=plan-reconciliation --stop-when-empty --max-time=55')
    ->everyMinute()
    ->withoutOverlapping()
    ->when(fn (): bool => config('queue.default') !== 'sync');
