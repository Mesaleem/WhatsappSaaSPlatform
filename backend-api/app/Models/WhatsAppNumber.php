<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A WhatsApp number an account owns. See the whatsapp_numbers migration for the
 * uniqueness and lock rules. Tenant-scoped by the caller (always filtered by
 * account_id in WhatsAppNumberService), not by a global scope.
 */
class WhatsAppNumber extends Model
{
    public const STATUS_PENDING_PAYMENT = 'pending_payment';
    public const STATUS_UNLINKED = 'unlinked';
    public const STATUS_LINKED = 'linked';
    public const STATUS_PAUSED = 'paused';

    protected $table = 'whatsapp_numbers';

    protected $fillable = [
        'account_id',
        'phone_number',
        'is_included',
        'is_default',
        'status',
        'locked_at',
        'addon_invoice_id',
        'term_ends_at',
    ];

    protected function casts(): array
    {
        return [
            'is_included' => 'boolean',
            'is_default' => 'boolean',
            'locked_at' => 'datetime',
            'term_ends_at' => 'datetime',
        ];
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    public function isLocked(): bool
    {
        return $this->locked_at !== null;
    }
}
