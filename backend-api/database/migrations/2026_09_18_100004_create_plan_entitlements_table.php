<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 1 Foundation — the pivot that lets a Plan bundle an arbitrary
 * set of Capabilities (with an optional per-capability usage_limit),
 * replacing PlanCatalog's single hardcoded provider+billing+quota
 * tuple. See the Phase 1 plan, Step 5.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('plan_entitlements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('plan_id')->constrained('plans')->cascadeOnDelete();
            $table->foreignId('capability_id')->constrained('capabilities')->cascadeOnDelete();
            // NULL = unbounded/not applicable for this capability. The
            // live/current usage a tenant has actually consumed lives on
            // subscriptions/usage_quotas, never here — this is the
            // plan-template default only.
            $table->unsignedInteger('usage_limit')->nullable();
            $table->timestamps();

            $table->unique(['plan_id', 'capability_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('plan_entitlements');
    }
};
