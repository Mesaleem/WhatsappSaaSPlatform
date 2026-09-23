<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Phase 4 Task 5 -- Meta WhatsApp Template Foundation.
     *
     * message_templates already carries everything the QR engine needs
     * (title, template_code, template_body, variables_schema, header_type/
     * header_media_url, the tiered approval `status` including
     * 'pending_meta_approval'). What it had NO representation for is the
     * Meta-specific half of a Cloud API template:
     *
     *   language              Meta requires an explicit language code on
     *                         every template send (e.g. 'en_US'); there is
     *                         no default and a mismatch is rejected.
     *   category              Meta's own MARKETING / UTILITY /
     *                         AUTHENTICATION classification, which governs
     *                         pricing and approval.
     *   meta_template_name    The name the template is registered under in
     *                         the WABA. Distinct from this platform's
     *                         template_code: Meta's namespace is per-WABA
     *                         and snake_case, ours is global and
     *                         UPPER_SNAKE.
     *   meta_template_status  Meta's own review verdict, which is separate
     *                         from this platform's workflow `status` --
     *                         a row can be 'approved' here (an admin
     *                         cleared it) while Meta still has it PENDING.
     *
     * All four are NULLABLE and default to null, so every existing row --
     * and every QR template ever created -- is completely unaffected. No
     * existing column is altered and no index is added: nothing queries
     * these yet, and adding a speculative index would be exactly the kind
     * of change this task excludes.
     */
    public function up(): void
    {
        Schema::table('message_templates', function (Blueprint $table) {
            // Meta's own BCP-47-ish codes: 'en', 'en_US', 'pt_BR'.
            $table->string('language', 20)->nullable()->after('template_body');
            $table->string('category', 32)->nullable()->after('language');
            // Meta allows up to 512 characters for a template name.
            $table->string('meta_template_name', 512)->nullable()->after('category');
            $table->string('meta_template_status', 32)->nullable()->after('meta_template_name');
        });
    }

    public function down(): void
    {
        Schema::table('message_templates', function (Blueprint $table) {
            $table->dropColumn([
                'language',
                'category',
                'meta_template_name',
                'meta_template_status',
            ]);
        });
    }
};
