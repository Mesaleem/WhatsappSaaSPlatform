<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 1 Foundation — the Provider dimension. Seeded from the two
 * `subscriptions.engine_type` values already in use ('qr', 'meta') plus
 * a 'none' row for tenants with no WhatsApp engine at all (e.g. a
 * Social+AI-only account). Does NOT touch subscriptions.engine_type or
 * WhatsAppEngineFactory — this is new, additive metadata; the driver
 * layer is untouched. See the Phase 1 plan, Step 5/6.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('providers', function (Blueprint $table) {
            $table->id();
            $table->string('slug')->unique();
            $table->string('label');
            // Informational only in Phase 1 — nothing reads this to
            // resolve an actual driver; WhatsAppEngineFactory::make()
            // is untouched and keeps matching on engine_type directly.
            $table->string('driver_class')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('providers');
    }
};
