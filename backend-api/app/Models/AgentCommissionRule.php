<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The DB-driven, per-Agent commission configuration (percentage or fixed
 * amount). One row per Agent account — see the migration's own docblock
 * for why updating this row never touches historical AgentCommission
 * rows (those snapshot type/value at generation time).
 */
class AgentCommissionRule extends Model
{
    public const TYPES = ['percentage', 'fixed'];

    protected $fillable = ['agent_account_id', 'type', 'value'];

    protected function casts(): array
    {
        return ['value' => 'decimal:2'];
    }

    public function agentAccount(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'agent_account_id');
    }
}
