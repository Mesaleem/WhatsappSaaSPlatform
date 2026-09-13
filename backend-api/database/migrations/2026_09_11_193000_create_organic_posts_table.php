<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Social/Ads Launcher Overhaul — Step 3 (Organic Multi-Channel
     * Publishing Engine). One row per organic (free, non-budget) post a
     * tenant attempted to publish to Facebook Page Feed, Instagram, or
     * LinkedIn via OrganicPublishService.
     *
     * DISCLOSED DIVERGENCE from ad_campaigns' "only persist on full
     * external success" precedent (see that table's own migration
     * docblock): THIS table is explicitly required to log FAILURES too
     * ("log post status... and publishing errors" — the feature's own
     * spec), so a row is created eagerly at 'pending' before the
     * external call, then updated to 'published'/'failed' after —
     * OrganicPost is an attempt log, not (like ad_campaigns) a mirror of
     * confirmed external state.
     *
     * `platform` is deliberately distinct from `provider`: 'meta' is one
     * OAuth provider but covers TWO different publishing surfaces/APIs
     * (Facebook Page Feed vs Instagram Content Publishing), so provider
     * alone can't say which driver ran or which asset_type on
     * social_accounts was used.
     */
    public function up(): void
    {
        Schema::create('organic_posts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('account_id')->constrained()->cascadeOnDelete();
            // The connected asset this was posted through. Nullable +
            // nullOnDelete so disconnecting the asset later doesn't erase
            // post history — same precedent as ad_campaigns.social_account_id.
            $table->foreignId('social_account_id')->nullable()->constrained('social_accounts')->nullOnDelete();

            $table->string('provider'); // 'meta' | 'linkedin'
            $table->string('platform'); // 'facebook' | 'instagram' | 'linkedin'

            $table->text('caption');
            $table->string('media_url')->nullable();
            $table->string('media_type')->nullable(); // 'image' | 'video' | null

            $table->string('status')->default('pending'); // 'pending' | 'published' | 'failed'
            $table->string('external_post_id')->nullable();
            $table->text('error_message')->nullable();
            $table->timestamp('published_at')->nullable();

            $table->timestamps();

            $table->index(['account_id', 'platform', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('organic_posts');
    }
};
