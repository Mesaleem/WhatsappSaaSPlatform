<?php

namespace App\Models;

use App\Services\Crm\CrmTenantLinks;
use App\Traits\LogsActivity;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * CRM Sales Phase 2 — a sales document the TENANT gives its customer (or, for a
 * customer PO, receives from it). One table for every type so numbering, PDF,
 * delivery and payments share one implementation; the type decides the lifecycle:
 *
 *   proposal / quotation  draft -> sent -> accepted | rejected | expired ; any open -> cancelled
 *   customer_po           received -> approved -> fulfilled ; received/approved -> cancelled
 *   sales_order           draft -> confirmed -> fulfilled ; draft/confirmed -> cancelled
 *   invoice               draft -> issued -> partially_paid -> paid ; issued (unpaid) -> void
 *   credit_note           draft -> issued ; issued -> void
 *
 * Only a draft (or a received customer PO) can be edited. Issuing freezes the
 * customer / seller snapshots and the line items and takes the number. Payments and
 * credit notes move an invoice between issued / partially_paid / paid
 * (CrmPaymentService / CrmDocumentService). Accepting a quotation never wins the deal by
 * itself.
 */
class CrmDocument extends Model
{
    use LogsActivity;

    protected string $auditModuleName = 'CRM';

    /** @var list<string> */
    protected array $auditIdentity = ['id', 'type', 'number'];

    protected $table = 'crm_documents';

    public const TYPE_PROPOSAL = 'proposal';
    public const TYPE_QUOTATION = 'quotation';
    public const TYPE_CUSTOMER_PO = 'customer_po';
    public const TYPE_SALES_ORDER = 'sales_order';
    public const TYPE_INVOICE = 'invoice';
    public const TYPE_CREDIT_NOTE = 'credit_note';

    /** @var list<string> */
    public const TYPES = [self::TYPE_PROPOSAL, self::TYPE_QUOTATION, self::TYPE_CUSTOMER_PO, self::TYPE_SALES_ORDER, self::TYPE_INVOICE, self::TYPE_CREDIT_NOTE];

    /** Number prefix per type. A customer PO carries the buyer's own number (external_reference). @var array<string, string> */
    public const PREFIXES = [
        self::TYPE_PROPOSAL => 'PROP',
        self::TYPE_QUOTATION => 'QUO',
        self::TYPE_SALES_ORDER => 'SO',
        self::TYPE_INVOICE => 'INV',
        self::TYPE_CREDIT_NOTE => 'CN',
    ];

    /** The status a new document starts in. @var array<string, string> */
    public const INITIAL_STATUS = [
        self::TYPE_PROPOSAL => 'draft',
        self::TYPE_QUOTATION => 'draft',
        self::TYPE_CUSTOMER_PO => 'received',
        self::TYPE_SALES_ORDER => 'draft',
        self::TYPE_INVOICE => 'draft',
        self::TYPE_CREDIT_NOTE => 'draft',
    ];

    /** Statuses in which a document can still be edited. @var array<string, list<string>> */
    public const EDITABLE = [
        self::TYPE_PROPOSAL => ['draft'],
        self::TYPE_QUOTATION => ['draft'],
        self::TYPE_CUSTOMER_PO => ['received'],
        self::TYPE_SALES_ORDER => ['draft'],
        self::TYPE_INVOICE => ['draft'],
        self::TYPE_CREDIT_NOTE => ['draft'],
    ];

    /** The status issuing moves a document to. @var array<string, string> */
    public const ISSUED_STATUS = [
        self::TYPE_PROPOSAL => 'sent',
        self::TYPE_QUOTATION => 'sent',
        self::TYPE_SALES_ORDER => 'confirmed',
        self::TYPE_INVOICE => 'issued',
        self::TYPE_CREDIT_NOTE => 'issued',
    ];

    /**
     * Status moves a user may make directly (issuing and payment-driven moves are done by
     * the services). from => [to...]
     *
     * @var array<string, array<string, list<string>>>
     */
    public const USER_TRANSITIONS = [
        self::TYPE_PROPOSAL => ['draft' => ['cancelled'], 'sent' => ['accepted', 'rejected', 'cancelled']],
        self::TYPE_QUOTATION => ['draft' => ['cancelled'], 'sent' => ['accepted', 'rejected', 'cancelled']],
        self::TYPE_CUSTOMER_PO => ['received' => ['approved', 'cancelled'], 'approved' => ['fulfilled', 'cancelled']],
        self::TYPE_SALES_ORDER => ['draft' => ['cancelled'], 'confirmed' => ['fulfilled', 'cancelled']],
        self::TYPE_INVOICE => ['draft' => ['void'], 'issued' => ['void']],
        self::TYPE_CREDIT_NOTE => ['draft' => ['void'], 'issued' => ['void']],
    ];

    /** Which document may be created from which (convert). @var array<string, list<string>> */
    public const CONVERSIONS = [
        self::TYPE_PROPOSAL => [self::TYPE_QUOTATION],
        self::TYPE_QUOTATION => [self::TYPE_SALES_ORDER, self::TYPE_INVOICE],
        self::TYPE_CUSTOMER_PO => [self::TYPE_SALES_ORDER, self::TYPE_INVOICE],
        self::TYPE_SALES_ORDER => [self::TYPE_INVOICE],
    ];

    /** @var list<string> */
    public const TAX_MODES = ['none', 'intra', 'inter'];

    protected $fillable = [
        'account_id', 'type', 'status', 'number', 'financial_year', 'parent_id', 'crm_deal_id', 'contact_id', 'crm_company_id',
        'title', 'issue_date', 'valid_until', 'due_date', 'currency', 'tax_mode', 'place_of_supply',
        'subtotal', 'discount_total', 'taxable_total', 'cgst_total', 'sgst_total', 'igst_total', 'tax_total', 'round_off', 'grand_total',
        'amount_paid', 'amount_credited', 'balance_due', 'customer_snapshot', 'seller_snapshot', 'body', 'terms', 'notes',
        'external_reference', 'attachment_path', 'attachment_name', 'public_token', 'issued_at', 'issued_by_user_id', 'decided_at', 'decided_by_user_id', 'cancelled_at',
        'cancel_reason', 'created_by_user_id', 'updated_by_user_id',
    ];

    protected $hidden = ['public_token'];

    /** The database defaults, on the model too, so the saving guard sees them on a new document. */
    protected $attributes = [
        'tax_mode' => 'none',
        'currency' => 'INR',
        'subtotal' => '0.00', 'discount_total' => '0.00', 'taxable_total' => '0.00', 'cgst_total' => '0.00', 'sgst_total' => '0.00',
        'igst_total' => '0.00', 'tax_total' => '0.00', 'round_off' => '0.00', 'grand_total' => '0.00', 'amount_paid' => '0.00',
        'amount_credited' => '0.00', 'balance_due' => '0.00',
    ];

    protected function casts(): array
    {
        $money = 'decimal:2';

        return [
            'account_id' => 'integer', 'parent_id' => 'integer', 'crm_deal_id' => 'integer', 'contact_id' => 'integer', 'crm_company_id' => 'integer',
            'issue_date' => 'date', 'valid_until' => 'date', 'due_date' => 'date',
            'subtotal' => $money, 'discount_total' => $money, 'taxable_total' => $money, 'cgst_total' => $money, 'sgst_total' => $money,
            'igst_total' => $money, 'tax_total' => $money, 'round_off' => $money, 'grand_total' => $money, 'amount_paid' => $money,
            'amount_credited' => $money, 'balance_due' => $money,
            'customer_snapshot' => 'array', 'seller_snapshot' => 'array',
            'issued_at' => 'datetime', 'decided_at' => 'datetime', 'cancelled_at' => 'datetime',
            'issued_by_user_id' => 'integer', 'created_by_user_id' => 'integer', 'updated_by_user_id' => 'integer', 'decided_by_user_id' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (CrmDocument $doc): void {
            $doc->public_token ??= Str::random(48);
        });

        static::saving(function (CrmDocument $doc): void {
            if ($doc->exists && $doc->isDirty(['account_id', 'type'])) {
                throw ValidationException::withMessages(['type' => ['A document cannot change its account or type.']]);
            }
            if (! in_array($doc->type, self::TYPES, true)) {
                throw ValidationException::withMessages(['type' => ['The selected document type is invalid.']]);
            }
            if (! in_array($doc->tax_mode, self::TAX_MODES, true)) {
                throw ValidationException::withMessages(['tax_mode' => ['The selected tax mode is invalid.']]);
            }

            $links = [];
            foreach (['crm_deal_id', 'contact_id', 'crm_company_id'] as $field) {
                if ($doc->isDirty([$field, 'account_id'])) {
                    $links[$field] = $doc->{$field};
                }
            }
            CrmTenantLinks::assertBelong((int) $doc->account_id, $links);

            if ($doc->parent_id !== null && $doc->isDirty(['parent_id', 'account_id'])
                && ! self::query()->whereKey($doc->parent_id)->where('account_id', $doc->account_id)->exists()) {
                throw ValidationException::withMessages(['parent_id' => ['The selected document is not available.']]);
            }

            // Money never goes negative and a balance never exceeds the total.
            foreach (['grand_total', 'amount_paid', 'amount_credited', 'balance_due'] as $field) {
                if (bccomp((string) $doc->{$field}, '0', 2) < 0) {
                    throw ValidationException::withMessages([$field => ['This amount cannot be negative.']]);
                }
            }
        });
    }

    public function isEditable(): bool
    {
        return in_array($this->status, self::EDITABLE[$this->type] ?? [], true);
    }

    public function isInvoice(): bool
    {
        return $this->type === self::TYPE_INVOICE;
    }

    /** An invoice that can take a payment. */
    public function isPayable(): bool
    {
        return $this->isInvoice() && in_array($this->status, ['issued', 'partially_paid'], true) && bccomp((string) $this->balance_due, '0', 2) > 0;
    }

    public function isOverdue(): bool
    {
        return $this->isPayable() && $this->due_date !== null && $this->due_date->isBefore(now()->startOfDay());
    }

    public function items(): HasMany
    {
        return $this->hasMany(CrmDocumentItem::class, 'crm_document_id')->orderBy('position')->orderBy('id');
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id');
    }

    public function deal(): BelongsTo
    {
        return $this->belongsTo(CrmDeal::class, 'crm_deal_id');
    }

    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class);
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(CrmCompany::class, 'crm_company_id');
    }

    public function allocations(): HasMany
    {
        return $this->hasMany(CrmPaymentAllocation::class, 'crm_document_id');
    }

    public function gatewayOrders(): HasMany
    {
        return $this->hasMany(CrmGatewayOrder::class, 'crm_document_id');
    }

    public function issuedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'issued_by_user_id');
    }

    public function decidedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by_user_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    public function deliveries(): HasMany
    {
        return $this->hasMany(CrmDocumentDelivery::class, 'crm_document_id');
    }

    public function scopeForAccount(Builder $query, int $accountId): Builder
    {
        return $query->where('account_id', $accountId);
    }
}
