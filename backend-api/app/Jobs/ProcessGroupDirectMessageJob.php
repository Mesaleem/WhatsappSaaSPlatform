<?php

namespace App\Jobs;

use App\Models\Account;
use App\Models\ContactGroup;
use App\Models\GroupDispatchRecipient;
use App\Models\MessageDispatchLog;
use App\Services\PaymentAlerts\PaymentAlertDispatcher;
use App\Services\WhatsApp\WhatsAppEngineFactory;
use App\Support\PhoneNumberNormalizer;
use App\Support\WhatsAppMediaPayloadBuilder;
use App\Services\Access\ProviderCapabilityService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

/**
 * Developer API Platform for WhatsApp Group Creation & Unified
 * Messaging -- the async half of GroupDirectMessageDispatcher::dispatch(),
 * mirroring ProcessGroupDispatchJob's structure exactly (same
 * native-single-send vs internal_segment-fan-out-with-anti-ban-jitter
 * split, same no-refund-on-partial-failure policy, same
 * resolveGroupDispatch() lifecycle) with raw text/media content sent
 * as-is to every recipient instead of a per-recipient rendered template
 * -- there is no template here to personalize per member.
 */
class ProcessGroupDirectMessageJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    /**
     * Phase 5 fix P5-3 — same bounded-run contract as
     * ProcessGroupDispatchJob: one slice never outlives this, and a large
     * group continues in further slices (continueInNextSlice()).
     */
    public int $timeout = MessageDispatchLog::GROUP_JOB_TIMEOUT_SECONDS;

    /** A timed-out run is failed (not retried) and reaches failed(), which settles the batch. */
    public bool $failOnTimeout = true;

    /**
     * @param array<string, mixed> $content Same shape DirectMessageDispatcher::dispatch() takes for this $messageType.
     */
    public function __construct(
        public readonly int $dispatchLogId,
        public readonly string $messageType,
        public readonly array $content,
        public readonly ?int $apiKeyId = null,
    ) {
    }

    public function handle(): void
    {
        $log = MessageDispatchLog::find($this->dispatchLogId);

        if (! $log) {
            Log::warning("ProcessGroupDirectMessageJob: message_dispatch_logs#{$this->dispatchLogId} no longer exists.");

            return;
        }

        // A cheap early exit only — NOT the duplicate-execution guard.
        if ($log->status !== 'queued') {
            return;
        }

        // Phase 5 fix P5-3 — the atomic claim; see ProcessGroupDispatchJob.
        $token = (string) Str::uuid();

        if (! $log->claimGroupDispatch($token)) {
            Log::info('ProcessGroupDirectMessageJob: batch is already claimed or settled; this run does nothing.', [
                'dispatch_log_id' => $log->id,
            ]);

            return;
        }

        try {
            $this->process($log, $token);
        } catch (Throwable $e) {
            Log::error('ProcessGroupDirectMessageJob failed unexpectedly.', [
                'dispatch_log_id' => $log->id,
                'exception' => $e->getMessage(),
            ]);

            // Phase 5 fix P5-3 — settle: refund = reserved - delivered, where
            // delivered is read from the persisted recipient rows.
            $log->settleGroupDispatchFromRecipients('Internal error: '.$e->getMessage());
        }
    }

    /**
     * Phase 5 fix P5-3 — timeout / queue-level failure. Settles the batch
     * from its recipient rows; idempotent (locked 'queued'-only transition).
     */
    public function failed(?Throwable $exception = null): void
    {
        $log = MessageDispatchLog::find($this->dispatchLogId);

        $log?->settleGroupDispatchFromRecipients(
            'Group job failed before completing: '.($exception?->getMessage() ?? 'unknown error'),
        );
    }

    private function process(MessageDispatchLog $log, string $token): void
    {
        $group = ContactGroup::find($log->group_id);

        if (! $group) {
            $this->resolveAllFailed($log, 'Contact group no longer exists.');

            return;
        }

        if ($group->isNative()) {
            $this->processNativeGroup($log, $group, $token);

            return;
        }

        // Phase 5 fix P5-1 — exactly the recipients the reservation froze
        // (GroupDispatchRecipient), never the live membership: a member added
        // since is not sent; one removed since is resolved as a failure below.
        $members = GroupDispatchRecipient::recipientsFor($log);

        if ($members->isEmpty()) {
            $this->resolveAllFailed($log, 'Group had no members left by the time this batch was processed.');

            return;
        }

        $account = Account::with(['currentSubscription', 'whatsAppSession'])->find($log->account_id);

        if (! $account) {
            $this->resolveAllFailed($log, 'Account no longer exists.');

            return;
        }

        if (PaymentAlertDispatcher::isWhatsAppDisconnected($account)) {
            $this->resolveAllFailed($log, 'WhatsApp account was disconnected before this batch could be processed.');

            return;
        }

        try {
            $driver = WhatsAppEngineFactory::make($account);
        } catch (RuntimeException $e) {
            $this->resolveAllFailed($log, $e->getMessage());

            return;
        }

        $engineType = $account->currentSubscription?->engine_type;
        // Phase 5 fix P5-3 — slicing; see ProcessGroupDispatchJob::process().
        // Still the frozen P5-1 list; already-attempted recipients skipped.
        $recorded = $log->recordedGroupRecipientReferenceIds(MessageDispatchLog::REFERENCE_TYPE_GROUP_MEMBER);
        $sliceStartedAt = now()->getTimestamp();
        $attemptedThisSlice = 0;
        $paceNextSend = $recorded !== [];

        foreach ($members as $member) {
            if (isset($recorded[(int) $member->id])) {
                continue;
            }

            if ($attemptedThisSlice > 0
                && now()->getTimestamp() - $sliceStartedAt >= MessageDispatchLog::GROUP_JOB_SLICE_BUDGET_SECONDS) {
                $this->continueInNextSlice($log, $token);

                return;
            }

            // Phase 5 fix P5-1 — reserved, but no longer in the group: not
            // sent, recorded as a failed recipient, refunded at resolution.
            if ($member->removed) {
                if (! $this->stillOwns($log, $token)) {
                    return;
                }

                $attemptedThisSlice++;

                MessageDispatchLog::recordGroupRecipient(
                    $log,
                    (string) $member->phone_number,
                    MessageDispatchLog::REFERENCE_TYPE_GROUP_MEMBER,
                    (int) $member->id,
                    success: false,
                    engineType: $engineType,
                    errorReason: 'Removed from the group after this batch was queued; not sent.',
                    hasMedia: $this->messageType === 'media',
                    mediaUrl: $this->mediaUrl(),
                );

                continue;
            }

            $normalizedPhone = PhoneNumberNormalizer::normalize($member->phone_number);

            if ($normalizedPhone === '') {
                if (! $this->stillOwns($log, $token)) {
                    return;
                }

                $attemptedThisSlice++;

                // Phase 5 Task 5 -- see ProcessGroupDispatchJob's twin
                // comment: an unsendable number is still a charged
                // attempt and gets its own audit row.
                MessageDispatchLog::recordGroupRecipient(
                    $log,
                    (string) $member->phone_number,
                    MessageDispatchLog::REFERENCE_TYPE_GROUP_MEMBER,
                    (int) $member->id,
                    success: false,
                    engineType: $engineType,
                    errorReason: "Recipient phone number '{$member->phone_number}' is not a valid number after normalization.",
                    hasMedia: $this->messageType === 'media',
                    mediaUrl: $this->mediaUrl(),
                );

                continue;
            }

            // Anti-ban jitter between consecutive provider sends.
            if ($paceNextSend) {
                sleep(random_int(3, 8));
            }

            if (! $this->stillOwns($log, $token)) {
                return;
            }

            $attemptedThisSlice++;
            $paceNextSend = true;

            $result = $this->send($driver, $engineType, $normalizedPhone);

            // Phase 5 Task 5 -- per-recipient audit row. Same rules as
            // ProcessGroupDispatchJob: the provider's own id verbatim
            // or null, never fabricated, and no quota operation of any
            // kind here. (P5-3: settlement counts it as delivered.)
            MessageDispatchLog::recordGroupRecipient(
                $log,
                $normalizedPhone,
                MessageDispatchLog::REFERENCE_TYPE_GROUP_MEMBER,
                (int) $member->id,
                success: ! empty($result['success']),
                engineType: $engineType,
                gatewayMessageId: $result['message_id'] ?? null,
                errorReason: $result['error'] ?? 'The WhatsApp engine rejected the message.',
                hasMedia: $this->messageType === 'media',
                mediaUrl: $this->mediaUrl(),
            );
        }

        $this->resolve($log);
    }

    private function processNativeGroup(MessageDispatchLog $log, ContactGroup $group, string $token): void
    {
        $account = Account::with(['currentSubscription', 'whatsAppSession'])->find($log->account_id);

        if (! $account) {
            $this->resolveAllFailed($log, 'Account no longer exists.');

            return;
        }

        if (! $group->isSyncedNativeGroup()) {
            $this->resolveAllFailed($log, 'This WhatsApp group is no longer synced (pending or failed).');

            return;
        }

        // Phase 5 Task 2 -- provider_capabilities is authoritative for
        // this rule when it states it; the 'qr' literal now lives only
        // in ProviderCapabilityService::supportsNativeWhatsAppGroups().
        if (! app(ProviderCapabilityService::class)->supportsNativeWhatsAppGroups($account->currentSubscription?->engine_type)) {
            $this->resolveAllFailed($log, 'This account is no longer on the QR (Baileys) engine.');

            return;
        }

        try {
            $driver = WhatsAppEngineFactory::make($account);
        } catch (RuntimeException $e) {
            $this->resolveAllFailed($log, $e->getMessage());

            return;
        }

        // Phase 5 fix P5-3 — never a second send into the group.
        if (isset($log->recordedGroupRecipientReferenceIds(MessageDispatchLog::REFERENCE_TYPE_NATIVE_GROUP)[(int) $group->id])) {
            $this->resolve($log);

            return;
        }

        if (! $this->stillOwns($log, $token)) {
            return;
        }

        $result = $this->send($driver, $account->currentSubscription?->engine_type, $group->wa_group_jid);

        $nativeSucceeded = ! empty($result['success']);

        // Phase 5 Task 5 -- a native group batch is ONE send to the group
        // JID, so it gets exactly one recipient row, keyed on the group
        // itself ('group_native') rather than on a member. The JID is
        // recorded as the recipient, which is literally where the message
        // went.
        MessageDispatchLog::recordGroupRecipient(
            $log,
            (string) $group->wa_group_jid,
            MessageDispatchLog::REFERENCE_TYPE_NATIVE_GROUP,
            (int) $group->id,
            success: $nativeSucceeded,
            engineType: $account->currentSubscription?->engine_type,
            gatewayMessageId: $result['message_id'] ?? null,
            errorReason: $result['error'] ?? 'The QR engine rejected the message.',
            hasMedia: $this->messageType === 'media',
            mediaUrl: $this->mediaUrl(),
        );

        if ($nativeSucceeded) {
            $this->resolve($log);

            return;
        }

        $errorMessage = $result['error'] ?? 'The QR engine rejected the message.';

        $this->resolve($log, $errorMessage);

        // Same disclosed sync_status-flip-on-send-failure policy as
        // ProcessGroupDispatchJob::processNativeGroup() -- see that
        // method's docblock for the full rationale and its own disclosed
        // uncertainty about which failures Baileys actually reports.
        $group->forceFill([
            'sync_status' => ContactGroup::SYNC_STATUS_FAILED,
            'sync_error' => "A message to this group failed: {$errorMessage}. If the WhatsApp group was deleted or this account was removed from it, delete and recreate it below.",
        ])->save();
    }

    /** Phase 5 Task 5 -- the attached file's URL, or null for a text send. */
    private function mediaUrl(): ?string
    {
        return $this->messageType === 'media' ? ($this->content['url'] ?? null) : null;
    }

    /** @return array{success: bool, error?: string, message_id?: string} */
    private function send($driver, ?string $engineType, string $to): array
    {
        if ($this->messageType === 'text') {
            return $driver->sendMessage($to, (string) ($this->content['body'] ?? ''), []);
        }

        [$driverMessage, $metaData] = WhatsAppMediaPayloadBuilder::build($engineType, $this->content);

        return $driver->sendMessage($to, $driverMessage, $metaData);
    }

    /**
     * Phase 5 Task 4 -- the $memberCount parameter is gone: the failure
     * count is now derived from the reservation by resolve() above, so a
     * caller can no longer pass a number that disagrees with what was
     * actually charged (this method used to be handed `1` on some
     * branches and `$members->count()` on others for the same batch).
     * The reason also rides INSIDE resolveGroupDispatch()'s guarded
     * transaction now; it used to be a second, unguarded write that
     * would still mutate a row the guard had just declined to resolve.
     */
    private function resolveAllFailed(MessageDispatchLog $log, string $reason): void
    {
        $this->resolve($log, $reason);
    }
    /**
     * Phase 5 Task 4 -- ONE resolution point, and the reason the failure
     * count is DERIVED rather than tallied.
     *
     * The reservation debited exactly $log->recipient_count credits
     * (MessageQuotaService::reserve(), from the dispatcher). The refund
     * must therefore be "reserved minus actually delivered", not "members
     * this job happened to attempt and fail" -- those two numbers are the
     * same in the normal case and diverge whenever group membership
     * changed between enqueue and run. If three members were removed in
     * that window, the tenant was still charged for them and nothing was
     * ever sent to them, so they are unsuccessful recipients and their
     * credits are owed back; tallying attempts alone would silently keep
     * that money.
     *
     * This also makes success_count + failure_count == recipient_count a
     * structural invariant of every resolved group row, which the old
     * per-branch counts (1 here, $members->count() there) did not hold --
     * a drift the Phase 5 Task 1 audit called out.
     *
     * max(0, ...) keeps the count from going negative. Since Phase 5 fix
     * P5-1 the job iterates the recipient list frozen at reservation
     * (GroupDispatchRecipient), so members ADDED after reservation are
     * never sent and cannot push attempts above what was reserved; only
     * batches queued before that fix (no frozen rows) use the live
     * membership, capped at recipient_count.
     */
    private function resolve(MessageDispatchLog $log, ?string $reason = null): bool
    {
        // Phase 5 fix P5-3 — delivered is read from the persisted recipient
        // rows (all slices), then the same resolveGroupDispatch() refunds
        // reserved - delivered once.
        return $log->settleGroupDispatchFromRecipients($reason);
    }

    /** Phase 5 fix P5-3 — heartbeat + "is this batch still mine and still queued?" */
    private function stillOwns(MessageDispatchLog $log, string $token): bool
    {
        if ($log->heartbeatGroupDispatch($token)) {
            return true;
        }

        Log::warning('ProcessGroupDirectMessageJob: lost ownership of the batch (settled or claimed elsewhere); stopping without sending.', [
            'dispatch_log_id' => $log->id,
        ]);

        return false;
    }

    /** Phase 5 fix P5-3 — hand the batch back and queue the next slice. */
    private function continueInNextSlice(MessageDispatchLog $log, string $token): void
    {
        if (! $log->releaseGroupDispatchClaim($token)) {
            return;
        }

        static::dispatch($this->dispatchLogId, $this->messageType, $this->content, $this->apiKeyId)
            ->onConnection($this->connection)
            ->onQueue($this->queue);
    }

}
