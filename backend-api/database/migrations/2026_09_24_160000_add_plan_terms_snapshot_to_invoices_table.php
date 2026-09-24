<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Phase 5 fix P5-4 — the purchased plan terms, captured on the invoice
     * when the order is created.
     *
     * InvoiceCreditService::markPaidAndCreditQuota() used to read the
     * engine, billing model, rate, quota and duration from the LIVE `plans`
     * row at payment time, so an admin edit between checkout and payment
     * changed what an already-placed order delivered. The invoice already
     * snapshotted the price (amount/tax_amount/total_amount) and the label;
     * these columns snapshot the remaining terms fulfilment consumes, and
     * fulfilment now reads them instead of the plan.
     *
     *   plan_engine_type              'qr' | 'meta'         (plans.engine_type)
     *   plan_billing_model            'flat_quota' | 'per_message' | 'unlimited'
     *   plan_rate_per_message         same type as plans.rate_per_message
     *   plan_total_allocated_messages same type as plans.total_allocated_messages
     *                                 (NULL for an unlimited plan)
     *   plan_duration_days            same type as plans.duration_days
     *   plan_terms_captured_at        set iff the terms above were captured —
     *                                 the marker fulfilment branches on
     *
     * Historical invoices keep NULL everywhere: nothing is invented for
     * them. An invoice without captured terms is fulfilled exactly as
     * before (from the plan row), which only matters for an order placed
     * before this migration and paid after it; a paid invoice is never
     * fulfilled twice. Top-up invoices (plan_key 'quota_topup') are not
     * plan purchases and never carry terms.
     *
     * Purely ADDITIVE: six nullable columns, no index (never queried by).
     */
    public function up(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->string('plan_engine_type', 16)->nullable()->after('plan_label');
            $table->string('plan_billing_model', 32)->nullable()->after('plan_engine_type');
            $table->decimal('plan_rate_per_message', 8, 4)->nullable()->after('plan_billing_model');
            $table->unsignedInteger('plan_total_allocated_messages')->nullable()->after('plan_rate_per_message');
            $table->unsignedInteger('plan_duration_days')->nullable()->after('plan_total_allocated_messages');
            $table->timestamp('plan_terms_captured_at')->nullable()->after('plan_duration_days');
        });
    }

    public function down(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->dropColumn([
                'plan_engine_type',
                'plan_billing_model',
                'plan_rate_per_message',
                'plan_total_allocated_messages',
                'plan_duration_days',
                'plan_terms_captured_at',
            ]);
        });
    }
};
