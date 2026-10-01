<?php

namespace App\Services\Social\Publishing;

use RuntimeException;

/**
 * Phase 9 Task 3 — the provider could not be reached or did not answer.
 * The post may already exist at the provider (the request can time out
 * after the provider accepted it), so the application never re-sends it
 * automatically: it is recorded as an unknown outcome for the user to
 * check and retry explicitly.
 */
class PublishUnreachable extends RuntimeException
{
}
