<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A payment recorded by hand (Super Admin or Agent) against an add-on invoice. */
class ManualPayment extends Model
{
    public const METHODS = ['cash', 'bank_transfer', 'upi', 'cheque', 'other'];

    protected $table = 'manual_payments';

    protected $fillable = [
        'invoice_id',
        'account_id',
        'amount',
        'method',
        'transaction_id',
        'paid_on',
        'term_starts_on',
        'recorded_by_user_id',
        'recorded_by_role',
        'note',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'paid_on' => 'date',
            'term_starts_on' => 'date',
        ];
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }
}
