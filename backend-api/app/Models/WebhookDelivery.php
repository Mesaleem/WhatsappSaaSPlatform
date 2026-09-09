<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WebhookDelivery extends Model
{
    protected $fillable = [
        'webhook_subscription_id',
        'event',
        'payload',
        'response_code',
        'status',
        'attempt',
    ];

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'response_code' => 'integer',
            'attempt' => 'integer',
        ];
    }

    public function webhookSubscription(): BelongsTo
    {
        return $this->belongsTo(WebhookSubscription::class);
    }

    public function scopeForSubscription(Builder $query, int $webhookSubscriptionId): Builder
    {
        return $query->where('webhook_subscription_id', $webhookSubscriptionId);
    }
}
