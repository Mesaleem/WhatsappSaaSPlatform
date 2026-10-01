<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 9 Task 1 — provider-agnostic social account foundation. Additive
 * only: four nullable columns on the existing `social_accounts` table
 * (created 2026_09_10_090002; nothing is renamed, dropped or back-filled,
 * and existing rows keep working unchanged).
 *
 * The existing table already covers ownership (account_id, cascade),
 * provider, provider account id (provider_id + asset_type, unique per
 * account+provider), display data (name, avatar_url), encrypted
 * credentials (access_token / refresh_token — `encrypted` casts, hidden)
 * and token expiry. What it could not record is WHY a connection is not
 * healthy and WHO connected it:
 *
 *   status_reason         a short, safe explanation of the last non-healthy
 *                         state (e.g. "Meta reports the access token has
 *                         expired") — never a token or a raw provider body
 *   status_checked_at     when the provider last confirmed the state
 *   connected_by_user_id  the user who bound the asset (audit; no FK so the
 *                         record survives a deleted user)
 *   metadata              provider-specific, NON-SECRET operating data a
 *                         provider needs later (JSON); credentials never go
 *                         here
 *
 * health_status keeps its three stored values (connected | token_expired |
 * reauth_required); the API derives the lifecycle status from them
 * (connected | expired | revoked), see SocialConnectionStatus.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('social_accounts', function (Blueprint $table) {
            $table->string('status_reason', 255)->nullable()->after('health_status');
            $table->timestamp('status_checked_at')->nullable()->after('status_reason');
            $table->unsignedBigInteger('connected_by_user_id')->nullable()->after('status_checked_at');
            $table->json('metadata')->nullable()->after('connected_by_user_id');
        });
    }

    public function down(): void
    {
        Schema::table('social_accounts', function (Blueprint $table) {
            $table->dropColumn(['status_reason', 'status_checked_at', 'connected_by_user_id', 'metadata']);
        });
    }
};
