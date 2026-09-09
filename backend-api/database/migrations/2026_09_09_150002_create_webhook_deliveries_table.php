<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * One row PER DELIVERY ATTEMPT (not one row per event) — `attempt`
     * records which try this is (1, 2, 3...) when DispatchWebhookJob's
     * queue retry fires again after a failure, so the "recent delivery
     * logs" view in the Developer Portal shows the full retry history,
     * not just the final outcome.
     */
    public function up(): void
    {
        Schema::create('webhook_deliveries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('webhook_subscription_id')->constrained()->cascadeOnDelete();
            $table->string('event');
            $table->json('payload');
            $table->unsignedSmallInteger('response_code')->nullable();
            $table->string('status'); // 'success' | 'failed'
            $table->unsignedTinyInteger('attempt')->default(1);
            $table->timestamps();

            $table->index(['webhook_subscription_id', 'created_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('webhook_deliveries');
    }
};
