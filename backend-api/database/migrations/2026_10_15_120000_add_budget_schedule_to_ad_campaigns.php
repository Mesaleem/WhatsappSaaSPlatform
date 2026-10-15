<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Ads Phase B (2026-10-15) — a campaign can run on a total (lifetime) budget
 * with a schedule, keeps what it was launched with so it can be duplicated /
 * run again, and remembers why Meta stopped it.
 *   budget_type      daily | lifetime (Meta: an ad set has one or the other; a
 *                    lifetime budget needs end_time). daily_budget is 0 for lifetime.
 *   start_time/end_time  the ad set schedule; NULL = starts now / runs until stopped.
 *   launch_spec      objective, targeting_specs, placements, creative, cpl_threshold —
 *                    only what the launch form sent (no tokens, no Meta ids).
 *   meta_stop_status Meta's effective_status when the guard found the campaign
 *                    stopped on Meta (e.g. PAUSED, CAMPAIGN_PAUSED, WITH_ISSUES); cleared on resume.
 * Additive and nullable / defaulted, so existing rows keep their meaning (daily, no schedule).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ad_campaigns', function (Blueprint $table) {
            $table->string('budget_type', 16)->default('daily')->after('daily_budget');
            $table->decimal('lifetime_budget', 12, 2)->nullable()->after('budget_type');
            $table->timestamp('start_time')->nullable()->after('lifetime_budget');
            $table->timestamp('end_time')->nullable()->after('start_time');
            $table->json('launch_spec')->nullable()->after('end_time');
            $table->string('meta_stop_status', 32)->nullable()->after('auto_pause_reason');
        });
    }

    public function down(): void
    {
        Schema::table('ad_campaigns', function (Blueprint $table) {
            $table->dropColumn(['budget_type', 'lifetime_budget', 'start_time', 'end_time', 'launch_spec', 'meta_stop_status']);
        });
    }
};
