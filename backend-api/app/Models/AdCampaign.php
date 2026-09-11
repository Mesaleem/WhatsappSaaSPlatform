<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Social Media Marketing & Meta Ads Automation Expansion — Phase 3.
 * One Meta Ads campaign launched through MetaAdsService::launch(), plus
 * the cached performance figures CheckAdPerformanceRules last polled.
 */
class AdCampaign extends Model
{
    public const OBJECTIVES = ['LEAD_GENERATION', 'MESSAGES', 'TRAFFIC'];

    public const STATUS_ACTIVE = 'ACTIVE';
    public const STATUS_PAUSED = 'PAUSED';

    protected $fillable = [
        'account_id',
        'social_account_id',
        'meta_campaign_id',
        'meta_adset_id',
        'meta_ad_id',
        'name',
        'objective',
        'status',
        'daily_budget',
        'cpl_threshold',
        'last_spend',
        'last_impressions',
        'last_leads',
        'last_cpl',
        'last_checked_at',
        'auto_paused_at',
        'auto_pause_reason',
    ];

    protected function casts(): array
    {
        return [
            'daily_budget' => 'decimal:2',
            'cpl_threshold' => 'decimal:2',
            'last_spend' => 'decimal:2',
            'last_cpl' => 'decimal:2',
            'last_impressions' => 'integer',
            'last_leads' => 'integer',
            'last_checked_at' => 'datetime',
            'auto_paused_at' => 'datetime',
        ];
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    public function socialAccount(): BelongsTo
    {
        return $this->belongsTo(SocialAccount::class);
    }

    public function scopeForAccount(Builder $query, int $accountId): Builder
    {
        return $query->where('account_id', $accountId);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_ACTIVE);
    }

    public function isActive(): bool
    {
        return $this->status === self::STATUS_ACTIVE;
    }
}
