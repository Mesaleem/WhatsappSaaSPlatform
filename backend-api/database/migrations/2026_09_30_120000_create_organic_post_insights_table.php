<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 9 Task 4 — social publishing analytics.
 *
 * One row per organic post: the LATEST insights snapshot fetched from the
 * provider plus the refresh bookkeeping. Provider-agnostic: every metric is
 * nullable, because no platform exposes every metric and a metric that is
 * not available must never be stored as 0.
 *
 *   state                 not_fetched | ok | partial | post_not_found |
 *                         reconnect_required | rate_limited | provider_error
 *                         (the outcome of the LAST attempt; metrics keep the
 *                         last successful values)
 *   impressions … video_avg_watch_time_ms   NULL = not available / not fetched; 0 = really zero
 *   unavailable_metrics   {metric: reason} for metrics the provider did not report
 *                         (not_supported | permission | unsupported_metric | not_reported)
 *   metrics_fetched_at    last successful fetch
 *   last_attempted_at / next_refresh_at / attempts   refresh schedule + back-off
 *   claim_token / claimed_at   single in-flight refresh per post (lease)
 *   error_code / error_message  safe classification of the last failure (no provider payload)
 *   metadata              safe provider data (e.g. Graph error code) — never tokens
 *
 * account_id is denormalised from the post for tenant-scoped queries.
 * New table only; organic_posts is not changed.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('organic_post_insights', function (Blueprint $table) {
            $table->id();
            $table->foreignId('account_id')->constrained('accounts')->cascadeOnDelete();
            $table->foreignId('organic_post_id')->unique()->constrained('organic_posts')->cascadeOnDelete();
            $table->foreignId('social_account_id')->nullable()->constrained('social_accounts')->nullOnDelete();
            $table->string('provider', 32);
            $table->string('platform', 32);
            $table->string('provider_post_id', 191);
            $table->string('state', 32)->default('not_fetched');

            $table->unsignedBigInteger('impressions')->nullable();
            $table->unsignedBigInteger('reach')->nullable();
            $table->unsignedBigInteger('reactions')->nullable();
            $table->unsignedBigInteger('comments')->nullable();
            $table->unsignedBigInteger('shares')->nullable();
            $table->unsignedBigInteger('saves')->nullable();
            $table->unsignedBigInteger('clicks')->nullable();
            $table->unsignedBigInteger('video_views')->nullable();
            $table->unsignedBigInteger('video_avg_watch_time_ms')->nullable();
            $table->json('unavailable_metrics')->nullable();

            $table->timestamp('metrics_fetched_at')->nullable();
            $table->timestamp('last_attempted_at')->nullable();
            $table->timestamp('next_refresh_at')->nullable();
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->string('claim_token', 64)->nullable();
            $table->timestamp('claimed_at')->nullable();
            $table->string('error_code', 64)->nullable();
            $table->string('error_message', 255)->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['state', 'next_refresh_at'], 'organic_post_insights_state_next_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('organic_post_insights');
    }
};
