<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class UsageQuota extends Model
{
    protected $fillable = [
        'account_id', 'capability_id', 'allocated', 'used',
        'period_starts_at', 'period_ends_at',
    ];

    protected function casts(): array
    {
        return [
            'period_starts_at' => 'datetime',
            'period_ends_at' => 'datetime',
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
