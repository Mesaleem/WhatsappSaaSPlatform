<?php

namespace App\Console\Commands;

use App\Jobs\ResumeJourneySessionJob;
use App\Models\WhatsAppFlowSession;
use App\Services\WhatsApp\WhatsAppJourneyEngine;
use Illuminate\Console\Command;

/**
 * Phase 7 Task 1 — the journey scheduler. Scheduled every minute
 * (routes/console.php). Finds sessions parked on a delay whose wait_until
 * has passed, on flows that are still active, and dispatches one
 * ResumeJourneySessionJob per session onto database:journeys.
 *
 * Apart from restoring blocked sessions (Phase 7 Task 1.6, a conditional
 * update per entitled account) it never changes a session. Running it twice, or
 * while jobs from the previous run are still queued, is safe — the job's
 * atomic claim decides what runs. Sessions of a deactivated flow are not
 * picked up (held) until the flow is active again.
 */
class ResumeDueJourneySessions extends Command
{
    protected $signature = 'journeys:resume-due {--limit=200 : Maximum sessions to dispatch per run}';

    protected $description = 'Dispatch resume jobs for WhatsApp journey sessions whose delay has elapsed';

    public function handle(): int
    {
        $limit = max(1, (int) $this->option('limit'));

        // Phase 7 Task 1.6 — sessions blocked by a lost entitlement come back
        // first when their account is entitled again (a restored delay is
        // already due, so it is picked up by the query below in this run).
        $restored = app(WhatsAppJourneyEngine::class)->restoreEntitledBlockedSessions();

        // Phase 7 Task 9 — immediate-path runs whose process died mid-run are
        // parked as due 'waiting' at their checkpoint, so the scan below
        // resumes them in this same run (bounded by --limit).
        $recovered = app(WhatsAppJourneyEngine::class)->recoverInterruptedRuns($limit);

        $ids = WhatsAppFlowSession::query()
            ->where('status', WhatsAppFlowSession::STATUS_WAITING)
            ->whereNotNull('wait_until')
            ->where('wait_until', '<=', now())
            ->whereHas('flow', fn ($q) => $q->where('is_active', true))
            ->orderBy('wait_until')
            ->limit($limit)
            ->pluck('id');

        foreach ($ids as $id) {
            ResumeJourneySessionJob::dispatch((int) $id)->onConnection('database')->onQueue('journeys');
        }

        $this->info("Restored {$restored} blocked, recovered {$recovered} interrupted and dispatched {$ids->count()} due journey session(s).");

        return self::SUCCESS;
    }
}
