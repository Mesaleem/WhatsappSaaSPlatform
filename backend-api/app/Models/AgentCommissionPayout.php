<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Internal Agent Commission settlement batch — see the creating
 * migration's docblock for the amount-snapshotting/status-lifecycle
 * rules this model exists to enforce (all mutated exclusively through
 * AgentPayoutService, never directly).
 */
class AgentCommissionPayout extends Model
{
    public const STATUSES = ['pending', 'processing', 'paid', 'failed', 'cancelled'];

    public const TERMINAL_STATUSES = ['paid', 'failed', 'cancelled'];

    protected $fillable = [
        'agent_account_id',
        'status',
        'gross_amount',
        'net_amount',
        'payout_reference',
        'paid_at',
        'note',
    ];

    protected function casts(): array
    {
        return [
            'gross_amount' => 'decimal:2',
            'net_amount' => 'decimal:2',
            'paid_at' => 'datetime',
        ];
    }

    public function agentAccount(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'agent_account_id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(AgentCommissionPayoutItem::class);
    }

    public function scopeForAgent(Builder $query, int $agentAccountId): Builder
    {
        return $query->where('agent_account_id', $agentAccountId);
    }
}
