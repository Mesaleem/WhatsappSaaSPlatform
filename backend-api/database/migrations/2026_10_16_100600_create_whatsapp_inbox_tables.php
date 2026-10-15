<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * QA fixes (2026-10-14) — the WhatsApp inbox.
 *
 * Customers' WhatsApp messages only ever reached the chatbot / journeys; a
 * client could neither see a conversation nor answer it. Every inbound message
 * (QR and Meta) and every message the platform sends is now kept per
 * conversation (one per customer number per account), and the client replies
 * from the inbox.
 *
 *   wa_conversations.last_inbound_at  the Meta 24-hour service window: free
 *                                     text is allowed only within 24 h of it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('wa_conversations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('account_id')->constrained('accounts')->cascadeOnDelete();
            $table->string('contact_phone', 32);
            $table->string('contact_name', 255)->nullable();
            $table->unsignedBigInteger('whatsapp_number_id')->nullable();
            $table->string('last_message_preview', 255)->nullable();
            $table->string('last_direction', 3)->nullable();
            $table->timestamp('last_message_at')->nullable();
            $table->timestamp('last_inbound_at')->nullable();
            $table->unsignedInteger('unread_count')->default(0);
            $table->timestamps();

            $table->unique(['account_id', 'contact_phone']);
            $table->index(['account_id', 'last_message_at']);
        });

        Schema::create('wa_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('wa_conversation_id')->constrained('wa_conversations')->cascadeOnDelete();
            $table->foreignId('account_id')->constrained('accounts')->cascadeOnDelete();
            $table->string('direction', 3); // in | out
            $table->string('message_type', 20)->default('text');
            $table->text('body')->nullable();
            $table->string('external_id', 191)->nullable();
            $table->string('status', 20)->nullable();
            $table->string('source', 40)->nullable();
            $table->unsignedBigInteger('sent_by_user_id')->nullable();
            $table->timestamp('created_at')->nullable();

            $table->index(['wa_conversation_id', 'id']);
            $table->index(['account_id', 'external_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('wa_messages');
        Schema::dropIfExists('wa_conversations');
    }
};
