<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 10 Task 1 — Ads & Attribution foundation.
 *
 * One row per ad-click referral received on a tenant's WhatsApp number
 * (today: Meta click-to-WhatsApp, `referral` on the inbound message), and
 * the steps it reached afterwards:
 *
 *   referral received  → referral_received_at, source_type/source_id (ad or post id),
 *                        click_id (ctwa_clid), referral_message_id (provider message id),
 *                        whatsapp_session_id + contact_phone (the conversation)
 *   ad metadata        → ad_campaign_id, only when the ad id matches one of the SAME
 *                        account's launcher campaigns (campaign / ad set ids are read
 *                        from ad_campaigns, not copied here)
 *   CRM lead           → capture_lead_id (existing `leads` CTWA capture), crm_lead_id, lead_linked_at
 *   journey            → flow_session_id, journey_started_at
 *   conversion         → converted_at (the attributed CRM lead is in status `converted`);
 *                        conversion_value / conversion_currency stay NULL until a
 *                        conversion value is actually observable
 *
 * Tenant scope: account_id is always the tenant that OWNS the receiving
 * WhatsApp number (resolved from the webhook's phone_number_id, unique per
 * session) — never inferred from the external ad id. The idempotency key
 * is per tenant: unique(account_id, provider, referral_message_id), so a
 * provider id seen by two tenants can never collide or cross over.
 *
 * New table only (additive, reversible); existing tables are untouched.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ad_attributions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('account_id')->constrained('accounts')->cascadeOnDelete();
            $table->string('provider', 32);
            $table->string('channel', 32);
            $table->string('source_type', 32)->nullable();
            $table->string('source_id', 191)->nullable();
            $table->string('click_id', 191)->nullable();
            $table->string('referral_message_id', 191);
            $table->foreignId('whatsapp_session_id')->nullable()->constrained('whatsapp_sessions')->nullOnDelete();
            $table->string('contact_phone', 32)->nullable();

            $table->foreignId('ad_campaign_id')->nullable()->constrained('ad_campaigns')->nullOnDelete();
            $table->foreignId('capture_lead_id')->nullable()->constrained('leads')->nullOnDelete();
            $table->foreignId('crm_lead_id')->nullable()->constrained('crm_leads')->nullOnDelete();
            $table->foreignId('flow_session_id')->nullable()->constrained('whatsapp_flow_sessions')->nullOnDelete();

            $table->timestamp('referral_received_at');
            $table->timestamp('lead_linked_at')->nullable();
            $table->timestamp('journey_started_at')->nullable();
            $table->timestamp('converted_at')->nullable();
            $table->decimal('conversion_value', 12, 2)->nullable();
            $table->string('conversion_currency', 3)->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->unique(['account_id', 'provider', 'referral_message_id'], 'ad_attr_account_provider_message_unique');
            $table->index(['account_id', 'referral_received_at'], 'ad_attr_account_received_index');
            $table->index(['account_id', 'source_id'], 'ad_attr_account_source_index');
            $table->index(['account_id', 'contact_phone', 'referral_received_at'], 'ad_attr_account_phone_received_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ad_attributions');
    }
};
