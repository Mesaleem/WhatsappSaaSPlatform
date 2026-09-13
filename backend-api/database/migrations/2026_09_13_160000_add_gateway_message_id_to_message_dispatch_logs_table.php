<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * [New feature, disclosed] — closes part of the "no persisted
     * message-log table for inbound Meta status-webhook events" gap
     * flagged in the product analysis report (§6, item 6): the outbound
     * WAMID (Meta's per-message id) that MetaCloudApiDriver::sendMessage()
     * already returns as $result['message_id'] on success was never
     * captured anywhere on message_dispatch_logs, so a later status
     * webhook (sent/delivered/read/failed, keyed on that WAMID) had
     * nothing to correlate against for chatbot/template/journey sends —
     * only payment_alerts.gateway_message_id existed for this purpose,
     * and only for the payment-alert pathway.
     *
     * Purely additive and nullable: every existing/pending row (and every
     * row written by a caller that does not pass a gatewayMessageId, e.g.
     * a Baileys/'qr'-engine send, which has no comparable Meta WAMID)
     * reads as NULL, matching this table's existing nullable-additive
     * migration pattern (see 2026_09_13_140003_add_resolution_counts_...).
     * NOT run by me, per standing instruction — pending authorization
     * alongside this table's other pending migrations.
     */
    public function up(): void
    {
        Schema::table('message_dispatch_logs', function (Blueprint $table) {
            $table->string('gateway_message_id')->nullable()->after('sent_at');
            $table->index('gateway_message_id');
        });
    }

    public function down(): void
    {
        Schema::table('message_dispatch_logs', function (Blueprint $table) {
            $table->dropIndex(['gateway_message_id']);
            $table->dropColumn('gateway_message_id');
        });
    }
};
