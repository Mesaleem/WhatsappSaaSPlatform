<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 11 Task 1 — which industries an account operates in. One row per (account, industry);
 * the industry / sub-type keys are validated against config/industries.php, so a new industry
 * is a registry entry, not a schema change (and there is no boolean flag per industry).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('account_industries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('account_id')->constrained('accounts')->cascadeOnDelete();
            $table->string('industry', 40);
            $table->string('subtype', 40)->nullable();
            $table->foreignId('assigned_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['account_id', 'industry'], 'account_industry_unique');
            $table->index('industry', 'account_industries_industry_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('account_industries');
    }
};
