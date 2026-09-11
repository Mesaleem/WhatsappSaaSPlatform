<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Social Media Marketing & Meta Ads Automation Expansion — Phase 2.
     * Instant Lead Bridge. One row per Meta Lead Ads submission actually
     * processed (not per webhook delivery — see the unique `provider_lead_id`
     * below).
     *
     * Two DISTINCT dedup guards, both disclosed:
     *  - `provider_lead_id` unique — a TECHNICAL guard against Meta
     *    redelivering the same webhook event (Meta retries on any
     *    non-2xx or slow response); this is what makes MetaLeadWebhookHandler
     *    safe to run synchronously and idempotently without a queue.
     *  - the app-level "same lead_phone within 24h for this account_id"
     *    rule the spec asks for is a BUSINESS guard against the same
     *    person submitting the same tenant's form more than once in a
     *    day — checked in MetaLeadWebhookHandler via a query on
     *    (account_id, lead_phone, created_at), not a DB constraint,
     *    since "within the last 24 hours" is a sliding window a unique
     *    index can't express.
     *
     * `raw_field_data` keeps the full Graph API field_data payload verbatim
     * (name/value pairs beyond phone/name/email, if the tenant's form
     * collects more) — nothing is discarded even though only three fields
     * are extracted into their own columns for now.
     */
    public function up(): void
    {
        Schema::create('leads', function (Blueprint $table) {
            $table->id();
            $table->foreignId('account_id')->constrained()->cascadeOnDelete();
            $table->foreignId('social_account_id')->nullable()->constrained('social_accounts')->nullOnDelete();

            $table->string('provider')->default('meta');
            $table->string('provider_lead_id')->unique();
            $table->string('form_id')->nullable();
            $table->string('ad_id')->nullable();

            $table->string('lead_name')->nullable();
            $table->string('lead_phone')->nullable();
            $table->string('lead_email')->nullable();
            $table->json('raw_field_data')->nullable();

            // Set independently — the tenant-notify send and the
            // lead-welcome send are two separate WhatsApp API calls that
            // can each succeed or fail on their own.
            $table->timestamp('tenant_notified_at')->nullable();
            $table->timestamp('lead_welcomed_at')->nullable();
            $table->string('tenant_notify_error')->nullable();
            $table->string('lead_welcome_error')->nullable();

            $table->timestamps();

            $table->index(['account_id', 'lead_phone', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('leads');
    }
};
