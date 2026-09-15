<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use App\Traits\LogsActivity;

class PaymentGatewaySetting extends Model
{
    use LogsActivity;

    /** IMPLEMENT: Dynamic Route Master with Super-Admin Bypass & Global Audit Tracking — module label shown in the Activity Logs UI. */
    protected string $auditModuleName = 'Payment Gateway Settings';
    private const ENABLED_CACHE_KEY = 'payment_gateway_setting:enabled_gateways';

    /**
     * Runtime Query Caching (Performance & Database Optimization audit) —
     * caches only the READ paths (PaymentGatewayFactory::make()/
     * settingsFor(), PaymentGatewayController::plans()'s available-gateways
     * list), which run on every checkout/order/webhook request. Deliberately
     * NOT applied to GatewaySettingsController::index()/update()'s
     * firstOrNew() calls — that admin screen must always read/write the
     * exact current row, including a not-yet-saved blank one, uncached.
     */
    protected static function booted(): void
    {
        static::saved(function (self $settings) {
            Cache::forget(self::cacheKeyFor($settings->gateway));
            Cache::forget(self::ENABLED_CACHE_KEY);
        });
        static::deleted(function (self $settings) {
            Cache::forget(self::cacheKeyFor($settings->gateway));
            Cache::forget(self::ENABLED_CACHE_KEY);
        });
    }

    private static function cacheKeyFor(string $gateway): string
    {
        return "payment_gateway_setting:{$gateway}";
    }

    /** Cached PaymentGatewaySetting::firstWhere('gateway', $gateway). */
    public static function findByGatewayCached(string $gateway): ?self
    {
        return Cache::remember(
            self::cacheKeyFor($gateway),
            3600,
            fn () => self::firstWhere('gateway', $gateway)
        );
    }

    /** Cached PaymentGatewaySetting::query()->where('is_enabled', true)->get(). */
    public static function enabledGatewaysCached(): Collection
    {
        return Cache::remember(
            self::ENABLED_CACHE_KEY,
            3600,
            fn () => self::query()->where('is_enabled', true)->get()
        );
    }
    protected $fillable = [
        'gateway',
        'mode',
        'is_enabled',
        'test_key_id',
        'test_key_secret',
        'test_webhook_secret',
        'live_key_id',
        'live_key_secret',
        'live_webhook_secret',
    ];

    protected $hidden = [
        'test_key_secret',
        'test_webhook_secret',
        'live_key_secret',
        'live_webhook_secret',
    ];

    protected function casts(): array
    {
        return [
            'is_enabled' => 'boolean',
            // Same Crypt::encryptString/decryptString transparent cast used
            // for WhatsAppSession.meta_access_token (Module 5).
            'test_key_secret' => 'encrypted',
            'test_webhook_secret' => 'encrypted',
            'live_key_secret' => 'encrypted',
            'live_webhook_secret' => 'encrypted',
        ];
    }

    public function isLiveMode(): bool
    {
        return $this->mode === 'live';
    }

    /** The publishable/Key ID for whichever mode is currently active. Not secret. */
    public function activeKeyId(): ?string
    {
        return $this->isLiveMode() ? $this->live_key_id : $this->test_key_id;
    }

    public function activeKeySecret(): ?string
    {
        return $this->isLiveMode() ? $this->live_key_secret : $this->test_key_secret;
    }

    public function activeWebhookSecret(): ?string
    {
        return $this->isLiveMode() ? $this->live_webhook_secret : $this->test_webhook_secret;
    }

    public function isFullyConfigured(): bool
    {
        return (bool) ($this->activeKeyId() && $this->activeKeySecret());
    }
}
