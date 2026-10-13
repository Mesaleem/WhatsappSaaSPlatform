<?php

namespace App\Models;

use App\Traits\LogsActivity;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * CRM Sales Phase 3 — the tenant's OWN payment gateway (Razorpay or Stripe), so its
 * customers pay the tenant directly, not WapHub. Secrets are encrypted at rest and
 * $hidden (so they never reach JSON or the audit log). webhook_token is the
 * unguessable part of this tenant's webhook URL.
 */
class CrmGatewaySetting extends Model
{
    use LogsActivity;

    protected string $auditModuleName = 'CRM';

    protected $table = 'crm_gateway_settings';

    /** @var list<string> */
    public const GATEWAYS = ['razorpay', 'stripe'];

    protected $fillable = ['account_id', 'gateway', 'mode', 'key_id', 'key_secret', 'webhook_secret', 'webhook_token', 'is_enabled'];

    protected $hidden = ['key_secret', 'webhook_secret'];

    protected function casts(): array
    {
        return ['account_id' => 'integer', 'key_secret' => 'encrypted', 'webhook_secret' => 'encrypted', 'is_enabled' => 'boolean'];
    }

    public function isUsable(): bool
    {
        return $this->is_enabled && filled($this->key_id) && filled($this->key_secret);
    }

    public function scopeForAccount(Builder $query, int $accountId): Builder
    {
        return $query->where('account_id', $accountId);
    }
}
