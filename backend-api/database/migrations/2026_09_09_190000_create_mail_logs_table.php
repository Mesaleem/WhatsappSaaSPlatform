<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Mail Log — one row per individual send ATTEMPT (not per campaign;
     * see notification_broadcasts for the campaign-level summary this
     * complements). Written from NotificationBroadcastController::store()'s
     * per-recipient email loop, one row whether that attempt succeeded or
     * failed, so a Super Admin/Admin can see exactly which addresses
     * bounced and why — the loop previously only incremented an
     * aggregate counter and discarded the actual exception.
     *
     * `broadcast_id` is nullable rather than required so this table can
     * carry other kinds of outbound mail later (e.g. a future
     * transactional email) without a schema change; every row today is
     * written from a broadcast send. `account_id` is a SNAPSHOT (same
     * convention as notification_broadcasts.account_id / login_audit_logs)
     * of the broadcast's account_id at send time, null for a Super
     * Admin's platform-wide/multi-client broadcasts.
     */
    public function up(): void
    {
        Schema::create('mail_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('broadcast_id')->nullable()->constrained('notification_broadcasts')->nullOnDelete();
            $table->foreignId('account_id')->nullable()->constrained('accounts')->nullOnDelete();
            $table->string('recipient_email');
            $table->string('recipient_name')->nullable();
            $table->string('subject');
            $table->enum('status', ['sent', 'failed']);
            $table->text('error_message')->nullable();
            $table->timestamp('sent_at');
            $table->timestamps();

            $table->index(['account_id', 'sent_at']);
            $table->index(['broadcast_id']);
            $table->index(['status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mail_logs');
    }
};
