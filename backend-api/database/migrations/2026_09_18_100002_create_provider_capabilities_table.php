<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 1 Foundation — Provider Capability: the single, data-driven
 * table that replaces scattered `engine_type === 'qr'` string checks
 * for "is this capability even possible/allowed on this provider".
 * `reason` distinguishes a genuine technical limitation (e.g. Meta
 * cannot do WhatsApp Groups) from a deliberate business/product-tier
 * restriction (e.g. QR does not get Journey/Ads) — both are DATA here,
 * never a hardcoded plan-name check. See the Phase 1 plan, Step 6.
 *
 * FK + unique constraint declared inside Schema::create() (not a later
 * Schema::table() ALTER) so this migrates cleanly on sqlite too, per
 * this project's own established convention (see
 * 2026_09_14_090000_add_account_type_and_agent_id_to_accounts_table's
 * docblock for why ALTER-time foreign() is avoided here).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('provider_capabilities', function (Blueprint $table) {
            $table->id();
            $table->foreignId('provider_id')->constrained('providers')->cascadeOnDelete();
            $table->foreignId('capability_id')->constrained('capabilities')->cascadeOnDelete();
            $table->boolean('supported')->default(true);
            $table->text('reason')->nullable();
            $table->timestamps();

            $table->unique(['provider_id', 'capability_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('provider_capabilities');
    }
};
