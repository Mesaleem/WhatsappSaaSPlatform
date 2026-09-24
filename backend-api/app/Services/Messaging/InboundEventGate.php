<?php

namespace App\Services\Messaging;

use App\Models\InboundMessageEvent;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Phase 7 Task 3 — the durable gate every inbound WhatsApp message passes
 * before the chatbot/Journey pipeline sees it (ChatbotEngineService).
 *
 * 1. SERIALIZE the conversation: take the (account, phone) lease in
 *    journey_conversation_locks with one conditional UPDATE — only a row
 *    whose lease is free or expired can be taken. Other messages from the
 *    same customer wait (polling) up to lockWaitMs, so they are processed
 *    one after another, each seeing the session state the previous one
 *    left. Other customers are never blocked.
 * 2. CLAIM the event: INSERT into inbound_message_events; the unique
 *    (account_id, provider, event_key) index makes the first insert win
 *    and every duplicate — redelivery, provider retry, a concurrent copy,
 *    any app instance — lose it. A lost claim means "already handled":
 *    nothing runs, nothing is sent, no lead is written, no quota is used.
 *    Claimed BEFORE processing, so an event is handled at most once
 *    (a crash mid-processing is not retried; duplicate sends are the
 *    worse failure for a messaging product).
 * 3. RUN the pipeline, stamp processed_at, release the lease.
 *
 * With no event key (an old qr-engine build that does not send the
 * Baileys message id) step 2 is skipped — there is no safe identity to
 * deduplicate on — but the conversation is still serialized.
 */
class InboundEventGate
{
    public const LEASE_SECONDS = 120;

    public const DEFAULT_LOCK_WAIT_MS = 10000;

    private const POLL_MS = 100;

    /**
     * @template T
     * @param callable(?int): T $process receives the claim id (null without an event key)
     * @return array{handled: bool, result: T|null, reason?: string, original_event_id?: int|null}
     */
    public function run(int $accountId, string $phone, string $provider, ?string $eventKey, callable $process): array
    {
        $owner = (string) Str::uuid();

        if (! $this->acquire($accountId, $phone, $owner)) {
            Log::error('InboundEventGate: conversation is busy; inbound message not processed.', ['account_id' => $accountId, 'provider' => $provider]);

            return ['handled' => false, 'result' => null, 'reason' => 'busy'];
        }

        try {
            $claimId = null;

            if ($eventKey !== null) {
                $claimId = $this->claim($accountId, $phone, $provider, $eventKey);

                if ($claimId === null) {
                    // Phase 7 Task 7 — the ORIGINAL claim, so the skip can be
                    // correlated with what that message did (one indexed read,
                    // duplicates only).
                    return ['handled' => false, 'result' => null, 'reason' => 'duplicate', 'original_event_id' => $this->claimedId($accountId, $provider, $eventKey)];
                }
            }

            // Phase 7 Task 7 — the claim id is handed to the processor for
            // correlation (null without an event key). A processor that takes
            // no argument is unaffected.
            $result = $process($claimId);

            if ($claimId !== null) {
                InboundMessageEvent::query()->whereKey($claimId)->update(['processed_at' => now()]);
            }

            return ['handled' => true, 'result' => $result];
        } finally {
            $this->release($accountId, $phone, $owner);
        }
    }

    /** @return int|null the new claim row id, or null if this event was already claimed */
    private function claim(int $accountId, string $phone, string $provider, string $eventKey): ?int
    {
        $inserted = DB::table('inbound_message_events')->insertOrIgnore([
            'account_id' => $accountId,
            'provider' => $provider,
            'event_key' => mb_substr($eventKey, 0, 191),
            'phone_number' => mb_substr($phone, 0, 32),
            'created_at' => now(),
        ]);

        if ($inserted !== 1) {
            return null;
        }

        return (int) DB::table('inbound_message_events')
            ->where('account_id', $accountId)->where('provider', $provider)->where('event_key', mb_substr($eventKey, 0, 191))
            ->value('id');
    }

    private function claimedId(int $accountId, string $provider, string $eventKey): ?int
    {
        $id = DB::table('inbound_message_events')
            ->where('account_id', $accountId)->where('provider', $provider)->where('event_key', mb_substr($eventKey, 0, 191))
            ->value('id');

        return $id === null ? null : (int) $id;
    }

    private function acquire(int $accountId, string $phone, string $owner): bool
    {
        $phone = mb_substr($phone, 0, 32);

        DB::table('journey_conversation_locks')->insertOrIgnore([
            'account_id' => $accountId, 'phone_number' => $phone, 'owner' => null, 'locked_until' => null,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $deadline = microtime(true) + ((int) config('journeys.inbound_lock_wait_ms', self::DEFAULT_LOCK_WAIT_MS)) / 1000;

        do {
            $now = now();

            $taken = DB::table('journey_conversation_locks')
                ->where('account_id', $accountId)
                ->where('phone_number', $phone)
                ->where(fn ($q) => $q->whereNull('owner')->orWhereNull('locked_until')->orWhere('locked_until', '<', $now))
                ->update(['owner' => $owner, 'locked_until' => $now->copy()->addSeconds(self::LEASE_SECONDS), 'updated_at' => $now]);

            if ($taken === 1) {
                return true;
            }

            if (microtime(true) >= $deadline) {
                return false;
            }

            usleep(self::POLL_MS * 1000);
        } while (true);
    }

    private function release(int $accountId, string $phone, string $owner): void
    {
        DB::table('journey_conversation_locks')
            ->where('account_id', $accountId)
            ->where('phone_number', mb_substr($phone, 0, 32))
            ->where('owner', $owner)
            ->update(['owner' => null, 'locked_until' => null, 'updated_at' => now()]);
    }
}
