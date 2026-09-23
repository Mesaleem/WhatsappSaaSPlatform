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

        if ($log->status !== 'queued') {
            return;
        }

        try {
            $this->process($log);
        } catch (Throwable $e) {
            Log::error('ProcessGroupDirectMessageJob failed unexpectedly.', [
                'dispatch_log_id' => $log->id,
                'exception' => $e->getMessage(),
            ]);

            // Phase 5 Task 4 -- deliberately NO refund here. When
            // process() throws, which recipients actually received their
            // message is unknown, and refunding the whole reservation
            // would hand back credits for messages that really went out.
            // The guarded write also stops this clobbering a row
            // process() had already resolved (reachable: the native path
            // resolves and then writes the group's sync_status).
            $log->failGroupDispatchWithoutRefund('Internal error: '.$e->getMessage());
        }
    }

    private function process(MessageDispatchLog $log): void
    {
        $group = ContactGroup::find($log->group_id);

        if (! $group) {
            $this->resolveAllFailed($log, 'Contact group no longer exists.');

            return;
        }

        if ($group->isNative()) {
            $this->processNativeGroup($log, $group);

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
        $successCount = 0;
        $failureCount = 0;
        $lastMemberIndex = $members->count() - 1;

        foreach ($members as $index => $member) {
            // Phase 5 fix P5-1 — reserved, but no longer in the group: not
            // sent, recorded as a failed recipient, refunded at resolution.
            if ($member->removed) {
                $failureCount++;

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
                $failureCount++;

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
            } else {
                $result = $this->send($driver, $engineType, $normalizedPhone);

                $succeeded = ! empty($result['success']);

                if ($succeeded) {
                    $successCount++;
                } else {
                    $failureCount++;
                }

                // Phase 5 Task 5 -- per-recipient audit row. Same rules as
                // ProcessGroupDispatchJob: the provider's own id verbatim
                // or null, never fabricated, and no quota operation of any
                // kind here.
                MessageDispatchLog::recordGroupRecipient(
                    $log,
                    $normalizedPhone,
                    MessageDispatchLog::REFERENCE_TYPE_GROUP_MEMBER,
                    (int) $member->id,
                    success: $succeeded,
                    engineType: $engineType,
                    gatewayMessageId: $result['message_id'] ?? null,
                    errorReason: $result['error'] ?? 'The WhatsApp engine rejected the message.',
                    hasMedia: $this->messageType === 'media',
                    mediaUrl: $this->mediaUrl(),
                );
            }

            if ($index !== $lastMemberIndex) {
                sleep(random_int(3, 8));
            }
        }

        // Phase 5 Task 4 -- resolve() derives the refund from what was
        // RESERVED, not from what this loop attempted, so a membership
        // change between enqueue and run cannot leave credits stranded.
        // The two numbers agreeing is the normal case; when they do not,
        // say so, because it is the only signal that the group changed
        // underneath a queued batch.
        $attempted = $successCount + $failureCount;
        $reserved = (int) ($log->recipient_count ?? 0);

        if ($attempted !== $reserved) {
            Log::info('Group dispatch attempted a different number of recipients than were reserved.', [
                'dispatch_log_id' => $log->id,
                'reserved' => $reserved,
                'attempted' => $attempted,
                'succeeded' => $successCount,
            ]);
        }

        $this->resolve($log, $successCount);
    }

    private function processNativeGroup(MessageDispatchLog $log, ContactGroup $group): void
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
            $this->resolve($log, 1);

            return;
        }

        $errorMessage = $result['error'] ?? 'The QR engine rejected the message.';

        $this->resolve($log, 0, $errorMessage);

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
        $this->resolve($log, 0, $reason);
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
    private function resolve(MessageDispatchLog $log, int $successCount, ?string $reason = null): bool
    {
        $reserved = (int) ($log->recipient_count ?? 0);

        return $log->resolveGroupDispatch($successCount, max(0, $reserved - $successCount), $reason);
    }

}
