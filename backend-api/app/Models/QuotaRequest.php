<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use App\Traits\LogsActivity;

class QuotaRequest extends Model
{
    use LogsActivity;

    /** IMPLEMENT: Dynamic Route Master with Super-Admin Bypass & Global Audit Tracking — module label shown in the Activity Logs UI. */
    protected string $auditModuleName = 'Quota Requests';
    protected $fillable = [
        'account_id',
        'requested_by',
        'requested_extra_messages',
        'requested_topup_amount',
        'reason',
        'status',
        'reviewed_by',
        'reviewed_at',
        'invoice_id',
    ];

    protected function casts(): array
    {
        return [
            'requested_extra_messages' => 'integer',
            // Per-Message Wallet Top-Up Requests: exactly one of this
            // and requested_extra_messages is ever non-null on a given
            // row (see this column's migration) -- null for a flat_quota
            // request, set for a per_message request.
            'requested_topup_amount' => 'decimal:2',
            'reviewed_at' => 'datetime',
        ];
    }

    /** True for a 'per_message' wallet top-up request; false for a flat_quota extra-messages request. */
    public function isWalletTopUp(): bool
    {
        return $this->requested_topup_amount !== null;
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    public function requestedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function reviewedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    public function isPending(): bool
    {
        return $this->status === 'pending';
    }
}
