<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * API Performance Fix — AnalyticsController::globalDailyRevenue() and
     * computeGlobalSummary()'s current_month_revenue both run
     * `WHERE status = 'paid' AND paid_at BETWEEN ? AND ?` with NO
     * account_id filter (these are platform-wide, cross-tenant
     * aggregates) — the existing (account_id, status) and
     * (account_id, created_at) composite indexes on this table cannot
     * serve that query at all, since account_id isn't part of it. This
     * adds the composite the query actually needs.
     */
    public function up(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->index(['status', 'paid_at'], 'invoices_status_paid_at_idx');
        });
    }

    public function down(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->dropIndex('invoices_status_paid_at_idx');
        });
    }
};
