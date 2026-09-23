<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 1 Foundation — which Capabilities a specific tenant Account
 * actually holds right now. `source` records where the grant came from
 * (a Plan purchase, a manual Super-Admin grant, or Agent delegation);
 * `granted_by_account_id` gives the Agent-delegation audit trail that
 * the current plain `allowed_modules` array cannot express. Generalizes
 * AccountService::resolveDelegatedModules()'s intersection pattern
 * without replacing it — allowed_modules keeps working unchanged in
 * Phase 1. See the Phase 1 plan, Step 5.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('account_entitlements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('account_id')->constrained('accounts')->cascadeOnDelete();
            $table->foreignId('capability_id')->constrained('capabilities')->cascadeOnDelete();
            // plan | manual_grant | agent_delegated
            $table->string('source')->default('manual_grant');
            $table->foreignId('granted_by_account_id')->nullable()->constrained('accounts')->nullOnDelete();
            $table->timestamps();

            $table->unique(['account_id', 'capability_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('account_entitlements');
    }
};
