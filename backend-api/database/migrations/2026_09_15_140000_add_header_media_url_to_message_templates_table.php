<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Media Templates (QR/Baileys-only) — companion column to the
     * existing `header_type` enum ('text'|'image'|'document', added by
     * 2026_09_13_140003_add_header_type_and_rejection_reason_to_message_templates_table.php)
     * which had no field to actually hold a media URL, so it was
     * entirely unused before this. header_media_url is nullable and only
     * meaningful when header_type is 'image' or 'document' — enforced at
     * the application layer (MessageTemplateController's validation,
     * MessageTemplate::requiresHeaderMedia()), not a DB constraint, the
     * same convention `rejection_reason` already uses on this table for
     * its own "only meaningful in one state" nullable column.
     *
     * [Disclosed, deliberate scope]: this column is engine-agnostic at
     * the schema level, but TemplateMessageDispatcher only ever forwards
     * it to BaileysDriver (the 'qr' engine) — a Meta Cloud API template
     * with real media requires Meta's own template-media registration
     * and approval flow, which this feature does not implement. A
     * 'meta'-engine account's template send ignores this column entirely
     * and always sends plain text, unchanged from before this migration.
     */
    public function up(): void
    {
        Schema::table('message_templates', function (Blueprint $table) {
            $table->string('header_media_url')->nullable()->after('header_type');
        });
    }

    public function down(): void
    {
        Schema::table('message_templates', function (Blueprint $table) {
            $table->dropColumn('header_media_url');
        });
    }
};
