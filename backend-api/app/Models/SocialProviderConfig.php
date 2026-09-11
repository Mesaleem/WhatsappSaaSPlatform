<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;

/**
 * Social Media Marketing & Meta Ads Automation Expansion — Phase 1.
 * Platform-level OAuth App credential vault, one row per provider
 * ('meta' | 'linkedin' | 'google'). Mirrors PaymentGatewaySetting's
 * cache-and-invalidate + encrypted-secret pattern exactly.
 */
class SocialProviderConfig extends Model
{
    public const PROVIDERS = ['meta', 'linkedin', 'google'];

    private static function cacheKeyFor(string $provider): string
    {
        return "social_provider_config:{$provider}";
    }

    protected static function booted(): void
    {
        static::saved(fn (self $config) => Cache::forget(self::cacheKeyFor($config->provider)));
        static::deleted(fn (self $config) => Cache::forget(self::cacheKeyFor($config->provider)));
    }

    /** Cached SocialProviderConfig::firstWhere('provider', $provider). Read on every OAuth redirect/callback. */
    public static function findByProviderCached(string $provider): ?self
    {
        return Cache::remember(
            self::cacheKeyFor($provider),
            3600,
            fn () => self::firstWhere('provider', $provider)
        );
    }

    /** All providers currently switched on by Super Admin. */
    public static function activeProviders(): Collection
    {
        return self::query()->where('is_active', true)->get();
    }

    protected $fillable = [
        'provider',
        'client_id',
        'client_secret',
        'redirect_uri',
        'webhook_verify_token',
        'is_active',
    ];

    protected $hidden = [
        'client_id',
        'client_secret',
        'webhook_verify_token',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            // Same Crypt::encryptString/decryptString transparent cast used
            // for PaymentGatewaySetting's key/secret columns.
            'client_id' => 'encrypted',
            'client_secret' => 'encrypted',
            'webhook_verify_token' => 'encrypted',
        ];
    }

    public function isFullyConfigured(): bool
    {
        return (bool) ($this->client_id && $this->client_secret && $this->redirect_uri);
    }
}
