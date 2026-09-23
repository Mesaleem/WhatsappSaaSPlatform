<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 1 Foundation — Agent Selling Entitlements: which Capabilities a
 * Reseller ("Agent") account is permitted to SELL/delegate to its own
 * Clients, kept as its own dimension (a separate table, not a flag on
 * account_entitlements) per the user's explicit "3 separate dimensions"
 * Agent model — distinct from account_entitlements (what the Agent's
 * OWN account currently holds/uses, source='agent_delegated' on the
 * Client's row) and from Spatie's ordinary Agent role permissions
 * (customer.create/view/manage, etc.). Locked decision — see the Phase
 * 1 plan, Step 5/J.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('agent_selling_entitlements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('agent_account_id')->constrained('accounts')->cascadeOnDelete();
            $table->foreignId('capability_id')->constrained('capabilities')->cascadeOnDelete();
            $table->boolean('sellable')->default(true);
            $table->timestamps();

            $table->unique(['agent_account_id', 'capability_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('agent_selling_entitlements');
    }
};
