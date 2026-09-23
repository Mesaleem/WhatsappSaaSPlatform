<?php

namespace App\Models;

use App\Traits\LogsActivity;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Cache;

class AccountEntitlement extends Model
{
    use LogsActivity;

    protected $fillable = [
        'account_id',
        'capability_id',
        'source',
        'granted_by_account_id',
        // Phase 5 Task 9 — explicit revoked state. NULL = held.
        'revoked_at',
        'revoked_by_user_id',
        // Phase 5 Task 10 — WHY it was revoked. See the constants below.
        'revoked_reason',
    ];

    /**
     * Phase 5 Task 10 — revocation reasons.
     *
     * The difference is load-bearing for PlanEntitlementReconciliationService:
     * a plan downgrade is a reversible consequence of what the customer
     * bought, while an administrator's revocation is a decision no
     * automated process may undo.
     */
    public const REVOKED_MANUAL = 'manual';

    public const REVOKED_PLAN_DOWNGRADE = 'plan_downgrade';

    /** The `source` values a plan reconciliation is allowed to touch. */
    public const SOURCE_PLAN = 'plan';

    public const SOURCE_MANUAL_GRANT = 'manual_grant';

    public const SOURCE_AGENT_DELEGATED = 'agent_delegated';

    protected function casts(): array
    {
        return ['revoked_at' => 'datetime'];
    }

    /**
     * Phase 5 Task 9 — entitlements the account actually HOLDS.
     *
     * Every read path that answers "can this tenant do X" must go
     * through this scope. A revoked row still exists (that is the whole
     * point — it records a deliberate administrative decision that a
     * plan backfill must not reverse), but it grants nothing.
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->whereNull('revoked_at');
    }

    /** Deliberately revoked — NOT the same as never granted. */
    public function scopeRevoked(Builder $query): Builder
    {
        return $query->whereNotNull('revoked_at');
    }

    public function isRevoked(): bool
    {
        return $this->revoked_at !== null;
    }

    /**
     * Revoked by a person, so no automated process may restore it.
     * Deliberately treats an unknown/missing reason on a revoked row as
     * manual: the safe reading, since restoring something that should
     * have stayed revoked is the damaging direction.
     */
    public function isManuallyRevoked(): bool
    {
        return $this->isRevoked() && $this->revoked_reason !== self::REVOKED_PLAN_DOWNGRADE;
    }

    /** Revoked only because the plan stopped including it — restorable. */
    public function isPlanRevoked(): bool
    {
        return $this->isRevoked() && $this->revoked_reason === self::REVOKED_PLAN_DOWNGRADE;
    }

    /** Rows a plan reconciliation may modify at all: source='plan' only. */
    public function scopePlanSourced(Builder $query): Builder
    {
        return $query->where('source', self::SOURCE_PLAN);
    }

    /**
     * Phase 1 Foundation, Task 10 — reuses Account::cacheKey()/its own
     * booted()-hook invalidation pattern exactly, so a grant/revoke here
     * is never served stale by AccessControlService::canTenant() via
     * Account::findCached()'s 60-minute TTL. Mirrors Account.php's own
     * booted() hook rather than inventing a second caching convention.
     */
    protected static function booted(): void
    {
        static::saved(fn (AccountEntitlement $e) => Cache::forget(Account::cacheKey($e->account_id)));
        static::deleted(fn (AccountEntitlement $e) => Cache::forget(Account::cacheKey($e->account_id)));
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    public function capability(): BelongsTo
    {
        return $this->belongsTo(Capability::class);
    }

    public function grantedBy(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'granted_by_account_id');
    }

    public function revokedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'revoked_by_user_id');
    }
}
