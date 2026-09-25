<?php

namespace App\Services\Groups;

use App\Jobs\ProcessGroupDispatchJob;
use App\Models\Account;
use App\Models\ContactGroup;
use App\Models\ContactGroupMember;
use App\Models\MessageDispatchLog;
use App\Models\MessageTemplate;
use App\Services\PaymentAlerts\PaymentAlertDispatcher;
use App\Services\Templates\TemplateMessageDispatcher;
use App\Support\TemplateRenderer;
use Illuminate\Support\Facades\DB;

/**
 * Group Messaging Phase 4 — Developer Send Message API's group-recipient
 * path. Kept as its own service class, mirroring PaymentAlertDispatcher
 * and TemplateMessageDispatcher's own docblocks' stated reasoning
 * exactly: today there is only one caller
 * (Api\V1\TemplateMessageController::sendMessage()), but this keeps the
 * controller thin and makes a future internal "Send to Group" UI action
 * a one-line addition rather than a duplicated code path.
 *
 * Individual-recipient sends on the SAME /v1/send-message endpoint are
 * deliberately NOT handled here — they delegate straight to the
 * existing TemplateMessageDispatcher (the exact same logic
 * /v1/messages/send-template already uses), so a single-recipient send
 * is bound to whichever rules that dispatcher already enforces, with
 * nothing duplicated or forked between the two entry points.
 *
 * dispatch() only ever does synchronous, fast validation plus the
 * atomic quota reservation — it never talks to the WhatsApp
 * driver/Node QR Engine itself. That happens later, in
 * ProcessGroupDispatchJob, once this method has queued it.
 *
 * Native WhatsApp Group Re-Architecture: the quota cost differs by
 * group_type. An 'internal_segment' group is still a fan-out of N
 * individual WhatsApp DMs (one per member) — N credits, unchanged from
 * before this re-architecture. A 'native_wa_group' send is ONE message
 * delivered into the real WhatsApp group chat (one WhatsApp API/Baileys
 * call, one message every group member sees) — 1 credit, regardless of
 * how many phone numbers are in this app's own member list for that
 * group. recipientCount below reflects that distinction; it is what
 * both the quota reservation AND recordGroupDispatchQueued()'s stored
 * recipient_count use.
 */
class GroupMessageDispatcher
{
    /**
     * @param array<string, string> $variables Applied to every recipient; 'name' is always overridden per-member by the job from that member's own contact_group_members.name.
     * @return array{
     *   status: 'queued'|'not_found'|'group_access_denied'|'quota_exhausted'|'empty_group'|'template_not_approved'|'disconnected'|'insufficient_quota'|'group_not_synced'|'unsupported_engine',
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
        int $templateId,
        array $variables,
        string $source = 'api',
        ?int $apiKeyId = null,
    ): array {
        $account = Account::with(['currentSubscription', 'whatsAppSession'])->find($accountId);

        if (! $account) {
            return ['status' => 'not_found', 'message' => 'Account not found.'];
        }

        // Strict Group Permission Guard — the exact same gate
        // module.guard:contact_groups enforces on the internal
        // ContactGroupController routes (see EnsureModuleEnabledMiddleware),
        // checked directly here since this is a single conditional branch
        // inside one endpoint's body, not a whole route to gate.
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
            // A native group can only ever be sent to once it has a real
            // wa_group_jid — 'pending' (still creating) and 'failed'
            // (creation never succeeded) both mean there is nothing to
            // send to yet.
            if (! $group->isSyncedNativeGroup()) {
                return [
                    'status' => 'group_not_synced',
                    'message' => $group->sync_status === ContactGroup::SYNC_STATUS_FAILED
                        ? 'This WhatsApp group failed to sync: '.($group->sync_error ?? 'unknown error').'. Delete and recreate it.'
                        : 'This WhatsApp group is still being created. Try again shortly.',
                ];
            }

            // engine_type is re-checked here even though
            // ContactGroupController::store() already required 'qr' at
            // creation time — a tenant's subscription/engine can change
            // after a native group already exists, and the official Meta
            // Cloud API can never deliver to a WhatsApp group JID (see
            // NativeWhatsAppGroupService's class docblock).
            if ($account->currentSubscription?->engine_type !== 'qr') {
                return [
                    'status' => 'unsupported_engine',
                    'message' => 'This account is no longer on the QR (Baileys) engine — Native WhatsApp Groups require it.',
                ];
            }

            $recipientCount = 1;
        } else {
            // [Disclosed]: contact_group_members has no active/inactive
            // concept (see that table's creating migration — id, group_id,
            // phone_number, name, timestamps only) — N is every member row
            // for this group, there is nothing to filter by "active".
            $recipientCount = ContactGroupMember::where('group_id', $group->id)->count();

            if ($recipientCount === 0) {
                return ['status' => 'empty_group', 'message' => 'This contact group has no members.'];
            }
        }

        $template = MessageTemplate::query()->approvedFor($accountId)->find($templateId);

        if (! $template) {
            return [
                'status' => 'template_not_approved',
                'message' => 'This template does not exist, is not approved, or is not available to this account.',
            ];
        }

        if (PaymentAlertDispatcher::isWhatsAppDisconnected($account)) {
            return ['status' => 'disconnected', 'message' => 'WhatsApp account is disconnected. Please connect your device first.'];
        }

        $subscription = $account->currentSubscription;

        // Atomic Quota & Balance Calculation — locked, re-checked-inside-
        // the-lock, then incremented by N in one transaction, same
        // lock-then-increment pattern TemplateMessageDispatcher and
        // ProcessPaymentAlertJob already use for a single credit; this is
        // the only call site in this codebase incrementing by more than 1.
        // The queued MessageDispatchLog row is created in the SAME
        // transaction as the reservation, so a caller can never observe
        // used_messages having moved without a corresponding audit row
        // (or vice versa).
        $reservation = DB::transaction(function () use ($subscription, $recipientCount, $accountId, $account, $group, $template, $variables, $source, $apiKeyId) {
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

            // A preview only, not the final per-recipient text — 'name'
            // (and every other member-specific field) differs per row and
            // is only known inside ProcessGroupDispatchJob's loop. This is
            // rendered with the caller-supplied $variables only, leaving
            // any {{name}}-style token exactly as typed (TemplateRenderer
            // leaves unmatched tokens untouched, by its own design) — an
            // honest "here is the template as configured" preview, not a
            // claim about what any specific recipient will actually see.
            $preview = TemplateRenderer::render($template->template_body, $variables);

            $log = MessageDispatchLog::recordGroupDispatchQueued(
                $accountId,
                $group->id,
                $group->name,
                $recipientCount,
                $template->title,
                $preview,
                $source,
                $apiKeyId,
                // Message Log "View Message": group-level snapshot. No
                // single rendered text exists (each member's is rendered
                // later), so rendered_message stays null and the preview
                // is labelled as such.
                templateSnapshot: TemplateMessageDispatcher::buildSnapshot(
                    $template,
                    $account,
                    $variables,
                    null,
                    scope: 'group',
                    groupPreview: $preview,
                ),
            );

            return ['status' => 'queued', 'dispatch_id' => $log->id];
        });

        if ($reservation['status'] === 'insufficient_quota') {
            return $reservation;
        }

        // Dispatched AFTER the transaction commits — the same ordering
        // guarantee ProcessPaymentAlertJob's webhook fire and
        // TemplateMessageDispatcher's MessageDispatchLog::record() call
        // both already use: a worker can never pick this job up before
        // the reservation it depends on is durably saved.
        ProcessGroupDispatchJob::dispatch($reservation['dispatch_id'], $template->id, $variables, $apiKeyId);

        return [
            'status' => 'queued',
            'dispatch_id' => $reservation['dispatch_id'],
            'queued_recipients_count' => $recipientCount,
        ];
    }
}
