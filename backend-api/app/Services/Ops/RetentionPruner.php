<?php

namespace App\Services\Ops;

use App\Models\WhatsAppFlowSession;
use Closure;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * Phase 12 Task 5 — bounded retention for operational tables.
 *
 * AUDIT (why each table is or is not here)
 *
 * PRUNED — established lifecycle, no business/audit value past the window:
 *   api_request_logs          observability log written by LogApiRequestMiddleware; nothing reads it back.
 *                             account_id is nullable (platform / unattributed) → two explicit scopes.
 *   webhook_deliveries        outbound-webhook attempt log; the API exposes only the latest 50 per subscription.
 *   journey_execution_events  execution trail; rows of an OPEN session are kept (existing Phase 7 rule).
 *   inbound_message_events    dedup keys; rows still referenced by retained journey history are kept.
 *   failed_jobs               Laravel's failed-job store.
 *
 * NOT PRUNED — documented exclusions (not safely disposable, or a guarantee depends on them):
 *   api_idempotency_keys      the replay guarantee has no documented TTL; deleting a key would let a reused
 *                             Idempotency-Key send a message twice.
 *   activity_logs, login_audit_logs               audit / security traceability.
 *   chatbot_logs, message_dispatch_logs, group_dispatch_recipients, in_app_notifications, mail_logs
 *                             conversation / delivery / notification history users and billing rely on.
 *   payment_alerts, collection_payments, invoices, credit_ledger_entries, credit_reservations, subscriptions
 *                             money.  ai_agent_tool_invocations / comment_automation_events: unique keys are the
 *                             dedup guard (deleting re-opens a duplicate action) and AI-action traceability.
 *   crm_capture_link_failures                    open review queue + unique(lead_id).
 *   whatsapp_flow_sessions    the business record of each run (CRM leads reference it).
 *   journey_conversation_locks                   deleting races InboundEventGate::acquire().
 *   ad_campaign_daily_metrics, organic_post_insights   reporting time series.
 *   jobs, job_batches (unused), sessions, cache, cache_locks, password_reset_tokens, personal_access_tokens
 *                             live work / framework-managed (tokens already have sanctum:prune-expired).
 *
 * SAFETY
 *   - Deletes use primary-key batches (default 1000), oldest first, at most max_batches per category/scope per run,
 *     no wrapping transaction, and re-check the cutoff in the DELETE itself.
 *   - Predicates never read another row's state; the only cross-table conditions are the two "keep" rules above.
 *     There is no tenant-level retention setting, so one tenant's data can never be removed on another's behalf.
 *   - A candidate id ceiling is found by binary search over the PRIMARY KEY (about log2(n) point reads), so no
 *     query scans an un-indexed timestamp column. The ceiling is only a bound: the DELETE still requires
 *     timestamp < cutoff, so a row inside retention can never be removed even if timestamps are not monotonic.
 *     Rows with a NULL timestamp are never pruned.
 *   - Dry run counts only (capped at one run's budget). Idempotent: a second run finds nothing more to delete.
 *   - One run at a time (cache lock). A failing category is reported and skipped; the others still run.
 *   - Logs carry only category / scope / table / counts / cutoff — never row contents or exception messages.
 */
class RetentionPruner
{
    private const LOCK = 'retention:prune';

    /** @return list<string> */
    public function categories(): array
    {
        return array_keys((array) config('retention.categories', []));
    }

    /**
     * @param  array{force?: bool, only?: list<string>, days?: array<string,int>, batch?: int, max_batches?: int, lock?: bool}  $options
     * @return array{locked: bool, delete: bool, rows: list<array<string,mixed>>, failed: bool}
     */
    public function run(bool $delete, array $options = []): array
    {
        $lock = null;
        if ($options['lock'] ?? true) {
            $lock = Cache::lock(self::LOCK, 3600);
            if (! $lock->get()) {
                return ['locked' => true, 'delete' => $delete, 'rows' => [], 'failed' => false];
            }
        }

        try {
            $batch = max(1, min(10000, (int) ($options['batch'] ?? config('retention.batch_size', 1000))));
            $maxBatches = max(1, (int) ($options['max_batches'] ?? config('retention.max_batches', 50)));
            $only = $options['only'] ?? null;
            $rows = [];

            foreach ($this->targets() as $target) {
                if ($only !== null && ! in_array($target['category'], $only, true)) {
                    continue;
                }
                $rows[] = $this->runTarget($target, $delete, $batch, $maxBatches, $options['days'][$target['category']] ?? null);
            }

            return ['locked' => false, 'delete' => $delete, 'rows' => $rows, 'failed' => collect($rows)->contains(fn ($r) => $r['status'] === 'error')];
        } finally {
            $lock?->release();
        }
    }

    /**
     * @return list<array{category: string, scope: string, table: string, column: string, connection: ?string, days_key: string, constraint: Closure}>
     */
    private function targets(): array
    {
        $failedDriver = (string) config('queue.failed.driver', 'database-uuids');

        $targets = [
            ['category' => 'api_request_logs', 'scope' => 'tenant', 'table' => 'api_request_logs', 'column' => 'created_at', 'connection' => null, 'days_key' => 'days',
                'constraint' => fn (Builder $q) => $q->whereNotNull('account_id')],
            ['category' => 'api_request_logs', 'scope' => 'platform', 'table' => 'api_request_logs', 'column' => 'created_at', 'connection' => null, 'days_key' => 'platform_days',
                'constraint' => fn (Builder $q) => $q->whereNull('account_id')],
            ['category' => 'webhook_deliveries', 'scope' => 'all', 'table' => 'webhook_deliveries', 'column' => 'created_at', 'connection' => null, 'days_key' => 'days',
                'constraint' => fn (Builder $q) => $q],
            ['category' => 'journey_execution_events', 'scope' => 'all', 'table' => 'journey_execution_events', 'column' => 'created_at', 'connection' => null, 'days_key' => 'days',
                'constraint' => fn (Builder $q) => $q->whereNotExists(fn ($s) => $s->select(DB::raw(1))
                    ->from('whatsapp_flow_sessions as s')
                    ->whereColumn('s.id', 'journey_execution_events.session_id')
                    ->whereIn('s.status', WhatsAppFlowSession::OPEN_STATUSES))],
            ['category' => 'inbound_message_events', 'scope' => 'all', 'table' => 'inbound_message_events', 'column' => 'created_at', 'connection' => null, 'days_key' => 'days',
                'constraint' => fn (Builder $q) => $q->whereNotExists(fn ($s) => $s->select(DB::raw(1))
                    ->from('journey_execution_events as e')
                    ->whereColumn('e.inbound_event_id', 'inbound_message_events.id'))],
        ];

        if (! in_array($failedDriver, ['null', ''], true)) {
            $targets[] = ['category' => 'failed_jobs', 'scope' => 'all', 'table' => (string) config('queue.failed.table', 'failed_jobs'), 'column' => 'failed_at',
                'connection' => config('queue.failed.database') ?: null, 'days_key' => 'days', 'constraint' => fn (Builder $q) => $q];
        }

        return $targets;
    }

    /** @return array<string, mixed> */
    private function runTarget(array $t, bool $delete, int $batch, int $maxBatches, ?int $daysOverride): array
    {
        $cfg = (array) config("retention.categories.{$t['category']}", []);
        $configured = $daysOverride ?? (int) ($cfg[$t['days_key']] ?? 0);
        $row = ['category' => $t['category'], 'scope' => $t['scope'], 'table' => $t['table'], 'days' => $configured, 'cutoff' => null, 'candidates' => 0, 'deleted' => 0, 'status' => 'ok'];

        if ($configured <= 0) {
            return ['status' => 'disabled'] + $row;
        }

        // The floor guards CONFIG typos; an explicit per-run override (a deliberate CLI argument) is taken as given.
        $min = $daysOverride !== null ? 1 : (int) ($cfg['min_days'] ?? 1);
        $days = max($configured, $min);
        if ($days !== $configured) {
            $row['days'] = $days;
            Log::warning('retention.days_below_floor', ['category' => $t['category'], 'scope' => $t['scope'], 'configured' => $configured, 'applied' => $days]);
        }
        $cutoff = now()->subDays($days);
        $row['cutoff'] = $cutoff->toDateTimeString();

        try {
            $db = DB::connection($t['connection']);
            if (! Schema::connection($t['connection'])->hasTable($t['table'])) {
                return ['status' => 'skipped_no_table'] + $row;
            }

            $ceiling = $this->idCeiling($db, $t['table'], $t['column'], $cutoff);
            if ($ceiling === null) {
                return $row;
            }

            $candidates = function () use ($db, $t, $cutoff, $ceiling): Builder {
                $q = $db->table($t['table'])->where('id', '<=', $ceiling)->where($t['column'], '<', $cutoff);
                ($t['constraint'])($q);

                return $q->orderBy('id');
            };

            if (! $delete) {
                // Count at most one run's budget: a LIMITed subquery, so a huge backlog is never scanned in full.
                $row['candidates'] = $db->query()->fromSub($candidates()->select('id')->limit($batch * $maxBatches), 'c')->count();
                $this->log($row, false);

                return $row;
            }

            for ($i = 0; $i < $maxBatches; $i++) {
                $ids = $candidates()->limit($batch)->pluck('id')->all();
                if ($ids === []) {
                    break;
                }
                // Primary-key delete, with the cutoff re-checked so a row can never be removed unless it is old.
                $row['deleted'] += $db->table($t['table'])->whereIn('id', $ids)->where($t['column'], '<', $cutoff)->delete();
            }
            $this->log($row, true);

            return $row;
        } catch (Throwable $e) {
            // Class and code only: a database exception message can embed SQL and bound values.
            Log::error('retention.prune_failed', ['category' => $t['category'], 'scope' => $t['scope'], 'table' => $t['table'], 'exception' => $e::class, 'deleted_before_failure' => $row['deleted']]);

            return ['status' => 'error', 'error' => $e::class] + $row;
        }
    }

    /** Largest primary key whose row is older than the cutoff, found by binary search over the PK; null when none. */
    private function idCeiling($db, string $table, string $column, \DateTimeInterface $cutoff): ?int
    {
        $lo = $db->table($table)->min('id');
        $hi = $db->table($table)->max('id');
        if ($lo === null || $hi === null) {
            return null;
        }
        $lo = (int) $lo;
        $hi = (int) $hi;

        $first = $db->table($table)->where('id', '>=', $lo)->orderBy('id')->first(['id', $column]);
        if (! $first || $first->{$column} === null || $first->{$column} >= $cutoff->format('Y-m-d H:i:s')) {
            return null;
        }

        $ceiling = (int) $first->id;
        $lo = $ceiling + 1;
        while ($lo <= $hi) {
            $mid = intdiv($lo + $hi, 2);
            $r = $db->table($table)->where('id', '>=', $mid)->orderBy('id')->first(['id', $column]);
            if (! $r) {
                $hi = $mid - 1;

                continue;
            }
            if ($r->{$column} !== null && $r->{$column} < $cutoff->format('Y-m-d H:i:s')) {
                $ceiling = max($ceiling, (int) $r->id);
                $lo = (int) $r->id + 1;
            } else {
                $hi = $mid - 1;
            }
        }

        return $ceiling;
    }

    private function log(array $row, bool $deleted): void
    {
        if (($deleted ? $row['deleted'] : $row['candidates']) === 0) {
            return;
        }
        Log::info($deleted ? 'retention.pruned' : 'retention.would_prune', [
            'category' => $row['category'], 'scope' => $row['scope'], 'table' => $row['table'],
            'days' => $row['days'], 'cutoff' => $row['cutoff'], 'count' => $deleted ? $row['deleted'] : $row['candidates'],
        ]);
    }
}
