<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A message scheduled for a later time. See the scheduled_messages migration. */
class ScheduledMessage extends Model
{
    public const PENDING = 'pending';
    public const SENT = 'sent';
    public const FAILED = 'failed';
    public const CANCELLED = 'cancelled';

    /** A template to one phone number. */
    public const KIND_TEMPLATE_INDIVIDUAL = 'template_individual';
    /** A template to a contact group. */
    public const KIND_TEMPLATE_GROUP = 'template_group';
    /** Plain text to one phone number ("no template" sends). */
    public const KIND_DIRECT_TEXT = 'direct_text';

    /** How far ahead a message may be scheduled. */
    public const MAX_DAYS_AHEAD = 90;

    protected $table = 'scheduled_messages';

    protected $fillable = [
        'account_id', 'api_key_id', 'source', 'kind', 'payload', 'send_at', 'status', 'attempts', 'last_error', 'sent_at',
    ];

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'send_at' => 'datetime',
            'sent_at' => 'datetime',
        ];
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }
}
