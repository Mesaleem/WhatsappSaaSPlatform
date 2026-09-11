<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class QuotaRequest extends Model
{
    protected $fillable = [
        'account_id',
        'requested_by',
        'requested_extra_messages',
        'reason',
        'status',
        'reviewed_by',
        'reviewed_at',
        'invoice_id',
    ];

    protected function casts(): array
    {
        return [
            'requested_extra_messages' => 'integer',
            'reviewed_at' => 'datetime',
        ];
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    public function requestedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function reviewedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    public function isPending(): bool
    {
        return $this->status === 'pending';
    }
}
