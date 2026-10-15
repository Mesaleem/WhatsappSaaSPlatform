<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Plan restructure (2026-10-15), step 2 — one row per counted use of a
     * per-period plan limit. Today: `broadcast` (a bulk send started: CSV
     * batch, bulk numbers, bulk template send, contact-group send, CSV
     * payment alerts), counted against subscriptions.max_broadcasts_per_month
     * inside the current 30-day slice of the plan term (App\Services\Billing\PlanLimits).
     * Append-only usage evidence; never edited.
     */
    public function up(): void
    {
        Schema::create('plan_usage_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('account_id')->constrained('accounts')->cascadeOnDelete();
            $table->string('kind', 32);
            $table->string('source', 64);
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['account_id', 'kind', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('plan_usage_events');
    }
};
