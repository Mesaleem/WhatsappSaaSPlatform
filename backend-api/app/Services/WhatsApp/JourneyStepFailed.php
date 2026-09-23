<?php

namespace App\Services\WhatsApp;

use RuntimeException;

/**
 * Phase 7 Task 1 — raised ONLY inside a scheduler-resumed journey run
 * (WhatsAppJourneyEngine::resumeDueSession()) when a step could not be
 * completed, e.g. the unified WhatsApp driver refused or failed a send.
 * resumeDueSession() turns it into a retry with backoff, or into
 * status = 'failed' with last_error once attempts run out.
 *
 * Never thrown on the immediate (inbound-webhook) path: there, a failed
 * send is logged and the journey continues, exactly as before.
 */
class JourneyStepFailed extends RuntimeException
{
}
