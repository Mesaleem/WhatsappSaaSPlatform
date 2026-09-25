<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 8 Task 2 — plan credit allowance and per-period allocation.
 * Additive only; existing rows keep working unchanged.
 *
 *   plans.included_credits          the plan's AI-credit allowance per
 *                                   purchased period. NOT NULL DEFAULT 0:
 *                                   every existing plan gets an explicit 0
 *                                   (owner decision), so nothing is granted
 *                                   until a Super Admin sets a value.
 *   invoices.plan_included_credits  captured at order time with the other
 *                                   P5-4 plan terms (the order is fulfilled
 *                                   with what was bought). NULL = captured
 *                                   before this migration = bought no credits.
 *   usage_quotas (+4 columns)       the existing Phase 1 per-period quota
 *                                   table now records each plan credit
 *                                   allocation period (capability `ai`):
 *                                   subscription_id, invoice_id, source and
 *                                   credit_ledger_entry_id (UNIQUE — one
 *                                   period row per allocation ledger entry;
 *                                   the ledger's own unique idempotency key
 *                                   is what prevents a second allocation).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('plans', function (Blueprint $table) {
            $table->unsignedBigInteger('included_credits')->default(0)->after('total_allocated_messages');
        });

        Schema::table('invoices', function (Blueprint $table) {
            $table->unsignedBigInteger('plan_included_credits')->nullable()->after('plan_duration_days');
        });

        Schema::table('usage_quotas', function (Blueprint $table) {
            $table->unsignedBigInteger('subscription_id')->nullable()->after('capability_id');
            $table->unsignedBigInteger('invoice_id')->nullable()->after('subscription_id');
            $table->string('source', 32)->nullable()->after('invoice_id');
            $table->unsignedBigInteger('credit_ledger_entry_id')->nullable()->after('source');

            $table->unique('credit_ledger_entry_id', 'usage_quotas_credit_ledger_entry_unique');
            $table->index(['account_id', 'capability_id', 'period_ends_at'], 'usage_quotas_account_capability_period_index');
            $table->index('invoice_id', 'usage_quotas_invoice_index');
        });

        // SQLite cannot ALTER a foreign key in (standing finding, PROJECT_STATE §7).
        if ($this->mysqlFamily()) {
            Schema::table('usage_quotas', function (Blueprint $table) {
                $table->foreign('credit_ledger_entry_id', 'usage_quotas_credit_ledger_entry_foreign')
                    ->references('id')->on('credit_ledger_entries')->cascadeOnDelete();
            });
        }
    }

    public function down(): void
    {
        if ($this->mysqlFamily()) {
            Schema::table('usage_quotas', function (Blueprint $table) {
                $table->dropForeign('usage_quotas_credit_ledger_entry_foreign');
            });
        }

        Schema::table('usage_quotas', function (Blueprint $table) {
            $table->dropUnique('usage_quotas_credit_ledger_entry_unique');
            $table->dropIndex('usage_quotas_account_capability_period_index');
            $table->dropIndex('usage_quotas_invoice_index');
            $table->dropColumn(['subscription_id', 'invoice_id', 'source', 'credit_ledger_entry_id']);
        });

        Schema::table('invoices', function (Blueprint $table) {
            $table->dropColumn('plan_included_credits');
        });

        Schema::table('plans', function (Blueprint $table) {
            $table->dropColumn('included_credits');
        });
    }

    private function mysqlFamily(): bool
    {
        return in_array(DB::getDriverName(), ['mysql', 'mariadb'], true);
    }
};
