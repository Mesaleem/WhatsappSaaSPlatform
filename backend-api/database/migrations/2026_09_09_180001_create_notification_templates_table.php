<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Reusable mail/notification content templates (Module: Mail Template
     * Manager). Deliberately NOT account-scoped: these are platform-wide
     * boilerplate (e.g. "Welcome", "Payment Reminder") authored by anyone
     * holding manage-notifications (super_admin or admin) and available
     * to every broadcast composer — mirroring how MailSetting (Turn D) is
     * a single platform-wide row rather than per-tenant. `created_by` is
     * kept only for attribution, not as an access-control boundary.
     *
     * `type` distinguishes the two composer modes on the Mail Template
     * Manager page: 'rich_text' (the contentEditable WYSIWYG editor) vs
     * 'raw_html' (a plain textarea of hand-written HTML) — both are
     * ultimately stored as the same HTML string in `body`; `type` only
     * tells the frontend which editor to reopen the template in.
     */
    public function up(): void
    {
        Schema::create('notification_templates', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('type')->default('rich_text'); // 'rich_text' | 'raw_html'
            $table->string('category')->nullable();
            $table->string('subject');
            $table->longText('body');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notification_templates');
    }
};
