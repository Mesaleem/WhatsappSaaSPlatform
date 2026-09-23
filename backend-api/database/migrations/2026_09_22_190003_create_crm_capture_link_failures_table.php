<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Phase 6 — CRM Hardening Round 2, Limitation 5. Makes a failed CRM
     * promotion observable instead of leaving it as a line in the log.
     *
     * WHY A TABLE AND NOT A REUSED MECHANISM: inspected first. This
     * codebase has three failure stores and none of them fits —
     * activity_logs records an authenticated user's CRUD (and
     * LogsActivity returns early when there is no Auth user, which is
     * exactly the webhook case); api_request_logs records inbound
     * Developer API calls; webhook_deliveries records OUTBOUND webhook
     * attempts this platform makes. A capture-promotion failure is none
     * of those. It is also deliberately NOT a queued job with retries:
     * the brief forbids introducing automation/scheduling here.
     *
     * WHAT IT MUST NOT CONTAIN: the capture payload, the provider access
     * token, or anything else that could turn an operational table into
     * a second copy of customer PII or a credential leak. It stores the
     * IDENTIFIERS needed to find the original record and the reason it
     * failed — the capture row itself is untouched and still holds
     * everything, so a retry re-reads from there rather than from here.
     *
     * COLUMNS:
     *  - account_id: which tenant is affected; cascades with the account.
     *  - lead_id: the capture row to retry, nullable + nullOnDelete so
     *    the failure record survives if that row later disappears.
     *  - provider / provider_lead_id: identify the submission even after
     *    lead_id is gone, and say which integration produced it.
     *  - reason: a short category (see CrmCaptureLinkFailure::REASONS) so
     *    failures can be counted by kind without parsing prose.
     *  - message: the exception text, truncated. No payload, no token.
     *  - resolved_at / resolved_lead_id: set when a retry succeeds, so
     *    the table is a work queue an operator can drain rather than an
     *    ever-growing log.
     *  - attempts: how many times promotion has been tried.
     *
     * unique(lead_id) keeps this idempotent: repeated failures for the
     * same capture update one row and bump attempts rather than piling
     * up. Partial (NULL lead_id) rows are exempt, which is what MySQL's
     * treatment of NULLs in a unique index already gives.
     */
    public function up(): void
    {
        Schema::create('crm_capture_link_failures', function (Blueprint $table) {
            $table->id();
            $table->foreignId('account_id')->constrained()->cascadeOnDelete();
            $table->foreignId('lead_id')->nullable()->constrained('leads')->nullOnDelete();

            $table->string('provider')->nullable();
            $table->string('provider_lead_id')->nullable();

            $table->string('reason', 64);
            $table->string('message', 1000)->nullable();

            $table->unsignedInteger('attempts')->default(1);
            $table->timestamp('resolved_at')->nullable();
            $table->foreignId('resolved_crm_lead_id')->nullable()->constrained('crm_leads')->nullOnDelete();

            $table->timestamps();

            $table->unique('lead_id', 'crm_capture_link_failures_lead_id_unique');
            // The operator's query: "what is still broken for this
            // tenant", newest first.
            $table->index(['account_id', 'resolved_at', 'created_at'], 'crm_capture_link_failures_open_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('crm_capture_link_failures');
    }
};
