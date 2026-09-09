<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ChatbotRule extends Model
{
    public const MATCH_TYPES = ['exact', 'contains', 'starts_with', 'regex', 'fallback'];
    public const RESPONSE_TYPES = ['text', 'media', 'interactive'];

    protected $fillable = [
        'account_id',
        'name',
        'match_type',
        'keywords',
        'response_type',
        'response_payload',
        'priority',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'keywords' => 'array',
            'response_payload' => 'array',
            'priority' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    public function logs(): HasMany
    {
        return $this->hasMany(ChatbotLog::class);
    }

    public function scopeForAccount(Builder $query, int $accountId): Builder
    {
        return $query->where('account_id', $accountId);
    }

    /**
     * Active rules for an account, in match-evaluation order. LOWER
     * priority runs FIRST — see the migration's docblock. `id ASC` is a
     * stable tiebreaker for rules sharing the same priority value.
     */
    public function scopeActiveInEvaluationOrder(Builder $query, int $accountId): Builder
    {
        return $query->forAccount($accountId)
            ->where('is_active', true)
            ->orderBy('priority')
            ->orderBy('id');
    }
}
