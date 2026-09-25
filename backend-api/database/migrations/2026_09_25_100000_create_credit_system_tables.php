<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 8 Task 1 — Credit System Foundation. Three new tables; nothing
 * existing is altered, so the migration is purely additive on a fresh AND
 * an existing database.
 *
 *   credit_accounts        one row per billable account: balance (credits
 *                          owned), reserved (held by open reservations).
 *                          available = balance − reserved. Integers only
 *                          (UNSIGNED BIGINT), never floats.
 *   credit_reservations    one row per reservation: reserved → consumed |
 *                          released (a status change is a conditional
 *                          UPDATE in CreditService; nothing else writes it).
 *   credit_ledger_entries  APPEND-ONLY: every movement of balance or
 *                          reserved, with the deltas and the resulting
 *                          state, actor, idempotency key and reference.
 *
 * Tenant consistency: a ledger entry / reservation carries account_id and a
 * COMPOSITE foreign key (credit_account_id, account_id) → credit_accounts
 * (id, account_id), so an entry can never belong to one tenant's credit
 * account and another tenant's account_id (same pattern as crm_lead_tags;
 * composite FK + CASCADE is proven on MariaDB 10.11 and declared in CREATE
 * TABLE, so SQLite enforces it too). Deleting an account cascades its whole
 * credit history (no model in this schema is soft-deleted; same as invoices).
 *
 * Idempotency is a DATABASE guarantee: unique(credit_account_id,
 * idempotency_key) on both the ledger and the reservations.
 *
 * On MySQL/MariaDB (only — SQLite cannot ALTER a CHECK in), CHECK
 * constraints back the service's invariants: reserved <= balance, and a
 * ledger/reservation amount is > 0.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('credit_accounts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('account_id')->unique()->constrained('accounts')->cascadeOnDelete();
            $table->unsignedBigInteger('balance')->default(0);
            $table->unsignedBigInteger('reserved')->default(0);
            $table->timestamps();

            // Target of the composite foreign keys below.
            $table->unique(['id', 'account_id'], 'credit_accounts_id_account_unique');
        });

        Schema::create('credit_reservations', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('credit_account_id');
            $table->unsignedBigInteger('account_id');
            $table->unsignedBigInteger('amount');
            $table->string('status', 16)->default('reserved');
            $table->unsignedBigInteger('consumed_amount')->nullable();
            $table->string('idempotency_key', 191);
            $table->string('reference_type', 64)->nullable();
            $table->string('reference_id', 64)->nullable();
            $table->json('metadata')->nullable();
            $table->timestamp('consumed_at')->nullable();
            $table->timestamp('released_at')->nullable();
            $table->timestamps();

            $table->unique(['credit_account_id', 'idempotency_key'], 'credit_reservations_idempotency_unique');
            $table->index(['account_id', 'status'], 'credit_reservations_account_status_index');
            $table->index(['reference_type', 'reference_id'], 'credit_reservations_reference_index');

            $table->foreign(['credit_account_id', 'account_id'], 'credit_reservations_credit_account_foreign')
                ->references(['id', 'account_id'])->on('credit_accounts')->cascadeOnDelete();
        });

        Schema::create('credit_ledger_entries', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('credit_account_id');
            $table->unsignedBigInteger('account_id');
            $table->string('type', 32);
            $table->unsignedBigInteger('amount');
            $table->bigInteger('balance_delta');
            $table->bigInteger('reserved_delta');
            $table->unsignedBigInteger('balance_after');
            $table->unsignedBigInteger('reserved_after');
            $table->string('idempotency_key', 191);
            $table->char('request_hash', 64);
            $table->unsignedBigInteger('reservation_id')->nullable();
            // The consumption entry a refund refers to. Deliberately NOT a
            // self-referencing foreign key: MariaDB treats a cascade that
            // re-enters the same table as RESTRICT, which would block
            // deleting an account. Enforced by CreditService instead.
            $table->unsignedBigInteger('refund_of_entry_id')->nullable();
            $table->string('reference_type', 64)->nullable();
            $table->string('reference_id', 64)->nullable();
            $table->string('reason', 255)->nullable();
            $table->json('metadata')->nullable();
            $table->string('source', 32);
            $table->foreignId('actor_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('created_at')->nullable();

            $table->unique(['credit_account_id', 'idempotency_key'], 'credit_ledger_idempotency_unique');
            $table->index(['account_id', 'id'], 'credit_ledger_account_index');
            $table->index(['account_id', 'type', 'created_at'], 'credit_ledger_account_type_index');
            $table->index(['reference_type', 'reference_id'], 'credit_ledger_reference_index');
            $table->index('reservation_id', 'credit_ledger_reservation_index');
            $table->index('refund_of_entry_id', 'credit_ledger_refund_of_index');

            $table->foreign(['credit_account_id', 'account_id'], 'credit_ledger_credit_account_foreign')
                ->references(['id', 'account_id'])->on('credit_accounts')->cascadeOnDelete();
            $table->foreign('reservation_id', 'credit_ledger_reservation_foreign')
                ->references('id')->on('credit_reservations')->cascadeOnDelete();
        });

        if (in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
            DB::statement('ALTER TABLE credit_accounts ADD CONSTRAINT credit_accounts_reserved_within_balance CHECK (reserved <= balance)');
            DB::statement('ALTER TABLE credit_reservations ADD CONSTRAINT credit_reservations_amount_positive CHECK (amount > 0)');
            DB::statement('ALTER TABLE credit_ledger_entries ADD CONSTRAINT credit_ledger_amount_positive CHECK (amount > 0)');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('credit_ledger_entries');
        Schema::dropIfExists('credit_reservations');
        Schema::dropIfExists('credit_accounts');
    }
};
