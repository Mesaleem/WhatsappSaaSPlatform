<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Module 9 — stores the WhatsApp engine's own message id (Meta's
     * WAMID, or Baileys' message_id) returned by
     * WhatsAppDriverInterface::sendMessage() on success. This is what lets
     * MetaWebhookController correlate an inbound Meta status callback
     * ("delivered") back to the specific payment_alerts row it belongs
     * to, in order to fire a 'message.delivered' outbound webhook event.
     * Nullable — a failed send never has one.
     */
    public function up(): void
    {
        Schema::table('payment_alerts', function (Blueprint $table) {
            $table->string('gateway_message_id')->nullable()->after('cost_deducted');
            $table->index('gateway_message_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('payment_alerts', function (Blueprint $table) {
            $table->dropIndex(['gateway_message_id']);
            $table->dropColumn('gateway_message_id');
        });
    }
};
