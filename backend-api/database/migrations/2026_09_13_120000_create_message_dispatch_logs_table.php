<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * [New feature, disclosed]: a single, cross-channel audit trail for
     * every outbound WhatsApp send this platform makes — Send Alert (web
     * UI and the external API), Send Template (web UI and the external
     * API), Chatbot auto-replies, and Journey Builder steps. Deliberately
     * separate from `payment_alerts`, which is left completely untouched
     * and keeps powering the existing "Recent Transactions" grid and its
     * CSV/PDF export — that table has payment-specific required columns
     * (customer_name, amount, payment_ref) that make no sense for a
     * template/chatbot/journey send, and nothing before this migration
     * recorded those three channels at all, which is exactly why the
     * Dashboard's Total Messages / Today's Messages / Daily Message
     * Volume never moved for them.
     */
    public function up(): void
    {
        Schema::create('message_dispatch_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('account_id')->constrained()->cascadeOnDelete();

            // web_ui = Send Alert (session-authenticated); web_template =
            // Send Template (session-authenticated); api = either of
            // those two dispatched via the external Developer API
            // (api_key_id below identifies which key); chatbot = an
            // inbound-triggered auto-reply; journey = a WhatsApp Journey
            // Builder step. 'journey' is a 5th source, not one of the 4
            // requested — added because it is a real, distinct dispatch
            // pathway this table would otherwise silently miss.
            $table->string('source');

            $table->foreignId('api_key_id')->nullable()->constrained()->nullOnDelete();

            $table->string('recipient_phone');
            $table->string('status'); // 'sent' | 'failed'
            $table->text('error_reason')->nullable();

            // [Disclosed]: always false as of this migration. Nothing in
            // WhatsAppDriverInterface::sendMessage() (BaileysDriver,
            // MetaCloudApiDriver) accepts or sends a media attachment —
            // this column is forward-compatible plumbing only, not a
            // real feature. Never render it as if media sending works.
            $table->boolean('has_media')->default(false);

            // Loose pointer back to the record that caused this send
            // (payment_alerts.id, message_templates.id, chatbot_rules.id,
            // whatsapp_journeys.id) — nullable, untyped FK on purpose,
            // since it points at a different table depending on `source`.
            // Traceability only; never joined against directly.
            $table->string('reference_type')->nullable();
            $table->unsignedBigInteger('reference_id')->nullable();

            $table->timestamp('sent_at')->nullable();
            $table->timestamps();

            $table->index(['account_id', 'created_at']);
            $table->index(['account_id', 'status']);
            $table->index(['account_id', 'source']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('message_dispatch_logs');
    }
};
