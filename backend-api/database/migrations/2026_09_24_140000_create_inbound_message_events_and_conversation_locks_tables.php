<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Phase 7 Task 3 — durable inbound idempotency + per-conversation
     * serialization for the chatbot/Journey pipeline. Additive: two new
     * tables, nothing existing is touched.
     *
     * inbound_message_events — APPEND-ONLY claim ledger. One row per
     * inbound message ever processed, keyed by the provider's own message
     * identity (Meta WAMID; QR/Baileys message key id). The UNIQUE
     * (account_id, provider, event_key) index is the authority: the first
     * INSERT wins, every duplicate/redelivery/concurrent copy loses the
     * insert and is skipped. Survives restarts and works across any number
     * of app instances because it is the database, not the cache.
     * processed_at is stamped once processing finishes (the only update).
     *
     * journey_conversation_locks — a lease per (account_id, phone_number),
     * taken with one conditional UPDATE before an inbound message is
     * processed and released after, so two different messages from the
     * same customer can never read the same session node and advance it
     * twice, and can never start two journeys side by side. A lease
     * (owner + locked_until) rather than a held transaction, so no row
     * lock (quota, CRM, logs) is held across provider sends; a crashed
     * holder's lease simply expires.
     */
    public function up(): void
    {
        Schema::create('inbound_message_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('account_id')->constrained()->cascadeOnDelete();
            $table->string('provider', 16);
            $table->string('event_key', 191);
            $table->string('phone_number', 32);
            $table->timestamp('created_at')->nullable();
            $table->timestamp('processed_at')->nullable();

            $table->unique(['account_id', 'provider', 'event_key'], 'ime_account_provider_event_unique');
        });

        Schema::create('journey_conversation_locks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('account_id')->constrained()->cascadeOnDelete();
            $table->string('phone_number', 32);
            $table->string('owner', 64)->nullable();
            $table->timestamp('locked_until')->nullable();
            $table->timestamps();

            $table->unique(['account_id', 'phone_number'], 'jcl_account_phone_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('journey_conversation_locks');
        Schema::dropIfExists('inbound_message_events');
    }
};
