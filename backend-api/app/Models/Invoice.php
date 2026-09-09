<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Invoice extends Model
{
    protected $fillable = [
        'account_id',
        'invoice_number',
        'plan_key',
        'plan_label',
        'amount',
        'tax_amount',
        'total_amount',
        'currency',
        'payment_gateway',
        'gateway_order_id',
        'gateway_payment_id',
        'status',
        'paid_at',
        'gateway_raw_response',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'tax_amount' => 'decimal:2',
            'total_amount' => 'decimal:2',
            'paid_at' => 'datetime',
            'gateway_raw_response' => 'array',
        ];
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    public function scopeForAccount(Builder $query, int $accountId): Builder
    {
        return $query->where('account_id', $accountId);
    }

    public function isPaid(): bool
    {
        return $this->status === 'paid';
    }
}
