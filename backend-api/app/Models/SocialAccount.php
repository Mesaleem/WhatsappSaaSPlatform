<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use App\Traits\LogsActivity;

/**
 * Social Media Marketing & Meta Ads Automation Expansion — Phase 1.
 * One tenant-bound external social/ad asset (Facebook Page, Instagram
 * Business Account, Meta Ad Account, ...). See the creating migration's
 * docblock for the asset_type disclosure and the known Page-Access-Token
 * gap.
 */
class SocialAccount extends Model
{
    use LogsActivity;

    /** IMPLEMENT: Dynamic Route Master with Super-Admin Bypass & Global Audit Tracking — module label shown in the Activity Logs UI. */
    protected string $auditModuleName = 'Social Accounts';
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
        // Phase 9 Task 1 — connection lifecycle (see the 2026_09_29_130000 migration).
        'status_reason',
        'status_checked_at',
        // Phase 9 Task 2 — last health-check claim/attempt (see the 2026_09_30_100000 migration).
        'health_check_attempted_at',
        'connected_by_user_id',
        'metadata',
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
            'status_checked_at' => 'datetime',
            'health_check_attempted_at' => 'datetime',
            'metadata' => 'array',
            // Same Crypt::encryptString/decryptString transparent cast as
            // WhatsAppSession.meta_access_token / webhook_subscriptions.secret.
            'access_token' => \App\Casts\EncryptedOrNull::class,
            'refresh_token' => \App\Casts\EncryptedOrNull::class,
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

    /**
     * Phase 9 Task 1 — the lifecycle status the API reports:
     * `revoked` (the provider no longer accepts the grant), `expired`
     * (stored as expired, or its token_expires_at has passed) or
     * `connected`. See App\Services\SocialAuth\SocialConnectionStatus.
     */
    public function connectionStatus(): string
    {
        return match (true) {
            $this->health_status === self::HEALTH_REAUTH_REQUIRED => \App\Services\SocialAuth\SocialConnectionStatus::REVOKED,
            $this->health_status === self::HEALTH_TOKEN_EXPIRED, $this->isTokenExpired() => \App\Services\SocialAuth\SocialConnectionStatus::EXPIRED,
            default => \App\Services\SocialAuth\SocialConnectionStatus::CONNECTED,
        };
    }
}
