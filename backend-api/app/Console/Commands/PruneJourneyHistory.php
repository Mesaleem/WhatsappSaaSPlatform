<?php

namespace App\Console\Commands;

use App\Services\Ops\RetentionPruner;
use Illuminate\Console\Command;

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
 *
 * Phase 12 Task 5 — the predicates and batching now live in App\Services\Ops\RetentionPruner (shared with the
 * scheduled `ops:prune-retention`); this command keeps its own options, output and dry-run-by-default behaviour,
 * and is still NOT scheduled. Defaults come from config/retention.php.
 */
class PruneJourneyHistory extends Command
{
    protected $signature = 'journeys:prune-history
        {--events-days= : Keep journey execution events newer than this many days (default: retention config)}
        {--inbound-days= : Keep inbound message event keys newer than this many days (default: retention config)}
        {--batch=1000 : Rows per delete batch}
        {--max-batches=50 : Maximum batches per table per run}
        {--force : Actually delete (default is a dry run that only counts)}';

    protected $description = 'Count (or, with --force, delete) old Journey execution events and inbound event keys';

    public function handle(RetentionPruner $pruner): int
    {
        $eventsDays = $this->option('events-days') !== null ? max(1, (int) $this->option('events-days')) : (int) config('retention.categories.journey_execution_events.days', 90);
        $inboundDays = $this->option('inbound-days') !== null ? max(1, (int) $this->option('inbound-days')) : (int) config('retention.categories.inbound_message_events.days', 30);
        $force = (bool) $this->option('force');

        $result = $pruner->run($force, [
            'only' => ['journey_execution_events', 'inbound_message_events'],
            'days' => ['journey_execution_events' => $eventsDays, 'inbound_message_events' => $inboundDays],
            'batch' => (int) $this->option('batch'),
            'max_batches' => (int) $this->option('max-batches'),
        ]);

        if ($result['locked']) {
            $this->warn('Another retention run is in progress; nothing done.');

            return self::SUCCESS;
        }

        $by = collect($result['rows'])->keyBy('category');
        $count = fn (string $c) => (int) ($force ? ($by[$c]['deleted'] ?? 0) : ($by[$c]['candidates'] ?? 0));
        $events = $count('journey_execution_events');
        $inbound = $count('inbound_message_events');

        $verb = $force ? 'Deleted' : 'Would delete (dry run; pass --force to delete)';
        $this->info("{$verb}: {$events} journey execution event(s) older than {$eventsDays} days, {$inbound} inbound event key(s) older than {$inboundDays} days.");

        return $result['failed'] ? self::FAILURE : self::SUCCESS;
    }
}
