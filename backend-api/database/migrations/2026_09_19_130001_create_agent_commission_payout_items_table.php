<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Line items of an Agent Commission Payout batch — one row per
 * AgentCommission actually settled by that payout. `amount_snapshot` is
 * a frozen COPY of the commission's `amount` at the moment it was
 * included (mirrors AgentCommission's own rule_type/rule_value snapshot
 * pattern): this is what makes a completed payout immune to any future
 * change to the source commission row or its rule.
 *
 * unique(agent_commission_payout_id, agent_commission_id) is the
 * DB-level idempotency guarantee that the SAME commission can never
 * appear twice within the SAME payout batch. Preventing a commission
 * from being claimed by a SECOND, separate payout batch while an
 * earlier one is still pending/processing/paid is an application-level
 * check (AgentPayoutService::lockEligibleCommissions(), inside a locked
 * transaction) rather than a DB constraint — a commission's eligibility
 * depends on the STATUS of whichever other payout(s) it already belongs
 * to (a cancelled/failed payout releases it back), which a plain unique
 * index cannot express, and this project's "do not add speculative
 * indexes" instruction rules out a conditional/partial index here.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('agent_commission_payout_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('agent_commission_payout_id')->constrained()->cascadeOnDelete();
            $table->foreignId('agent_commission_id')->constrained('agent_commissions')->cascadeOnDelete();
            $table->decimal('amount_snapshot', 12, 2);
            $table->timestamps();

            $table->unique(['agent_commission_payout_id', 'agent_commission_id'], 'payout_item_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('agent_commission_payout_items');
    }
};
