<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Module 9 — "Enforce rate limiting based on the tenant account's rate
     * limit setting" (spec, requirement 1). Requests per minute allowed on
     * the external Developer API (Api/V1). Defaulted to 60/min for every
     * existing and new account; a Super Admin can raise/lower it per
     * account via AccountController::update().
     */
    public function up(): void
    {
        Schema::table('accounts', function (Blueprint $table) {
            $table->unsignedInteger('api_rate_limit_per_minute')->default(60)->after('status');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('accounts', function (Blueprint $table) {
            $table->dropColumn('api_rate_limit_per_minute');
        });
    }
};
