<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One authorized server for one API key. installation_hash is the
 * sha256 of the installation credential - never the credential.
 *
 * PHASE 4 TASK 4 — `credential_pending` audit finding: this table has
 * no persisted `credential_pending` status literal, and none is
 * needed. "Credential pending" is an already-supported DERIVED state
 * — a row that isLive() (status pending_activation or active) but
 * !hasCredential() (installation_hash still null) — not a fourth value
 * of `status`. This state already occurs today, before this task,
 * every time ApiKeyBindingService::approve() or rebind() creates a
 * live binding (both always pass credential: null to createLive()):
 * the buyer must separately call installationCredential() to mint one.
 * A future legacy/migration-backfilled binding (Task 5) landing in
 * this same state needs no new column or status on THIS table — any
 * distinction Task 5 needs between "pending because of a normal
 * approval/rebind" and "pending because of a legacy backfill" belongs
 * on the KEY (e.g. a grace-period column), not on the binding, because
 * a binding with no credential yet behaves identically either way from
 * this table's point of view: nothing to verify against until one is
 * issued. No gap found; verifyCredential() below already returns false
 * (never a bypass) for exactly this state.
 */
class ApiKeyBinding extends Model
{
    public const STATUS_PENDING = 'pending_activation';
    public const STATUS_ACTIVE = 'active';
    public const STATUS_REVOKED = 'revoked';

    public const POLICY_NONE = 'NONE';
    public const POLICY_SINGLE_IP = 'SINGLE_IP';
    public const POLICY_ALLOWLIST = 'IP_ALLOWLIST';
    public const POLICIES = [self::POLICY_NONE, self::POLICY_SINGLE_IP, self::POLICY_ALLOWLIST];

    /**
     * Phase 4 Task 3 — the single authoritative definition of "live"
     * (occupies an installation slot): pending_activation or active.
     * isLive() and the live() query scope below both read this constant
     * instead of repeating the literal list, so there is exactly one
     * place that can ever disagree with itself. `revoked` is the only
     * other status and never occupies a slot.
     */
    public const LIVE_STATUSES = [self::STATUS_PENDING, self::STATUS_ACTIVE];

    protected $fillable = [
        'account_id', 'api_key_id', 'status', 'active_slot', 'label', 'ip_policy', 'authorized_ips',
        'installation_prefix', 'installation_hash', 'registered_ip', 'registered_at', 'last_success_ip',
        'last_success_client', 'last_success_at', 'revoked_at', 'revoked_reason', 'created_by_user_id', 'revoked_by_user_id',
    ];

    protected $hidden = ['installation_hash'];

    protected function casts(): array
    {
        return [
            'authorized_ips' => 'array',
            'registered_at' => 'datetime',
            'last_success_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }

    public function apiKey(): BelongsTo
    {
        return $this->belongsTo(ApiKey::class);
    }

    public function isLive(): bool
    {
        return in_array($this->status, self::LIVE_STATUSES, true);
    }

    /**
     * Phase 4 Task 3 — query-scope form of isLive(), for counting rather
     * than loading instances: `ApiKeyBinding::live()->count()`. Same
     * definition, same constant, usable in a WHERE/COUNT.
     *
     * Deliberately independent of ApiKey::access_disabled_at: a key
     * being access-disabled (ApiKeyBindingService::setAccessDisabled())
     * never writes to this table at all — it is an authorization
     * toggle on the KEY, orthogonal to whether the binding itself is
     * still live. A disabled key's live binding keeps occupying its
     * slot until the binding is actually revoked (Super Admin revoke(),
     * rebind(), or an approved change request) — disabling a key does
     * not free the installation it was bound to.
     *
     * @param  \Illuminate\Database\Eloquent\Builder  $query
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeLive($query)
    {
        return $query->whereIn('status', self::LIVE_STATUSES);
    }

    /**
     * Phase 4 Task 3 — account-scoped counting is the seam Task 6
     * consumes. Same naming convention as ApiKey::scopeForAccount().
     *
     * @param  \Illuminate\Database\Eloquent\Builder  $query
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeForAccount($query, int $accountId)
    {
        return $query->where('account_id', $accountId);
    }

    public function hasCredential(): bool
    {
        return $this->installation_hash !== null;
    }

    public static function hashCredential(string $credential): string
    {
        return hash('sha256', $credential);
    }

    /**
     * Phase 4 Task 4 — the credential-verification primitive Task 7's
     * gate() rewrite will call. Timing-safe (hash_equals(), same
     * convention as ApiKey::isSecretValid()), so this never branches on
     * a partial match.
     *
     * Isolation (requirement 5: Key A's credential must never
     * authenticate Key B): this is an INSTANCE method on one already-
     * resolved binding, not a global "find any binding whose hash
     * matches" lookup — there is no such lookup anywhere in this
     * codebase, and this method does not add one. The caller must
     * already hold the specific binding (via $apiKey->liveBinding(),
     * itself scoped to that one key's own bindings relation) before it
     * can verify anything against it, so a credential can only ever be
     * checked against the one binding it was minted for.
     *
     * Returns false — never true, never an exception — for a binding
     * with no stored hash yet (credential_pending; see class docblock
     * section added by this task). There being nothing to compare
     * against is not a bypass: it is simply "verification cannot
     * succeed here", and what a credential-pending binding is allowed
     * to do instead is Task 7's decision, not this method's.
     */
    public function verifyCredential(string $plaintextCredential): bool
    {
        return $this->installation_hash !== null
            && hash_equals($this->installation_hash, self::hashCredential($plaintextCredential));
    }

    /**
     * Phase 4 Task 4 — the other half of the primitive: records that a
     * presented credential was just accepted. Not called from anywhere
     * yet (gate() does not verify credentials until Task 7 wires it in,
     * per this task's explicit scope boundary) — provided now so Task 7
     * only has to call it, not design it. Once Task 7 does call it,
     * issueCredential()'s existing "already in use" guard
     * ($binding->hasCredential() && $binding->last_success_at !== null)
     * starts actually firing — that guard already existed; it simply
     * had no setter to ever make its condition true until now.
     *
     * last_success_client stores the installation_prefix of the
     * credential that just succeeded (matching this table's own
     * migration comment: "installation_prefix of the credential last
     * accepted") — always the binding's OWN current prefix, never an
     * arbitrary caller-supplied label, since that is the only credential
     * verifyCredential() could have just matched.
     */
    public function recordSuccessfulUse(string $ip): void
    {
        $this->forceFill([
            'last_success_at' => now(),
            'last_success_ip' => $ip,
            'last_success_client' => $this->installation_prefix,
        ])->save();
    }

    /** @return array<string, mixed> the fields a buyer or Super Admin may see (no hash, no credential). */
    public function toSafeArray(): array
    {
        return [
            'id' => $this->id,
            'status' => $this->status,
            'label' => $this->label,
            'ip_policy' => $this->ip_policy,
            'authorized_ips' => $this->authorized_ips ?? [],
            'registered_ip' => $this->registered_ip,
            'registered_at' => $this->registered_at?->toIso8601String(),
            'last_success_ip' => $this->last_success_ip,
            'last_success_at' => $this->last_success_at?->toIso8601String(),
            'credential_issued' => $this->hasCredential(),
            'revoked_at' => $this->revoked_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
