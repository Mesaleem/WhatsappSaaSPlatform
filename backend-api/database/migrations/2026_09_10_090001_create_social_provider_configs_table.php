<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Social Media Marketing & Meta Ads Automation Expansion — Phase 1.
     *
     * Platform-level OAuth app credential vault, one row per provider —
     * same "one row per external integration, unique() on the provider
     * key" shape as payment_gateway_settings (Module 8). Read at RUNTIME
     * by SocialOAuthProviderFactory so Super Admin can rotate a Meta/
     * LinkedIn/Google App's client_id/client_secret from the admin UI
     * with zero deploy, exactly like the Razorpay/Stripe vault.
     *
     * client_id is not secret (Meta/LinkedIn/Google App IDs are public —
     * they appear in the redirect URL itself) but is still `text` +
     * encrypted here for defense-in-depth and schema symmetry with
     * client_secret; both are encrypted-cast at the model layer, not
     * database-level encryption (same Crypt::encryptString/decryptString
     * pattern as WhatsAppSession.meta_access_token / PaymentGatewaySetting).
     *
     * DISCLOSED ADDITION beyond the spec's literal 5-field list:
     * `webhook_verify_token`, generated once per provider and shown to
     * Super Admin to paste into that provider's App Dashboard webhook
     * config. This is deliberately APP-LEVEL (one token per provider),
     * not per-tenant like WhatsAppSession.meta_webhook_verify_token —
     * unlike a tenant's own WhatsApp number, a Lead Ads / Page webhook
     * subscription belongs to the single Meta App registered in
     * social_provider_configs, not to any one tenant, so a per-tenant
     * token would be the wrong ownership model here. See
     * SocialWebhookController.
     */
    public function up(): void
    {
        Schema::create('social_provider_configs', function (Blueprint $table) {
            $table->id();
            $table->string('provider')->unique(); // 'meta' | 'linkedin' | 'google'
            $table->text('client_id')->nullable();
            $table->text('client_secret')->nullable();
            $table->string('redirect_uri')->nullable();
            $table->text('webhook_verify_token')->nullable();
            $table->boolean('is_active')->default(false);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('social_provider_configs');
    }
};
