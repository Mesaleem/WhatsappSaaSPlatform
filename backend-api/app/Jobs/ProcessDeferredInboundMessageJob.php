<?php

namespace App\Jobs;

use App\Services\Chatbot\ChatbotEngineService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Jobs\SyncJob;
use Illuminate\Queue\SerializesModels;

/**
 * Phase 5 fix P5-9 — an inbound WhatsApp message whose conversation was
 * busy (another worker held the InboundEventGate lease for the same
 * account + phone) when it arrived.
 *
 * The gate used to poll the lease for up to 10 s inside the webhook request
 * and then DROP the message. Now the request returns at once and this job
 * retries later, never waiting in-process: each run tries the lease once
 * (through the unchanged gate, via ChatbotEngineService); still busy →
 * the next attempt is queued with a short delay.
 *
 * Latency: as soon as a worker serves the `journeys` queue after the delay —
 * within about a minute with only the scheduled per-minute worker, about the
 * back-off delay with a continuously running one.
 *
 * Guarantees kept:
 *  - serialization: processing still happens only under the lease;
 *  - at most once: the event key is claimed inside the gate exactly as for
 *    an inline message, so a redelivery that was also deferred, or one that
 *    arrived after this message was processed, is a duplicate and skipped;
 *  - tenant isolation: account id, phone and provider are the ones the
 *    webhook resolved at intake, carried verbatim — never re-read from a
 *    request.
 *
 * $tries = 1 (a chain of fresh jobs, not a release): the pipeline catches
 * every exception itself, and a redelivered run past $tries is failed by
 * the queue rather than processed twice.
 */
class ProcessDeferredInboundMessageJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * Back-off alone before giving up ≈ 1+2+3+4 + 5 × 116 s ≈ 10 minutes (more
     * in wall-clock time, since each attempt also waits for a worker) — far
     * past InboundEventGate::LEASE_SECONDS (120 s), so a lease left behind
     * by a crashed worker has always expired well before the last attempt.
     */
    public const MAX_ATTEMPTS = 120;

    public const MAX_DELAY_SECONDS = 5;

    /**
     * The durable queue Journey delays already use (ResumeDueJourneySessions),
     * drained by the scheduled `queue:work database --queue=journeys`
     * (routes/console.php) — independent of QUEUE_CONNECTION, so a deferred
     * message survives even on a `sync` default connection.
     */
    public const CONNECTION = 'database';

    public const QUEUE = 'journeys';

    public int $tries = 1;

    public function __construct(
        public readonly int $accountId,
        public readonly string $senderPhone,
        public readonly string $incomingMessage,
        public readonly ?array $referral,
        public readonly ?string $provider,
        public readonly ?string $eventKey,
        public readonly int $attempt,
    ) {
    }

    /** Short linear back-off: 1, 2, 3, 4, then 5 s between attempts. */
    public static function delayBeforeAttempt(int $attempt): int
    {
        return max(1, min($attempt, self::MAX_DELAY_SECONDS));
    }

    public function handle(ChatbotEngineService $chatbot): void
    {
        $chatbot->handleDeferredInboundMessage(
            $this->accountId,
            $this->senderPhone,
            $this->incomingMessage,
            $this->referral,
            $this->provider,
            $this->eventKey,
            $this->attempt,
            $this->job instanceof SyncJob,
        );
    }
}
