<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Phase 8 Task 12 (continued) — platform-level, DB-editable AI
     * provider configuration. One row per provider ('openai' |
     * 'anthropic' | 'gemini' | 'groq', matching AiManager's registered
     * factory keys), consulted by AiManager::resolve() AHEAD of
     * config('ai.*') (which stays the fallback, unchanged, for every
     * field a row leaves null/unset).
     *
     * WHY: the ConnexxaIQ incident this task exists to avoid — a
     * provider retires/renames a hosted model and every request starts
     * failing with a vendor `model_not_found` error — currently needs an
     * env change + redeploy to fix (config/ai.php reads AI_*_MODEL from
     * env only). This table lets a Super Admin flip `model` (or disable
     * the provider, or rotate its key) from the admin UI, picked up on
     * the next request with no deploy. See AiProviderSettingsTest.
     *
     * NOT stored here: `allowed_models` is a free-text JSON catalog
     * Super Admin maintains for their own reference / a future UI model
     * picker — it is NOT enforced anywhere yet (the existing, separate
     * `ai.journey.allowed_models` env allowlist, read by
     * JourneyAiNodeRunner, is untouched and continues to gate what a
     * Journey node's model-hint override may request).
     *
     * is_enabled is nullable on purpose: null means "no platform
     * override — fall back to AI_ENABLED_PROVIDERS", so creating a row
     * only to set e.g. `model` does not accidentally force the provider
     * on or off. true/false is an explicit Super Admin override either
     * way (a kill switch, or turning on a provider without an env edit).
     *
     * api_key is encrypted at the model layer (EncryptedOrNull, same
     * cast as SocialProviderConfig/PaymentGatewaySetting) — an OPTIONAL
     * override of the env credential; the per-tenant
     * accounts.gemini_api_key → platform social_provider_configs
     * 'gemini' → env chain the Ad Copywriter already uses for Gemini
     * specifically is untouched by this table (different, older, Ad-
     * Copywriter-only vault — see CopywriterService's docblock).
     *
     * tokens_per_credit_override is folded into the existing, provider-
     * neutral config('ai.credits.tokens_per_credit_overrides') map by
     * AiManager at resolve time, keyed by provider name — billing code
     * (MeteredAiService/AiCreditPricing) never learns this table exists,
     * preserving the provider-neutrality GroqProviderTest and
     * GeminiProviderTest both assert on.
     */
    public function up(): void
    {
        Schema::create('ai_provider_settings', function (Blueprint $table) {
            $table->id();
            $table->string('provider')->unique();
            $table->boolean('is_enabled')->nullable();
            $table->string('model')->nullable();
            $table->json('allowed_models')->nullable();
            $table->text('api_key')->nullable();
            $table->string('base_url')->nullable();
            $table->unsignedInteger('tokens_per_credit_override')->nullable();
            $table->text('notes')->nullable();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_provider_settings');
    }
};
