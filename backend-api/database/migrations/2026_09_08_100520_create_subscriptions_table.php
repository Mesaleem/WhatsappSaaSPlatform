<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('subscriptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('account_id')->constrained()->cascadeOnDelete();

            // 'qr' (unofficial Baileys) | 'meta' (official Cloud API)
            $table->string('engine_type')->default('qr');

            // 'flat_quota' | 'per_message' | 'unlimited'
            $table->string('billing_model')->default('flat_quota');

            // Only meaningful for billing_model = 'per_message'. 8,4 gives
            // sub-paisa precision (e.g. 0.2000 INR/msg) without float error.
            $table->decimal('rate_per_message', 8, 4)->nullable();

            // NULL for 'unlimited'; required for 'flat_quota' / 'per_message'.
            $table->unsignedInteger('total_allocated_messages')->nullable();
            $table->unsignedInteger('used_messages')->default(0);

            $table->decimal('price_paid', 10, 2)->default(0);
            // 'cash' | 'razorpay'
            $table->string('payment_mode')->default('cash');

            $table->timestamp('starts_at');
            $table->timestamp('expires_at');

            // 'active' | 'expired' | 'exhausted' — kept in sync by
            // Subscription::refreshStatus() rather than a scheduled job.
            $table->string('status')->default('active');

            $table->timestamps();

            $table->index(['account_id', 'starts_at']);
            $table->index('status');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('subscriptions');
    }
};
