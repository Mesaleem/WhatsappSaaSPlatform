<?php

namespace App\Services\WhatsApp;

use RuntimeException;

/**
 * Task 26 — a Code node script that cannot be parsed or evaluated safely
 * (syntax error, unknown identifier, a step/depth cap exceeded). The
 * engine never guesses past one: it fails the session with this message
 * as last_error and sends nothing further — same contract as
 * InvalidJourneyCondition for the two branching nodes.
 */
class JourneyCodeSandboxException extends RuntimeException
{
}
