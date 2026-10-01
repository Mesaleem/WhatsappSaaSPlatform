<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Phase 8 Task 9 — one source document of a knowledge base.
 *
 * LIFECYCLE (status):
 *   pending     accepted; version `version` waits to be processed
 *   processing  a worker holds it (processing_started_at = its lease)
 *   ready       version `version` is indexed (indexed_version = version)
 *   failed      the last processing of `version` failed (error_code /
 *               error_message are safe, never document text)
 *
 * `indexed_version` is the version retrieval uses — it survives a failed
 * re-processing, so a document that was searchable stays searchable.
 * `content` is hidden from serialization (lists/details show metadata only).
 */
class KnowledgeDocument extends Model
{
    public const STATUS_PENDING = 'pending';

    public const STATUS_PROCESSING = 'processing';

    public const STATUS_READY = 'ready';

    public const STATUS_FAILED = 'failed';

    public const SOURCE_TEXT = 'text';

    protected $guarded = ['id'];

    protected $hidden = ['content'];

    protected function casts(): array
    {
        return [
            'version' => 'integer',
            'indexed_version' => 'integer',
            'chunk_count' => 'integer',
            'content_chars' => 'integer',
            'processing_started_at' => 'datetime',
            'processed_at' => 'datetime',
        ];
    }

    public function scopeForAccount(Builder $query, int $accountId): Builder
    {
        return $query->where('account_id', $accountId);
    }

    public function knowledgeBase(): BelongsTo
    {
        return $this->belongsTo(KnowledgeBase::class);
    }
}
