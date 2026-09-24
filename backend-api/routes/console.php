<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

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
Schedule::command('ads:check-performance-rules')
    ->everyFifteenMinutes()
    ->withoutOverlapping();

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
    ->withoutOverlapping();
