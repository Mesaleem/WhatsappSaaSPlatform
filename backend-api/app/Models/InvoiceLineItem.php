<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One purchased item on an invoice (for example one extra WhatsApp number).
 * Amounts are GST-inclusive, like the invoice total.
 */
class InvoiceLineItem extends Model
{
    protected $table = 'invoice_line_items';

    protected $fillable = [
        'invoice_id',
        'description',
        'quantity',
        'unit_amount',
        'amount',
    ];

    protected function casts(): array
    {
        return [
            'quantity' => 'integer',
            'unit_amount' => 'decimal:2',
            'amount' => 'decimal:2',
        ];
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }
}
