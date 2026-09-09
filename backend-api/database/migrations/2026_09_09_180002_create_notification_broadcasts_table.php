<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * One row per SENT broadcast campaign (Advanced Broadcast Engine).
     * Dispatch is synchronous (matching this environment's
     * QUEUE_CONNECTION=sync and the WebhookSender precedent — no real
     * async queue infra is verifiable here), so a row is only ever
     * written once the whole send loop has finished; there is no
     * pending/queued status.
     *
     * `account_id` is not in the spec's literal field list but is added
     * (disclosed) for the same reason every other list endpoint in this
     * platform carries one: it lets AuditLogController-style scoping
     * ('account' | 'global') apply consistently to the broadcast history
     * table. It is a SNAPSHOT of the single target account when the
     * broadcast's target_type was 'account_users' targeting one tenant
     * (the normal case for an Admin sender, always forced server-side to
     * their own account); it is left null for a Super Admin's
     * 'specific_clients' (multiple accounts) or 'all_users'
     * (platform-wide) broadcasts, which have no single account to
     * attribute the row to.
     */
    public function up(): void
    {
        Schema::create('notification_broadcasts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('template_id')->nullable()->constrained('notification_templates')->nullOnDelete();
            $table->foreignId('account_id')->nullable()->constrained('accounts')->nullOnDelete();
            $table->string('subject');
            $table->longText('body');
            $table->string('category')->nullable();
            $table->json('channels'); // e.g. ["email","in_app"]
            $table->string('target_type'); // 'account_users' | 'specific_clients' | 'all_users' | 'custom_emails'
            $table->string('target_summary')->nullable(); // human-readable, e.g. "3 accounts selected"
            $table->unsignedInteger('recipient_count')->default(0);
            $table->unsignedInteger('email_sent_count')->default(0);
            $table->unsignedInteger('email_failed_count')->default(0);
            $table->foreignId('sent_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('sent_at')->nullable();
            $table->timestamps();

            $table->index(['account_id', 'sent_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notification_broadcasts');
    }
};
