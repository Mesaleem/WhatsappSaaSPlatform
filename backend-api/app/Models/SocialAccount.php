<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Social Media Marketing & Meta Ads Automation Expansion — Phase 1.
 * One tenant-bound external social/ad asset (Facebook Page, Instagram
 * Business Account, Meta Ad Account, ...). See the creating migration's
 * docblock for the asset_type disclosure and the known Page-Access-Token
 * gap.
 */
class SocialAccount extends Model
{
    public const HEALTH_CONNECTED = 'connected';
    public const HEALTH_TOKEN_EXPIRED = 'token_expired';
    public const HEALTH_REAUTH_REQUIRED = 'reauth_required';

    public const ASSET_TYPES = [
        'facebook_page',
        'instagram',
        'meta_ad_account',
        'linkedin_page',
        'youtube_channel',
    ];

    protected $fillable = [
        'account_id',
        'provider',
        'asset_type',
        'provider_id',
        'name',
        'avatar_url',
        'access_token',
        'refresh_token',
        'token_expires_at',
        'health_status',
    ];

    /**
     * Never re-exposed after creation (see SocialAuthController::present()) —
     * hidden here too as a defensive backstop, same defense-in-depth
     * reasoning as WebhookSubscription.secret.
     */
    protected $hidden = [
        'access_token',
        'refresh_token',
    ];

    protected function casts(): array
    {
        return [
            'token_expires_at' => 'datetime',
            // Same Crypt::encryptString/decryptString transparent cast as
            // WhatsAppSession.meta_access_token / webhook_subscriptions.secret.
            'access_token' => 'encrypted',
            'refresh_token' => 'encrypted',
        ];
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    public function scopeForAccount(Builder $query, int $accountId): Builder
    {
        return $query->where('account_id', $accountId);
    }

    public function scopeOfAssetType(Builder $query, string $assetType): Builder
    {
        return $query->where('asset_type', $assetType);
    }

    public function isHealthy(): bool
    {
        return $this->health_status === self::HEALTH_CONNECTED;
    }

    public function isTokenExpired(): bool
    {
        return $this->token_expires_at !== null && $this->token_expires_at->isPast();
    }
}
