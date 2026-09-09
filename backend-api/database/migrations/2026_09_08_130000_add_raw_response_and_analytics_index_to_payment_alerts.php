<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Module 7 additions, both purely additive (no data loss, no column
     * drops, no renames):
     *
     * 1. `raw_response` — the WhatsApp driver's raw sendMessage() result
     *    (Meta Graph API response / qr-engine-service response), captured
     *    by ProcessPaymentAlertJob on both success and failure. This is
     *    what MessageLogController's detail endpoint needs to satisfy the
     *    "full payload metadata" requirement — that data was previously
     *    discarded after being logged, not persisted. NULL for any alert
     *    sent before this migration.
     * 2. A composite (account_id, created_at) index — AnalyticsController's
     *    daily time-series aggregation filters and groups by exactly this
     *    pair; the existing (account_id, status) index from Module 6 does
     *    not cover it.
     */
    public function up(): void
    {
        Schema::table('payment_alerts', function (Blueprint $table) {
            $table->text('raw_response')->nullable()->after('error_reason');
            $table->index(['account_id', 'created_at'], 'payment_alerts_account_created_idx');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('payment_alerts', function (Blueprint $table) {
            $table->dropIndex('payment_alerts_account_created_idx');
            $table->dropColumn('raw_response');
        });
    }
};
