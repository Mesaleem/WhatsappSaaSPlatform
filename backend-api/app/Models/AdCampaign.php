<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use App\Traits\LogsActivity;
use LogicException;

/**
 * Social Media Marketing & Meta Ads Automation Expansion — Phase 3.
 * One Meta Ads campaign launched through MetaAdsService::launch(), plus
 * the cached performance figures CheckAdPerformanceRules last polled.
 */
class AdCampaign extends Model
{
    use LogsActivity;

    /** IMPLEMENT: Dynamic Route Master with Super-Admin Bypass & Global Audit Tracking — module label shown in the Activity Logs UI. */
    protected string $auditModuleName = 'Meta Ads Launcher';
    /**
     * Social/Ads Launcher Overhaul — Step 4 (Click-to-WhatsApp Ads).
     * CLICK_TO_WHATSAPP added alongside the original three — see
     * MetaAdsService::OBJECTIVE_MAP/OPTIMIZATION_GOAL_MAP and
     * createAdSet()'s destination_type/promoted_object branch for the
     * Meta-side mapping, and that class's docblock for the disclosed
     * [Hypothesis] status of the exact promoted_object shape.
     */
    public const OBJECTIVES = ['LEAD_GENERATION', 'MESSAGES', 'TRAFFIC', 'CLICK_TO_WHATSAPP'];

    public const STATUS_ACTIVE = 'ACTIVE';
    public const STATUS_PAUSED = 'PAUSED';

    /*
     * Phase 10 Task 2 — lifecycle states around the provider calls.
     *   LAUNCHING   — local row recorded, Meta calls in progress.
     *   FAILED      — Meta rejected a launch step (any Meta objects already
     *                 created are kept on the row for manual review).
     *   UNCONFIRMED — the launch outcome is unknown (no/5xx response); it is
     *                 never resent automatically — check Meta Ads Manager.
     *   UNAVAILABLE — Meta reports the campaign no longer exists / cannot be
     *                 loaded; no further status calls are made for it.
     */
    public const STATUS_LAUNCHING = 'LAUNCHING';
    public const STATUS_FAILED = 'FAILED';
    public const STATUS_UNCONFIRMED = 'UNCONFIRMED';
    public const STATUS_UNAVAILABLE = 'UNAVAILABLE';

    public const STATUSES = [
        self::STATUS_LAUNCHING, self::STATUS_ACTIVE, self::STATUS_PAUSED,
        self::STATUS_FAILED, self::STATUS_UNCONFIRMED, self::STATUS_UNAVAILABLE,
    ];

    protected $fillable = [
        'account_id',
        'social_account_id',
        'meta_campaign_id',
        'meta_adset_id',
        'meta_ad_id',
        'launch_request_id',
        'name',
        'objective',
        'status',
        'daily_budget',
        'currency',
        'cpl_threshold',
        'last_spend',
        'last_impressions',
        'last_leads',
        'last_cpl',
        'last_checked_at',
        'auto_paused_at',
        'auto_pause_reason',
        'last_provider_error',
        'last_provider_error_at',
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
            'last_provider_error_at' => 'datetime',
        ];
    }

    /**
     * Phase 10 Task 2 — the Ad Account a campaign runs on must belong to the
     * campaign's own tenant (defence in depth: every write path already
     * resolves it through SocialAccount::forAccount()).
     */
    protected static function booted(): void
    {
        static::saving(function (AdCampaign $campaign) {
            if ($campaign->social_account_id === null || ! $campaign->isDirty(['social_account_id', 'account_id'])) {
                return;
            }

            $owner = SocialAccount::query()->whereKey($campaign->social_account_id)->value('account_id');

            if ($owner !== null && (int) $owner !== (int) $campaign->account_id) {
                throw new LogicException('An ad campaign cannot use another account\'s Ad Account.');
            }
        });
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
