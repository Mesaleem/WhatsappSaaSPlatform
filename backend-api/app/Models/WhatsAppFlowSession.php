<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Module 5 — No-Code WhatsApp Journey Builder. See the creating
 * migration's docblock for the full status/context_data contract.
 */
class WhatsAppFlowSession extends Model
{
    /**
     * Journey Builder Table Name Fix (2026-09-16) — same root cause as
     * WhatsAppFlow::$table above: Eloquent's default derivation of
     * 'WhatsAppFlowSession' -> 'whats_app_flow_sessions' does not match
     * the creating migration's actual table name, 'whatsapp_flow_sessions'
     * (2026_09_11_200001_create_whatsapp_flow_sessions_table.php). Fixed
     * for consistency and to pre-empt the identical "table doesn't
     * exist" error the moment a journey session is created/looked up
     * (WhatsAppJourneyEngine::findActive()) — no schema change, no
     * migration needed.
     */
    protected $table = 'whatsapp_flow_sessions';

    public const STATUS_ACTIVE = 'active';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_EXPIRED = 'expired';

    /**
     * Phase 7 Task 1 — temporal backbone. See the
     * 2026_09_24_100000 migration and WhatsAppJourneyEngine::resumeDueSession().
     *   waiting    parked on a `delay` node until wait_until; owned by the
     *              scheduler, NOT by inbound messages (they fall through to
     *              chatbot rules and never start a second journey).
     *   failed     a resume step kept failing; last_error says why.
     *   cancelled  stopped by the tenant; never resumed.
     */
    public const STATUS_WAITING = 'waiting';

    public const STATUS_FAILED = 'failed';

    public const STATUS_CANCELLED = 'cancelled';

    /**
     * Phase 7 Task 1.6 — the account lost Journey entitlement
     * (JourneyRuntimeEntitlement) while this session was open. Nothing
     * executes, nothing is deleted: current_node_id / context_data /
     * attempts are kept, and last_error says why. Not a failure.
     *
     * Where it goes back to when entitlement returns is encoded by the
     * session's own timer, an invariant the engine already keeps:
     *   wait_until NOT NULL → it was (or was resuming from) a delay → 'waiting'
     *                          (wait_until is set to the block time, so it is
     *                          due at once and continues from its checkpoint)
     *   wait_until NULL     → it was awaiting a question reply → 'active'
     */
    public const STATUS_BLOCKED = 'blocked';

    /** Statuses from which a session can still do something — the only ones that can be cancelled. */
    public const OPEN_STATUSES = [self::STATUS_ACTIVE, self::STATUS_WAITING, self::STATUS_BLOCKED];

    /** Phase 7 Task 9 — a session in one of these never changes status again. */
    public const TERMINAL_STATUSES = [self::STATUS_COMPLETED, self::STATUS_FAILED, self::STATUS_EXPIRED, self::STATUS_CANCELLED];

    /**
     * Phase 7 Task 9 — THE session state machine (from => allowed to). Every
     * write in WhatsAppJourneyEngine is a conditional UPDATE guarded by its
     * `from` set (transition(), cancelSession(), restoreBlocked(), the
     * resume claim, recoverInterruptedRuns()), so a write racing another
     * one can only ever perform a transition listed here.
     *
     *   active   → waiting    delay parked; immediate-path retry scheduled;
     *                         interrupted immediate run recovered (scheduler)
     *            → blocked    entitlement lost (inbound path)
     *            → completed / failed / expired / cancelled
     *            (active → active: checkpoint / question pause, no status change)
     *   waiting  → active     question reached by a resumed run
     *            → blocked    entitlement lost at resume / mid-run
     *            → completed / failed / expired / cancelled
     *            (waiting → waiting: claim lease, retry backoff, checkpoint)
     *   blocked  → waiting    entitlement restored, it had a timer
     *            → active     entitlement restored, it was awaiting a reply
     *            → cancelled
     *   completed / failed / expired / cancelled: terminal, no way out.
     *
     * Who acts on which state: inbound messages reach only `active`
     * (continue) and `blocked` (restore, when entitled); the scheduler
     * reaches only due `waiting` (claim), `blocked` (restore) and
     * interrupted `active` (recovery); operators reach any open state
     * (cancel) and, through a manual test, replace the phone's open session.
     */
    public const TRANSITIONS = [
        self::STATUS_ACTIVE => [self::STATUS_ACTIVE, self::STATUS_WAITING, self::STATUS_BLOCKED, self::STATUS_COMPLETED, self::STATUS_FAILED, self::STATUS_EXPIRED, self::STATUS_CANCELLED],
        self::STATUS_WAITING => [self::STATUS_WAITING, self::STATUS_ACTIVE, self::STATUS_BLOCKED, self::STATUS_COMPLETED, self::STATUS_FAILED, self::STATUS_EXPIRED, self::STATUS_CANCELLED],
        self::STATUS_BLOCKED => [self::STATUS_WAITING, self::STATUS_ACTIVE, self::STATUS_CANCELLED],
        self::STATUS_COMPLETED => [],
        self::STATUS_FAILED => [],
        self::STATUS_EXPIRED => [],
        self::STATUS_CANCELLED => [],
    ];

    public static function canTransition(string $from, string $to): bool
    {
        return in_array($to, self::TRANSITIONS[$from] ?? [], true);
    }

    /**
     * Phase 7 Task 9 — defence in depth for Eloquent saves: a model whose
     * loaded status is terminal may not be saved with a different status.
     * (The engine's own writes are conditional UPDATEs and never rely on
     * this; it catches a future caller that would forceFill()->save().)
     */
    protected static function booted(): void
    {
        static::updating(function (self $session) {
            $from = (string) $session->getOriginal('status');

            if ($session->isDirty('status') && ! self::canTransition($from, (string) $session->status)) {
                throw new \LogicException("Journey session #{$session->id}: illegal status change {$from} → {$session->status}.");
            }
        });
    }

    protected $fillable = [
        'account_id',
        'flow_id',
        'flow_version_id',
        'phone_number',
        'current_node_id',
        'context_data',
        'status',
        'wait_until',
        'attempts',
        'last_error',
        'last_interaction_at',
    ];

    protected function casts(): array
    {
        return [
            'context_data' => 'array',
            'last_interaction_at' => 'datetime',
            'wait_until' => 'datetime',
            'attempts' => 'integer',
        ];
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    public function flow(): BelongsTo
    {
        return $this->belongsTo(WhatsAppFlow::class, 'flow_id');
    }

    /** Phase 7 Task 2 — the immutable graph this session runs on. */
    public function flowVersion(): BelongsTo
    {
        return $this->belongsTo(WhatsAppFlowVersion::class, 'flow_version_id');
    }

    public function scopeForAccount(Builder $query, int $accountId): Builder
    {
        return $query->where('account_id', $accountId);
    }

    /**
     * The one active session (if any) for a phone number under this
     * account — see the creating migration's docblock on why this is an
     * application-level "at most one" invariant, not a DB constraint.
     */
    public static function findActive(int $accountId, string $phoneNumber): ?self
    {
        return self::query()
            ->forAccount($accountId)
            ->where('phone_number', $phoneNumber)
            ->where('status', self::STATUS_ACTIVE)
            ->latest('id')
            ->first();
    }

    /**
     * Phase 7 Task 1 — does this phone have a journey parked on a delay?
     * Used so an inbound message never starts a SECOND journey while one
     * is waiting (the "one open journey per phone" invariant findActive()
     * already keeps for question pauses).
     */
    public static function hasWaiting(int $accountId, string $phoneNumber): bool
    {
        return self::query()
            ->forAccount($accountId)
            ->where('phone_number', $phoneNumber)
            ->where('status', self::STATUS_WAITING)
            ->exists();
    }

    /** Phase 7 Task 1.6 — the blocked session (if any) for a phone number under this account. */
    public static function findBlocked(int $accountId, string $phoneNumber): ?self
    {
        return self::query()
            ->forAccount($accountId)
            ->where('phone_number', $phoneNumber)
            ->where('status', self::STATUS_BLOCKED)
            ->latest('id')
            ->first();
    }

    public function setVariable(string $name, mixed $value): void
    {
        $context = $this->context_data ?? [];
        $context[$name] = $value;
        $this->context_data = $context;
    }

    public function getVariable(string $name): mixed
    {
        return ($this->context_data ?? [])[$name] ?? null;
    }
}
