<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A request to change the number a WhatsApp slot holds. See the migration. */
class WhatsAppNumberChangeRequest extends Model
{
    public const REQUESTED = 'requested';
    public const APPROVED = 'approved';
    public const REJECTED = 'rejected';

    protected $table = 'whatsapp_number_change_requests';

    protected $fillable = [
        'account_id',
        'whatsapp_number_id',
        'old_phone',
        'new_phone',
        'reason',
        'status',
        'requested_by_user_id',
        'decided_by_user_id',
        'decision_note',
        'decided_at',
    ];

    protected function casts(): array
    {
        return ['decided_at' => 'datetime'];
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    public function number(): BelongsTo
    {
        return $this->belongsTo(WhatsAppNumber::class, 'whatsapp_number_id');
    }
}
