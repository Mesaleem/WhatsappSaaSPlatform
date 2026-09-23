<?php

namespace App\Jobs;

use App\Services\WhatsApp\WhatsAppJourneyEngine;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Phase 7 Task 1 — resume ONE journey session parked on a delay.
 *
 * Dispatched by `journeys:resume-due` onto the 'database' connection's
 * 'journeys' queue (same pattern as SendWhatsAppTemplateJob on
 * 'whatsapp-bulk'), drained by the scheduled worker in routes/console.php.
 *
 * The job carries only the session id. It holds no state of its own: all
 * of it is on the whatsapp_flow_sessions row, and
 * WhatsAppJourneyEngine::resumeDueSession() CLAIMS that row atomically
 * before doing anything. So a duplicate, late or replayed job is a no-op
 * ('skipped'), and a lost job is harmless (the row is still due and the
 * next scan dispatches it again).
 *
 * $tries = 1: retries are owned by the session state machine (backoff,
 * attempts, 'failed' + last_error), not by Laravel's queue retry, so a
 * WhatsApp message is never re-sent by a blind job retry.
 *
 * ShouldBeUnique (per session, for one lease) only keeps the jobs table
 * from filling with copies while a row waits for a worker; correctness
 * does not depend on it.
 */
class ResumeJourneySessionJob implements ShouldQueue, ShouldBeUnique
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $uniqueFor = WhatsAppJourneyEngine::RESUME_LEASE_SECONDS;

    public function __construct(public readonly int $sessionId)
    {
    }

    public function uniqueId(): string
    {
        return (string) $this->sessionId;
    }

    public function handle(WhatsAppJourneyEngine $engine): void
    {
        $engine->resumeDueSession($this->sessionId);
    }
}
