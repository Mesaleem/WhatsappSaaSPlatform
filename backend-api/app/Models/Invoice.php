<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

class Invoice extends Model
{
    protected $fillable = [
        'account_id',
        'invoice_number',
        'plan_key',
        'plan_label',
        'amount',
        'tax_amount',
        'total_amount',
        'currency',
        'payment_gateway',
        'gateway_order_id',
        'gateway_payment_id',
        'status',
        'paid_at',
        'gateway_raw_response',
    ];

    /**
     * Phase 5 fix P5-4 — the purchased plan terms. Deliberately NOT in
     * $fillable: they are written only by capturePlanTerms() from the
     * database plan, never from request data, and booted() refuses any
     * change once captured. Hidden so no existing invoice response changes
     * shape.
     */
    public const PLAN_TERM_COLUMNS = [
        'plan_engine_type',
        'plan_billing_model',
        'plan_rate_per_message',
        'plan_total_allocated_messages',
        'plan_duration_days',
        'plan_terms_captured_at',
    ];

    protected $hidden = self::PLAN_TERM_COLUMNS;

    protected static function booted(): void
    {
        // Captured terms are immutable: whatever path saves an invoice
        // afterwards (gateway order id, paid/failed status, raw response),
        // it can never rewrite what the customer bought.
        static::updating(function (Invoice $invoice) {
            if ($invoice->getOriginal('plan_terms_captured_at') !== null && $invoice->isDirty(self::PLAN_TERM_COLUMNS)) {
                throw new LogicException("Invoice #{$invoice->getKey()}: captured plan terms are immutable.");
            }
        });
    }

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'tax_amount' => 'decimal:2',
            'total_amount' => 'decimal:2',
            'paid_at' => 'datetime',
            'gateway_raw_response' => 'array',
            'plan_rate_per_message' => 'decimal:4',
            'plan_total_allocated_messages' => 'integer',
            'plan_duration_days' => 'integer',
            'plan_terms_captured_at' => 'datetime',
        ];
    }

    /**
     * Phase 5 fix P5-4 — snapshot the fulfilment terms of $plan (the
     * authoritative database row the order is being created for) onto this
     * not-yet-saved invoice. Exactly the plan attributes
     * InvoiceCreditService::markPaidAndCreditQuota() consumes; price and
     * label are already on the invoice.
     */
    public function capturePlanTerms(Plan $plan): static
    {
        if ($this->exists) {
            throw new LogicException('Plan terms are captured when the invoice is created, never afterwards.');
        }

        return $this->forceFill([
            'plan_engine_type' => $plan->engine_type,
            'plan_billing_model' => $plan->billing_model,
            'plan_rate_per_message' => $plan->rate_per_message,
            'plan_total_allocated_messages' => $plan->total_allocated_messages,
            'plan_duration_days' => $plan->duration_days,
            'plan_terms_captured_at' => now(),
        ]);
    }

    /**
     * The terms this invoice purchased, or null when none were captured
     * (an invoice created before P5-4, or a non-plan top-up invoice).
     *
     * @return array{engine_type: string, billing_model: string, rate_per_message: mixed, total_allocated_messages: ?int, duration_days: int}|null
     */
    public function purchasedPlanTerms(): ?array
    {
        if ($this->plan_terms_captured_at === null) {
            return null;
        }

        return [
            'engine_type' => (string) $this->plan_engine_type,
            'billing_model' => (string) $this->plan_billing_model,
            'rate_per_message' => $this->plan_rate_per_message,
            'total_allocated_messages' => $this->plan_total_allocated_messages,
            'duration_days' => (int) $this->plan_duration_days,
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

    public function isPaid(): bool
    {
        return $this->status === 'paid';
    }
}
