<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Phase 8 Task 15 — "Manage All APIs": a named, reusable API connection
 * (base URL + headers + query parameters) owned by ONE account. An `api`
 * node's `data.apiConnectionId` references a row of this table instead
 * of re-entering the same endpoint/credentials in every node that calls
 * it — the same "server-side reference, never a secret in the node's own
 * config" pattern the `rag` node's `knowledgeBaseId` and the `agent`
 * node's `registeredAgentId` already use.
 *
 * Always looked up through forAccount(): an id from a request is never
 * trusted alone (WhatsAppFlowController::assertApiConnectionsOwned()
 * checks this on every journey save, mirroring assertKnowledgeBasesOwned()
 * exactly).
 *
 * `headers` / `query` are stored EXACTLY as JourneySecrets already stores
 * an `api` node's own header/query pairs: a credential-named entry's
 * value is `JourneySecrets::PREFIX . Crypt::encryptString(...)`; every
 * other entry (e.g. Content-Type) stays plaintext. See
 * JourneyApiConnectionSecrets for the protect/mask helpers that apply
 * this — this model never encrypts/masks on its own; the controller
 * calls those helpers explicitly on write and read, the same division of
 * responsibility JourneySecrets already has with WhatsAppFlowController.
 */
class JourneyApiConnection extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'headers' => 'array',
            'query' => 'array',
        ];
    }

    public function scopeForAccount(Builder $query, int $accountId): Builder
    {
        return $query->where('account_id', $accountId);
    }
}
