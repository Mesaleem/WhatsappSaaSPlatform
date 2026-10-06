<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** One Excel/CSV upload sent in batches from the Send Notification page (see MessageBatchService). */
class MessageBatch extends Model
{
    public const SCHEDULED = 'scheduled';
    public const RUNNING = 'running';
    public const PAUSED = 'paused';
    public const STOPPED = 'stopped';
    public const COMPLETED = 'completed';

    /** Statuses a batch can still send from. */
    public const ACTIVE = [self::SCHEDULED, self::RUNNING];

    /** Chunk sizes the page offers; the cap matches BulkMessageCooldown::MAX_RECIPIENTS_PER_BATCH. */
    public const MIN_BATCH_SIZE = 10;
    public const MAX_BATCH_SIZE = 100;
    public const MAX_INTERVAL_MINUTES = 60;

    protected $fillable = [
        'account_id', 'created_by_user_id', 'template_id', 'sender_number_ids', 'variables', 'media_url', 'title', 'kind', 'source_filename',
        'total', 'batch_size', 'interval_minutes', 'status', 'scheduled_at', 'next_chunk_at', 'sent_count',
        'failed_count', 'stop_reason', 'stopped_at', 'completed_at',
    ];

    protected function casts(): array
    {
        return [
            'variables' => 'array',
            'sender_number_ids' => 'array',
            'scheduled_at' => 'datetime',
            'next_chunk_at' => 'datetime',
            'stopped_at' => 'datetime',
            'completed_at' => 'datetime',
            'total' => 'integer',
            'batch_size' => 'integer',
            'interval_minutes' => 'integer',
            'sent_count' => 'integer',
            'failed_count' => 'integer',
        ];
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(MessageBatchItem::class);
    }

    public function isActive(): bool
    {
        return in_array($this->status, self::ACTIVE, true);
    }
}
