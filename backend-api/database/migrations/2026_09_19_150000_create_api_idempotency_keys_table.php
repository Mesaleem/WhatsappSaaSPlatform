<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Phase 3 Task 5 -- Idempotency protection for the external Developer
     * API's message-sending operations. Inspection (this task's own
     * audit) found no existing generic Idempotency-Key mechanism: the
     * closest things are PaymentAlertDispatcher's own payment_ref-scoped
     * dedup (unique(['account_id','payment_ref']) on payment_alerts) and
     * MessageDispatchLog's audit trail, neither of which lets a caller
     * safely retry an arbitrary send after a network timeout without
     * risking a duplicate outbound WhatsApp message.
     *
     * account_id + idempotency_key identifies one logical operation (see
     * ApiIdempotency::handle()'s own docblock) -- the unique index below
     * is deliberately the ONLY concurrency guard, mirroring the exact
     * same "unique index is the deduplication guard's backstop" pattern
     * payment_alerts already established: two simultaneous requests for
     * the same (account_id, idempotency_key) attempt the same INSERT,
     * exactly one wins, and the other reads back the winner's row
     * instead of ever re-running the actual send.
     *
     * request_fingerprint is a one-way sha256 hash of the request's own
     * validated payload -- never the payload itself -- so a caller who
     * reuses a key with a genuinely different request body is detected
     * (and rejected) without this table ever holding message content.
     * response_body is the exact JSON this API already returned to this
     * same caller the first time; replaying it to the same
     * account/api-key on a later retry is not a new exposure.
     */
    public function up(): void
    {
        Schema::create('api_idempotency_keys', function (Blueprint $table) {
            $table->id();
            $table->foreignId('account_id')->constrained()->cascadeOnDelete();
            $table->string('idempotency_key', 255);
            $table->string('request_fingerprint', 64);

            // 'processing' -- claimed, the operation has not yet reached a
            // safe, replayable completion point. 'completed' -- the
            // operation actually sent/queued the message; response_status/
            // response_body are set and are what a matching retry replays.
            // A 'processing' row whose operation ended in a non-2xx result
            // (validation/auth/quota/provider failure -- nothing was
            // actually sent) is DELETED rather than marked 'completed', so
            // the same key remains available for a genuine retry -- see
            // ApiIdempotency::handle()'s own docblock.
            $table->string('status', 20)->default('processing');
            $table->unsignedSmallInteger('response_status')->nullable();
            $table->longText('response_body')->nullable();

            $table->timestamps();

            $table->unique(['account_id', 'idempotency_key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('api_idempotency_keys');
    }
};
