<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Phase 5 fix P5-3 — an atomic, persisted claim on a queued group batch.
     *
     * A group job used to guard itself with a plain read
     * (`if ($log->status !== 'queued') return;`), so two workers holding the
     * same batch could both pass it and both send to every recipient. The
     * claim is a conditional UPDATE on these columns (see
     * MessageDispatchLog::claimGroupDispatch()): exactly one caller can move
     * `claim_token` from NULL to its own token while the row is still
     * 'queued'.
     *
     *   claim_token  the owning job run's random token; NULL while nobody
     *                owns the batch (never started, or between two slices
     *                of a long batch). Never exposed ($hidden on the model).
     *   claimed_at   the owner's heartbeat, refreshed before every send.
     *                The stale-batch recovery command reads it to tell a
     *                dead owner from a live one.
     *
     * `status` keeps its existing values: a claimed batch is still 'queued'
     * to every reader (API, Analytics, Message Logs), so no consumer of the
     * column changes.
     *
     * The index serves the recovery command's scan for queued group
     * batches, so it never walks the whole log table.
     *
     * Purely ADDITIVE: two nullable columns and one index; no existing
     * column, value or index is altered.
     */
    public function up(): void
    {
        Schema::table('message_dispatch_logs', function (Blueprint $table) {
            $table->string('claim_token', 64)->nullable()->after('status');
            $table->timestamp('claimed_at')->nullable()->after('claim_token');

            $table->index(['status', 'recipient_type', 'claimed_at'], 'mdl_status_type_claimed_index');
        });
    }

    public function down(): void
    {
        Schema::table('message_dispatch_logs', function (Blueprint $table) {
            $table->dropIndex('mdl_status_type_claimed_index');
            $table->dropColumn(['claim_token', 'claimed_at']);
        });
    }
};
