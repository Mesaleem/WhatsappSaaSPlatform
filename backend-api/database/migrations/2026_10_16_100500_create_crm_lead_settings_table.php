<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * QA fixes (2026-10-14) — what happens after a lead arrives.
 *
 *   assign_mode            none | owner | round_robin — who a new unassigned
 *                          lead goes to (round robin: every active team member
 *                          with CRM access, in turn).
 *   notify_assignee        the assignee gets an in-app notification + email.
 *   whatsapp_inbound_leads a first WhatsApp message from an unknown number
 *                          creates a CRM lead (source whatsapp). Off by default.
 *   last_assigned_user_id  round-robin cursor.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('crm_lead_settings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('account_id')->unique()->constrained('accounts')->cascadeOnDelete();
            $table->string('assign_mode', 20)->default('none');
            $table->boolean('notify_assignee')->default(true);
            $table->boolean('whatsapp_inbound_leads')->default(false);
            $table->unsignedBigInteger('last_assigned_user_id')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('crm_lead_settings');
    }
};
