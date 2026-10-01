<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Phase 8 Task 9 — a named collection of knowledge documents owned by ONE
 * account (the target account that pays for its embeddings). Always looked
 * up through forAccount(): an id from a request is never trusted alone.
 *
 * embedding_provider / embedding_model / embedding_dimensions are set by the
 * first document that finishes indexing and then bind every later document
 * and every search to the same embedding space (vectors from different
 * models are not comparable).
 */
class KnowledgeBase extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['embedding_dimensions' => 'integer'];
    }

    public function scopeForAccount(Builder $query, int $accountId): Builder
    {
        return $query->where('account_id', $accountId);
    }

    public function documents(): HasMany
    {
        // Same account by construction: the composite FK (knowledge_base_id, account_id).
        return $this->hasMany(KnowledgeDocument::class);
    }

    public function hasEmbeddingSpace(): bool
    {
        return $this->embedding_provider !== null && $this->embedding_model !== null && $this->embedding_dimensions !== null;
    }
}
