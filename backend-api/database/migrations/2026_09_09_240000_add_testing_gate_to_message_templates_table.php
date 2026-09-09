<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Strict 1-Template-Per-Client & Testing Gate — a template cannot be
     * approved (and therefore cannot go live for a client) until a Super
     * Admin has fired at least one real test send through it and it
     * actually succeeded. is_super_admin_tested is the gate
     * MessageTemplateController::approve() checks; tested_at is kept
     * alongside it purely as an audit/"how stale is this test" signal —
     * editing template_body after a successful test does NOT reset
     * either column (disclosed in the audit report as a known edge case:
     * a Super Admin who edits a template after testing it must be
     * trusted to re-test before approving, the same trust boundary this
     * whole panel already runs on for every other field).
     */
    public function up(): void
    {
        Schema::table('message_templates', function (Blueprint $table) {
            $table->boolean('is_super_admin_tested')->default(false)->after('status');
            $table->timestamp('tested_at')->nullable()->after('is_super_admin_tested');
        });
    }

    public function down(): void
    {
        Schema::table('message_templates', function (Blueprint $table) {
            $table->dropColumn(['is_super_admin_tested', 'tested_at']);
        });
    }
};
