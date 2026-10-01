<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 10 Task 2 — Ads campaign lifecycle hardening.
 *
 * A launch now records its local row BEFORE the first Meta call (status
 * LAUNCHING), so a provider rejection (FAILED) or a lost response
 * (UNCONFIRMED) is kept instead of vanishing — hence meta_campaign_id
 * becomes nullable (it is filled as soon as Meta returns the campaign id).
 * The global UNIQUE on meta_campaign_id is kept (NULLs do not collide).
 *
 * launch_request_id — the caller's Idempotency-Key, unique PER TENANT, so a
 * repeated launch request replays the stored row instead of calling Meta again.
 * The index leads with launch_request_id (not account_id) so it can never
 * take over the account_id foreign key's index.
 *
 * last_provider_error / _at — a SAFE summary of the last provider failure
 * (HTTP status + Meta error code only, never Meta's raw message or a token).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ad_campaigns', function (Blueprint $table) {
            $table->string('meta_campaign_id')->nullable()->change();
        });

        Schema::table('ad_campaigns', function (Blueprint $table) {
            $table->string('launch_request_id', 100)->nullable()->after('meta_ad_id');
            $table->string('last_provider_error', 255)->nullable()->after('auto_pause_reason');
            $table->timestamp('last_provider_error_at')->nullable()->after('last_provider_error');
            $table->unique(['launch_request_id', 'account_id'], 'ad_campaigns_launch_request_account_unique');
        });
    }

    public function down(): void
    {
        // Restoring NOT NULL would fail on rows whose launch never reached Meta.
        // Refuse BEFORE changing anything; rows are never deleted automatically.
        if (DB::table('ad_campaigns')->whereNull('meta_campaign_id')->exists()) {
            throw new RuntimeException(
                'ad_campaigns has rows without meta_campaign_id (failed/unconfirmed launches). '
                .'Review and remove them manually before rolling back this migration.'
            );
        }

        Schema::table('ad_campaigns', function (Blueprint $table) {
            $table->dropUnique('ad_campaigns_launch_request_account_unique');
        });

        Schema::table('ad_campaigns', function (Blueprint $table) {
            $table->dropColumn(['launch_request_id', 'last_provider_error', 'last_provider_error_at']);
        });

        Schema::table('ad_campaigns', function (Blueprint $table) {
            $table->string('meta_campaign_id')->nullable(false)->change();
        });
    }
};
