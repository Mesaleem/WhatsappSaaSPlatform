<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Group Messaging Phase 5 — Dashboard Analytics Upgrade.
     *
     * [Disclosed, root cause]: a group-dispatch batch writes exactly ONE
     * message_dispatch_logs row per batch (recipient_count members,
     * resolved later by ProcessGroupDispatchJob via
     * MessageDispatchLog::resolveGroupDispatch()) — not one row per
     * recipient. That row's `status` only ever resolves to 'sent'
     * (>= 1 recipient succeeded) or 'failed' (every recipient failed),
     * per resolveGroupDispatch()'s own docblock. A batch of 40 sent / 10
     * failed is therefore stored as a single 'sent' row. Counting
     * `status = 'sent'` rows for a "Total Group Messages Sent" KPI would
     * report 1 (the batch), not 40 (the actual successful sends), and
     * would silently drop the 10 real failures inside it.
     * resolveGroupDispatch() already receives both counts as parameters
     * (successCount/failureCount) — they were simply never persisted.
     *
     * This migration adds the two columns needed to report them
     * accurately. Nullable and additive: every pre-existing row
     * (every individual send, and any group batch already resolved
     * before this migration runs) is left untouched and reads as NULL,
     * not 0 — AnalyticsController's new recipient-type breakdown treats
     * NULL/this migration-not-yet-run as "unknown, fall back to
     * counting batches instead", never as a false zero (see
     * AnalyticsController::recipientTypeBreakdown()'s docblock).
     *
     * Depends on 2026_09_13_140002_add_group_messaging_fields_to_
     * message_dispatch_logs_table.php having already run (adds
     * recipient_count, which this migration anchors after) — same
     * standing pending-authorization status as that migration and the
     * two others noted in this codebase's other pending-migration
     * disclosures; this one is NOT run by me, per standing instruction.
     */
    public function up(): void
    {
        Schema::table('message_dispatch_logs', function (Blueprint $table) {
            $table->unsignedInteger('success_count')->nullable()->after('recipient_count');
            $table->unsignedInteger('failure_count')->nullable()->after('success_count');
        });
    }

    public function down(): void
    {
        Schema::table('message_dispatch_logs', function (Blueprint $table) {
            $table->dropColumn(['success_count', 'failure_count']);
        });
    }
};
