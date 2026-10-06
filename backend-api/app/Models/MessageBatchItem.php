<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One phone number inside a batch. Status moves pending -> sent or failed, or pending -> cancelled when the batch stops. */
class MessageBatchItem extends Model
{
    public const PENDING = 'pending';
    /** Claimed by the runner, being sent right now. */
    public const SENDING = 'sending';
    public const SENT = 'sent';
    public const FAILED = 'failed';
    public const CANCELLED = 'cancelled';

    protected $fillable = ['message_batch_id', 'sequence', 'send_at', 'phone', 'sender_number_id', 'status', 'error', 'attempted_at'];

    protected function casts(): array
    {
        return [
            'attempted_at' => 'datetime',
            'send_at' => 'datetime',
        ];
    }

    public function batch(): BelongsTo
    {
        return $this->belongsTo(MessageBatch::class, 'message_batch_id');
    }
}
