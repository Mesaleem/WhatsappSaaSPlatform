<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use LogicException;

/**
 * Phase 8 Task 11 — one immutable snapshot of an agent's executable
 * configuration: instructions, model hint, tool allow-list and limit
 * overrides. A change to any of them creates a NEW version; an existing row
 * is never updated, so an execution that pinned a version keeps behaving the
 * same however the agent is edited afterwards.
 */
class AiAgentVersion extends Model
{
    protected $table = 'ai_agent_versions';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'version' => 'integer',
            'tools' => 'array',
            'settings' => 'array',
        ];
    }

    protected static function booted(): void
    {
        static::updating(function () {
            throw new LogicException('AI agent versions are immutable; create a new version instead.');
        });
    }

    public function scopeForAccount(Builder $query, int $accountId): Builder
    {
        return $query->where('account_id', $accountId);
    }

    /** @return list<string> */
    public function toolNames(): array
    {
        return array_values(array_filter(is_array($this->tools) ? $this->tools : [], 'is_string'));
    }

    public function setting(string $key): ?int
    {
        $value = is_array($this->settings) ? ($this->settings[$key] ?? null) : null;

        return is_int($value) ? $value : null;
    }
}
