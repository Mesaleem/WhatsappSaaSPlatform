<?php

namespace App\Models;

use App\Traits\LogsActivity;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** CRM Sales Phase 2 — one snapshot line of a document. Written only by CrmDocumentService. */
class CrmDocumentItem extends Model
{
    protected $table = 'crm_document_items';

    protected $fillable = [
        'crm_document_id', 'account_id', 'crm_product_id', 'position', 'description', 'hsn_sac', 'quantity', 'unit',
        'unit_price', 'discount_amount', 'tax_rate', 'taxable_amount', 'cgst_amount', 'sgst_amount', 'igst_amount', 'line_total',
    ];

    protected function casts(): array
    {
        return [
            'crm_document_id' => 'integer', 'account_id' => 'integer', 'crm_product_id' => 'integer', 'position' => 'integer',
            'quantity' => 'decimal:3', 'unit_price' => 'decimal:2', 'discount_amount' => 'decimal:2', 'tax_rate' => 'decimal:2',
            'taxable_amount' => 'decimal:2', 'cgst_amount' => 'decimal:2', 'sgst_amount' => 'decimal:2', 'igst_amount' => 'decimal:2',
            'line_total' => 'decimal:2',
        ];
    }

    public function document(): BelongsTo
    {
        return $this->belongsTo(CrmDocument::class, 'crm_document_id');
    }
}
