<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Phase 3 Task 5 -- the Eloquent model for the api_idempotency_keys
 * table, which was created by the
 * 2026_09_19_150000_create_api_idempotency_keys_table migration but had
 * no model, and no reader or writer anywhere in app/ (verified by
 * repo-wide grep for both the table name and the ApiIdempotency class
 * that migration's docblock refers to -- neither existed). The table was
 * therefore live in the schema while providing zero actual protection:
 * an external caller retrying a send after a network timeout got a
 * second outbound WhatsApp message. This model plus
 * EnsureIdempotentApiRequest are the readers/writers that migration was
 * always waiting for; the migration itself is UNCHANGED, and no new
 * migration was added, since the table and its
 * unique(['account_id','idempotency_key']) index already exist exactly
 * as this feature needs them.
 *
 * A row is never serialized into any API response -- it is read only by
 * the middleware. response_body is nonetheless hidden as defense in
 * depth (the same convention ApiKey uses for its hashes), so a future
 * code path that returns this model directly cannot leak one caller's
 * stored response envelope.
 */
class ApiIdempotencyKey extends Model
{
    /**
     * Claimed, but the operation has not yet reached a safe, replayable
     * completion point. A row in this state is never replayed -- a
     * matching retry is refused instead, because replaying a response
     * that was never produced would be a lie, and re-running the
     * operation would risk the duplicate send this whole mechanism
     * exists to prevent.
     */
    public const STATUS_PROCESSING = 'processing';

    /**
     * The operation actually sent/queued the message and returned a 2xx;
     * response_status/response_body hold exactly what this API already
     * returned to this same caller, and are what a matching retry
     * replays verbatim.
     */
    public const STATUS_COMPLETED = 'completed';

    protected $fillable = [
        'account_id',
        'idempotency_key',
        'request_fingerprint',
        'status',
        'response_status',
        'response_body',
    ];

    /** @var list<string> */
    protected $hidden = [
        'response_body',
        'request_fingerprint',
    ];

    protected function casts(): array
    {
        return [
            'response_status' => 'integer',
        ];
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    public function isCompleted(): bool
    {
        return $this->status === self::STATUS_COMPLETED;
    }
}
