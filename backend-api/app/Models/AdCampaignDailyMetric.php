<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Social Media Marketing & Meta Ads Automation Expansion — Final Phase.
 * One calendar-day performance snapshot for one AdCampaign, upserted by
 * CheckAdPerformanceRules::evaluate() — see the creating migration's
 * docblock for why this exists (AdCampaign's own last_* columns are
 * TODAY-only and overwritten every 15 minutes, so they cannot answer a
 * "this month" question).
 */
class AdCampaignDailyMetric extends Model
{
    protected $fillable = [
        'account_id',
        'ad_campaign_id',
        'metric_date',
        'spend',
        'impressions',
        'leads',
        'cpl',
    ];

    protected function casts(): array
    {
        return [
            'metric_date' => 'date',
            'spend' => 'decimal:2',
            'cpl' => 'decimal:2',
            'impressions' => 'integer',
            'leads' => 'integer',
        ];
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    public function adCampaign(): BelongsTo
    {
        return $this->belongsTo(AdCampaign::class);
    }

    public function scopeForAccount(Builder $query, int $accountId): Builder
    {
        return $query->where('account_id', $accountId);
    }

    public function scopeBetweenDates(Builder $query, string $from, string $to): Builder
    {
        return $query->whereBetween('metric_date', [$from, $to]);
    }
}
