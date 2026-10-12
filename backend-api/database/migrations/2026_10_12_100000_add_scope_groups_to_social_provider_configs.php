<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Optional OAuth permission groups (e.g. Meta's "publish posts & reels",
 * "lead ads") the Super Admin switches on from Social Gateway Settings once
 * the matching use case / permission exists on the platform's provider app.
 * Null = none on (the .env flags still apply on top).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('social_provider_configs', function (Blueprint $table) {
            $table->json('scope_groups')->nullable()->after('webhook_verify_token');
        });
    }

    public function down(): void
    {
        Schema::table('social_provider_configs', function (Blueprint $table) {
            $table->dropColumn('scope_groups');
        });
    }
};
