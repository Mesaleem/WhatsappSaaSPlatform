<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Group Messaging — Step 1. [Disclosed correction]: the request that
     * created this migration also asked for template_name, message_preview
     * and source on message_dispatch_logs — all three already exist
     * (template_name/message_preview: 2026_09_13_130000_add_template_metadata_
     * to_message_dispatch_logs_table.php, still pending its own
     * authorization; source: present since this table's creation
     * migration). Re-adding any of them here would fail with a duplicate-
     * column error once the earlier migration runs, so only the four
     * genuinely new columns are added below.
     *
     * recipient_phone (the existing, required column) is left untouched.
     * How a group-blast row populates it — one summary row with no single
     * phone, one row per recipient with recipient_type='group' repeated,
     * or something else — is an open design question for whoever builds
     * the actual group-dispatch pathway (a later step); this migration
     * only adds the columns, it does not decide that.
     */
    public function up(): void
    {
        Schema::table('message_dispatch_logs', function (Blueprint $table) {
            $table->string('recipient_type')->default('individual')->after('recipient_phone');
            $table->foreignId('group_id')->nullable()->after('recipient_type')->constrained('contact_groups')->nullOnDelete();
            $table->string('group_name')->nullable()->after('group_id');
            $table->unsignedInteger('recipient_count')->default(1)->after('group_name');
        });
    }

    public function down(): void
    {
        Schema::table('message_dispatch_logs', function (Blueprint $table) {
            $table->dropForeign(['group_id']);
            $table->dropColumn(['recipient_type', 'group_id', 'group_name', 'recipient_count']);
        });
    }
};
