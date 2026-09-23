<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One settled AgentCommission row within an AgentCommissionPayout batch.
 * See the creating migration's docblock for why amount_snapshot exists
 * (a frozen copy, independent of AgentCommission::amount).
 */
class AgentCommissionPayoutItem extends Model
{
    protected $fillable = [
        'agent_commission_payout_id',
        'agent_commission_id',
        'amount_snapshot',
    ];

    protected function casts(): array
    {
        return [
            'amount_snapshot' => 'decimal:2',
        ];
    }

    public function payout(): BelongsTo
    {
        return $this->belongsTo(AgentCommissionPayout::class, 'agent_commission_payout_id');
    }

    public function commission(): BelongsTo
    {
        return $this->belongsTo(AgentCommission::class, 'agent_commission_id');
    }
}
