<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * Phase 8 Task 1 — a hold on part of an account's credits.
 *
 *   reserved ──consume──▶ consumed   (terminal)
 *   reserved ──release──▶ released   (terminal)
 *
 * The status changes ONLY through CreditService's conditional UPDATE
 * (`WHERE status = 'reserved'`), under the credit account's row lock, in
 * the same transaction as the ledger entries that move the credits. A model
 * save/delete is refused, so no other code path can revive, re-consume or
 * re-release a reservation.
 *
 * Phase 8 Task 3 — while open, consumed_amount accumulates partial
 * consumptions (CreditService::capture) and the remaining hold is
 * amount − consumed_amount. The terminal status names the action that
 * closed it: `consumed` (fully captured, or settled) or `released` (the
 * remaining hold given back; consumed_amount keeps what was captured
 * before). A direct spend creates its reservation already `consumed`.
 * expires_at (nullable, caller-chosen): past it, only a release is allowed.
 */
class CreditReservation extends Model
{
    public const STATUS_RESERVED = 'reserved';

    public const STATUS_CONSUMED = 'consumed';

    public const STATUS_RELEASED = 'released';

    protected $table = 'credit_reservations';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'credit_account_id' => 'integer',
            'account_id' => 'integer',
            'amount' => 'integer',
            'consumed_amount' => 'integer',
            'metadata' => 'array',
            'consumed_at' => 'datetime',
            'released_at' => 'datetime',
            'expires_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(function () {
            throw new LogicException('A credit reservation changes state only through CreditService.');
        });

        static::deleting(function () {
            throw new LogicException('Credit reservations cannot be deleted.');
        });
    }

    public function isOpen(): bool
    {
        return $this->status === self::STATUS_RESERVED;
    }

    /** What the reservation still holds (0 once terminal). */
    public function remaining(): int
    {
        return $this->isOpen() ? (int) $this->amount - (int) ($this->consumed_amount ?? 0) : 0;
    }

    public function isExpired(): bool
    {
        return $this->expires_at !== null && ! $this->expires_at->isFuture();
    }

    public function scopeForAccount(Builder $query, int $accountId): Builder
    {
        return $query->where('account_id', $accountId);
    }

    public function creditAccount(): BelongsTo
    {
        return $this->belongsTo(CreditAccount::class);
    }
}
