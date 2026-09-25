<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Phase 8 Task 1 — one credit account per billable account (tenant).
 *
 * `balance` = credits the account owns; `reserved` = the part of it held by
 * open reservations; available() = balance − reserved. Both are integers.
 *
 * NOTHING mutates these two columns except App\Services\Credits\
 * CreditService, inside a transaction holding this row's lock, and always
 * together with a CreditLedgerEntry that records the change. Neither column
 * is fillable, so no request payload can reach them through mass
 * assignment.
 */
class CreditAccount extends Model
{
    protected $table = 'credit_accounts';

    protected $fillable = ['account_id'];

    protected function casts(): array
    {
        return [
            'account_id' => 'integer',
            'balance' => 'integer',
            'reserved' => 'integer',
        ];
    }

    public function available(): int
    {
        return (int) $this->balance - (int) $this->reserved;
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    public function entries(): HasMany
    {
        return $this->hasMany(CreditLedgerEntry::class);
    }

    public function reservations(): HasMany
    {
        return $this->hasMany(CreditReservation::class);
    }
}
