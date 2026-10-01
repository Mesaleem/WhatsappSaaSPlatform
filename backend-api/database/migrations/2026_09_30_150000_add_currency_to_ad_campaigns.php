<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Owner request (2026-09-30) — budgets, spend and CPL are in the Meta ad
 * account's own currency (INR for an Indian ad account). The currency Meta
 * reported for the ad account is stored on each launched campaign, so stored
 * figures (list, dashboard) can be shown in it without calling Meta. NULL =
 * not known (launched before this change, or Meta could not be asked).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ad_campaigns', function (Blueprint $table) {
            $table->string('currency', 3)->nullable()->after('daily_budget');
        });
    }

    public function down(): void
    {
        Schema::table('ad_campaigns', function (Blueprint $table) {
            $table->dropColumn('currency');
        });
    }
};
