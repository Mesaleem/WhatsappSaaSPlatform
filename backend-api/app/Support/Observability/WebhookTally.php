<?php

namespace App\Support\Observability;

/**
 * Phase 12 Task 3 — per-request counters for Meta inbound observability. Registered `scoped`, so it is reset
 * for every request/job. Pure counting: no I/O, no effect on webhook behaviour.
 */
final class WebhookTally
{
    public int $statuses = 0;

    public int $messages = 0;

    /** Redeliveries the existing claim/gate logic skipped (delivered-status claim, inbound event gate). */
    public int $duplicates = 0;

    /** Inbound messages deferred because the conversation was busy. */
    public int $busy = 0;
}
