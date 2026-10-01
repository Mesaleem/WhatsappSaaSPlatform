<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Phase 8 Task 11 — a registered AI agent owned by ONE account. Always
 * looked up through forAccount(): an id from a request or a journey node is
 * never trusted alone (another account's id is indistinguishable from a
 * missing one).
 *
 * The executable configuration lives in immutable AiAgentVersion rows;
 * current_version_id names the one new executions start from. Name,
 * description and is_enabled are registry metadata and never create a
 * version.
 */
class AiAgent extends Model
{
    protected $table = 'ai_agents';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'is_enabled' => 'boolean',
            'current_version_id' => 'integer',
            'version_count' => 'integer',
        ];
    }

    public function scopeForAccount(Builder $query, int $accountId): Builder
    {
        return $query->where('account_id', $accountId);
    }

    public function versions(): HasMany
    {
        // Same account by construction: the composite FK (ai_agent_id, account_id).
        return $this->hasMany(AiAgentVersion::class, 'ai_agent_id');
    }

    public function currentVersion(): ?AiAgentVersion
    {
        return $this->current_version_id === null ? null
            : AiAgentVersion::query()->forAccount((int) $this->account_id)->where('ai_agent_id', $this->id)->find($this->current_version_id);
    }
}
