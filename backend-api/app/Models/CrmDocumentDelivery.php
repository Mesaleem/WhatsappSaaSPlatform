<?php

namespace App\Models;

use App\Traits\LogsActivity;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * CRM Sales Phase 4 — one attempt to send a document (or a payment reminder) and its
 * outcome. `sent` = the provider accepted it; `recorded` = staff sent it outside the
 * platform and said so; `failed` keeps the error. Never claims "delivered".
 */
class CrmDocumentDelivery extends Model
{
    protected $table = 'crm_document_deliveries';

    /** @var list<string> */
    public const CHANNELS = ['whatsapp', 'email', 'manual'];

    protected $fillable = [
        'account_id', 'crm_document_id', 'channel', 'purpose', 'recipient', 'status', 'provider_message_id', 'error',
        'sent_by_user_id', 'crm_payment_reminder_id', 'sent_at',
    ];

    protected function casts(): array
    {
        return ['account_id' => 'integer', 'crm_document_id' => 'integer', 'sent_by_user_id' => 'integer', 'crm_payment_reminder_id' => 'integer', 'sent_at' => 'datetime'];
    }

    public function sentBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'sent_by_user_id');
    }

    public function scopeForAccount(Builder $query, int $accountId): Builder
    {
        return $query->where('account_id', $accountId);
    }
}
