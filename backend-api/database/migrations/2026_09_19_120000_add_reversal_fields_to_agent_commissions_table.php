<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Agent Commission Foundation — refund/reversal lifecycle. Extends the
 * existing agent_commissions ledger rather than adding a second table
 * (per this task's explicit instruction): a reversed commission is the
 * SAME row, transitioned to status='reversed', with its original
 * amount/rule snapshot left completely untouched (see
 * InvoiceCreditService::reverseAgentCommission()).
 *
 * reversed_at / reversal_reference are nullable and populated only at
 * the moment of reversal — never touched for a still-'confirmed' row.
 * reversal_reference stores whatever gateway-side identifier the refund
 * webhook itself carries (Razorpay: refund entity id; Stripe: refund/
 * charge id) purely for audit traceability back to the source event.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('agent_commissions', function (Blueprint $table) {
            $table->timestamp('reversed_at')->nullable()->after('status');
            $table->string('reversal_reference')->nullable()->after('reversed_at');
        });
    }

    public function down(): void
    {
        Schema::table('agent_commissions', function (Blueprint $table) {
            $table->dropColumn(['reversed_at', 'reversal_reference']);
        });
    }
};
