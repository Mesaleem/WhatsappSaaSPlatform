<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * Phase 8 Task 1 — the credit ledger: one IMMUTABLE row per movement of a
 * credit account's balance or reserved amount.
 *
 * Each row answers, on its own: which account (account_id), how much
 * (amount; balance_delta / reserved_delta signed), why (type, reason,
 * reference_type/reference_id, refund_of_entry_id, reservation_id), who
 * (actor_user_id, source), when (created_at), with which idempotency key
 * (idempotency_key + request_hash of the operation's parameters), and the
 * resulting state (balance_after, reserved_after). Replaying the rows of one
 * credit account in id order reproduces its current balance and reserved.
 *
 * Written ONLY by App\Services\Credits\CreditService. Updating or deleting a
 * row through the model throws (same guard as WhatsAppFlowVersion); the
 * only way rows disappear is the account itself being deleted (cascade).
 */
class CreditLedgerEntry extends Model
{
    public const TYPE_GRANT = 'grant';

    public const TYPE_PURCHASE = 'purchase';

    public const TYPE_ADJUSTMENT = 'adjustment';

    public const TYPE_CONSUMPTION = 'consumption';

    public const TYPE_REFUND = 'refund';

    public const TYPE_RESERVATION = 'reservation';

    public const TYPE_RESERVATION_RELEASE = 'reservation_release';

    /**
     * Phase 8 Task 2 — credits a PLAN included for one purchased period
     * (PlanCreditAllocator). Distinct from a manual grant, a purchase, an
     * adjustment and a refund; reference_type 'invoice' names the order.
     */
    public const TYPE_PLAN_ALLOCATION = 'plan_allocation';

    public const TYPES = [
        self::TYPE_GRANT, self::TYPE_PURCHASE, self::TYPE_ADJUSTMENT, self::TYPE_CONSUMPTION,
        self::TYPE_REFUND, self::TYPE_RESERVATION, self::TYPE_RESERVATION_RELEASE, self::TYPE_PLAN_ALLOCATION,
    ];

    public const UPDATED_AT = null;

    protected $table = 'credit_ledger_entries';

    protected $guarded = ['id'];

    protected $hidden = ['request_hash'];

    protected function casts(): array
    {
        return [
            'credit_account_id' => 'integer',
            'account_id' => 'integer',
            'amount' => 'integer',
            'balance_delta' => 'integer',
            'reserved_delta' => 'integer',
            'balance_after' => 'integer',
            'reserved_after' => 'integer',
            'reservation_id' => 'integer',
            'refund_of_entry_id' => 'integer',
            'actor_user_id' => 'integer',
            'metadata' => 'array',
            'created_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(function () {
            throw new LogicException('Credit ledger entries are immutable.');
        });

        static::deleting(function () {
            throw new LogicException('Credit ledger entries are immutable and cannot be deleted.');
        });
    }

    public function scopeForAccount(Builder $query, int $accountId): Builder
    {
        return $query->where('account_id', $accountId);
    }

    public function creditAccount(): BelongsTo
    {
        return $this->belongsTo(CreditAccount::class);
    }

    public function reservation(): BelongsTo
    {
        return $this->belongsTo(CreditReservation::class);
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_user_id');
    }
}
