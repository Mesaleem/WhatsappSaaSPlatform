<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Phase 7 Task 7 — durable, append-only Journey execution history.
     *
     * Before this, a run was observable only through the session row's
     * CURRENT state (status, current_node_id, attempts, wait_until,
     * last_error — each overwritten by the next step) and through log
     * files. This table keeps one compact row per execution event; see
     * App\Models\JourneyExecutionEvent for the vocabulary.
     *
     * flow_id / flow_version_id / session_id / inbound_event_id are plain
     * indexed ids, deliberately NOT foreign keys: the history of a run must
     * survive the journey (and its sessions) being deleted. Only the
     * account cascades — a deleted tenant takes its history with it.
     *
     * Additive: a new table, no change to any existing table or row.
     */
    public function up(): void
    {
        Schema::create('journey_execution_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('account_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('flow_id')->nullable();
            $table->unsignedBigInteger('flow_version_id')->nullable();
            $table->unsignedBigInteger('session_id')->nullable();
            $table->unsignedBigInteger('inbound_event_id')->nullable();
            $table->string('source', 16);
            $table->string('event', 32);
            $table->string('node_id', 64)->nullable();
            $table->string('node_type', 32)->nullable();
            $table->string('result', 64)->nullable();
            $table->unsignedSmallInteger('attempt')->nullable();
            $table->string('error_category', 32)->nullable();
            $table->string('error_message', 500)->nullable();
            $table->timestamp('scheduled_for')->nullable();
            $table->json('details')->nullable();
            $table->timestamp('created_at')->nullable();

            $table->index(['account_id', 'session_id', 'id'], 'jee_account_session_idx');
            $table->index(['account_id', 'flow_id', 'id'], 'jee_account_flow_idx');
            $table->index(['session_id', 'created_at'], 'jee_session_created_idx');
            $table->index(['account_id', 'event', 'created_at'], 'jee_account_event_created_idx');
            $table->index(['error_category', 'created_at'], 'jee_category_created_idx');
            $table->index('inbound_event_id', 'jee_inbound_event_idx');
            $table->index('created_at', 'jee_created_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('journey_execution_events');
    }
};
