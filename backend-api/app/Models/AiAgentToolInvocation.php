<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Phase 8 Task 11 — one agent tool call, written by
 * App\Services\Ai\Agents\Tools\ToolExecutor only. For a side-effecting tool
 * the row is inserted in the SAME transaction as the effect, so its
 * existence proves the effect happened exactly once (see the migration).
 * Holds the arguments' hash and the normalized result the model received —
 * never the raw arguments, a prompt or a credential.
 */
class AiAgentToolInvocation extends Model
{
    public const STATUS_SUCCEEDED = 'succeeded';

    public const STATUS_ERROR = 'error';     // the tool ran and reported a controlled error (e.g. not found)

    public const STATUS_DENIED = 'denied';   // refused before execution (allow-list, authorization, arguments)

    protected $table = 'ai_agent_tool_invocations';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['result' => 'array'];
    }

    public function scopeForAccount(Builder $query, int $accountId): Builder
    {
        return $query->where('account_id', $accountId);
    }
}
