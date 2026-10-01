<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 11 Task 5 — the generic collection lifecycle: a charge assignment can be CANCELLED (voided).
 *
 * Everything else in the lifecycle is already derived from payment rows (open → partially_paid → paid, plus
 * overdue from the due date), so only the one fact that cannot be derived is stored: when (and by whom, why)
 * an assignment was cancelled. `cancelled_at IS NOT NULL` ⇔ status `cancelled`; there is no stored status
 * column to drift out of step with the payments.
 *
 * Cancelling is allowed only while NO payment exists (the service checks under the assignment row lock);
 * refunds/reversals are out of scope, so a charge with money against it can never be voided.
 *
 * Timestamp: the same day as, and after, the collections tables it alters (2026_10_01_110000).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('collection_charge_assignments', function (Blueprint $table) {
            $table->timestamp('cancelled_at')->nullable()->after('due_date');
            $table->foreignId('cancelled_by_user_id')->nullable()->after('cancelled_at')->constrained('users')->nullOnDelete();
            $table->string('cancellation_reason', 255)->nullable()->after('cancelled_by_user_id');
        });
    }

    public function down(): void
    {
        Schema::table('collection_charge_assignments', function (Blueprint $table) {
            $table->dropForeign(['cancelled_by_user_id']);
            $table->dropColumn(['cancelled_at', 'cancelled_by_user_id', 'cancellation_reason']);
        });
    }
};
