<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * CRM Sales Phases 4–5 — document delivery history and payment reminders.
 *
 * crm_document_deliveries      every attempt to send a document (WhatsApp, email) and every
 *                              manual "I sent it myself" record, with its own status: sent
 *                              means the provider accepted it, never "delivered".
 * crm_reminder_settings        one row per tenant: before / on / after due-date rules,
 *                              channels, automatic or approval, email fallback, quiet hours.
 * crm_payment_reminders        one row per invoice per rule occurrence; unique(document,
 *                              rule_key) is what makes a reminder impossible to send twice.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('crm_document_deliveries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('account_id')->constrained()->cascadeOnDelete();
            $table->foreignId('crm_document_id')->constrained('crm_documents')->cascadeOnDelete();
            $table->string('channel', 10);
            $table->string('purpose', 12)->default('document');
            $table->string('recipient', 191);
            $table->string('status', 12);
            $table->string('provider_message_id', 191)->nullable();
            $table->string('error', 500)->nullable();
            $table->foreignId('sent_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->unsignedBigInteger('crm_payment_reminder_id')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamps();

            $table->index(['account_id', 'crm_document_id']);
        });

        Schema::create('crm_reminder_settings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('account_id')->unique()->constrained()->cascadeOnDelete();
            $table->boolean('enabled')->default(false);
            $table->json('days_before')->nullable();
            $table->boolean('on_due_date')->default(true);
            $table->json('days_after')->nullable();
            $table->string('channel', 10)->default('whatsapp');
            $table->boolean('auto_send')->default(true);
            $table->boolean('email_fallback')->default(false);
            $table->unsignedTinyInteger('quiet_start_hour')->nullable();
            $table->unsignedTinyInteger('quiet_end_hour')->nullable();
            $table->unsignedTinyInteger('max_attempts')->default(3);
            $table->timestamps();
        });

        Schema::create('crm_payment_reminders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('account_id')->constrained()->cascadeOnDelete();
            $table->foreignId('crm_document_id')->constrained('crm_documents')->cascadeOnDelete();
            $table->string('rule_key', 20);
            $table->date('scheduled_for');
            $table->string('status', 20)->default('pending');
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->string('last_error', 500)->nullable();
            $table->decimal('balance_at_send', 14, 2)->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('next_attempt_at')->nullable();
            $table->timestamps();

            $table->unique(['crm_document_id', 'rule_key']);
            $table->index(['status', 'scheduled_for']);
            $table->index(['account_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('crm_payment_reminders');
        Schema::dropIfExists('crm_reminder_settings');
        Schema::dropIfExists('crm_document_deliveries');
    }
};
