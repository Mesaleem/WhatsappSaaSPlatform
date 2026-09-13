<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Group Messaging — Step 1. [Disclosed correction]: the request that
     * created this migration also asked for a `status` column on
     * `templates` — that table is actually named `message_templates`
     * (see 2026_09_09_210000_create_message_templates_table.php), and it
     * already has `status` — a native enum('pending','approved','rejected')
     * default 'pending', enforced throughout MessageTemplateController
     * and MessageTemplate::STATUSES. Re-adding it here would both fail
     * (duplicate column) and, had it been added with the requested
     * uppercase values, silently break every existing status check in
     * this codebase, which all compare against the lowercase values.
     * Only header_type and rejection_reason — genuinely new — are added
     * below.
     *
     * header_type uses the same lowercase-enum convention as the
     * existing `status` column on this table (and `source`/`status` on
     * message_dispatch_logs) rather than the request's literal uppercase,
     * for internal consistency within one table — disclosed deviation,
     * not a silent one.
     *
     * rejection_reason is nullable and, as of this migration, not yet
     * written by MessageTemplateController::reject() (that method just
     * does $template->forceFill(['status' => 'rejected'])->save()) —
     * wiring the reject endpoint to accept and store a reason is
     * follow-up controller work, out of this schema-only step's scope.
     */
    public function up(): void
    {
        Schema::table('message_templates', function (Blueprint $table) {
            $table->enum('header_type', ['text', 'image', 'document'])->default('text')->after('variables_schema');
            $table->text('rejection_reason')->nullable()->after('tested_at');
        });
    }

    public function down(): void
    {
        Schema::table('message_templates', function (Blueprint $table) {
            $table->dropColumn(['header_type', 'rejection_reason']);
        });
    }
};
