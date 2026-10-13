<?php

namespace App\Models;

use App\Traits\LogsActivity;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * CRM Sales Phase 5 — one reminder occurrence for one invoice (unique per invoice and
 * rule, so it can never be sent twice). Status: pending -> sent | skipped | failed |
 * awaiting_approval (when the tenant wants to approve each send).
 */
class CrmPaymentReminder extends Model
{
    protected $table = 'crm_payment_reminders';

    public const STATUS_PENDING = 'pending';
    public const STATUS_AWAITING_APPROVAL = 'awaiting_approval';
    public const STATUS_SENT = 'sent';
    public const STATUS_SKIPPED = 'skipped';
    public const STATUS_FAILED = 'failed';

    protected $fillable = ['account_id', 'crm_document_id', 'rule_key', 'scheduled_for', 'status', 'attempts', 'last_error', 'balance_at_send', 'sent_at', 'next_attempt_at'];

    protected function casts(): array
    {
        return [
            'account_id' => 'integer', 'crm_document_id' => 'integer', 'scheduled_for' => 'date', 'attempts' => 'integer',
            'balance_at_send' => 'decimal:2', 'sent_at' => 'datetime', 'next_attempt_at' => 'datetime',
        ];
    }

    public function document(): BelongsTo
    {
        return $this->belongsTo(CrmDocument::class, 'crm_document_id');
    }

    public function scopeForAccount(Builder $query, int $accountId): Builder
    {
        return $query->where('account_id', $accountId);
    }
}
