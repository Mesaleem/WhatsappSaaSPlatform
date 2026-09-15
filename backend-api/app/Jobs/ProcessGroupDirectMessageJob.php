<?php

namespace App\Jobs;

use App\Models\Account;
use App\Models\ContactGroup;
use App\Models\ContactGroupMember;
use App\Models\MessageDispatchLog;
use App\Services\PaymentAlerts\PaymentAlertDispatcher;
use App\Services\WhatsApp\WhatsAppEngineFactory;
use App\Support\PhoneNumberNormalizer;
use App\Support\WhatsAppMediaPayloadBuilder;
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

            $log->forceFill([
                'status' => 'failed',
                'sent_at' => null,
                'error_reason' => 'Internal error: '.$e->getMessage(),
            ])->save();
        }
    }

    private function process(MessageDispatchLog $log): void
    {
        $group = ContactGroup::find($log->group_id);

        if (! $group) {
            $this->resolveAllFailed($log, 1, 'Contact group no longer exists.');

            return;
        }

        if ($group->isNative()) {
            $this->processNativeGroup($log, $group);

            return;
        }

        $members = ContactGroupMember::where('group_id', $log->group_id)->get();

        if ($members->isEmpty()) {
            $this->resolveAllFailed($log, 1, 'Group had no members left by the time this batch was processed.');

            return;
        }

        $account = Account::with(['currentSubscription', 'whatsAppSession'])->find($log->account_id);

        if (! $account) {
            $this->resolveAllFailed($log, $members->count(), 'Account no longer exists.');

            return;
        }

        if (PaymentAlertDispatcher::isWhatsAppDisconnected($account)) {
            $this->resolveAllFailed($log, $members->count(), 'WhatsApp account was disconnected before this batch could be processed.');

            return;
        }

        try {
            $driver = WhatsAppEngineFactory::make($account);
        } catch (RuntimeException $e) {
            $this->resolveAllFailed($log, $members->count(), $e->getMessage());

            return;
        }

        $engineType = $account->currentSubscription?->engine_type;
        $successCount = 0;
        $failureCount = 0;
        $lastMemberIndex = $members->count() - 1;

        foreach ($members as $index => $member) {
            $normalizedPhone = PhoneNumberNormalizer::normalize($member->phone_number);

            if ($normalizedPhone === '') {
                $failureCount++;
            } else {
                $result = $this->send($driver, $engineType, $normalizedPhone);

                if (! empty($result['success'])) {
                    $successCount++;
                } else {
                    $failureCount++;
                }
            }

            if ($index !== $lastMemberIndex) {
                sleep(random_int(3, 8));
            }
        }

        $log->resolveGroupDispatch($successCount, $failureCount);
    }

    private function processNativeGroup(MessageDispatchLog $log, ContactGroup $group): void
    {
        $account = Account::with(['currentSubscription', 'whatsAppSession'])->find($log->account_id);

        if (! $account) {
            $this->resolveAllFailed($log, 1, 'Account no longer exists.');

            return;
        }

        if (! $group->isSyncedNativeGroup()) {
            $this->resolveAllFailed($log, 1, 'This WhatsApp group is no longer synced (pending or failed).');

            return;
        }

        if ($account->currentSubscription?->engine_type !== 'qr') {
            $this->resolveAllFailed($log, 1, 'This account is no longer on the QR (Baileys) engine.');

            return;
        }

        try {
            $driver = WhatsAppEngineFactory::make($account);
        } catch (RuntimeException $e) {
            $this->resolveAllFailed($log, 1, $e->getMessage());

            return;
        }

        $result = $this->send($driver, $account->currentSubscription?->engine_type, $group->wa_group_jid);

        if (! empty($result['success'])) {
            $log->resolveGroupDispatch(1, 0);

            return;
        }

        $errorMessage = $result['error'] ?? 'The QR engine rejected the message.';

        $log->resolveGroupDispatch(0, 1);
        $log->forceFill(['error_reason' => $errorMessage])->save();

        // Same disclosed sync_status-flip-on-send-failure policy as
        // ProcessGroupDispatchJob::processNativeGroup() -- see that
        // method's docblock for the full rationale and its own disclosed
        // uncertainty about which failures Baileys actually reports.
        $group->forceFill([
            'sync_status' => ContactGroup::SYNC_STATUS_FAILED,
            'sync_error' => "A message to this group failed: {$errorMessage}. If the WhatsApp group was deleted or this account was removed from it, delete and recreate it below.",
        ])->save();
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

    private function resolveAllFailed(MessageDispatchLog $log, int $memberCount, string $reason): void
    {
        $log->resolveGroupDispatch(0, $memberCount);
        $log->forceFill(['error_reason' => $reason])->save();
    }
}
