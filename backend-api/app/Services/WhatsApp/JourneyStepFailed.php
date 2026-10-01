<?php

namespace App\Services\WhatsApp;

use DateTimeInterface;
use RuntimeException;

/**
 * Phase 7 Task 1 — raised ONLY inside a scheduler-resumed journey run
 * (WhatsAppJourneyEngine::resumeDueSession()) when a step could not be
 * completed, e.g. the unified WhatsApp driver refused or failed a send.
 * resumeDueSession() turns it into a retry with backoff, or into
 * status = 'failed' with last_error once attempts run out.
 *
 * Phase 7 Task 5: thrown on the immediate (inbound / manual test) path
 * too — a send that did not go out, or a save_lead whose CRM write failed.
 * There WhatsAppJourneyEngine::runImmediate() parks the session as
 * 'waiting' at the failed node so the same resume/retry machinery takes
 * over; nothing downstream of the failed node runs.
 *
 * Phase 7 Task 7: carries its failure category (JourneyExecutionRecorder::
 * CATEGORIES) for the execution history; the message is unchanged.
 */
class JourneyStepFailed extends RuntimeException
{
    /**
     * Phase 7 Task 8: $retryable = false marks a refusal no retry can fix
     * (JourneySendGate: suspended account / no subscription); the engine
     * then fails the session at once instead of scheduling a retry.
     *
     * Phase 8 Task 7: $notBefore (optional) — the earliest moment a retry can
     * succeed (a Journey AI step whose metered operation is still marked
     * running must not be retried before that operation can be abandoned).
     * The engine schedules the retry at the later of its own backoff and
     * this; nothing else about the retry model changes.
     */
    public function __construct(string $message = '', public readonly string $category = 'internal_error', public readonly bool $retryable = true, public readonly ?DateTimeInterface $notBefore = null)
    {
        parent::__construct($message);
    }
}
