<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Auditable commission ledger row — one per successfully-paid Invoice
 * belonging to an Agent-owned customer. rule_type/rule_value are a
 * point-in-time snapshot (see the migration's docblock): this row's
 * `amount` never changes when AgentCommissionRule is edited later.
 */
class AgentCommission extends Model
{
    public const STATUSES = ['confirmed', 'reversed'];

    protected $fillable = [
        'agent_account_id',
        'customer_account_id',
        'invoice_id',
        'agent_commission_rule_id',
        'rule_type',
        'rule_value',
        'base_amount',
        'amount',
        'status',
        'reversed_at',
        'reversal_reference',
    ];

    protected function casts(): array
    {
        return [
            'rule_value' => 'decimal:2',
            'base_amount' => 'decimal:2',
            'amount' => 'decimal:2',
            'reversed_at' => 'datetime',
        ];
    }

    public function agentAccount(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'agent_account_id');
    }

    public function customerAccount(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'customer_account_id');
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    public function commissionRule(): BelongsTo
    {
        return $this->belongsTo(AgentCommissionRule::class, 'agent_commission_rule_id');
    }

    public function scopeForAgent(Builder $query, int $agentAccountId): Builder
    {
        return $query->where('agent_account_id', $agentAccountId);
    }
}
