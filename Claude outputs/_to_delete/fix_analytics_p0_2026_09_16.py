import sys

PATH = "app/Http/Controllers/Api/AnalyticsController.php"

with open(PATH, "r", encoding="utf-8", newline="") as f:
    content = f.read()

original_content = content

replacements = []

# --- Chunk A: summary() period total_sent/total_failed ---------------------
old_a = """        if ($dispatchLogsAvailable) {
            $agg = ($account ? MessageDispatchLog::forAccount($account->id) : MessageDispatchLog::query())
                ->whereBetween('created_at', [$from, $to])
                ->selectRaw(
                    "SUM(CASE WHEN status = 'sent' THEN 1 ELSE 0 END) as total_sent, ".
                    "SUM(CASE WHEN status = 'failed' THEN 1 ELSE 0 END) as total_failed"
                )
                ->first();
        } else {
            $agg = ($account ? PaymentAlert::forAccount($account->id) : PaymentAlert::query())
                ->whereBetween('created_at', [$from, $to])
                ->selectRaw(
                    "SUM(CASE WHEN status = 'sent' THEN 1 ELSE 0 END) as total_sent, ".
                    "SUM(CASE WHEN status = 'failed' THEN 1 ELSE 0 END) as total_failed"
                )
                ->first();
        }

        $totalSent = (int) $agg->total_sent;
        $totalFailed = (int) $agg->total_failed;
        $attempted = $totalSent + $totalFailed;"""

new_a = """        if ($dispatchLogsAvailable) {
            // Group Messaging Undercount Fix (P0, 2026-09-16) — the
            // row-counting aggregate that used to live here undercounted
            // every group send, since one message_dispatch_logs row can
            // represent an entire batch (see
            // MessageDispatchLog::resolveGroupDispatch()'s docblock).
            // resolveRecipientAwareTotals() is the single shared
            // recipient_type-aware aggregation this file now uses
            // everywhere a "how many messages were sent/failed" figure
            // is computed, so this can never again disagree with
            // recipient_breakdown / today_breakdown / daily_by_recipient_type
            // below, which already used the correct logic.
            $totals = $this->resolveRecipientAwareTotals(
                $account ? MessageDispatchLog::forAccount($account->id) : MessageDispatchLog::query(),
                $from,
                $to,
                $recipientTypeAvailable,
                $resolutionCountsAvailable
            );
            $totalSent = $totals['sent'];
            $totalFailed = $totals['failed'];
        } else {
            $agg = ($account ? PaymentAlert::forAccount($account->id) : PaymentAlert::query())
                ->whereBetween('created_at', [$from, $to])
                ->selectRaw(
                    "SUM(CASE WHEN status = 'sent' THEN 1 ELSE 0 END) as total_sent, ".
                    "SUM(CASE WHEN status = 'failed' THEN 1 ELSE 0 END) as total_failed"
                )
                ->first();
            $totalSent = (int) $agg->total_sent;
            $totalFailed = (int) $agg->total_failed;
        }

        $attempted = $totalSent + $totalFailed;"""

replacements.append(("Chunk A (summary period total)", old_a, new_a))

# --- Chunk B: summary() today aggregate -------------------------------------
old_b = """        $todayQuery = $dispatchLogsAvailable
            ? ($account ? MessageDispatchLog::forAccount($account->id) : MessageDispatchLog::query())
            : ($account ? PaymentAlert::forAccount($account->id) : PaymentAlert::query());
        $todayAgg = $todayQuery
            ->whereDate('created_at', now()->toDateString())
            ->selectRaw(
                "SUM(CASE WHEN status = 'sent' THEN 1 ELSE 0 END) as total_sent, ".
                "SUM(CASE WHEN status = 'failed' THEN 1 ELSE 0 END) as total_failed"
            )
            ->first();"""

new_b = """        // Group Messaging Undercount Fix (P0, 2026-09-16) — same
        // recipient-aware correction as the period-scoped total above,
        // re-windowed to just today.
        if ($dispatchLogsAvailable) {
            $todayTotals = $this->resolveRecipientAwareTotals(
                $account ? MessageDispatchLog::forAccount($account->id) : MessageDispatchLog::query(),
                now()->startOfDay(),
                now()->endOfDay(),
                $recipientTypeAvailable,
                $resolutionCountsAvailable
            );
            $todaySent = $todayTotals['sent'];
            $todayFailed = $todayTotals['failed'];
        } else {
            $todayAgg = ($account ? PaymentAlert::forAccount($account->id) : PaymentAlert::query())
                ->whereDate('created_at', now()->toDateString())
                ->selectRaw(
                    "SUM(CASE WHEN status = 'sent' THEN 1 ELSE 0 END) as total_sent, ".
                    "SUM(CASE WHEN status = 'failed' THEN 1 ELSE 0 END) as total_failed"
                )
                ->first();
            $todaySent = (int) $todayAgg->total_sent;
            $todayFailed = (int) $todayAgg->total_failed;
        }"""

replacements.append(("Chunk B (summary today total)", old_b, new_b))

# --- Chunk B2: summary() return array today fields --------------------------
old_b2 = """            'total_sent_today' => (int) $todayAgg->total_sent,
            'total_failed_today' => (int) $todayAgg->total_failed,"""

new_b2 = """            'total_sent_today' => $todaySent,
            'total_failed_today' => $todayFailed,"""

replacements.append(("Chunk B2 (summary return today fields)", old_b2, new_b2))

# --- Chunk C1: charts() restructure ------------------------------------------
old_c1 = """            $query = $dispatchLogsAvailable
                ? ($account ? MessageDispatchLog::forAccount($account->id) : MessageDispatchLog::query())
                : ($account ? PaymentAlert::forAccount($account->id) : PaymentAlert::query());

            $rows = $query
                ->whereBetween('created_at', [$from, $to])
                ->selectRaw(
                    "DATE(created_at) as bucket_date, ".
                    "SUM(CASE WHEN status = 'sent' THEN 1 ELSE 0 END) as sent, ".
                    "SUM(CASE WHEN status = 'failed' THEN 1 ELSE 0 END) as failed"
                )
                ->groupBy('bucket_date')
                ->orderBy('bucket_date')
                ->get()
                ->keyBy('bucket_date');

            $daily = [];
            $attemptedTotal = 0;
            $cursor = $from->copy()->startOfDay();
            $end = $to->copy()->startOfDay();

            while ($cursor->lte($end)) {
                $key = $cursor->toDateString();
                $row = $rows->get($key);
                $sent = $row ? (int) $row->sent : 0;
                $failed = $row ? (int) $row->failed : 0;

                $daily[] = ['date' => $key, 'sent' => $sent, 'failed' => $failed];
                $attemptedTotal += $sent + $failed;
                $cursor->addDay();
            }

            $engineBreakdown = $account
                ? ($subscription ? [['engine_type' => $subscription->engine_type, 'count' => $attemptedTotal]] : [])
                : $this->globalEngineBreakdown($from, $to);"""

new_c1 = """            $query = $dispatchLogsAvailable
                ? ($account ? MessageDispatchLog::forAccount($account->id) : MessageDispatchLog::query())
                : ($account ? PaymentAlert::forAccount($account->id) : PaymentAlert::query());

            // Group Messaging Undercount Fix (P0, 2026-09-16) — computed
            // BEFORE `$daily` below (moved up from its original position
            // near the end of this closure) so the primary daily series
            // can be derived from it directly instead of re-querying with
            // the old row-counting aggregate. See
            // resolveRecipientAwareTotals()'s docblock for why a group
            // batch row can no longer be counted as "1 message" here.
            $dailyByRecipientType = $recipientTypeAvailable
                ? $this->dailyRecipientTypeSeries($account, $from, $to, $resolutionCountsAvailable)
                : null;

            if ($dailyByRecipientType) {
                // Derived by summing the already-correct individual+group
                // per-day figures rather than a second, row-counting
                // query — guarantees `daily` and `daily_by_recipient_type`
                // can never disagree (both arrays inside
                // $dailyByRecipientType are gap-filled over the same
                // $from..$to range in the same order, so they align
                // index-for-index).
                $daily = [];
                $attemptedTotal = 0;

                foreach ($dailyByRecipientType['individual'] as $index => $individualDay) {
                    $groupDay = $dailyByRecipientType['group'][$index];
                    $sent = $individualDay['sent'] + $groupDay['sent'];
                    $failed = $individualDay['failed'] + $groupDay['failed'];

                    $daily[] = ['date' => $individualDay['date'], 'sent' => $sent, 'failed' => $failed];
                    $attemptedTotal += $sent + $failed;
                }
            } else {
                // Fallback for a deployment without recipient_type yet
                // (or the payment_alerts-only path, which has no group
                // concept at all) — unchanged row-counting behavior.
                $rows = $query
                    ->whereBetween('created_at', [$from, $to])
                    ->selectRaw(
                        "DATE(created_at) as bucket_date, ".
                        "SUM(CASE WHEN status = 'sent' THEN 1 ELSE 0 END) as sent, ".
                        "SUM(CASE WHEN status = 'failed' THEN 1 ELSE 0 END) as failed"
                    )
                    ->groupBy('bucket_date')
                    ->orderBy('bucket_date')
                    ->get()
                    ->keyBy('bucket_date');

                $daily = [];
                $attemptedTotal = 0;
                $cursor = $from->copy()->startOfDay();
                $end = $to->copy()->startOfDay();

                while ($cursor->lte($end)) {
                    $key = $cursor->toDateString();
                    $row = $rows->get($key);
                    $sent = $row ? (int) $row->sent : 0;
                    $failed = $row ? (int) $row->failed : 0;

                    $daily[] = ['date' => $key, 'sent' => $sent, 'failed' => $failed];
                    $attemptedTotal += $sent + $failed;
                    $cursor->addDay();
                }
            }

            $engineBreakdown = $account
                ? ($subscription ? [['engine_type' => $subscription->engine_type, 'count' => $attemptedTotal]] : [])
                : $this->globalEngineBreakdown($from, $to);"""

replacements.append(("Chunk C1 (charts restructure)", old_c1, new_c1))

# --- Chunk C2: remove now-duplicate dailyByRecipientType computation --------
old_c2 = """            $dailyByRecipientType = $recipientTypeAvailable
                ? $this->dailyRecipientTypeSeries($account, $from, $to, $resolutionCountsAvailable)
                : null;

            return [
                'scope' => $account ? 'account' : 'global',
                'range' => ['from' => $from->toDateString(), 'to' => $to->toDateString()],
                'daily' => $daily,"""

new_c2 = """            return [
                'scope' => $account ? 'account' : 'global',
                'range' => ['from' => $from->toDateString(), 'to' => $to->toDateString()],
                'daily' => $daily,"""

replacements.append(("Chunk C2 (remove duplicate dailyByRecipientType)", old_c2, new_c2))

# --- Chunk D: computeGlobalSummary() all-time total --------------------------
old_d = """        // [New feature, disclosed]: same message_dispatch_logs-over-
        // payment_alerts preference, guarded and self-healing, as
        // summary()/charts() above — see summary()'s docblock for the
        // full reasoning.
        $dispatchLogsAvailable = Schema::hasTable('message_dispatch_logs');
        $agg = $dispatchLogsAvailable
            ? MessageDispatchLog::selectRaw(
                "SUM(CASE WHEN status = 'sent' THEN 1 ELSE 0 END) as total_sent, ".
                "SUM(CASE WHEN status = 'failed' THEN 1 ELSE 0 END) as total_failed"
            )->first()
            : PaymentAlert::selectRaw(
                "SUM(CASE WHEN status = 'sent' THEN 1 ELSE 0 END) as total_sent, ".
                "SUM(CASE WHEN status = 'failed' THEN 1 ELSE 0 END) as total_failed"
            )->first();

        $totalSent = (int) $agg->total_sent;
        $totalFailed = (int) $agg->total_failed;
        $attempted = $totalSent + $totalFailed;"""

new_d = """        // [New feature, disclosed]: same message_dispatch_logs-over-
        // payment_alerts preference, guarded and self-healing, as
        // summary()/charts() above — see summary()'s docblock for the
        // full reasoning.
        $dispatchLogsAvailable = Schema::hasTable('message_dispatch_logs');
        // Group Messaging Undercount Fix (P0, 2026-09-16) — same two
        // independent guards summary()/charts() already compute, added
        // here so the platform-wide KPI can use the same
        // resolveRecipientAwareTotals() aggregation instead of the
        // row-counting SUM(CASE...) that used to live here (undercounted
        // every group batch platform-wide, not just per-tenant).
        $recipientTypeAvailable = $dispatchLogsAvailable && Schema::hasColumn('message_dispatch_logs', 'recipient_type');
        $resolutionCountsAvailable = $recipientTypeAvailable && Schema::hasColumn('message_dispatch_logs', 'success_count');

        if ($dispatchLogsAvailable) {
            $totals = $this->resolveRecipientAwareTotals(
                MessageDispatchLog::query(),
                null,
                null,
                $recipientTypeAvailable,
                $resolutionCountsAvailable
            );
            $totalSent = $totals['sent'];
            $totalFailed = $totals['failed'];
        } else {
            $agg = PaymentAlert::selectRaw(
                "SUM(CASE WHEN status = 'sent' THEN 1 ELSE 0 END) as total_sent, ".
                "SUM(CASE WHEN status = 'failed' THEN 1 ELSE 0 END) as total_failed"
            )->first();
            $totalSent = (int) $agg->total_sent;
            $totalFailed = (int) $agg->total_failed;
        }

        $attempted = $totalSent + $totalFailed;"""

replacements.append(("Chunk D (globalSummary all-time total)", old_d, new_d))

# --- Chunk E: computeGlobalSummary() today aggregate -------------------------
old_e = """        $todayAgg = ($dispatchLogsAvailable ? MessageDispatchLog::query() : PaymentAlert::query())
            ->whereDate('created_at', now()->toDateString())
            ->selectRaw(
                "SUM(CASE WHEN status = 'sent' THEN 1 ELSE 0 END) as total_sent, ".
                "SUM(CASE WHEN status = 'failed' THEN 1 ELSE 0 END) as total_failed"
            )
            ->first();"""

new_e = """        // Group Messaging Undercount Fix (P0, 2026-09-16) — same
        // recipient-aware correction as the all-time total above.
        if ($dispatchLogsAvailable) {
            $todayTotals = $this->resolveRecipientAwareTotals(
                MessageDispatchLog::query(),
                now()->startOfDay(),
                now()->endOfDay(),
                $recipientTypeAvailable,
                $resolutionCountsAvailable
            );
            $todaySent = $todayTotals['sent'];
            $todayFailed = $todayTotals['failed'];
        } else {
            $todayAgg = PaymentAlert::query()
                ->whereDate('created_at', now()->toDateString())
                ->selectRaw(
                    "SUM(CASE WHEN status = 'sent' THEN 1 ELSE 0 END) as total_sent, ".
                    "SUM(CASE WHEN status = 'failed' THEN 1 ELSE 0 END) as total_failed"
                )
                ->first();
            $todaySent = (int) $todayAgg->total_sent;
            $todayFailed = (int) $todayAgg->total_failed;
        }"""

replacements.append(("Chunk E (globalSummary today total)", old_e, new_e))

# --- Chunk E2: computeGlobalSummary() return array today fields -------------
old_e2 = """            'total_messages_sent_today' => (int) $todayAgg->total_sent,
            'total_messages_failed_today' => (int) $todayAgg->total_failed,"""

new_e2 = """            'total_messages_sent_today' => $todaySent,
            'total_messages_failed_today' => $todayFailed,"""

replacements.append(("Chunk E2 (globalSummary return today fields)", old_e2, new_e2))

# --- Chunk F: insert the new shared helper -----------------------------------
old_f = """        return collect($totals)
            ->map(fn (int $count, string $engine) => ['engine_type' => $engine, 'count' => $count])
            ->values()
            ->all();
    }

    /**
     * Group Messaging Phase 5 — Dashboard Analytics Upgrade. Splits the
     * summary()-level total_sent/total_failed figures by recipient_type."""

new_f = """        return collect($totals)
            ->map(fn (int $count, string $engine) => ['engine_type' => $engine, 'count' => $count])
            ->values()
            ->all();
    }

    /**
     * Group Messaging Undercount Fix (P0, 2026-09-16) — single shared
     * recipient_type-aware "how many messages were sent/failed" helper.
     * Reused by every primary KPI/chart aggregate in this controller
     * (summary()'s period + today totals, charts()'s primary daily
     * series, computeGlobalSummary()'s platform-wide + today totals) so
     * none of them can ever again disagree with recipient_breakdown /
     * today_breakdown / daily_by_recipient_type below, which already
     * used this exact sent/failed definition.
     *
     * Individual rows: one row = one attempted send, counted by status
     * (unchanged from this file's original, pre-Group-Messaging
     * behavior).
     *
     * Group rows: one row = an entire batch (see
     * MessageDispatchLog::resolveGroupDispatch()'s docblock for why), so
     * counting rows would report "1" regardless of recipient count —
     * this sums success_count/failure_count instead, exactly like
     * recipientTypeBreakdown() below. Falls back to counting rows for
     * group data too when $resolutionCountsAvailable is false (the
     * success_count/failure_count migration hasn't run yet) — same
     * disclosed, self-healing approximation as recipientTypeBreakdown().
     *
     * $from/$to are both nullable together: pass null/null for an
     * all-time aggregate (computeGlobalSummary()'s platform-wide total,
     * which has no date range of its own), or a real Carbon pair to
     * scope by period exactly like every other date-ranged query in this
     * file.
     *
     * @return array{sent: int, failed: int}
     */
    private function resolveRecipientAwareTotals($baseQuery, ?Carbon $from, ?Carbon $to, bool $recipientTypeAvailable, bool $resolutionCountsAvailable): array
    {
        $scoped = clone $baseQuery;

        if ($from && $to) {
            $scoped->whereBetween('created_at', [$from, $to]);
        }

        if (! $recipientTypeAvailable) {
            $agg = (clone $scoped)
                ->selectRaw(
                    "SUM(CASE WHEN status = 'sent' THEN 1 ELSE 0 END) as sent, ".
                    "SUM(CASE WHEN status = 'failed' THEN 1 ELSE 0 END) as failed"
                )
                ->first();

            return ['sent' => (int) $agg->sent, 'failed' => (int) $agg->failed];
        }

        $individual = (clone $scoped)
            ->where('recipient_type', 'individual')
            ->selectRaw(
                "SUM(CASE WHEN status = 'sent' THEN 1 ELSE 0 END) as sent, ".
                "SUM(CASE WHEN status = 'failed' THEN 1 ELSE 0 END) as failed"
            )
            ->first();

        $groupSelect = $resolutionCountsAvailable
            ? "SUM(COALESCE(success_count, 0)) as sent, SUM(COALESCE(failure_count, 0)) as failed"
            : "SUM(CASE WHEN status = 'sent' THEN 1 ELSE 0 END) as sent, SUM(CASE WHEN status = 'failed' THEN 1 ELSE 0 END) as failed";

        $group = (clone $scoped)
            ->where('recipient_type', 'group')
            ->selectRaw($groupSelect)
            ->first();

        return [
            'sent' => (int) $individual->sent + (int) $group->sent,
            'failed' => (int) $individual->failed + (int) $group->failed,
        ];
    }

    /**
     * Group Messaging Phase 5 — Dashboard Analytics Upgrade. Splits the
     * summary()-level total_sent/total_failed figures by recipient_type."""

replacements.append(("Chunk F (insert resolveRecipientAwareTotals helper)", old_f, new_f))

# --- Apply all replacements, verifying exact single-match before writing ----
errors = []
for label, old, new in replacements:
    count = content.count(old)
    if count != 1:
        errors.append(f"{label}: expected exactly 1 match, found {count}")
        continue
    content = content.replace(old, new, 1)

if errors:
    print("ABORTED — no file was written. Mismatches found:")
    for e in errors:
        print(" -", e)
    sys.exit(1)

if content == original_content:
    print("ABORTED — no changes were produced.")
    sys.exit(1)

with open(PATH, "w", encoding="utf-8", newline="") as f:
    f.write(content)

print("OK — all", len(replacements), "replacements applied successfully.")
print("New file size:", len(content), "bytes (was", len(original_content), ")")
