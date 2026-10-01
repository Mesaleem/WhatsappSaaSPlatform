<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 10 Task 6 — the dashboard's conversion_daily series and the
 * conversion-value summary filter and group by (account_id, converted_at).
 * Additive, index only; no data change.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ad_attributions', function (Blueprint $table) {
            $table->index(['account_id', 'converted_at'], 'ad_attr_account_converted_idx');
        });
    }

    public function down(): void
    {
        Schema::table('ad_attributions', function (Blueprint $table) {
            $table->dropIndex('ad_attr_account_converted_idx');
        });
    }
};
