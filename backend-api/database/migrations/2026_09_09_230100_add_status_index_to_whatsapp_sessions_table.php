<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * API Performance Fix — AnalyticsController::computeGlobalSummary()'s
     * `active_whatsapp_engines` count filters WhatsAppSession by `status`
     * with no index to serve it. Row count here is bounded by the number
     * of tenant accounts (account_id is unique on this table), so this is
     * lower-impact than the payment_alerts/invoices indexes above, but it
     * is one of the exact columns named in this audit's "ensure queries
     * use indexed columns" instruction and costs nothing to add.
     */
    public function up(): void
    {
        Schema::table('whatsapp_sessions', function (Blueprint $table) {
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::table('whatsapp_sessions', function (Blueprint $table) {
            $table->dropIndex(['status']);
        });
    }
};
