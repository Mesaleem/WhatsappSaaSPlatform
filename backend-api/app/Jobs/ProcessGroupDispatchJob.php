<?php

namespace App\Jobs;

use App\Models\Account;
use App\Models\ContactGroup;
use App\Models\GroupDispatchRecipient;
use App\Models\MessageDispatchLog;
use App\Models\MessageTemplate;
use App\Services\PaymentAlerts\PaymentAlertDispatcher;
use App\Services\WhatsApp\WhatsAppEngineFactory;
use App\Support\PhoneNumberNormalizer;
use App\Support\TemplateRenderer;
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
     * Phase 5 fix P5-3 — one run (one slice) may never outlive this; see
     * MessageDispatchLog::GROUP_JOB_TIMEOUT_SECONDS for why 85 s (below the
     * database queue's retry_after, above one worst-case slice). A large
     * group is not given a giant timeout: it is processed in slices, each
     * queueing the next (continueInNextSlice()).
     */
    public int $timeout = MessageDispatchLog::GROUP_JOB_TIMEOUT_SECONDS;

    /** A timed-out run is failed (not retried) and reaches failed(), which settles the batch. */
    public bool $failOnTimeout = true;

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

        // A cheap early exit only. It is NOT the duplicate-execution guard:
        // two workers can both read 'queued' here.
        if ($log->status !== 'queued') {
            return;
        }

        // Phase 5 fix P5-3 — the guard: an atomic conditional UPDATE. Only
        // one run can own the batch; any other copy of this job (duplicate
        // dispatch, redelivery) gets false and sends nothing.
        $token = (string) Str::uuid();

        if (! $log->claimGroupDispatch($token)) {
            Log::info('ProcessGroupDispatchJob: batch is already claimed or settled; this run does nothing.', [
                'dispatch_log_id' => $log->id,
            ]);

            return;
        }

        try {
            $this->process($log, $token);
        } catch (Throwable $e) {
            Log::error('ProcessGroupDispatchJob failed unexpectedly.', [
                'dispatch_log_id' => $log->id,
                'exception' => $e->getMessage(),
            ]);

            // Phase 5 fix P5-3 — settle instead of leaving the refund
            // unresolved. Every recipient that was delivered has a
            // recipient row with sent_at (written right after the provider
            // accepted it), so the refund is exactly reserved - delivered;
            // the old "unknown outcome, refund nothing" is no longer true.
            $log->settleGroupDispatchFromRecipients('Internal error: '.$e->getMessage());
        }
    }

    /**
     * Phase 5 fix P5-3 — Laravel calls this when the job dies outside
     * handle()'s own try/catch: the run exceeded $timeout (the worker is
     * killed), or the job was marked failed by the queue. Settles the
     * batch from its recipient rows. Idempotent: settlement only happens
     * on the locked 'queued' -> terminal transition, so a second call,
     * or a call for an already-settled batch, changes nothing.
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
            // Edge case: every member was removed from the group between
            // GroupMessageDispatcher::dispatch() counting N and this job
            // running.
            //
            // [Phase 5 Task 4]: this branch used to be a raw forceFill
            // that left success_count/failure_count NULL and refunded
            // nothing -- the tenant paid for N recipients that provably
            // received nothing. It now goes through the same guarded,
            // refunding resolution as every other total-failure branch.
            $this->resolveAllFailed($log, 'Group had no members left by the time this batch was processed.');

            return;
        }

        $account = Account::with(['currentSubscription', 'whatsAppSession'])->find($log->account_id);
        $template = MessageTemplate::query()->approvedFor($log->account_id)->find($this->templateId);

        // Both re-checked here even though GroupMessageDispatcher already
        // validated them at enqueue time — the account could be
        // deactivated, or the template edited/unapproved, in the gap
        // between enqueue and this job actually running.
        if (! $account) {
            $this->resolveAllFailed($log, 'Account no longer exists.');

            return;
        }

        if (! $template) {
            $this->resolveAllFailed($log, 'Template is no longer approved or available.');

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

        // Recorded on every recipient row: `source` is the entry point,
        // not the provider, so without this an audit row could not say
        // which engine carried the message.
        $engineType = $account->currentSubscription?->engine_type;

        // Phase 5 fix P5-3 — slicing. Recipients an earlier slice of this
        // batch already attempted have a recipient row and are skipped, so a
        // continuation never re-sends. The list itself is still the frozen
        // one (P5-1); nothing here reads the live group.
        $recorded = $log->recordedGroupRecipientReferenceIds(MessageDispatchLog::REFERENCE_TYPE_GROUP_MEMBER);
        $sliceStartedAt = now()->getTimestamp();
        $attemptedThisSlice = 0;
        // Pacing continues across slices: if an earlier slice already
        // attempted recipients, the first send of this slice is spaced too.
        $paceNextSend = $recorded !== [];

        foreach ($members as $member) {
            if (isset($recorded[(int) $member->id])) {
                continue;
            }

            // Bounded run time whatever the group size: once the slice
            // budget is spent, hand the rest to a fresh job.
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
                );

                continue;
            }

            $normalizedPhone = PhoneNumberNormalizer::normalize($member->phone_number);

            if ($normalizedPhone === '') {
                if (! $this->stillOwns($log, $token)) {
                    return;
                }

                $attemptedThisSlice++;

                // Phase 5 Task 5 -- an unsendable number is still an
                // attempt this batch was charged for, so it gets its own
                // audit row. The raw stored value is recorded, not the
                // empty normalized string, because that is the datum an
                // operator needs to fix.
                MessageDispatchLog::recordGroupRecipient(
                    $log,
                    (string) $member->phone_number,
                    MessageDispatchLog::REFERENCE_TYPE_GROUP_MEMBER,
                    (int) $member->id,
                    success: false,
                    engineType: $engineType,
                    errorReason: "Recipient phone number '{$member->phone_number}' is not a valid number after normalization.",
                );

                continue;
            }

            // Anti-ban jitter between consecutive provider sends, same
            // rationale and range as before (and as ProcessPaymentAlertJob's
            // single-send delay) — never before the batch's first send.
            if ($paceNextSend) {
                sleep(random_int(3, 8));
            }

            // Checked immediately before the send, after the pacing: if the
            // batch was settled meanwhile (failed()/stale recovery) or is
            // owned by another run, stop without sending.
            if (! $this->stillOwns($log, $token)) {
                return;
            }

            $attemptedThisSlice++;
            $paceNextSend = true;

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

            // Phase 5 Task 5 -- the per-recipient audit row, written
            // immediately after the driver call while the provider's
            // own result is still in hand. gateway_message_id is
            // whatever the driver returned (Meta's WAMID, or
            // qr-engine-service's own id) and stays null when it
            // returned none -- never fabricated. This is the row
            // MetaWebhookController::correlateFailedStatus() can now
            // match a group send against, and (P5-3) the row settlement
            // counts as delivered.
            //
            // NOT a quota operation: the batch reserved N up front
            // and releases the failures once, at resolution. Nothing
            // here consumes or releases per recipient.
            MessageDispatchLog::recordGroupRecipient(
                $log,
                $normalizedPhone,
                MessageDispatchLog::REFERENCE_TYPE_GROUP_MEMBER,
                (int) $member->id,
                success: ! empty($result['success']),
                engineType: $engineType,
                gatewayMessageId: $result['message_id'] ?? null,
                errorReason: $result['error'] ?? 'The WhatsApp engine rejected the message.',
            );
        }

        $this->resolve($log);
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
    private function processNativeGroup(MessageDispatchLog $log, ContactGroup $group, string $token): void
    {
        $account = Account::with(['currentSubscription', 'whatsAppSession'])->find($log->account_id);
        $template = MessageTemplate::query()->approvedFor($log->account_id)->find($this->templateId);

        if (! $account) {
            $this->resolveAllFailed($log, 'Account no longer exists.');

            return;
        }

        if (! $template) {
            $this->resolveAllFailed($log, 'Template is no longer approved or available.');

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

        // Phase 5 fix P5-3 — never a second send into the group: if this
        // batch's single native send was already attempted, just settle.
        if (isset($log->recordedGroupRecipientReferenceIds(MessageDispatchLog::REFERENCE_TYPE_NATIVE_GROUP)[(int) $group->id])) {
            $this->resolve($log);

            return;
        }

        if (! $this->stillOwns($log, $token)) {
            return;
        }

        $renderedMessage = TemplateRenderer::render($template->template_body, $this->variables);

        $result = $driver->sendMessage($group->wa_group_jid, $renderedMessage, [
            'template_id' => $template->id,
            'group_id' => $log->group_id,
        ]);

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
        );

        if ($nativeSucceeded) {
            $this->resolve($log);

            return;
        }

        $errorMessage = $result['error'] ?? 'The QR engine rejected the message.';

        $this->resolve($log, $errorMessage);

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
        // Phase 5 fix P5-3 — "delivered" is no longer this slice's own
        // tally: it is read from the persisted recipient rows, so a batch
        // processed in several slices (or settled after a failure) counts
        // every delivery exactly once. Same resolveGroupDispatch(), same
        // refund = reserved - delivered, same single locked transition.
        return $log->settleGroupDispatchFromRecipients($reason);
    }

    /** Phase 5 fix P5-3 — heartbeat + "is this batch still mine and still queued?" */
    private function stillOwns(MessageDispatchLog $log, string $token): bool
    {
        if ($log->heartbeatGroupDispatch($token)) {
            return true;
        }

        Log::warning('ProcessGroupDispatchJob: lost ownership of the batch (settled or claimed elsewhere); stopping without sending.', [
            'dispatch_log_id' => $log->id,
        ]);

        return false;
    }

    /**
     * Phase 5 fix P5-3 — hand the batch back and queue the next slice on
     * the same connection/queue. If the claim can no longer be released
     * the batch was settled meanwhile, and nothing is queued.
     */
    private function continueInNextSlice(MessageDispatchLog $log, string $token): void
    {
        if (! $log->releaseGroupDispatchClaim($token)) {
            return;
        }

        static::dispatch($this->dispatchLogId, $this->templateId, $this->variables, $this->apiKeyId)
            ->onConnection($this->connection)
            ->onQueue($this->queue);
    }

}
