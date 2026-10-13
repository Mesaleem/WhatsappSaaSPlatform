<?php

namespace App\Models;

use App\Traits\LogsActivity;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * CRM Sales Phase 3 — part of a payment applied to one invoice. Reversing a payment
 * stamps reversed_at on its allocations (never deletes them), and invoice balances are
 * recomputed from the active ones.
 */
class CrmPaymentAllocation extends Model
{
    use LogsActivity;

    protected string $auditModuleName = 'CRM';

    protected $table = 'crm_payment_allocations';

    protected $fillable = ['account_id', 'crm_payment_id', 'crm_document_id', 'amount', 'reversed_at'];

    protected function casts(): array
    {
        return ['account_id' => 'integer', 'crm_payment_id' => 'integer', 'crm_document_id' => 'integer', 'amount' => 'decimal:2', 'reversed_at' => 'datetime'];
    }

    public function payment(): BelongsTo
    {
        return $this->belongsTo(CrmPayment::class, 'crm_payment_id');
    }

    public function document(): BelongsTo
    {
        return $this->belongsTo(CrmDocument::class, 'crm_document_id');
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->whereNull('reversed_at');
    }
}
