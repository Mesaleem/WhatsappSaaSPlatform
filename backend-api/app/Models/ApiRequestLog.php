<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Phase 3 Task 4 -- one row per external Developer API (/v1/*) HTTP
 * request, written by LogApiRequestMiddleware regardless of outcome
 * (success, authentication failure, or authorization failure). Deliberately
 * carries no columns capable of holding a secret, a credential hash, or
 * request/message content -- see this model's own creating migration and
 * LogApiRequestMiddleware's docblock for the full rationale.
 */
class ApiRequestLog extends Model
{
    protected $fillable = [
        'request_id',
        'account_id',
        'api_key_id',
        'api_key_prefix',
        'method',
        'path',
        'status_code',
        'duration_ms',
        'ip',
        'user_agent',
    ];

    protected function casts(): array
    {
        return [
            'status_code' => 'integer',
            'duration_ms' => 'integer',
        ];
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    public function apiKey(): BelongsTo
    {
        return $this->belongsTo(ApiKey::class);
    }

    public function scopeForAccount(Builder $query, int $accountId): Builder
    {
        return $query->where('account_id', $accountId);
    }
}
