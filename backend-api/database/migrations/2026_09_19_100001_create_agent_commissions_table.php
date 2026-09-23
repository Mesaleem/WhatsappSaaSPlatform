<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Agent Commission Foundation — the auditable commission ledger. One row
 * per successfully-paid Invoice that belonged to an Agent-owned customer
 * (a direct customer with no agent_id never produces a row here at all —
 * see InvoiceCreditService::grantAgentCommission()).
 *
 * `invoice_id` is UNIQUE: the single DB-level idempotency guarantee that
 * a duplicated payment callback/webhook can never create a second
 * commission row for the same transaction, on top of (not instead of)
 * InvoiceCreditService's own existing Invoice::isPaid() idempotency
 * check — the same defense-in-depth relationship account_entitlements'
 * unique(account_id, capability_id) already has with its own
 * firstOrCreate() call.
 *
 * `rule_type`/`rule_value` are a SNAPSHOT of the AgentCommissionRule in
 * effect at generation time, copied onto this row rather than resolved
 * via a live join — this is what makes a later change to the Agent's
 * rule leave every historical commission row untouched, per this task's
 * explicit requirement. `agent_commission_rule_id` is kept alongside the
 * snapshot purely for traceability/audit back to the rule row itself
 * (nulled, never cascade-deleted, if that rule row is later removed).
 *
 * `status` starts at 'confirmed' (this row is only ever written after
 * InvoiceCreditService has already confirmed the payment — a failed or
 * pending invoice never reaches this code path, so there is no
 * meaningful 'pending' commission state to model). 'reversed' exists so
 * a future refund/chargeback-handling addition has an explicit state to
 * transition into instead of deleting or silently leaving a stale
 * 'confirmed' row — the existing billing architecture has no
 * refund/reversal flow of its own yet to hook this into (disclosed in
 * this task's final report), so no such automatic transition is wired
 * here; the column exists so one can be added without a further
 * migration.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('agent_commissions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('agent_account_id')->constrained('accounts')->cascadeOnDelete();
            $table->foreignId('customer_account_id')->constrained('accounts')->cascadeOnDelete();
            $table->foreignId('invoice_id')->unique()->constrained('invoices')->cascadeOnDelete();
            $table->foreignId('agent_commission_rule_id')->nullable()->constrained('agent_commission_rules')->nullOnDelete();
            $table->enum('rule_type', ['percentage', 'fixed']);
            $table->decimal('rule_value', 10, 2);
            $table->decimal('base_amount', 12, 2);
            $table->decimal('amount', 12, 2);
            $table->string('status')->default('confirmed');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('agent_commissions');
    }
};
