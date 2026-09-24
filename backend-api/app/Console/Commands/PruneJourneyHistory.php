<?php

namespace App\Console\Commands;

use App\Models\WhatsAppFlowSession;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Phase 7 Task 7 — bounded retention for the two append-only Journey
 * operational tables. DRY RUN BY DEFAULT: without --force it only counts
 * what it would delete. It is NOT registered in the scheduler — enabling
 * automatic deletion is an owner decision (see PROJECT_STATE.md).
 *
 * RETENTION POLICY (documented, opt-in):
 *   journey_execution_events  older than --events-days (default 90), EXCEPT
 *                             rows of a session that is still open
 *                             (active / waiting / blocked) — a live run's
 *                             history is never removed.
 *   inbound_message_events    older than --inbound-days (default 30), EXCEPT
 *                             rows still referenced by a retained journey
 *                             event (their event key is that run's
 *                             correlation). 30 days is far beyond any
 *                             provider's redelivery window [Inference: Meta
 *                             retries a failed webhook for up to ~7 days],
 *                             so dedup is not weakened in practice.
 *
 * NOT pruned, by design:
 *   whatsapp_flow_sessions    the business record of each run (CRM leads
 *                             reference their session id).
 *   journey_conversation_locks one reusable row per (account, phone); a
 *                             delete could race InboundEventGate::acquire()
 *                             (insert-or-ignore, then a conditional update)
 *                             and drop a message, so it is left alone.
 *
 * Bounded work: at most --batch × --max-batches rows per table per run,
 * deleted by primary key in batches, oldest first.
 */
class PruneJourneyHistory extends Command
{
    protected $signature = 'journeys:prune-history
        {--events-days=90 : Keep journey execution events newer than this many days}
        {--inbound-days=30 : Keep inbound message event keys newer than this many days}
        {--batch=1000 : Rows per delete batch}
        {--max-batches=50 : Maximum batches per table per run}
        {--force : Actually delete (default is a dry run that only counts)}';

    protected $description = 'Count (or, with --force, delete) old Journey execution events and inbound event keys';

    public function handle(): int
    {
        $eventsDays = max(1, (int) $this->option('events-days'));
        $inboundDays = max(1, (int) $this->option('inbound-days'));
        $batch = max(1, min(10000, (int) $this->option('batch')));
        $maxBatches = max(1, (int) $this->option('max-batches'));
        $force = (bool) $this->option('force');

        $events = $this->prune('journey_execution_events', $this->eventCandidates(now()->subDays($eventsDays)), $batch, $maxBatches, $force);
        $inbound = $this->prune('inbound_message_events', $this->inboundCandidates(now()->subDays($inboundDays)), $batch, $maxBatches, $force);

        $verb = $force ? 'Deleted' : 'Would delete (dry run; pass --force to delete)';
        $this->info("{$verb}: {$events} journey execution event(s) older than {$eventsDays} days, {$inbound} inbound event key(s) older than {$inboundDays} days.");

        if ($force && ($events + $inbound) > 0) {
            Log::info('journeys:prune-history deleted old journey history.', ['execution_events' => $events, 'inbound_events' => $inbound]);
        }

        return self::SUCCESS;
    }

    private function eventCandidates(\DateTimeInterface $cutoff): \Closure
    {
        return fn () => DB::table('journey_execution_events as e')
            ->where('e.created_at', '<', $cutoff)
            ->whereNotExists(fn ($q) => $q->select(DB::raw(1))
                ->from('whatsapp_flow_sessions as s')
                ->whereColumn('s.id', 'e.session_id')
                ->whereIn('s.status', WhatsAppFlowSession::OPEN_STATUSES))
            ->orderBy('e.id');
    }

    private function inboundCandidates(\DateTimeInterface $cutoff): \Closure
    {
        return fn () => DB::table('inbound_message_events as i')
            ->where('i.created_at', '<', $cutoff)
            ->whereNotExists(fn ($q) => $q->select(DB::raw(1))
                ->from('journey_execution_events as e')
                ->whereColumn('e.inbound_event_id', 'i.id'))
            ->orderBy('i.id');
    }

    private function prune(string $table, \Closure $candidates, int $batch, int $maxBatches, bool $force): int
    {
        if (! $force) {
            return min($candidates()->count(), $batch * $maxBatches);
        }

        $deleted = 0;

        for ($i = 0; $i < $maxBatches; $i++) {
            $ids = $candidates()->limit($batch)->pluck(str_starts_with($table, 'journey') ? 'e.id' : 'i.id')->all();

            if ($ids === []) {
                break;
            }

            $deleted += DB::table($table)->whereIn('id', $ids)->delete();
        }

        return $deleted;
    }
}
