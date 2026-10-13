<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * CRM Sales Phase 1 — activities: calls, meetings, tasks, emails, WhatsApp
 * follow-ups and notes, plus `event` rows the system writes (a lead converted, a deal
 * won). One record can link to a lead, a contact, a company and/or a deal, so the same
 * history shows on each of them (a timeline).
 *
 * The four links carry no foreign key on purpose (see the deals migration): deleting
 * a lead, contact, company or deal is an existing or planned action that must keep
 * working; the models null the link on delete and CrmActivity's saving guard checks
 * every link belongs to the same tenant.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('crm_activities', function (Blueprint $table) {
            $table->id();
            $table->foreignId('account_id')->constrained()->cascadeOnDelete();
            $table->string('type', 20);
            $table->string('subject', 191);
            $table->text('body')->nullable();
            $table->unsignedBigInteger('crm_lead_id')->nullable();
            $table->unsignedBigInteger('contact_id')->nullable();
            $table->unsignedBigInteger('crm_company_id')->nullable();
            $table->unsignedBigInteger('crm_deal_id')->nullable();
            $table->timestamp('due_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->string('status', 12)->default('pending');
            $table->string('priority', 10)->default('normal');
            $table->string('outcome', 255)->nullable();
            $table->foreignId('assigned_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['account_id', 'status', 'due_at']);
            $table->index(['account_id', 'assigned_user_id', 'status']);
            $table->index(['account_id', 'crm_lead_id']);
            $table->index(['account_id', 'contact_id']);
            $table->index(['account_id', 'crm_company_id']);
            $table->index(['account_id', 'crm_deal_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('crm_activities');
    }
};
