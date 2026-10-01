<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Phase 8 Task 9 — one retrievable passage of one version of a document.
 * `embedding` (packed float32, unit length — DatabaseVectorStore) is never
 * serialized.
 */
class KnowledgeChunk extends Model
{
    protected $guarded = ['id'];

    protected $hidden = ['embedding'];

    protected function casts(): array
    {
        return [
            'document_version' => 'integer',
            'chunk_index' => 'integer',
            'char_start' => 'integer',
            'char_end' => 'integer',
            'embedding_dimensions' => 'integer',
            'embedded_at' => 'datetime',
        ];
    }

    public function scopeForAccount(Builder $query, int $accountId): Builder
    {
        return $query->where('account_id', $accountId);
    }
}
