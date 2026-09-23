<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 1 Foundation — the Capability dimension: the atomic, addressable
 * unit of "what a tenant may use" (crm, journey_automation, ads, social,
 * ai, whatsapp_groups, ...), independent of who is asking (Permission)
 * and independent of commercial packaging (Plan). See
 * wa-saas-platform-phase1-foundation-plan.md Step 5.
 *
 * Purely additive — does not touch Account::MODULES/allowed_modules,
 * which keeps working unchanged.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('capabilities', function (Blueprint $table) {
            $table->id();
            $table->string('slug')->unique();
            $table->string('label');
            $table->text('description')->nullable();
            // whatsapp | messaging | growth | platform — informational
            // grouping for admin UI, not enforced by any code path.
            $table->string('category')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('capabilities');
    }
};
