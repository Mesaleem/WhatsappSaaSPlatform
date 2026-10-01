<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 8 Task 5 — AI usage metering. One new table; nothing existing is
 * altered (purely additive on a fresh and an existing database).
 *
 * ai_operations is the durable record of ONE billable AI operation and the
 * correlation between it and the credit ledger:
 *   - who/what: account_id (the BILLED target account), actor_user_id,
 *     source, operation, provider, model;
 *   - usage: input/output tokens as the vendor reported them
 *     (usage_reported = false when it reported none);
 *   - money: credits_reserved (the hold taken before the call),
 *     credits_charged (the settled amount), credits_uncharged (usage above
 *     the hold that was not billed), reservation_id / consumption_entry_id
 *     (the credit_reservations / credit_ledger_entries rows);
 *   - state: running → failed | succeeded → settled (| abandoned).
 *
 * It is ALSO the durable retry state for the critical case "the provider
 * answered but recording the charge failed": status 'succeeded' with a
 * computed credits_charged stays until `ai:settle-operations` settles it
 * through the idempotent CreditService consume (consume:{reservation_id}).
 *
 * No prompt, system prompt, response text, credential or header is stored.
 * The credit ledger remains the only source of truth for balances; this
 * table never changes a balance.
 *
 * unique(account_id, operation_key): one row per caller-supplied (or
 * generated) operation key per account — a retried operation key can never
 * create a second charge.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_operations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('account_id')->constrained('accounts')->cascadeOnDelete();
            $table->unsignedBigInteger('actor_user_id')->nullable();
            $table->string('operation_key', 191);
            $table->unsignedSmallInteger('attempt')->default(1);
            $table->string('source', 32);
            $table->string('operation', 64);
            $table->string('provider', 32)->nullable();
            $table->string('model', 128)->nullable();
            $table->string('status', 16);
            $table->unsignedBigInteger('credits_reserved')->default(0);
            $table->unsignedBigInteger('credits_charged')->nullable();
            $table->unsignedBigInteger('credits_uncharged')->default(0);
            $table->unsignedInteger('input_tokens')->nullable();
            $table->unsignedInteger('output_tokens')->nullable();
            $table->boolean('usage_reported')->nullable();
            $table->unsignedBigInteger('reservation_id')->nullable();
            $table->unsignedBigInteger('consumption_entry_id')->nullable();
            $table->string('error_code', 64)->nullable();
            $table->unsignedInteger('latency_ms')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('settled_at')->nullable();
            $table->timestamps();

            $table->unique(['account_id', 'operation_key'], 'ai_operations_account_key_unique');
            $table->index(['status', 'updated_at'], 'ai_operations_status_updated_index');
            $table->index(['account_id', 'created_at'], 'ai_operations_account_created_index');
            $table->index('reservation_id', 'ai_operations_reservation_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_operations');
    }
};
