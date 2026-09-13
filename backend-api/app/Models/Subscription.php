<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Subscription extends Model
{
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
