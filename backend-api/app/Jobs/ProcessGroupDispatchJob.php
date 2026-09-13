<?php

namespace App\Jobs;

use App\Models\Account;
use App\Models\ContactGroup;
use App\Models\ContactGroupMember;
use App\Models\MessageDispatchLog;
use App\Models\MessageTemplate;
use App\Services\PaymentAlerts\PaymentAlertDispatcher;
use App\Services\WhatsApp\WhatsAppEngineFactory;
use App\Support\PhoneNumberNormalizer;
use App\Support\TemplateRenderer;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

/**
 * Group Messaging Phase 4 — the async half of GroupMessageDispatcher::dispatch().
 * Dispatched AFTER that method has already: verified the contact_groups
 * module + active subscription + group/template validity, and atomically
 * reserved (incremented used_messages by) the group's full recipient
 * count. This job's only job is to walk every member, send to each one,
 * and resolve the single 'queued' MessageDispatchLog row that reservation
 * created into its final 'sent'/'failed' state — mirrors
 * ProcessPaymentAlertJob's own "row created by the controller at enqueue
 * time, resolved by the job" lifecycle, just against one summary row
 * covering N recipients instead of N rows covering one recipient each.
 *
 * [Disclosed]: used_messages is NOT refunded here if some/all recipients
 * fail. The N credits were charged as a room-reservation for the whole
 * batch at enqueue time (GroupMessageDispatcher::dispatch(), per the
 * spec's own "Upon successful validation, atomically increment
 * used_messages by N" step, BEFORE queueing) — not per confirmed send,
 * unlike the individual-recipient path (TemplateMessageDispatcher, which
 * only increments on a CONFIRMED successful send). This is a deliberate
 * difference between the two paths, not an oversight: a group dispatch
 * has no synchronous response to hold open until every member is
 * attempted, so "charge only on success" isn't possible without either
 * blocking the HTTP request for the whole batch or reconciling quota
 * later — neither of which the given requirements asked for.
 *
 * Native WhatsApp Group Re-Architecture: when the referenced
 * ContactGroup is group_type='native_wa_group', process() below takes a
 * different path entirely — ONE send to the group's wa_group_jid instead
 * of looping every ContactGroupMember row (GroupMessageDispatcher already
 * reserved exactly 1 credit for this case, matching one WhatsApp send).
 * The member loop and its per-recipient personalization/anti-ban jitter
 * are unchanged for 'internal_segment' groups.
 */
class ProcessGroupDispatchJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * Exactly one attempt, same reasoning as ProcessPaymentAlertJob: a
     * WhatsApp send is not idempotent on our side, so an automatic retry
     * of a partially-completed batch risks re-sending to members who
     * already received the first attempt.
     */
    public int $tries = 1;

    /**
     * @param array<string, string> $variables Caller-supplied template
     *     variables, applied to every recipient; each member's own
     *     'name' (from contact_group_members.name) overrides any 'name'
     *     key in here, since the whole point of a named contact list is
     *     per-recipient personalization — see handle() below.
     */
    public function __construct(
        public readonly int $dispatchLogId,
        public readonly int $templateId,
        public readonly array $variables,
        public readonly ?int $apiKeyId = null,
    ) {
    }

    public function handle(): void
    {
        $log = MessageDispatchLog::find($this->dispatchLogId);

        if (! $log) {
            Log::warning("ProcessGroupDispatchJob: message_dispatch_logs#{$this->dispatchLogId} no longer exists.");

            return;
        }

        // Defensive, same as ProcessPaymentAlertJob: makes a duplicate
        // dispatch() call a no-op instead of a duplicate send/resolve.
        if ($log->status !== 'queued') {
            return;
        }

        try {
            $this->process($log);
        } catch (Throwable $e) {
            Log::error('ProcessGroupDispatchJob failed unexpectedly.', [
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
            $log->resolveGroupDispatch(0, 1);
            $log->forceFill(['error_reason' => 'Contact group no longer exists.'])->save();

            return;
        }

        if ($group->isNative()) {
            $this->processNativeGroup($log, $group);

            return;
        }

        $members = ContactGroupMember::where('group_id', $log->group_id)->get();

        if ($members->isEmpty()) {
            // Edge case: every member was removed from the group between
            // GroupMessageDispatcher::dispatch() counting N and this job
            // running. recordGroupDispatchQueued() already reserved N
            // credits for this batch; those are not refunded (see this
            // class's docblock), so this resolves the row honestly as a
            // total failure rather than silently disappearing.
            $log->forceFill([
                'status' => 'failed',
                'sent_at' => null,
                'error_reason' => 'Group had no members left by the time this batch was processed.',
            ])->save();

            return;
        }

        $account = Account::with(['currentSubscription', 'whatsAppSession'])->find($log->account_id);
        $template = MessageTemplate::query()->approvedFor($log->account_id)->find($this->templateId);

        // Both re-checked here even though GroupMessageDispatcher already
        // validated them at enqueue time — the account could be
        // deactivated, or the template edited/unapproved, in the gap
        // between enqueue and this job actually running.
        if (! $account) {
            $this->resolveAllFailed($log, $members->count(), 'Account no longer exists.');

            return;
        }

        if (! $template) {
            $this->resolveAllFailed($log, $members->count(), 'Template is no longer approved or available.');

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

        $successCount = 0;
        $failureCount = 0;
        $lastMemberIndex = $members->count() - 1;

        foreach ($members as $index => $member) {
            $normalizedPhone = PhoneNumberNormalizer::normalize($member->phone_number);

            if ($normalizedPhone === '') {
                $failureCount++;
            } else {
                // Per-recipient personalization: the member's own name
                // (contact_group_members.name) always wins over any
                // caller-supplied 'name' variable — a group dispatch's
                // whole purpose is addressing each recipient by their own
                // stored name. Every other caller-supplied variable is
                // unchanged across the batch.
                $memberVariables = array_merge($this->variables, [
                    'name' => $member->name ?? '',
                ]);

                $renderedMessage = TemplateRenderer::render($template->template_body, $memberVariables);

                $result = $driver->sendMessage($normalizedPhone, $renderedMessage, [
                    'template_id' => $template->id,
                    'group_id' => $log->group_id,
                ]);

                if (! empty($result['success'])) {
                    $successCount++;
                } else {
                    $failureCount++;
                }
            }

            // Anti-ban jitter between consecutive sends, same rationale
            // and range as ProcessPaymentAlertJob's single-send delay —
            // skipped after the last member since there is no next send
            // in this batch left to space out.
            if ($index !== $lastMemberIndex) {
                sleep(random_int(3, 8));
            }
        }

        $log->resolveGroupDispatch($successCount, $failureCount);
    }

    /**
     * Native WhatsApp Group Re-Architecture — sends exactly ONE message
     * into the real WhatsApp group ($group->wa_group_jid), mirroring the
     * same account/template/disconnected re-checks the internal_segment
     * path above already does (the account could be deactivated, or the
     * template edited/unapproved, in the gap between enqueue and this
     * job actually running), plus a re-check that the group is still
     * synced and the account is still on the 'qr' engine — the same two
     * conditions GroupMessageDispatcher::dispatch() already required
     * before this job was ever queued, re-verified for the same reason
     * every other re-check here exists: state can change in that gap.
     *
     * There is no per-member loop, no anti-ban jitter (a single send has
     * nothing to space out), and no per-recipient name personalization
     * — a WhatsApp group has no single "the recipient", so
     * $this->variables is rendered as-is, same as the preview
     * GroupMessageDispatcher::dispatch() already computed.
     */
    private function processNativeGroup(MessageDispatchLog $log, ContactGroup $group): void
    {
        $account = Account::with(['currentSubscription', 'whatsAppSession'])->find($log->account_id);
        $template = MessageTemplate::query()->approvedFor($log->account_id)->find($this->templateId);

        if (! $account) {
            $this->resolveAllFailed($log, 1, 'Account no longer exists.');

            return;
        }

        if (! $template) {
            $this->resolveAllFailed($log, 1, 'Template is no longer approved or available.');

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

        $renderedMessage = TemplateRenderer::render($template->template_body, $this->variables);

        $result = $driver->sendMessage($group->wa_group_jid, $renderedMessage, [
            'template_id' => $template->id,
            'group_id' => $log->group_id,
        ]);

        if (! empty($result['success'])) {
            $log->resolveGroupDispatch(1, 0);

            return;
        }

        $errorMessage = $result['error'] ?? 'The QR engine rejected the message.';

        $log->resolveGroupDispatch(0, 1);
        $log->forceFill(['error_reason' => $errorMessage])->save();

        // [New, disclosed — closes part of the "no way to know a native
        // WhatsApp group was deleted/left" gap]: flips this group's own
        // sync_status to 'failed' so it shows up on the Contact Groups
        // page (NativeGroupCell already renders a red "Sync failed"
        // badge + Recreate action for this status — reused as-is, no new
        // status value introduced) and GroupMessageDispatcher::dispatch()
        // refuses further sends to it until recreated (its existing
        // isSyncedNativeGroup() gate), instead of silently failing every
        // future send forever with the group still showing "Synced".
        //
        // [Important, disclosed limitation]: this can only flag a send
        // that Baileys/qr-engine-service actually REPORTS as failed.
        // Whether sending to a group you were removed from, or that was
        // deleted, reliably produces a thrown/caught error at all is
        // [Unknown] — verified against this repo's installed
        // @whiskeysockets/baileys@7.0.0-rc14 source only for IQ-style
        // requests (WABinary/generic-utils.js's assertNodeErrorFree()),
        // not confirmed for the message-send path specifically, and this
        // environment has no live WhatsApp session to test the real
        // behavior against. If WhatsApp silently drops the message
        // instead of returning an error, this will NOT catch it — it
        // only ever reacts to failures qr-engine-service actually
        // reports, never anything else. Not gated on error text/code
        // (no verified way to distinguish "group gone" from a transient
        // failure) — ANY reported send failure marks the group failed;
        // see ContactGroupController::recreate()'s docblock for the
        // false-positive trade-off this creates and how the frontend
        // discloses it before a user acts on it.
        $group->forceFill([
            'sync_status' => ContactGroup::SYNC_STATUS_FAILED,
            'sync_error' => "A message to this group failed: {$errorMessage}. If the WhatsApp group was deleted or this account was removed from it, delete and recreate it below.",
        ])->save();
    }

    private function resolveAllFailed(MessageDispatchLog $log, int $memberCount, string $reason): void
    {
        $log->resolveGroupDispatch(0, $memberCount);
        $log->forceFill(['error_reason' => $reason])->save();
    }
}
