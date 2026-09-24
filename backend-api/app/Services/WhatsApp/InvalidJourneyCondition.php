<?php

namespace App\Services\WhatsApp;

use InvalidArgumentException;

/**
 * Phase 7 Task 4 — a condition that cannot be evaluated safely (unknown
 * operator, non-numeric value for a numeric operator, malformed rule list,
 * ambiguous branch). The engine never guesses past one: it fails the
 * session with this message as last_error and sends nothing further.
 */
class InvalidJourneyCondition extends InvalidArgumentException
{
}
