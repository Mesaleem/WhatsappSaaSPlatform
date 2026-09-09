<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ApiKey extends Model
{
    protected $fillable = [
        'account_id',
        'name',
        'key_prefix',
        'key_hash',
        'last_used_at',
        'expires_at',
        'revoked_at',
    ];

    /**
     * key_hash is a SHA-256 digest, never a secret worth reading back —
     * hidden defensively anyway so a stray `Model::toArray()` never leaks
     * it, even though it can't be reversed into the plaintext key.
     */
    protected $hidden = [
        'key_hash',
    ];

    protected function casts(): array
    {
        return [
            'last_used_at' => 'datetime',
            'expires_at' => 'datetime',
            'revoked_at' => 'datetime',
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

    public function isRevoked(): bool
    {
        return $this->revoked_at !== null;
    }

    public function isExpired(): bool
    {
        return $this->expires_at !== null && $this->expires_at->isPast();
    }

    /** True only if this key can currently authenticate a request. */
    public function isValid(): bool
    {
        return ! $this->isRevoked() && ! $this->isExpired();
    }

    /** SHA-256 hex digest — the only form of the key ever persisted. */
    public static function hashKey(string $plainTextKey): string
    {
        return hash('sha256', $plainTextKey);
    }
}
