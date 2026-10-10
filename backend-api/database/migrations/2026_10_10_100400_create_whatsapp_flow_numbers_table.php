<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Journey <-> Channel binding (Connexxa parity, scoped). ConnexxaIQ requires
 * 1-2 channels to be picked at journey CREATION time ("Channels (up to 2)*"
 * on its Create Journey dialog) before a journey can be built at all. This
 * pivot is the wa-saas equivalent: a journey is bound to one or more of the
 * account's WhatsAppNumber "slots" (whatsapp_numbers -- the closest existing
 * concept to a Connexxa channel; see WhatsAppNumberService's docblock).
 *
 * SCOPE NOTE (decided 2026-10-10, see CONNEXXA_JOURNEY_GAP_ANALYSIS):
 * whatsapp_sessions.account_id is UNIQUE -- today an account has exactly one
 * live inbound-webhook engine connection, whatever its whatsapp_numbers slot
 * count. So this pivot is validation/metadata ONLY for now: it enforces the
 * "can't build a journey without picking a channel" creation gate and records
 * which number(s) a journey is meant for, but WhatsAppJourneyEngine::matchFlow()
 * does NOT yet filter by it -- that needs a separate, platform-wide
 * multi-session-per-account engine change (Meta webhook resolver + Baileys
 * driver), which is out of this journey-builder-parity scope by the user's
 * own decision. Nothing about today's single-channel trigger matching changes.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('whatsapp_flow_numbers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('flow_id')->constrained('whatsapp_flows')->cascadeOnDelete();
            $table->foreignId('whatsapp_number_id')->constrained('whatsapp_numbers')->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['flow_id', 'whatsapp_number_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('whatsapp_flow_numbers');
    }
};
