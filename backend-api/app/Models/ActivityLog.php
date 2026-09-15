<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * IMPLEMENT: Dynamic Route Master with Super-Admin Bypass & Global Audit
 * Tracking — Global Audit Logging Engine (requirement 3). Rows are
 * written exclusively by the LogsActivity trait (see its docblock);
 * nothing else should insert into this table directly, so every row's
 * shape stays consistent for ActivityLogController's filters.
 */
class ActivityLog extends Model
{
    protected $fillable = [
        'user_id',
        'account_id',
        'agent_id',
        'module_name',
        'action_type',
        'route_path',
        'ip_address',
        'old_values',
        'new_values',
    ];

    protected function casts(): array
    {
        return [
            'old_values' => 'array',
            'new_values' => 'array',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    public function agent(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'agent_id');
    }
}
