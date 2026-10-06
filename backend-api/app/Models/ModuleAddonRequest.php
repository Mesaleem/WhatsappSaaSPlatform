<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A client's request for a paid module add-on. See the module_addon_requests migration. */
class ModuleAddonRequest extends Model
{
    public const REQUESTED = 'requested';
    public const INVOICED = 'invoiced';
    public const PAID = 'paid';
    public const EXPIRED = 'expired';
    public const REJECTED = 'rejected';

    protected $table = 'module_addon_requests';

    protected $fillable = [
        'account_id',
        'module',
        'units',
        'status',
        'reason',
        'requested_by_user_id',
        'decided_by_user_id',
        'decision_note',
        'decided_at',
        'invoice_id',
        'term_starts_at',
        'term_ends_at',
    ];

    protected function casts(): array
    {
        return [
            'term_starts_at' => 'datetime',
            'term_ends_at' => 'datetime',
            'decided_at' => 'datetime',
        ];
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }
}
