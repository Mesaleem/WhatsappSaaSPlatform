<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use App\Traits\LogsActivity;

class ApiKey extends Model
{
    use LogsActivity;

    /** IMPLEMENT: Dynamic Route Master with Super-Admin Bypass & Global Audit Tracking — module label shown in the Activity Logs UI. */
    protected string $auditModuleName = 'Developer API Keys';
    protected $fillable = [
        'account_id',
        'name',
        'key_prefix',
        'key_hash',
        // Developer API Platform for WhatsApp Group Creation & Unified
        // Messaging -- optional dual-factor secret, nullable for every
        // key issued before this feature (see this pair's creating
        // migration's docblock for the full backward-compatibility
        // rationale).
        'secret_prefix',
        'secret_hash',
        'last_used_at',
        'expires_at',
        'revoked_at',
        'access_disabled_at',
        'access_disabled_reason',
        // Phase 4 Task 5 — legacy API-key binding backfill deadline
        // foundation. NULL for every key except one the backfill
        // command actually bound (see BackfillApiKeyLegacyBindings).
        'legacy_binding_grace_expires_at',
        // Phase 4 Task 6 — per-key cooldown storage primitive. NULL
        // until Task 9 wires an actual policy that sets it
        // (ApiKeyBindingService::setCooldown()/clearCooldown()).
        'cooldown_until',
    ];

    /**
     * key_hash is a SHA-256 digest, never a secret worth reading back —
     * hidden defensively anyway so a stray `Model::toArray()` never leaks
     * it, even though it can't be reversed into the plaintext key.
     */
    protected $hidden = [
        'key_hash',
        'secret_hash',
    ];

    protected function casts(): array
    {
        return [
            'last_used_at' => 'datetime',
            'expires_at' => 'datetime',
            'revoked_at' => 'datetime',
            'access_disabled_at' => 'datetime',
            'legacy_binding_grace_expires_at' => 'datetime',
            'cooldown_until' => 'datetime',
        ];
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    public function bindings(): HasMany
    {
        return $this->hasMany(ApiKeyBinding::class);
    }

    /** The pending/active authorized server, if any (at most one - enforced by a unique slot). */
    public function liveBinding(): ?ApiKeyBinding
    {
        // Phase 4 Task 3 — reads the same live() scope the account-wide
        // counting seam uses (ApiKeyBindingService::countLiveInstallations()),
        // rather than repeating the status list here.
        return $this->bindings()->live()->first();
    }

    public function isAccessDisabled(): bool
    {
        return $this->access_disabled_at !== null;
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

    /**
     * Phase 4 Task 6 — read-side half of the cooldown primitive. Not
     * consulted by gate() or any enforcement path yet (that is Task 9);
     * provided now so Task 9 only has to call it, not design it.
     */
    public function isInCooldown(): bool
    {
        return $this->cooldown_until !== null && $this->cooldown_until->isFuture();
    }

    /** SHA-256 hex digest — the only form of the key ever persisted. */
    public static function hashKey(string $plainTextKey): string
    {
        return hash('sha256', $plainTextKey);
    }

    /**
     * True only if this key's dual-factor secret has been provisioned --
     * false for every key issued before the Developer API Platform for
     * WhatsApp Group Creation & Unified Messaging feature (secret_hash
     * null), until its holder calls
     * POST /developer/api-keys/{id}/regenerate-secret.
     */
    public function hasSecret(): bool
    {
        return $this->secret_hash !== null;
    }

    /**
     * Timing-safe comparison against the stored secret digest --
     * hash_equals() rather than a plain === so this check runs in
     * constant time regardless of where the strings first differ (the
     * same protection ApiAuthMiddleware needs for a secret compared on
     * every external API request, unlike key_hash's plain lookup-by-hash
     * comparison, which leaks nothing timing-wise since it never branches
     * on a partial match).
     */
    public function isSecretValid(string $plainTextSecret): bool
    {
        return $this->hasSecret() && hash_equals($this->secret_hash, self::hashSecret($plainTextSecret));
    }

    /** SHA-256 hex digest -- the only form of the secret ever persisted, same convention as hashKey() above. */
    public static function hashSecret(string $plainTextSecret): string
    {
        return hash('sha256', $plainTextSecret);
    }
}
