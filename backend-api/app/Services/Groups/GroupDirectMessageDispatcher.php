<?php

namespace App\Services\Groups;

use App\Jobs\ProcessGroupDirectMessageJob;
use App\Models\Account;
use App\Models\ContactGroup;
use App\Models\ContactGroupMember;
use App\Models\GroupDispatchRecipient;
use App\Models\MessageDispatchLog;
use App\Services\Access\ProviderCapabilityService;
use App\Services\Messaging\MessageQuotaService;
use Illuminate\Support\Facades\DB;

/**
 * Developer API Platform for WhatsApp Group Creation & Unified
 * Messaging -- the group-recipient sibling of DirectMessageDispatcher,
 * for message_type 'text'|'media' (message_type 'template' stays on the
 * existing GroupMessageDispatcher, unchanged -- see
 * Api\V1\UnifiedMessageController). Mirrors GroupMessageDispatcher's own
 * structure and quota rules almost exactly (same module-gate/active-
 * subscription/native-vs-internal-segment branching, same N-credit vs
 * 1-credit quota split, same atomic reserve-then-queue pattern) minus
 * the template lookup/approval step, which does not apply to raw
 * text/media content.
 */
class GroupDirectMessageDispatcher
{
    /**
     * @param array<string, mixed> $content Same shape DirectMessageDispatcher::dispatch() takes for this $messageType.
     * @return array{
     *   status: 'queued'|'not_found'|'group_access_denied'|'quota_exhausted'|'empty_group'|'disconnected'|'insufficient_quota'|'group_not_synced'|'unsupported_engine',
     *   message?: string,
     *   dispatch_id?: int,
     *   queued_recipients_count?: int,
     *   required?: int,
     *   remaining?: int,
     * }
     */
    public static function dispatch(
        int $accountId,
        int $groupId,
        string $messageType,
        array $content,
        string $source = 'api',
        ?int $apiKeyId = null,
    ): array {
        $account = Account::with(['currentSubscription', 'whatsAppSession'])->find($accountId);

        if (! $account) {
            return ['status' => 'not_found', 'message' => 'Account not found.'];
        }

        if (! $account->hasModuleEnabled('contact_groups')) {
            return [
                'status' => 'group_access_denied',
                'message' => 'Your current subscription plan only supports individual message dispatches. Upgrade to unlock Group Messaging.',
            ];
        }

        if (! $account->hasActiveSubscription()) {
            return ['status' => 'quota_exhausted', 'message' => $account->quotaExhaustedMessage()];
        }

        $group = ContactGroup::where('account_id', $accountId)->find($groupId);

        if (! $group) {
            return ['status' => 'not_found', 'message' => 'Contact group not found.'];
        }

        if ($group->isNative()) {
            if (! $group->isSyncedNativeGroup()) {
                return [
                    'status' => 'group_not_synced',
                    'message' => $group->sync_status === ContactGroup::SYNC_STATUS_FAILED
                        ? 'This WhatsApp group failed to sync: '.($group->sync_error ?? 'unknown error').'. Delete and recreate it.'
                        : 'This WhatsApp group is still being created. Try again shortly.',
                ];
            }

            // Phase 5 Task 2 -- provider_capabilities is authoritative for
            // this rule when it states it; the 'qr' literal now lives only
            // in ProviderCapabilityService::supportsNativeWhatsAppGroups().
            if (! app(ProviderCapabilityService::class)->supportsNativeWhatsAppGroups($account->currentSubscription?->engine_type)) {
                return [
                    'status' => 'unsupported_engine',
                    'message' => 'This account is no longer on the QR (Baileys) engine -- Native WhatsApp Groups require it.',
                ];
            }

            $recipientCount = 1;
        } else {
            $recipientCount = ContactGroupMember::where('group_id', $group->id)->count();

            if ($recipientCount === 0) {
                return ['status' => 'empty_group', 'message' => 'This contact group has no members.'];
            }
        }

        if (\App\Services\PaymentAlerts\PaymentAlertDispatcher::isWhatsAppDisconnected($account)) {
            return ['status' => 'disconnected', 'message' => 'WhatsApp account is disconnected. Please connect your device first.'];
        }

        $subscription = $account->currentSubscription;
        $preview = $messageType === 'text'
            ? (string) ($content['body'] ?? '')
            : (string) ($content['caption'] ?? '['.($content['media_type'] ?? 'media').']');

        $reservation = DB::transaction(function () use ($subscription, $recipientCount, $accountId, $group, $preview, $source, $apiKeyId, $messageType, $content) {

            // Phase 5 fix P5-1 — the recipient list is read HERE, inside the
            // reservation transaction, and N is its size: exactly these
            // members are reserved, frozen below and sent by the job.
            $members = $group->isNative() ? null : GroupDispatchRecipient::membersToReserve($group);

            if ($members !== null) {
                $recipientCount = $members->count();

                if ($recipientCount === 0) {
                    return ['status' => 'empty_group', 'message' => 'This contact group has no members.'];
                }
            }
            // Phase 5 Task 4 -- see GroupMessageDispatcher's sibling
            // comment: reserve() (not consume()) inside this same
            // transaction, so the reservation and the queued audit row
            // below still commit together.
            if (! app(MessageQuotaService::class)->reserve($subscription, $recipientCount)) {
                $current = $subscription->newQuery()->find($subscription->id);

                return [
                    'status' => 'insufficient_quota',
                    'required' => $recipientCount,
                    'remaining' => $current?->remainingQuota() ?? 0,
                ];
            }

            // Phase 5 Task 5 -- has_media/media_url are finally real for a
            // group batch. This call site is the only one in the codebase
            // that KNOWS a group send carries a file, and it used to
            // discard that fact (recordGroupDispatchQueued hardcoded
            // has_media false), so Message Logs reported "No" for every
            // media blast.
            $log = MessageDispatchLog::recordGroupDispatchQueued(
                $accountId,
                $group->id,
                $group->name,
                $recipientCount,
                null,
                $preview,
                $source,
                $apiKeyId,
                hasMedia: $messageType === 'media',
                mediaUrl: $messageType === 'media' ? ($content['url'] ?? null) : null,
            );

            if ($members !== null) {
                GroupDispatchRecipient::freeze($log, $members);
            }

            return ['status' => 'queued', 'dispatch_id' => $log->id, 'recipient_count' => $recipientCount];
        });

        if ($reservation['status'] !== 'queued') {
            return $reservation;
        }

        ProcessGroupDirectMessageJob::dispatch($reservation['dispatch_id'], $messageType, $content, $apiKeyId);

        return [
            'status' => 'queued',
            'dispatch_id' => $reservation['dispatch_id'],
            'queued_recipients_count' => $reservation['recipient_count'],
        ];
    }
}
