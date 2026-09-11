<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Social Media Marketing & Meta Ads Automation Expansion — Final
     * Phase (White-Label Automated PDF Reporting).
     *
     * ROOT-CAUSE FINDING, not part of the literal spec's table list but
     * necessary for the spec's own "monthly performance PDF report"
     * requirement to be honest: verified by reading
     * MetaAdsService::fetchInsights() directly, it calls Meta's Insights
     * API with `date_preset: 'today'`, and CheckAdPerformanceRules
     * OVERWRITES ad_campaigns.last_spend/last_impressions/last_leads/
     * last_cpl every 15 minutes with that day's figures. There is
     * therefore NO historical/monthly aggregate for ad spend, impressions
     * or leads ANYWHERE in this schema before this migration — a
     * "monthly" report built from last_spend etc. would in fact be
     * reporting TODAY'S spend mislabeled as a month total, which is
     * incorrect, not merely imprecise.
     *
     * This table is a daily snapshot, upserted once per campaign per
     * calendar day by CheckAdPerformanceRules::evaluate() (see that
     * class's docblock for exactly where), so SocialReportController can
     * SUM(spend)/SUM(impressions)/SUM(leads) over metric_date BETWEEN
     * start-of-month AND end-of-month for a genuinely period-scoped
     * total. unique(ad_campaign_id, metric_date) makes the upsert
     * idempotent — running the 15-minute cron many times in one day
     * updates the SAME day's row rather than accumulating duplicates.
     *
     * DISCLOSED, UNAVOIDABLE LIMITATION: this table starts empty. A
     * report run the same day this ships will show partial data (from
     * today's cron ticks only, not a full month) — there is no way to
     * backfill history that was never recorded, and every prior period's
     * true daily figures are permanently unrecoverable (only the last
     * snapshot per campaign — today's — was ever kept). This is a data-
     * retention gap in the PRE-EXISTING design, not something this
     * migration could have prevented; it can only start correct
     * accumulation from here forward.
     */
    public function up(): void
    {
        Schema::create('ad_campaign_daily_metrics', function (Blueprint $table) {
            $table->id();
            $table->foreignId('account_id')->constrained()->cascadeOnDelete();
            $table->foreignId('ad_campaign_id')->constrained('ad_campaigns')->cascadeOnDelete();

            $table->date('metric_date');
            $table->decimal('spend', 10, 2)->default(0);
            $table->unsignedInteger('impressions')->default(0);
            $table->unsignedInteger('leads')->default(0);
            $table->decimal('cpl', 10, 2)->nullable();

            $table->timestamps();

            $table->unique(['ad_campaign_id', 'metric_date']);
            $table->index(['account_id', 'metric_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ad_campaign_daily_metrics');
    }
};
