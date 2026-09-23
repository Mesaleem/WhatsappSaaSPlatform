<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Agent Commission Payout Ledger — internal settlement batches only (no
 * gateway/bank/UPI payout integration; see AgentPayoutService's
 * docblock). One row per Super-Admin-initiated payout batch for one
 * Agent. `gross_amount` is the sum of every included
 * agent_commission_payout_items row's snapshotted amount at creation
 * time; `net_amount` mirrors it today (no deductions modeled yet) but is
 * stored separately so a future deduction feature needs no further
 * migration. Both are frozen at creation time — a later change to
 * AgentCommission/AgentCommissionRule can never alter a payout already
 * recorded here, which is the entire point of the separate
 * amount_snapshot column on agent_commission_payout_items (see that
 * migration's docblock).
 *
 * `status` starts at 'pending' and is moved forward exclusively by
 * AgentPayoutService::updateStatus() (Super-Admin-only, gated in
 * BillingController); 'paid'/'failed'/'cancelled' are all terminal —
 * once reached, the row is never transitioned again.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('agent_commission_payouts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('agent_account_id')->constrained('accounts')->cascadeOnDelete();
            $table->string('status')->default('pending');
            $table->decimal('gross_amount', 12, 2);
            $table->decimal('net_amount', 12, 2);
            $table->string('payout_reference')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->text('note')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('agent_commission_payouts');
    }
};
