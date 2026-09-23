<?php

namespace App\Models;

use App\Traits\LogsActivity;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AgentSellingEntitlement extends Model
{
    use LogsActivity;

    protected $fillable = ['agent_account_id', 'capability_id', 'sellable'];

    protected function casts(): array
    {
        return ['sellable' => 'boolean'];
    }

    public function agentAccount(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'agent_account_id');
    }

    public function capability(): BelongsTo
    {
        return $this->belongsTo(Capability::class);
    }
}
