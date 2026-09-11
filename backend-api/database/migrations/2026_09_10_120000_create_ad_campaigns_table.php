<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Social Media Marketing & Meta Ads Automation Expansion — Phase 3.
     * Meta Ads Launcher & Auto-Budget Guard. One row per campaign actually
     * launched on Meta via AdCampaignController::launch() ->
     * MetaAdsService::launch(). A row only exists once Meta has
     * successfully returned a campaign id — a failed launch attempt
     * leaves no row (see AdCampaignController::launch()'s docblock).
     *
     * `objective` stores this API's OWN literal vocabulary
     * (LEAD_GENERATION|MESSAGES|TRAFFIC, exactly as specified) rather than
     * Meta's ODAX `OUTCOME_*` constant — MetaAdsService is the one place
     * that translates between the two (see its OBJECTIVE_MAP), so this
     * column stays meaningful even if Meta's own objective taxonomy
     * changes again.
     *
     * `daily_budget`/`cpl_threshold` are stored in the tenant-facing whole
     * currency unit (e.g. rupees/dollars as a decimal) — MetaAdsService
     * converts to Meta's minor-unit integer (paise/cents) only at the API
     * boundary, so this table stays human-readable and consistent with
     * how the rest of this app stores money (see Subscription/Invoice).
     *
     * `last_spend`/`last_impressions`/`last_leads`/`last_cpl`/
     * `last_checked_at` are a cache written by CheckAdPerformanceRules
     * (the cron guard) — the campaign list endpoint serves these cached
     * figures rather than calling Meta's Insights API on every page load
     * (rate-limit and latency reasons, disclosed in the Phase 3 audit
     * report), so they are at most one cron cycle (15-30 min) stale.
     */
    public function up(): void
    {
        Schema::create('ad_campaigns', function (Blueprint $table) {
            $table->id();
            $table->foreignId('account_id')->constrained()->cascadeOnDelete();
            // The connected meta_ad_account SocialAccount this campaign was
            // launched through. Nullable + nullOnDelete so disconnecting the
            // ad account later (SocialAuthController::destroy()) doesn't
            // cascade-delete campaign history/spend records.
            $table->foreignId('social_account_id')->nullable()->constrained('social_accounts')->nullOnDelete();

            $table->string('meta_campaign_id')->unique();
            $table->string('meta_adset_id')->nullable();
            $table->string('meta_ad_id')->nullable();

            $table->string('name');
            $table->string('objective');
            $table->string('status')->default('ACTIVE');
            $table->decimal('daily_budget', 10, 2);
            $table->decimal('cpl_threshold', 10, 2)->nullable();

            $table->decimal('last_spend', 10, 2)->default(0);
            $table->unsignedInteger('last_impressions')->default(0);
            $table->unsignedInteger('last_leads')->default(0);
            $table->decimal('last_cpl', 10, 2)->nullable();
            $table->timestamp('last_checked_at')->nullable();

            // Set by CheckAdPerformanceRules the moment it pauses a
            // campaign automatically — distinct from a manual pause via
            // AdCampaignController::pause(), so the frontend can show
            // WHY a campaign is paused (see present()'s docblock).
            $table->timestamp('auto_paused_at')->nullable();
            $table->string('auto_pause_reason')->nullable();

            $table->timestamps();

            $table->index(['account_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ad_campaigns');
    }
};
