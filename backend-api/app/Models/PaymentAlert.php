<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PaymentAlert extends Model
{
    protected $fillable = [
        'account_id',
        'recipient_phone',
        'customer_name',
        'amount',
        'payment_ref',
        'status',
        'cost_deducted',
        'error_reason',
        'sent_at',
        'raw_response',
        'gateway_message_id',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'cost_deducted' => 'decimal:4',
            'sent_at' => 'datetime',
            // The WhatsApp driver's raw sendMessage() result — an Eloquent
            // 'array' cast both json_encode()s on write and json_decode()s
            // on read, so ProcessPaymentAlertJob can just assign a PHP
            // array and callers of this model get one back.
            'raw_response' => 'array',
        ];
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    /**
     * The dedup check ProcessPaymentAlertJob and PaymentAlertController both
     * rely on. Scoped to account_id — payment_ref is only unique per tenant,
     * see the migration's docblock.
     */
    public function scopeForAccount(Builder $query, int $accountId): Builder
    {
        return $query->where('account_id', $accountId);
    }

    public function scopeWithStatus(Builder $query, string $status): Builder
    {
        return $query->where('status', $status);
    }

    public function scopeSent(Builder $query): Builder
    {
        return $query->where('status', 'sent');
    }

    public function scopeFailed(Builder $query): Builder
    {
        return $query->where('status', 'failed');
    }

    /**
     * True if this account already has a payment_alerts row for this
     * payment_ref (any status — a previously failed attempt still
     * "claims" the reference, since retrying isn't in this module's scope
     * and silently re-queueing a failed ref could double-charge/double-send
     * once a retry path exists later).
     */
    public static function isDuplicate(int $accountId, string $paymentRef): bool
    {
        return static::forAccount($accountId)->where('payment_ref', $paymentRef)->exists();
    }
}
