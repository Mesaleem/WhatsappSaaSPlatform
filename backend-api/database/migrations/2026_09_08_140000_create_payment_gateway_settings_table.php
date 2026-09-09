<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * One row per gateway ('razorpay', 'stripe'), holding BOTH test and
     * live credentials at once so `mode` can be toggled without having to
     * re-enter keys — this is what "Support test/live mode toggleable via
     * UI" means in practice. Secrets (*_key_secret, *_webhook_secret) are
     * TEXT + encrypted-cast at the model layer, same pattern as
     * WhatsAppSession.meta_access_token (Module 5); *_key_id is NOT secret
     * (Razorpay Key ID / Stripe publishable key — safe to hand to the
     * frontend) and is left a plain string.
     */
    public function up(): void
    {
        Schema::create('payment_gateway_settings', function (Blueprint $table) {
            $table->id();
            $table->string('gateway')->unique(); // 'razorpay' | 'stripe'
            $table->string('mode')->default('test'); // 'test' | 'live'
            $table->boolean('is_enabled')->default(false);

            $table->string('test_key_id')->nullable();
            $table->text('test_key_secret')->nullable();
            $table->text('test_webhook_secret')->nullable();

            $table->string('live_key_id')->nullable();
            $table->text('live_key_secret')->nullable();
            $table->text('live_webhook_secret')->nullable();

            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('payment_gateway_settings');
    }
};
