<?php

namespace App\Models;

use App\Traits\LogsActivity;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * CRM Sales Phase 3 — a payment the tenant received from its customer, manual (cash,
 * UPI, bank, cheque...) or through the tenant's own gateway. Recorded once and never
 * edited; a mistake is reversed (reversed_at + reason + who), which releases its
 * allocations. Written only by CrmPaymentService.
 */
class CrmPayment extends Model
{
    use LogsActivity;

    protected string $auditModuleName = 'CRM';

    /** @var list<string> */
    protected array $auditIdentity = ['id', 'number'];

    protected $table = 'crm_payments';

    /** @var list<string> */
    public const METHODS = ['cash', 'upi', 'bank_transfer', 'cheque', 'card', 'gateway', 'other'];

    public const STATUS_SUCCESSFUL = 'successful';
    public const STATUS_REVERSED = 'reversed';

    protected $fillable = [
        'account_id', 'number', 'financial_year', 'contact_id', 'crm_company_id', 'amount', 'currency', 'method', 'reference', 'paid_on',
        'status', 'source', 'gateway', 'gateway_payment_id', 'idempotency_key', 'notes', 'recorded_by_user_id',
        'reversed_at', 'reversed_by_user_id', 'reversal_reason',
    ];

    protected function casts(): array
    {
        return [
            'account_id' => 'integer', 'contact_id' => 'integer', 'crm_company_id' => 'integer', 'amount' => 'decimal:2',
            'paid_on' => 'date', 'recorded_by_user_id' => 'integer', 'reversed_at' => 'datetime', 'reversed_by_user_id' => 'integer',
        ];
    }

    public function allocations(): HasMany
    {
        return $this->hasMany(CrmPaymentAllocation::class, 'crm_payment_id');
    }

    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class);
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(CrmCompany::class, 'crm_company_id');
    }

    public function recordedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by_user_id');
    }

    public function scopeForAccount(Builder $query, int $accountId): Builder
    {
        return $query->where('account_id', $accountId);
    }
}
