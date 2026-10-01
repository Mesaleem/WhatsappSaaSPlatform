<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Phase 9 Task 4 — latest provider insights snapshot for one organic post
 * (see the create_organic_post_insights_table migration). Written only by
 * PostInsightsService.
 */
class OrganicPostInsight extends Model
{
    public const STATE_NOT_FETCHED = 'not_fetched';

    public const STATE_OK = 'ok';

    public const STATE_PARTIAL = 'partial';

    public const STATE_POST_NOT_FOUND = 'post_not_found';

    public const STATE_RECONNECT_REQUIRED = 'reconnect_required';

    public const STATE_RATE_LIMITED = 'rate_limited';

    public const STATE_PROVIDER_ERROR = 'provider_error';

    /** Normalised metric columns, in display order. */
    public const METRICS = [
        'impressions', 'reach', 'reactions', 'comments', 'shares', 'saves', 'clicks', 'video_views', 'video_avg_watch_time_ms',
    ];

    protected $fillable = [
        'account_id', 'organic_post_id', 'social_account_id', 'provider', 'platform', 'provider_post_id', 'state',
        'impressions', 'reach', 'reactions', 'comments', 'shares', 'saves', 'clicks', 'video_views', 'video_avg_watch_time_ms',
        'unavailable_metrics', 'metrics_fetched_at', 'last_attempted_at', 'next_refresh_at', 'attempts',
        'claim_token', 'claimed_at', 'error_code', 'error_message', 'metadata',
    ];

    protected $hidden = ['claim_token'];

    protected function casts(): array
    {
        return [
            'unavailable_metrics' => 'array',
            'metadata' => 'array',
            'metrics_fetched_at' => 'datetime',
            'last_attempted_at' => 'datetime',
            'next_refresh_at' => 'datetime',
            'claimed_at' => 'datetime',
            'attempts' => 'integer',
        ] + array_fill_keys(self::METRICS, 'integer');
    }

    public function post(): BelongsTo
    {
        return $this->belongsTo(OrganicPost::class, 'organic_post_id');
    }

    public function scopeForAccount(Builder $query, int $accountId): Builder
    {
        return $query->where('account_id', $accountId);
    }
}
