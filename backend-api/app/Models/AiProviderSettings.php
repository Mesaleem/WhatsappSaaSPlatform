<?php

namespace App\Models;

use App\Traits\LogsActivity;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;

/**
 * Phase 8 Task 12 (continued) — platform-level, DB-editable AI provider
 * configuration, one row per provider. Mirrors SocialProviderConfig's
 * cache-and-invalidate + encrypted-secret pattern exactly (see that
 * model's docblock); read by AiManager::resolve() ahead of
 * config('ai.*'), which stays the fallback for every field a row leaves
 * null. See the create_ai_provider_settings_table migration for the full
 * design rationale (why is_enabled is nullable, what api_key does and
 * does not override, how tokens_per_credit_override reaches billing).
 */
class AiProviderSettings extends Model
{
    use LogsActivity;

    /** Module label shown in the Activity Logs UI. */
    protected string $auditModuleName = 'AI Provider Settings';

    /**
     * Keep in sync with AiManager's registered factory keys
     * (constructor + any extend() call) — a provider missing here is
     * simply never DB-overridable, same caveat as
     * SocialProviderConfig::PROVIDERS.
     */
    public const PROVIDERS = ['openai', 'anthropic', 'gemini', 'groq'];

    private static function cacheKeyFor(string $provider): string
    {
        return "ai_provider_settings:{$provider}";
    }

    protected static function booted(): void
    {
        static::saved(fn (self $settings) => Cache::forget(self::cacheKeyFor($settings->provider)));
        static::deleted(fn (self $settings) => Cache::forget(self::cacheKeyFor($settings->provider)));
    }

    /** Cached AiProviderSettings::firstWhere('provider', $provider). Read on every AiManager::resolve(). */
    public static function findByProviderCached(string $provider): ?self
    {
        return Cache::remember(
            self::cacheKeyFor($provider),
            3600,
            fn () => self::firstWhere('provider', $provider)
        );
    }

    protected $fillable = [
        'provider',
        'is_enabled',
        'model',
        'allowed_models',
        'api_key',
        'base_url',
        'tokens_per_credit_override',
        'notes',
        'updated_by',
    ];

    protected $hidden = [
        'api_key',
    ];

    protected function casts(): array
    {
        return [
            'is_enabled' => 'boolean',
            'allowed_models' => 'array',
            'tokens_per_credit_override' => 'integer',
            // Same Crypt::encryptString/decryptString transparent cast
            // used for SocialProviderConfig's client_id/client_secret.
            'api_key' => \App\Casts\EncryptedOrNull::class,
        ];
    }

    /**
     * The config overrides this row contributes to AiManager::resolve(),
     * i.e. everything except is_enabled/allowed_models/notes/updated_by
     * (handled separately) — only non-null fields, so an unset column
     * never clobbers the env-config default.
     *
     * @return array<string, mixed>
     */
    public function configOverrides(): array
    {
        return array_filter([
            'model' => $this->model,
            'api_key' => $this->api_key,
            'base_url' => $this->base_url,
        ], fn ($value) => $value !== null && $value !== '');
    }
}
