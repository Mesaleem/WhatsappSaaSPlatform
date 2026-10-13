<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * CRM Sales Phase 1 — the customer's company (B2B). Named crm_companies, not
 * "accounts": `accounts` is the WapHub tenant, and the two must never be confused.
 *
 * unique(id, account_id) lets contacts / deals / activities declare a composite,
 * tenant-proving foreign key to it (the same pattern contacts and crm_leads use).
 * Money and tax details live on sales documents (later phases), not here.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('crm_companies', function (Blueprint $table) {
            $table->id();
            $table->foreignId('account_id')->constrained()->cascadeOnDelete();
            $table->string('name', 191);
            $table->string('industry', 100)->nullable();
            $table->string('website', 255)->nullable();
            $table->string('gstin', 15)->nullable();
            $table->string('phone', 32)->nullable();
            $table->string('email', 191)->nullable();
            $table->string('address_line1', 191)->nullable();
            $table->string('address_line2', 191)->nullable();
            $table->string('city', 100)->nullable();
            $table->string('state', 100)->nullable();
            $table->string('pincode', 12)->nullable();
            $table->string('country', 100)->nullable();
            $table->foreignId('owner_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('type', 20)->default('prospect');
            $table->string('status', 20)->default('active');
            $table->timestamps();

            $table->unique(['id', 'account_id']);
            $table->index(['account_id', 'name']);
            $table->index(['account_id', 'gstin']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('crm_companies');
    }
};
