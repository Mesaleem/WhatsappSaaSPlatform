<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Per-Message Wallet Top-Up Requests — extends the existing Quota
     * Exhaustion Request Workflow (see the quota_requests table's own
     * create migration and QuotaRequestController's docblock) to cover
     * 'per_message' subscriptions, which have no message-count cap to
     * increment (their total_allocated_messages is a server-computed
     * floor(price_paid / rate_per_message), not a directly toppable
     * quota) but do have a real rupee wallet that can run low.
     *
     * requested_extra_messages is relaxed to nullable rather than
     * replaced, since a row now represents ONE OF two mutually exclusive
     * request shapes: a flat_quota row has requested_extra_messages set
     * and requested_topup_amount null; a per_message row has the
     * reverse. QuotaRequestController::approve() branches on which one
     * is non-null to decide which top-up path to run.
     */
    public function up(): void
    {
        Schema::table('quota_requests', function (Blueprint $table) {
            $table->decimal('requested_topup_amount', 10, 2)->nullable()->after('requested_extra_messages');
        });

        Schema::table('quota_requests', function (Blueprint $table) {
            $table->unsignedInteger('requested_extra_messages')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('quota_requests', function (Blueprint $table) {
            $table->unsignedInteger('requested_extra_messages')->nullable(false)->change();
            $table->dropColumn('requested_topup_amount');
        });
    }
};
