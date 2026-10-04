<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One authorized server for one API key. installation_hash is the sha256 of the installation credential - never the credential. */
class ApiKeyBinding extends Model
{
    public const STATUS_PENDING = 'pending_activation';
    public const STATUS_ACTIVE = 'active';
    public const STATUS_REVOKED = 'revoked';

    public const POLICY_NONE = 'NONE';
    public const POLICY_SINGLE_IP = 'SINGLE_IP';
    public const POLICY_ALLOWLIST = 'IP_ALLOWLIST';
    public const POLICIES = [self::POLICY_NONE, self::POLICY_SINGLE_IP, self::POLICY_ALLOWLIST];

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
        return in_array($this->status, [self::STATUS_PENDING, self::STATUS_ACTIVE], true);
    }

    public function hasCredential(): bool
    {
        return $this->installation_hash !== null;
    }

    public static function hashCredential(string $credential): string
    {
        return hash('sha256', $credential);
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
