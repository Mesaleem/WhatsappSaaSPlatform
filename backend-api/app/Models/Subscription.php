<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use App\Traits\LogsActivity;

class Subscription extends Model
{
    use HasFactory;
    use LogsActivity;

    /** IMPLEMENT: Dynamic Route Master with Super-Admin Bypass & Global Audit Tracking — module label shown in the Activity Logs UI. */
    protected string $auditModuleName = 'Billing & Subscriptions';
    protected $fillable = [
        'account_id',
        'engine_type',
        'billing_model',
        'rate_per_message',
        'total_allocated_messages',
        'used_messages',
        'price_paid',
        'payment_mode',
        'starts_at',
        'expires_at',
        'status',
    ];

    /**
     * Wallet Balance -- Single Source of Truth (2026-09-18): spent_amount/
     * remaining_balance used to be computed independently in three places
     * (AccountsPage.tsx client-side, BillingController::clientSummary(),
     * Account::quotaExhaustedMessage()) with the same formula. Moving the
     * canonical calculation here means every JSON response that includes
     * a Subscription -- GET /api/admin/accounts, /api/admin/accounts/{id},
     * the /subscription update responses -- carries these two fields
     * automatically, with no per-endpoint wiring and no risk of a
     * frontend build/deploy lag ever showing stale-derived numbers again.
     */
    protected $appends = ['spent_amount', 'remaining_balance'];

    protected function casts(): array
    {
        return [
            'rate_per_message' => 'decimal:4',
            'total_allocated_messages' => 'integer',
            'used_messages' => 'integer',
            'price_paid' => 'decimal:2',
            'starts_at' => 'datetime',
            'expires_at' => 'datetime',
        ];
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    /**
     * Amount consumed so far, rupee-denominated -- meaningful only for
     * 'per_message' (the only billing model with a real rate_per_message;
     * flat_quota/unlimited are paid as a flat price_paid regardless of
     * usage, so "amount spent" has no per-message meaning for them and
     * stays null rather than a misleading 0). null, not 0, lets callers
     * (frontend and PHP alike) distinguish "not applicable" from
     * "nothing spent yet".
     */
    protected function spentAmount(): Attribute
    {
        return Attribute::make(
            get: fn () => $this->billing_model === 'per_message' && $this->rate_per_message !== null
                ? round((float) $this->rate_per_message * $this->used_messages, 2)
                : null,
        );
    }

    /**
     * price_paid minus spent_amount, floored at 0 -- NOT derived from
     * total_allocated_messages (floor(price_paid / rate), kept only for
     * send-gating at a whole-message boundary): deriving the rupee
     * balance from that floored quota instead would leak its rounding
     * remainder into a figure read as the account's literal remaining
     * wallet value. price_paid is treated as the exact, unmodified
     * starting balance -- this accessor only ever subtracts from it,
     * never reconstructs or overrides it.
     */
    protected function remainingBalance(): Attribute
    {
        return Attribute::make(
            get: fn () => $this->spent_amount !== null
                ? round(max((float) $this->price_paid - $this->spent_amount, 0), 2)
                : null,
        );
    }

    /**
     * There is no scheduled sweep job in this module, so status is kept
     * correct lazily: recompute from expires_at/used_messages and persist
     * only if it actually changed. Called from read paths (AccountController,
     * SubscriptionGuardMiddleware) and after any mutation.
     */
    public function refreshStatus(): string
    {
        $status = $this->computeStatus();

        if ($status !== $this->status) {
            $this->forceFill(['status' => $status])->save();
        }

        return $status;
    }

    public function computeStatus(): string
    {
        if ($this->expires_at !== null && $this->expires_at->isPast()) {
            return 'expired';
        }

        if (
            $this->billing_model !== 'unlimited'
            && $this->total_allocated_messages !== null
            && $this->used_messages >= $this->total_allocated_messages
        ) {
            return 'exhausted';
        }

        return 'active';
    }

    public function isActive(): bool
    {
        return $this->refreshStatus() === 'active';
    }

    /**
     * Group Messaging Phase 4 — credits left before hitting the plan's
     * cap, or null when there IS no cap to check against (billing_model
     * 'unlimited', or a capped model with no total_allocated_messages
     * set yet). [Disclosed correction]: there is no billing_model value
     * literally named 'LIMITED' — this mirrors computeStatus()'s own
     * exhaustion condition above exactly (non-'unlimited' AND a cap is
     * set), just exposed as a number instead of a boolean.
     */
    public function remainingQuota(): ?int
    {
        if ($this->billing_model === 'unlimited' || $this->total_allocated_messages === null) {
            return null;
        }

        return max(0, $this->total_allocated_messages - $this->used_messages);
    }

    /** True when this subscription can cover $n more messages — always true for an uncapped plan (remainingQuota() === null). */
    public function hasQuotaFor(int $n): bool
    {
        $remaining = $this->remainingQuota();

        return $remaining === null || $remaining >= $n;
    }
}
