<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Phase 8 Task 5 — one metered AI operation (see the create_ai_operations
 * migration). Written only by App\Services\Ai\Billing\MeteredAiService and
 * the `ai:settle-operations` command. Holds metadata only — never a prompt,
 * a response or a credential.
 */
class AiOperation extends Model
{
    public const STATUS_RUNNING = 'running';

    public const STATUS_FAILED = 'failed';

    public const STATUS_SUCCEEDED = 'succeeded'; // usage recorded, charge not yet settled

    public const STATUS_SETTLED = 'settled';

    public const STATUS_ABANDONED = 'abandoned'; // a run that never reported back; hold released, not charged

    /** A key in one of these may be retried (a new attempt). */
    public const RETRYABLE_STATUSES = [self::STATUS_FAILED, self::STATUS_ABANDONED];

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'usage_reported' => 'boolean',
            'completed_at' => 'datetime',
            'settled_at' => 'datetime',
            'credits_reserved' => 'integer',
            'credits_charged' => 'integer',
            'credits_uncharged' => 'integer',
            'input_tokens' => 'integer',
            'output_tokens' => 'integer',
            'attempt' => 'integer',
        ];
    }

    public function scopeForAccount(Builder $query, int $accountId): Builder
    {
        return $query->where('account_id', $accountId);
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    public function reservation(): BelongsTo
    {
        return $this->belongsTo(CreditReservation::class, 'reservation_id');
    }
}
