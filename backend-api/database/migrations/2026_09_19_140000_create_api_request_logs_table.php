<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Phase 3 Task 4 -- Public API observability/auditability. Inspection
     * (this task's own audit report) found no existing per-request log
     * for the external Developer API: `message_dispatch_logs` records
     * only a resolved outbound-message OUTCOME, written from deep inside
     * a dispatcher, and is never reached at all when a request fails
     * authentication or authorization -- those return before any
     * dispatcher ever runs. This table is the smallest additive schema
     * needed to answer "who called, which tenant, which key, which
     * endpoint, when, what result, what request id" for EVERY external
     * API request, auth/authz failures included, without ever storing a
     * secret, a key/secret hash, or request/message content.
     *
     * account_id/api_key_id are nullable -- both are null whenever
     * authentication itself failed (no tenant/key was ever resolved),
     * which is expected and correct, not a data-quality gap.
     * api_key_prefix is denormalized (not just joined via api_key_id) so
     * a row stays human-identifiable by its safe prefix alone even if a
     * future feature ever hard-deletes an ApiKey row (this codebase
     * currently only revokes, never deletes, keys, but this column costs
     * nothing and matches the existing key_prefix/secret_prefix
     * convention on api_keys itself).
     */
    public function up(): void
    {
        Schema::create('api_request_logs', function (Blueprint $table) {
            $table->id();
            $table->string('request_id', 64);
            $table->foreignId('account_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('api_key_id')->nullable()->constrained()->nullOnDelete();
            $table->string('api_key_prefix', 32)->nullable();
            $table->string('method', 10);
            $table->string('path', 255);
            $table->unsignedSmallInteger('status_code');
            $table->unsignedInteger('duration_ms')->nullable();
            $table->string('ip', 45)->nullable();
            $table->string('user_agent', 255)->nullable();
            $table->timestamps();

            $table->index('request_id');
            $table->index(['account_id', 'created_at']);
            $table->index('api_key_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('api_request_logs');
    }
};
