<?php

namespace App\Services\Groups;

use App\Jobs\ProcessGroupDirectMessageJob;
use App\Models\Account;
use App\Models\ContactGroup;
use App\Models\ContactGroupMember;
use App\Models\MessageDispatchLog;
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

            if ($account->currentSubscription?->engine_type !== 'qr') {
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

        $reservation = DB::transaction(function () use ($subscription, $recipientCount, $accountId, $group, $preview, $source, $apiKeyId) {
            $locked = $subscription->newQuery()->lockForUpdate()->find($subscription->id);

            if (! $locked->hasQuotaFor($recipientCount)) {
                return [
                    'status' => 'insufficient_quota',
                    'required' => $recipientCount,
                    'remaining' => $locked->remainingQuota() ?? 0,
                ];
            }

            $locked->increment('used_messages', $recipientCount);
            $locked->refreshStatus();

            $log = MessageDispatchLog::recordGroupDispatchQueued(
                $accountId,
                $group->id,
                $group->name,
                $recipientCount,
                null,
                $preview,
                $source,
                $apiKeyId,
            );

            return ['status' => 'queued', 'dispatch_id' => $log->id];
        });

        if ($reservation['status'] === 'insufficient_quota') {
            return $reservation;
        }

        ProcessGroupDirectMessageJob::dispatch($reservation['dispatch_id'], $messageType, $content, $apiKeyId);

        return [
            'status' => 'queued',
            'dispatch_id' => $reservation['dispatch_id'],
            'queued_recipients_count' => $recipientCount,
        ];
    }
}
