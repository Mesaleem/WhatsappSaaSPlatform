<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Social Media Marketing & Meta Ads Automation Expansion — Phase 1.
     *
     * Per-tenant platform toggles a Super Admin sets on Account (mirrors
     * the is_platform_device / allowed_modules precedent of additive
     * boolean/json columns on this table). Deliberately default(false)
     * — the OPPOSITE default of allowed_modules' "null = everything
     * enabled" zero-regression stance, because this is a brand-new
     * capability, not a pre-existing one being retrofitted with toggles:
     * no tenant should be able to launch Meta/LinkedIn/YouTube social
     * connections until a Super Admin explicitly turns the platform on
     * for that account. SocialAuthController must check the relevant
     * allow_* flag before issuing an OAuth redirect URL for a given
     * provider/asset type.
     */
    public function up(): void
    {
        Schema::table('accounts', function (Blueprint $table) {
            $table->boolean('allow_facebook')->default(false)->after('allowed_modules');
            $table->boolean('allow_instagram')->default(false)->after('allow_facebook');
            $table->boolean('allow_linkedin')->default(false)->after('allow_instagram');
            $table->boolean('allow_youtube')->default(false)->after('allow_linkedin');
        });
    }

    public function down(): void
    {
        Schema::table('accounts', function (Blueprint $table) {
            $table->dropColumn(['allow_facebook', 'allow_instagram', 'allow_linkedin', 'allow_youtube']);
        });
    }
};
