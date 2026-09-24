<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Phase 7 Task 7 — one append-only Journey execution event. Written only by
 * App\Services\WhatsApp\JourneyExecutionRecorder; never updated.
 *
 * VOCABULARY (EVENTS) — each row names its session, pinned version and node
 * wherever one applies:
 *   session_started      a run began (source inbound|test); inbound_event_id set for inbound
 *   session_resumed      the scheduler claimed a due session (attempt = claim number;
 *                        result delay_due|retry)
 *   session_waiting      parked on a delay (scheduled_for = when it is due)
 *   session_blocked      entitlement/module lost; state kept (error_category entitlement_blocked)
 *   session_restored     entitlement back; blocked → waiting/active (result = the status)
 *   session_completed    valid end (node = where it ended)
 *   session_expired      ended without success, always with error_category + message
 *   session_failed       terminal failure, always with error_category + message
 *   session_cancelled    cancelled by an operator
 *   node_started         a node with a side effect (a send or a lead write) is about to run
 *   node_succeeded       a node finished (result: sent|prompted|saved|next:<node>|dead_end)
 *   node_failed          a node failed (error_category, attempt)
 *   node_retry_scheduled a failed node will be retried (attempt, scheduled_for)
 *   reply_received       a reply reached a question (result answered|invalid)
 *   inbound_deduplicated a redelivered inbound message was skipped (inbound_event_id = the
 *                        original claim)
 *
 * ERROR CATEGORIES: see JourneyExecutionRecorder::CATEGORIES.
 *
 * `attempt` is the session's resume-claim counter at the time of the event:
 * 0 on the immediate path (inbound / manual test), n on the nth scheduler
 * claim of the current wait. A session fails after MAX_RESUME_ATTEMPTS.
 */
class JourneyExecutionEvent extends Model
{
    public const UPDATED_AT = null;

    public const SESSION_STARTED = 'session_started';

    public const SESSION_RESUMED = 'session_resumed';

    public const SESSION_WAITING = 'session_waiting';

    public const SESSION_BLOCKED = 'session_blocked';

    public const SESSION_RESTORED = 'session_restored';

    public const SESSION_COMPLETED = 'session_completed';

    public const SESSION_EXPIRED = 'session_expired';

    public const SESSION_FAILED = 'session_failed';

    public const SESSION_CANCELLED = 'session_cancelled';

    public const NODE_STARTED = 'node_started';

    public const NODE_SUCCEEDED = 'node_succeeded';

    public const NODE_FAILED = 'node_failed';

    public const NODE_RETRY_SCHEDULED = 'node_retry_scheduled';

    public const REPLY_RECEIVED = 'reply_received';

    public const INBOUND_DEDUPLICATED = 'inbound_deduplicated';

    public const EVENTS = [
        self::SESSION_STARTED, self::SESSION_RESUMED, self::SESSION_WAITING, self::SESSION_BLOCKED,
        self::SESSION_RESTORED, self::SESSION_COMPLETED, self::SESSION_EXPIRED, self::SESSION_FAILED,
        self::SESSION_CANCELLED, self::NODE_STARTED, self::NODE_SUCCEEDED, self::NODE_FAILED,
        self::NODE_RETRY_SCHEDULED, self::REPLY_RECEIVED, self::INBOUND_DEDUPLICATED,
    ];

    protected $table = 'journey_execution_events';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'details' => 'array',
            'scheduled_for' => 'datetime',
            'attempt' => 'integer',
        ];
    }

    public function scopeForAccount(Builder $query, int $accountId): Builder
    {
        return $query->where('account_id', $accountId);
    }
}
