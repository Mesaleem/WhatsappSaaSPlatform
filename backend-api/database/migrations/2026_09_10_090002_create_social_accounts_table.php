<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Social Media Marketing & Meta Ads Automation Expansion — Phase 1.
     *
     * One row per tenant-bound external asset (a Facebook Page, an
     * Instagram Business Account, a Meta Ad Account, later a LinkedIn
     * Page / YouTube Channel) — NOT one row per OAuth login. The Asset
     * Selection Modal in SocialAccountsPage lets a client bind more than
     * one asset from a single OAuth grant (e.g. one Facebook Page AND
     * one Meta Ad Account), so account_id+provider is intentionally
     * NOT unique on its own; account_id+provider+provider_id is.
     *
     * DISCLOSED ADDITION beyond the spec's literal field list:
     * `asset_type`. The spec's `provider_id` is documented as "Page/Ad
     * Account ID" — i.e. it can identify either of two different kinds
     * of Meta asset — so a bare (provider, provider_id) pair is
     * ambiguous about what was actually connected. asset_type
     * disambiguates it explicitly ('facebook_page' | 'instagram' |
     * 'meta_ad_account' | 'linkedin_page' | 'youtube_channel') and is
     * what the frontend's lock banner / Ad Launcher / Lead Sync gates
     * check for, rather than string-matching on provider alone.
     *
     * access_token/refresh_token: `text` + encrypted-cast (Crypt::
     * encryptString/decryptString), same pattern as
     * WhatsAppSession.meta_access_token and webhook_subscriptions.secret.
     *
     * KNOWN GAP (disclosed): Meta issues a distinct Page Access Token
     * per Page, separate from the User Access Token exchanged in
     * SocialAuthController::callback(). Phase 1 stores the user token
     * uniformly across all assets bound from one OAuth grant; exchanging
     * and storing a true per-Page token is a Phase 2 hardening item
     * before any real Ad Launcher / Lead Sync goes live, tracked the
     * same way MetaWebhookController discloses its own signature-
     * verification gap.
     */
    public function up(): void
    {
        Schema::create('social_accounts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('account_id')->constrained()->cascadeOnDelete();
            $table->string('provider'); // 'meta' | 'linkedin' | 'google'
            $table->string('asset_type'); // 'facebook_page' | 'instagram' | 'meta_ad_account' | 'linkedin_page' | 'youtube_channel'
            $table->string('provider_id'); // Page ID / Ad Account ID / IG Business Account ID, per asset_type
            $table->string('name')->nullable();
            $table->string('avatar_url')->nullable();
            $table->text('access_token')->nullable();
            $table->text('refresh_token')->nullable();
            $table->timestamp('token_expires_at')->nullable();
            $table->string('health_status')->default('connected'); // 'connected' | 'token_expired' | 'reauth_required'
            $table->timestamps();

            $table->unique(['account_id', 'provider', 'provider_id']);
            $table->index(['account_id', 'asset_type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('social_accounts');
    }
};
