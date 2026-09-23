<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Agent Commission Foundation — the DB-driven rule an Agent's commission
 * is computed from (never a hardcoded rate anywhere in code). One row
 * per Agent account (unique agent_account_id), mutable by Super Admin —
 * updating it going forward never touches AgentCommission rows already
 * written, since those store their own snapshot of type/value at the
 * time they were generated (see create_agent_commissions_table.php).
 *
 * Mirrors agent_selling_entitlements' shape/placement: its own dimension,
 * not a column bolted onto accounts, kept minimal per this task's
 * "minimum production-ready foundation" scope.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('agent_commission_rules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('agent_account_id')->unique()->constrained('accounts')->cascadeOnDelete();
            $table->enum('type', ['percentage', 'fixed']);
            $table->decimal('value', 10, 2);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('agent_commission_rules');
    }
};
