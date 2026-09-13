<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Social/Ads Launcher Overhaul — Step 1 (Gemini Pro Engine &
     * Multi-Token Prompt Refactor).
     *
     * DISCLOSED DESIGN DECISION: "database-backed per-tenant API key
     * retrieval" is implemented as a genuine two-tier lookup, matching
     * this codebase's own precedent for tenant-vs-platform config
     * (e.g. accounts.allow_facebook is a per-tenant TOGGLE layered over
     * social_provider_configs' platform-level OAuth app credentials):
     *
     *  1. accounts.gemini_api_key (this column) — an OPTIONAL, genuinely
     *     per-tenant override. A tenant who supplies their own Google AI
     *     Studio / Gemini API key here has their AI Ad Copywriter calls
     *     billed against THEIR key, not the platform's.
     *  2. social_provider_configs (provider = 'gemini') — the PLATFORM
     *     default, Super-Admin-set via the existing Admin Social Gateway
     *     Settings screen/endpoint (SocialGatewayController), reusing
     *     that vault's existing encrypted-at-rest `client_secret` column
     *     as "the API key" for this non-OAuth provider — see
     *     SocialProviderConfig::PROVIDERS' docblock for why client_id/
     *     redirect_uri are simply left null for this provider rather
     *     than inventing a parallel vault table.
     *  3. config('services.gemini.key') (GEMINI_API_KEY env var) — final
     *     fallback, preserving this codebase's existing OpenAI/Anthropic
     *     precedent of "no key configured anywhere = deterministic
     *     template fallback, never a 500" for a fresh/local install.
     *
     * Encrypted-cast at the model layer (Crypt::encryptString/
     * decryptString), same pattern as every other secret column in this
     * schema (SocialAccount.access_token, SocialProviderConfig.
     * client_secret, WhatsAppSession.meta_access_token).
     */
    public function up(): void
    {
        Schema::table('accounts', function (Blueprint $table) {
            $table->text('gemini_api_key')->nullable()->after('brand_accent_color');
        });
    }

    public function down(): void
    {
        Schema::table('accounts', function (Blueprint $table) {
            $table->dropColumn('gemini_api_key');
        });
    }
};
