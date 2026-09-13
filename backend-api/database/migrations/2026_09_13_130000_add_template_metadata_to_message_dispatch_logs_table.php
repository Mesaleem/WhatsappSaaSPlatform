<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * [New feature, disclosed] — additive follow-up to
     * 2026_09_13_120000_create_message_dispatch_logs_table.php (must run
     * first; both are still pending authorization as of this migration).
     *
     * template_name: the MessageTemplate's title at send time, written
     * only by TemplateMessageDispatcher — null for every other source
     * (web_ui/api payment alerts, chatbot, journey), matching the
     * request's "null for direct API/Chatbot" exactly.
     *
     * message_preview: a short snippet (see MessageDispatchLog::
     * PREVIEW_MAX_LENGTH) of the actual outgoing text, truncated
     * centrally in MessageDispatchLog::record() — not the full message,
     * to keep row size bounded on a table that can grow large.
     */
    public function up(): void
    {
        Schema::table('message_dispatch_logs', function (Blueprint $table) {
            $table->string('template_name')->nullable()->after('reference_id');
            $table->string('message_preview', 200)->nullable()->after('template_name');
        });
    }

    public function down(): void
    {
        Schema::table('message_dispatch_logs', function (Blueprint $table) {
            $table->dropColumn(['template_name', 'message_preview']);
        });
    }
};
