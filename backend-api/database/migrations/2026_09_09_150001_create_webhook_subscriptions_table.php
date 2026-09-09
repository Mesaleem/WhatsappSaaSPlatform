<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * `secret` is stored via Eloquent's `encrypted` cast on the model
     * (Crypt::encryptString/decryptString — the same transparent-at-rest
     * pattern already used for WhatsAppSession.meta_access_token and
     * PaymentGatewaySetting's key/secret columns), NOT hashed, because
     * DispatchWebhookJob needs the plaintext back to compute each
     * delivery's HMAC-SHA256 signature. A `text` column is used (not
     * `string`) because the encrypted ciphertext is longer than the
     * plaintext secret it wraps.
     */
    public function up(): void
    {
        Schema::create('webhook_subscriptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('account_id')->constrained()->cascadeOnDelete();
            $table->string('url');
            $table->text('secret');
            // e.g. ["message.sent", "message.failed", "message.delivered"]
            $table->json('events');
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['account_id', 'is_active']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('webhook_subscriptions');
    }
};
