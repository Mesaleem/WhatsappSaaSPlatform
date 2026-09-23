<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 1 Foundation — generalized, provider-agnostic Usage/Quota
 * tracking, additive alongside (not replacing) subscriptions'
 * total_allocated_messages/used_messages, which stay untouched in
 * Phase 1. capability_id is nullable: NULL means "generic message
 * quota" (the same concept subscriptions already tracks); a real value
 * lets a future phase track e.g. AI credits separately without a schema
 * change. No row is written into this table by Phase 1 itself — it
 * exists for new capabilities only, nothing live is migrated into it.
 * See the Phase 1 plan, Step 5.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('usage_quotas', function (Blueprint $table) {
            $table->id();
            $table->foreignId('account_id')->constrained('accounts')->cascadeOnDelete();
            $table->foreignId('capability_id')->nullable()->constrained('capabilities')->nullOnDelete();
            $table->unsignedInteger('allocated')->nullable();
            $table->unsignedInteger('used')->default(0);
            $table->timestamp('period_starts_at')->nullable();
            $table->timestamp('period_ends_at')->nullable();
            $table->timestamps();

            $table->index(['account_id', 'capability_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('usage_quotas');
    }
};
