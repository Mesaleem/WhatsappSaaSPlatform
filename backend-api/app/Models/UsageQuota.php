<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class UsageQuota extends Model
{
    protected $fillable = [
        'account_id', 'capability_id', 'allocated', 'used',
        'period_starts_at', 'period_ends_at',
        // Phase 8 Task 2 — a plan credit allocation period (capability `ai`).
        'subscription_id', 'invoice_id', 'source', 'credit_ledger_entry_id',
    ];

    protected function casts(): array
    {
        return [
            'period_starts_at' => 'datetime',
            'period_ends_at' => 'datetime',
            'allocated' => 'integer',
            'used' => 'integer',
            'subscription_id' => 'integer',
            'invoice_id' => 'integer',
            'credit_ledger_entry_id' => 'integer',
        ];
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    public function capability(): BelongsTo
    {
        return $this->belongsTo(Capability::class);
    }
}
