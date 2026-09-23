<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Phase 7 Task 1 — Journey temporal execution backbone.
     *
     * Purely ADDITIVE: three nullable/defaulted columns and one index on
     * the existing whatsapp_flow_sessions table. No existing column, row
     * or status value changes meaning, so every in-flight session keeps
     * running exactly as before.
     *
     * The session row IS the durable run state. A session parked on a
     * `delay` node has status = 'waiting' and wait_until = when it is due;
     * a scheduled scan (journeys:resume-due) hands due rows to a queued job
     * that CLAIMS the row with one conditional UPDATE before doing anything,
     * so the row — not the queue, not a worker's memory — decides whether a
     * step runs. That is what lets a run survive worker/app restarts, lost
     * or duplicated queue jobs, and retries.
     *
     *   wait_until  when a 'waiting' session becomes due. While a worker is
     *               executing it, this is pushed forward by a lease (so a
     *               crashed worker's row becomes due again on its own).
     *   attempts    resume attempts spent on the current wait (reset to 0
     *               whenever the session leaves 'waiting' or parks again).
     *   last_error  the most recent resume failure, kept on 'failed' rows.
     *
     * New status values ('waiting', 'failed', 'cancelled') need no schema
     * change: `status` is already a free string column.
     *
     * (status, wait_until) serves the due-scan's WHERE status = 'waiting'
     * AND wait_until <= now ORDER BY wait_until.
     */
    public function up(): void
    {
        Schema::table('whatsapp_flow_sessions', function (Blueprint $table) {
            $table->timestamp('wait_until')->nullable()->after('status');
            $table->unsignedSmallInteger('attempts')->default(0)->after('wait_until');
            $table->string('last_error', 500)->nullable()->after('attempts');

            $table->index(['status', 'wait_until'], 'whatsapp_flow_sessions_due_index');
        });
    }

    public function down(): void
    {
        Schema::table('whatsapp_flow_sessions', function (Blueprint $table) {
            $table->dropIndex('whatsapp_flow_sessions_due_index');
            $table->dropColumn(['wait_until', 'attempts', 'last_error']);
        });
    }
};
