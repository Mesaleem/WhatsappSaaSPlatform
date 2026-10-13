<?php

namespace App\Services\Crm\Sales;

use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

/**
 * CRM Sales Phase 2 — document and receipt numbers: PREFIX/2026-27/0001, one series per
 * tenant, type and Indian financial year (April–March). The counter row is created if
 * missing and then LOCKED (SELECT … FOR UPDATE) for the increment, inside the caller's
 * transaction, so two issues at once can never take the same number; the unique index
 * on the documents / payments is the backstop.
 */
class CrmDocumentNumbering
{
    /** "2026-27" for any date from 1 Apr 2026 to 31 Mar 2027. */
    public static function financialYear(CarbonInterface $date): string
    {
        $start = $date->month >= 4 ? $date->year : $date->year - 1;

        return $start.'-'.substr((string) ($start + 1), -2);
    }

    /**
     * Must run inside a DB transaction.
     *
     * @return array{number: string, financial_year: string}
     */
    public function next(int $accountId, string $series, string $prefix, CarbonInterface $date): array
    {
        $fy = self::financialYear($date);
        $now = now();

        // UPSERT, not INSERT IGNORE (found by tests/Probes/crm_sales_concurrency_probe.php): on a duplicate,
        // INSERT IGNORE leaves a SHARED lock, and racers all upgrading to FOR UPDATE deadlocked. ON DUPLICATE
        // KEY UPDATE takes the exclusive lock directly, so racers queue — the same fix CreditService uses.
        DB::table('crm_document_counters')->upsert(
            [['account_id' => $accountId, 'type' => $series, 'financial_year' => $fy, 'last_number' => 0, 'created_at' => $now, 'updated_at' => $now]],
            ['account_id', 'type', 'financial_year'],
            ['account_id'],
        );

        $counter = DB::table('crm_document_counters')
            ->where('account_id', $accountId)->where('type', $series)->where('financial_year', $fy)
            ->lockForUpdate()->first();

        $next = (int) $counter->last_number + 1;
        DB::table('crm_document_counters')->where('id', $counter->id)->update(['last_number' => $next, 'updated_at' => $now]);

        return ['number' => sprintf('%s/%s/%04d', $prefix, $fy, $next), 'financial_year' => $fy];
    }
}
