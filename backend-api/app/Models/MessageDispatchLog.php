<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use App\Services\Messaging\MessageQuotaService;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;

/**
 * [New feature, disclosed] — see the creating migration's docblock for
 * the full rationale. One row per outbound WhatsApp send attempt, across
 * every dispatch pathway (Send Alert, Send Template, Chatbot, Journey
 * Builder — web and external-API entry points alike).
 */
class MessageDispatchLog extends Model
{
    protected $fillable = [
        'account_id',
        'source',
        'api_key_id',
        'recipient_phone',
        'status',
        'error_reason',
        'has_media',
        // [Bug fix, disclosed]: the actual file URL a media send
        // attached -- see record()'s own docblock and the migration
        // adding this column.
        'media_url',
        'reference_type',
        'reference_id',
        'sent_at',
        // [Bug fix, Phase 4 Task 3]: record() has always passed
        // 'gateway_message_id' into self::create(), but the column was
        // never listed here -- so Laravel's mass-assignment guard silently
        // dropped it and the column was NEVER written, for any provider.
        //
        // That broke a real, already-shipped correlation:
        // MetaWebhookController::correlateFailedStatus() looks a dispatch
        // row up by MessageDispatchLog::where('gateway_message_id', $wamid),
        // so a post-send rejection from Meta (blocked template, opted-out
        // recipient, expired 24h window) could never be matched back to
        // the send it belongs to and never surfaced as 'failed' in
        // Analytics/Message Logs. payment_alerts already had this column
        // in its own $fillable, which is why THAT correlation worked and
        // this one silently did not.
        //
        // Adding it changes no send behaviour on either provider -- the
        // value was already being computed and passed; it just starts
        // being persisted, which is what record()'s own docblock and the
        // 2026_09_13_160000 migration always intended.
        'gateway_message_id',
        // [New feature, disclosed]: template_name/message_preview — see
        // the migration that adds these two columns for the full
        // rationale and the pending-authorization note.
        'template_name',
        'message_preview',
        // Group Messaging Step 1 — schema-only: these four columns
        // exist and are mass-assignable now, but nothing yet writes them
        // (record() below is unchanged) — that's the group-dispatch
        // pathway, a later step.
        'recipient_type',
        'group_id',
        'group_name',
        'recipient_count',
        // Dashboard Analytics Upgrade (Phase 5) — see the migration
        // adding these two columns for why they exist; set only by
        // resolveGroupDispatch() below via forceFill (mass-assignment
        // isn't actually exercised through create()/fill() for these,
        // but listed here for the same self-documentation every other
        // column on this model already follows).
        'success_count',
        'failure_count',
        // Phase 5 Task 5 -- per-recipient group dispatch rows. See the
        // 2026_09_22_130000 migration for why these two exist and why no
        // new table was needed.
        'parent_dispatch_id',
        'engine_type',
    ];

    protected function casts(): array
    {
        return [
            'has_media' => 'boolean',
            'sent_at' => 'datetime',
            'group_id' => 'integer',
            'recipient_count' => 'integer',
            'success_count' => 'integer',
            'failure_count' => 'integer',
            'parent_dispatch_id' => 'integer',
            'claimed_at' => 'datetime',
        ];
    }

    /**
     * Phase 5 fix P5-3 -- the claim token is an internal ownership marker
     * for a running group job; it has no meaning to any API consumer.
     */
    protected $hidden = [
        'claim_token',
    ];

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    public function apiKey(): BelongsTo
    {
        return $this->belongsTo(ApiKey::class);
    }

    /** Group Messaging Step 1 — null for every individual send; set only once a group-blast dispatch pathway exists. */
    public function group(): BelongsTo
    {
        return $this->belongsTo(ContactGroup::class, 'group_id');
    }

    // =================================================================
    // Phase 5 Task 5 -- per-recipient group dispatch rows
    // =================================================================

    /**
     * A THIRD recipient_type value, alongside the column's own DB default
     * 'individual' and recordGroupDispatchQueued()'s 'group'.
     *
     * It is deliberately its own value rather than reusing 'individual',
     * and that choice is load-bearing for Analytics rather than cosmetic.
     * AnalyticsController::resolveRecipientAwareTotals() counts
     * 'individual' rows one-per-row by status and sums 'group' rows via
     * success_count/failure_count. Labelling a recipient row 'individual'
     * would count it AND its parent's counts -- every group message
     * counted twice. Labelling it 'group' would sum its
     * success_count/failure_count, which are null on a recipient row, so
     * it would silently contribute nothing while still being scanned.
     * A distinct value matches neither branch, so every existing
     * Analytics figure is arithmetically unchanged by this task.
     */
    public const RECIPIENT_TYPE_GROUP_RECIPIENT = 'group_recipient';

    /** reference_type for one member of an internal segment: reference_id = contact_group_members.id. */
    public const REFERENCE_TYPE_GROUP_MEMBER = 'group_member';

    /** reference_type for a native WhatsApp group's single send: reference_id = contact_groups.id. */
    public const REFERENCE_TYPE_NATIVE_GROUP = 'group_native';

    /** The batch this recipient row belongs to; null on every aggregate and individual row. */
    public function parentDispatch(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_dispatch_id');
    }

    /** The per-recipient rows underneath a group batch. */
    public function recipientDispatches(): HasMany
    {
        return $this->hasMany(self::class, 'parent_dispatch_id');
    }

    /** Only the per-recipient rows — excludes aggregates and individual sends. */
    public function scopeGroupRecipients(Builder $query): Builder
    {
        return $query->where('recipient_type', self::RECIPIENT_TYPE_GROUP_RECIPIENT);
    }

    public function scopeForAccount(Builder $query, int $accountId): Builder
    {
        return $query->where('account_id', $accountId);
    }

    public function scopeSent(Builder $query): Builder
    {
        return $query->where('status', 'sent');
    }

    public function scopeFailed(Builder $query): Builder
    {
        return $query->where('status', 'failed');
    }

    /** message_preview is stored as a short snippet, not the full outgoing text — see record()'s own truncation. */
    private const PREVIEW_MAX_LENGTH = 160;

    /**
     * Single write path so every dispatch pathway logs with the exact
     * same shape — a bare ::create() call at 5 different call sites risks
     * one of them silently drifting on a field name.
     *
     * [New feature, disclosed]: $templateName/$messagePreview are
     * trailing optional params (appended, not inserted, so every
     * existing named-argument call site is unaffected). $templateName
     * is passed only by TemplateMessageDispatcher (the only pathway with
     * an actual named MessageTemplate) — stays null for web_ui/api
     * payment alerts, chatbot replies, and journey sends, exactly as
     * requested ("null for direct API/Chatbot"). $messagePreview is
     * truncated to PREVIEW_MAX_LENGTH here, centrally, so a caller never
     * has to remember to truncate its own outgoing text before passing
     * it in.
     *
     * [Bug fix, disclosed]: $hasMedia used to be hardcoded `false` here
     * for every caller, unconditionally — so the Message Logs "Media
     * Attachment" column read "No" even for a Media Template send that
     * actually attached an image/PDF (confirmed: the WhatsApp message
     * and the Baileys/QR send both carried the file; only this audit
     * row was wrong). That hardcoding predates Media Templates
     * (QR/Baileys-only) and was simply never revisited once that
     * feature added a real media-sending path. Now a trailing optional
     * param, default `false` — every pre-existing call site (payment
     * alerts, journey, and every failure branch here) is unaffected;
     * only TemplateMessageDispatcher::dispatch(), DirectMessageDispatcher::
     * dispatch() (its 'media' messageType), and ChatbotEngineService's
     * 'media'-type auto-reply pass `true`, and only on their actual
     * successful-send call. Still NOT wired up for the async Group
     * Messaging media path (GroupDirectMessageDispatcher's queued row /
     * resolveGroupDispatch() below) -- disclosed, not fixed here, since
     * that path resolves the row in a later job rather than at the same
     * call site that knows whether media was attached.
     */
    public static function record(
        int $accountId,
        string $source,
        string $recipientPhone,
        bool $success,
        ?string $errorReason = null,
        ?int $apiKeyId = null,
        ?string $referenceType = null,
        ?int $referenceId = null,
        ?string $templateName = null,
        ?string $messagePreview = null,
        ?string $gatewayMessageId = null,
        bool $hasMedia = false,
        // [Bug fix, disclosed]: the media URL itself, alongside the
        // $hasMedia flag -- kept as a SEPARATE trailing param (not
        // folded into $hasMedia) so a caller can still pass
        // hasMedia: true with mediaUrl: null for a source that only
        // ever confirms "media was attached" without the underlying
        // URL, though every current caller supplies both together.
        ?string $mediaUrl = null,
    ): self {
        return self::create([
            'account_id' => $accountId,
            'source' => $source,
            'api_key_id' => $apiKeyId,
            'recipient_phone' => $recipientPhone,
            'status' => $success ? 'sent' : 'failed',
            'error_reason' => $errorReason,
            'has_media' => $hasMedia,
            'media_url' => $mediaUrl,
            'reference_type' => $referenceType,
            'reference_id' => $referenceId,
            'sent_at' => $success ? now() : null,
            'template_name' => $templateName,
            'message_preview' => $messagePreview !== null
                ? mb_substr(trim($messagePreview), 0, self::PREVIEW_MAX_LENGTH)
                : null,
            // [New, disclosed]: the engine-returned message id (Meta
            // WAMID on success; always null for a failed send, and null
            // for engines/callers that don't supply one, e.g. Baileys).
            // See 2026_09_13_160000_add_gateway_message_id_to_message_
            // dispatch_logs_table.php for why this column exists.
            'gateway_message_id' => $success ? $gatewayMessageId : null,
        ]);
    }

    /**
     * Group Messaging Phase 4 — creates the ONE audit row for a group
     * dispatch batch, at enqueue time, before any recipient has actually
     * been sent to. Deliberately NOT record() above: record() only ever
     * writes a RESOLVED row (status is always 'sent' or 'failed', because
     * every one of its 4 existing callers already knows the outcome by
     * the time it's called). A group batch is different — it resolves
     * LATER, inside ProcessGroupDispatchJob, so marking it 'sent' here
     * (before a single message has gone out) would be an inaccurate audit
     * trail, and 'failed' would be equally wrong if it later succeeds.
     *
     * 'queued' is a genuinely new third value for this column — a
     * plain `string`, not a native DB enum (see this table's creating
     * migration), so this needs no schema change. It is used ONLY by
     * this method and resolveGroupDispatch() below; every existing
     * call site (record(), used by 4 other dispatch pathways) is
     * completely unaffected and never produces this value.
     *
     * recipient_phone has no single value for a group batch — this
     * was flagged as an open design question in this column's own
     * migration docblock (Group Messaging Step 1) and is resolved here:
     * 'group:{id}' is a clear, greppable placeholder, never mistaken for
     * a real phone number.
     */
    public static function recordGroupDispatchQueued(
        int $accountId,
        int $groupId,
        string $groupName,
        int $recipientCount,
        ?string $templateName,
        ?string $messagePreview,
        string $source,
        ?int $apiKeyId,
        // Phase 5 Task 5 -- trailing optional params, so every existing
        // positional call site is unaffected. has_media was hardcoded
        // `false` here for every batch, including a media blast, which
        // made the Message Logs "Media Attachment" column read "No" for a
        // group send that really did attach a file -- the same root cause
        // record()'s own docblock documents for the individual paths, and
        // the one it explicitly left open for this pathway.
        bool $hasMedia = false,
        ?string $mediaUrl = null,
    ): self {
        return self::create([
            'account_id' => $accountId,
            'source' => $source,
            'api_key_id' => $apiKeyId,
            'recipient_phone' => "group:{$groupId}",
            'recipient_type' => 'group',
            'group_id' => $groupId,
            'group_name' => $groupName,
            'recipient_count' => $recipientCount,
            'status' => 'queued',
            'has_media' => $hasMedia,
            'media_url' => $mediaUrl,
            'template_name' => $templateName,
            'message_preview' => $messagePreview !== null
                ? mb_substr(trim($messagePreview), 0, self::PREVIEW_MAX_LENGTH)
                : null,
            'sent_at' => null,
        ]);
    }

    /**
     * Phase 5 Task 5 — ONE audit row per actual recipient attempt inside
     * a group batch, written by ProcessGroupDispatchJob /
     * ProcessGroupDirectMessageJob immediately after each driver call.
     *
     * Closes four gaps the Phase 5 Task 1 audit recorded, all of which
     * came from a batch having only a single aggregate row:
     *   - no per-recipient gateway_message_id, so a Meta status callback
     *     could never be matched back to the group send it belonged to;
     *   - has_media hardcoded false on the aggregate, even for a media
     *     blast;
     *   - no recipient-level delivery/failure audit at all;
     *   - no engine recorded.
     *
     * The aggregate row is untouched and still owns the batch: recipient
     * count, success/failure counts, the reservation and its refund, and
     * the final batch status. These rows hang underneath it via
     * parent_dispatch_id.
     *
     * DUPLICATE PROTECTION is the database's, not the cache's.
     * createOrFirst() inserts against the
     * unique(parent_dispatch_id, reference_type, reference_id) index and,
     * on a collision, returns the row that is already there. So a job
     * dispatched twice, a resolution that runs twice, or two workers
     * racing the same batch all converge on exactly one row per
     * recipient, and the FIRST recorded outcome stays authoritative --
     * a later duplicate attempt never rewrites history.
     *
     * $gatewayMessageId is stored exactly as the provider returned it
     * (Meta's WAMID, or whatever qr-engine-service supplies) and stays
     * null when the provider returns none. Nothing is ever fabricated,
     * and it is written only on a successful send — matching record()'s
     * own long-standing rule.
     *
     * No credential is stored here: the parameters carry a phone number,
     * a provider-issued id and the driver's own error text, never a
     * token, secret or verify token.
     */
    public static function recordGroupRecipient(
        self $parent,
        string $recipientPhone,
        string $referenceType,
        int $referenceId,
        bool $success,
        ?string $engineType = null,
        ?string $gatewayMessageId = null,
        ?string $errorReason = null,
        bool $hasMedia = false,
        ?string $mediaUrl = null,
    ): self {
        return self::createOrFirst(
            [
                'parent_dispatch_id' => $parent->getKey(),
                'reference_type' => $referenceType,
                'reference_id' => $referenceId,
            ],
            [
                // Tenancy is inherited from the batch, never re-derived
                // and never taken from anything caller-supplied.
                'account_id' => $parent->account_id,
                'source' => $parent->source,
                'api_key_id' => $parent->api_key_id,
                'engine_type' => $engineType,
                'recipient_phone' => $recipientPhone,
                'recipient_type' => self::RECIPIENT_TYPE_GROUP_RECIPIENT,
                'group_id' => $parent->group_id,
                'group_name' => $parent->group_name,
                'recipient_count' => 1,
                'status' => $success ? 'sent' : 'failed',
                'error_reason' => $success ? null : $errorReason,
                'has_media' => $hasMedia,
                'media_url' => $mediaUrl,
                'template_name' => $parent->template_name,
                'message_preview' => $parent->message_preview,
                'sent_at' => $success ? now() : null,
                'gateway_message_id' => $success ? $gatewayMessageId : null,
            ],
        );
    }

    /** The only non-terminal value this column ever holds — see recordGroupDispatchQueued(). */
    public const STATUS_QUEUED = 'queued';

    /**
     * Called by ProcessGroupDispatchJob / ProcessGroupDirectMessageJob
     * once every member in the batch has been attempted. Resolves the
     * 'queued' row recordGroupDispatchQueued() created above into its
     * final state by UPDATING that same row rather than creating a
     * second one — so dispatch_id (returned synchronously to the API
     * caller at enqueue time) identifies exactly one row across its
     * whole lifecycle. 'sent' if at least one recipient succeeded,
     * 'failed' only if every recipient failed — a disclosed,
     * reasonable choice for a partial-success batch, since this column
     * has no native "partial" state.
     *
     * =================================================================
     * Phase 5 Task 4 — THE FAILURE REFUND, AND WHY IT LIVES HERE
     * =================================================================
     * A group batch reserves N credits up front
     * (MessageQuotaService::reserve(), from the two group dispatchers)
     * and, until this task, never gave any of them back: a 10-recipient
     * batch in which 4 recipients failed still billed all 10. That gap
     * was documented in ProcessGroupDispatchJob's own docblock and is
     * closed here — release($failureCount), so only recipients that
     * actually did not receive anything are returned.
     *
     * This is the single write path BOTH group jobs already funnel
     * every resolution through (including resolveAllFailed()), which is
     * exactly why the refund belongs here rather than being repeated at
     * the ~8 call sites. A model reaching for a service is a layering
     * compromise, taken deliberately: the alternative was eight
     * independent refund call sites, which is the class of duplication
     * this whole phase exists to remove.
     *
     * IDEMPOTENCE is a persisted state transition, not a flag and not a
     * cache entry, and NOT release()'s zero-floor:
     *
     *   1. the row is re-read under SELECT ... FOR UPDATE, so two
     *      workers resolving the same dispatch are serialised by the
     *      database rather than by anything in application memory;
     *   2. the refund is applied ONLY on the 'queued' -> terminal
     *      transition. `status` already distinguishes in-flight from
     *      resolved, so no new column and no migration is needed: a row
     *      that is no longer 'queued' has, by construction, already had
     *      its refund applied inside this same transaction;
     *   3. the release and the status write commit together. A refund
     *      can never land without the row being marked resolved, and a
     *      row can never be marked resolved without its refund having
     *      landed — if release() throws, the whole transaction rolls
     *      back and the row stays 'queued', still resolvable.
     *
     * The second worker therefore observes a non-'queued' row, refunds
     * nothing, mutates nothing, and returns false. Callers that need to
     * know whether THEY were the one to resolve it read that bool; the
     * pre-existing callers that ignore it are unaffected.
     *
     * $errorReason overrides the computed "F of N recipient(s) failed."
     * text. It exists so the three call sites that used to follow this
     * method with a second `forceFill(['error_reason' => ...])->save()`
     * can write inside the guarded transaction instead — otherwise that
     * second write would still mutate a row this method had just
     * declined to touch.
     *
     * @return bool true when THIS call performed the resolution (and the
     *              refund); false when the dispatch was already resolved.
     */
    public function resolveGroupDispatch(int $successCount, int $failureCount, ?string $errorReason = null): bool
    {
        return (bool) DB::transaction(function () use ($successCount, $failureCount, $errorReason) {
            /** @var self|null $locked */
            $locked = self::query()->whereKey($this->getKey())->lockForUpdate()->first();

            // Already resolved (or gone). Refund nothing, mutate nothing.
            if (! $locked || $locked->status !== self::STATUS_QUEUED) {
                return false;
            }

            if ($failureCount > 0) {
                // Same subscription row the reservation debited:
                // currentSubscription is one row per account, updated in
                // place by InvoiceCreditService rather than superseded,
                // so a plan change between reserve and resolve still
                // credits back the row that was charged.
                $subscription = $locked->account?->currentSubscription;

                if ($subscription) {
                    app(MessageQuotaService::class)->release($subscription, $failureCount);
                }
            }

            $total = $successCount + $failureCount;

            $locked->forceFill([
                'status' => $successCount > 0 ? 'sent' : 'failed',
                'sent_at' => $successCount > 0 ? now() : null,
                'error_reason' => $errorReason
                    ?? ($failureCount > 0 ? "{$failureCount} of {$total} recipient(s) failed." : null),
                // Dashboard Analytics Upgrade (Phase 5) — persisted so a
                // "Total Group Messages Sent/Failed" KPI can sum actual
                // per-recipient outcomes instead of counting this one
                // summary row by its coarse sent/failed status (see the
                // migration adding these two columns for the full
                // root-cause explanation).
                'success_count' => $successCount,
                'failure_count' => $failureCount,
            ])->save();

            // Keep the caller's own instance in step with what was just
            // written, so code that reads $log->status after this call
            // (or saves an unrelated attribute on it) is not working from
            // a stale copy.
            $this->forceFill($locked->getAttributes())->syncOriginal();

            return true;
        });
    }

    /**
     * [Phase 5 fix P5-3 — no longer called by the group jobs.] A job that
     * throws now settles through settleGroupDispatchFromRecipients(), which
     * refunds exactly the recipients that provably received nothing
     * (reserved - recipient rows with sent_at), instead of refunding
     * nothing. Kept, unchanged, for its existing callers and tests.
     *
     * Phase 5 Task 4 — the ONE resolution that must NOT refund.
     *
     * Both group jobs wrap process() in a try/catch and mark the row
     * failed when something throws. At that point the batch's real
     * per-recipient outcome is unknown: some recipients may already have
     * received their message. Refunding the whole reservation would
     * hand back credits for messages that actually went out, so this
     * deliberately returns nothing and records only the failure — the
     * pre-existing behaviour, unchanged.
     *
     * What IS new is the guard. This used to be a bare forceFill, which
     * would happily overwrite a row that process() had already resolved
     * (a throw AFTER resolution is reachable: processNativeGroup()
     * resolves and then writes the group's sync_status). The same
     * 'queued'-only transition used above prevents that duplicate
     * final-state mutation.
     *
     * @return bool true when this call marked the row failed.
     */
    public function failGroupDispatchWithoutRefund(string $reason): bool
    {
        return (bool) DB::transaction(function () use ($reason) {
            /** @var self|null $locked */
            $locked = self::query()->whereKey($this->getKey())->lockForUpdate()->first();

            if (! $locked || $locked->status !== self::STATUS_QUEUED) {
                return false;
            }

            $locked->forceFill([
                'status' => 'failed',
                'sent_at' => null,
                'error_reason' => $reason,
            ])->save();

            $this->forceFill($locked->getAttributes())->syncOriginal();

            return true;
        });
    }

    // =================================================================
    // Phase 5 fix P5-3 — group batch ownership, slicing and recovery
    // =================================================================
    //
    // A group batch lives in ONE parent row from reservation to settlement.
    // P5-3 adds three things around the existing settlement
    // (resolveGroupDispatch(), unchanged):
    //
    //   1. an ATOMIC CLAIM, so only one job run can ever send for a batch;
    //   2. SETTLEMENT FROM THE PERSISTED RECIPIENT ROWS, so a batch that
    //      stops part-way (exception, timeout, killed worker) is refunded
    //      exactly reserved - delivered, and counts are never guessed;
    //   3. STALE-BATCH RECOVERY for an owner that died without settling.
    //
    // The quota side is untouched: every refund still goes through
    // resolveGroupDispatch() -> MessageQuotaService::release(), once, on
    // the locked 'queued' -> terminal transition.

    /**
     * Hard limit for one run (one slice) of a group job. Must stay below
     * the database queue's retry_after (config/queue.php, 90 s) so a
     * running slice is never handed to a second worker, and above the
     * worst case of one slice: the slice budget below plus one recipient
     * (<= 8 s pacing + <= 15 s provider HTTP timeout + DB writes).
     */
    public const GROUP_JOB_TIMEOUT_SECONDS = 85;

    /**
     * A slice stops starting new sends once it has run this long, hands the
     * batch back (releases its claim) and queues a continuation job for the
     * remaining recipients. 50 s + one worst-case recipient (~24 s) stays
     * inside GROUP_JOB_TIMEOUT_SECONDS, whatever the group's size.
     */
    public const GROUP_JOB_SLICE_BUDGET_SECONDS = 50;

    /**
     * A CLAIMED batch whose owner has not heartbeated for this long is
     * dead: a live owner heartbeats before every send, i.e. at most every
     * ~24 s, and no slice may run past GROUP_JOB_TIMEOUT_SECONDS.
     */
    public const GROUP_CLAIM_STALE_AFTER_SECONDS = 900;

    /**
     * An UNCLAIMED queued batch (never started, or waiting for its
     * continuation slice) is only presumed abandoned after this long, to
     * tolerate ordinary queue latency and backlog.
     */
    public const GROUP_UNCLAIMED_STALE_AFTER_SECONDS = 3600;

    /**
     * Atomically take ownership of a queued group batch. A conditional
     * UPDATE, not a read-then-write: of any number of concurrent callers
     * exactly one moves claim_token from NULL to its own token, and the
     * rest get false and must not send anything.
     */
    public function claimGroupDispatch(string $token): bool
    {
        return self::query()
            ->whereKey($this->getKey())
            ->where('recipient_type', 'group')
            ->where('status', self::STATUS_QUEUED)
            ->whereNull('claim_token')
            ->update(['claim_token' => $token, 'claimed_at' => now()]) === 1;
    }

    /**
     * Called before every send. Refreshes the heartbeat and answers "do I
     * still own a still-queued batch?". False means the batch was settled
     * (failed()/recovery) or is owned by someone else: the caller must stop
     * without sending.
     *
     * Ownership is decided by the SELECT, not by the UPDATE's affected-row
     * count: MySQL/MariaDB report 0 affected rows when claimed_at is
     * rewritten with the same second, which would look like a lost claim.
     */
    public function heartbeatGroupDispatch(string $token): bool
    {
        $owned = fn () => self::query()
            ->whereKey($this->getKey())
            ->where('status', self::STATUS_QUEUED)
            ->where('claim_token', $token);

        $owned()->update(['claimed_at' => now()]);

        return $owned()->exists();
    }

    /**
     * Give the batch back (only if this token still owns it) so the next
     * slice can claim it. claimed_at keeps the time of the hand-over, which
     * is what the unclaimed stale threshold measures from.
     */
    public function releaseGroupDispatchClaim(string $token): bool
    {
        return self::query()
            ->whereKey($this->getKey())
            ->where('status', self::STATUS_QUEUED)
            ->where('claim_token', $token)
            ->update(['claim_token' => null, 'claimed_at' => now()]) === 1;
    }

    /**
     * reference_ids of the recipient rows this batch already has, so a
     * continuation slice skips every recipient an earlier slice attempted.
     *
     * @return array<int, true> keyed by reference_id
     */
    public function recordedGroupRecipientReferenceIds(string $referenceType): array
    {
        return self::query()
            ->where('parent_dispatch_id', $this->getKey())
            ->where('account_id', $this->account_id)
            ->where('recipient_type', self::RECIPIENT_TYPE_GROUP_RECIPIENT)
            ->where('reference_type', $referenceType)
            ->pluck('reference_id')
            ->mapWithKeys(fn ($id) => [(int) $id => true])
            ->all();
    }

    /**
     * Settle a queued group batch from what was ACTUALLY delivered, as
     * persisted in its recipient rows (sent_at is set only when the
     * provider accepted the message, and is never cleared afterwards).
     *
     *   delivered = recipient rows with sent_at (capped at the reservation)
     *   refund    = reserved - delivered
     *
     * The refund, the counts and the terminal status are written by the
     * existing resolveGroupDispatch(), inside this same locked transaction,
     * so it happens at most once whoever calls this (normal completion,
     * a caught exception, the job's failed() hook, or stale recovery) and
     * however many times.
     *
     * $onlyIfStale: the recovery command's re-check, made under the row
     * lock, so a batch whose owner heartbeated after it was listed is left
     * alone.
     *
     * @return bool true when THIS call settled the batch.
     */
    public function settleGroupDispatchFromRecipients(?string $reason = null, bool $onlyIfStale = false): bool
    {
        return (bool) DB::transaction(function () use ($reason, $onlyIfStale) {
            /** @var self|null $locked */
            $locked = self::query()->whereKey($this->getKey())->lockForUpdate()->first();

            if (! $locked || $locked->status !== self::STATUS_QUEUED || $locked->recipient_type !== 'group') {
                return false;
            }

            if ($onlyIfStale && ! $locked->isStaleGroupDispatch()) {
                return false;
            }

            $reserved = max(0, (int) $locked->recipient_count);

            $delivered = min($reserved, self::query()
                ->where('parent_dispatch_id', $locked->getKey())
                ->where('account_id', $locked->account_id)
                ->where('recipient_type', self::RECIPIENT_TYPE_GROUP_RECIPIENT)
                ->whereNotNull('sent_at')
                ->count());

            $settled = $locked->resolveGroupDispatch($delivered, $reserved - $delivered, $reason);

            if ($settled) {
                $this->forceFill($locked->getAttributes())->syncOriginal();
            }

            return $settled;
        });
    }

    /** True when this queued group batch's owner is gone (see the thresholds above). */
    public function isStaleGroupDispatch(): bool
    {
        if ($this->status !== self::STATUS_QUEUED || $this->recipient_type !== 'group') {
            return false;
        }

        if ($this->claim_token !== null) {
            return $this->claimed_at !== null
                && $this->claimed_at->lte(now()->subSeconds(self::GROUP_CLAIM_STALE_AFTER_SECONDS));
        }

        $lastActivity = $this->claimed_at ?? $this->created_at;

        return $lastActivity !== null
            && $lastActivity->lte(now()->subSeconds(self::GROUP_UNCLAIMED_STALE_AFTER_SECONDS));
    }

    /** Queued group batches that look abandoned; each is re-checked under lock before settling. */
    public function scopeStaleGroupDispatches(Builder $query): Builder
    {
        $claimCutoff = now()->subSeconds(self::GROUP_CLAIM_STALE_AFTER_SECONDS);
        $unclaimedCutoff = now()->subSeconds(self::GROUP_UNCLAIMED_STALE_AFTER_SECONDS);

        return $query
            ->where('status', self::STATUS_QUEUED)
            ->where('recipient_type', 'group')
            ->where(function (Builder $q) use ($claimCutoff, $unclaimedCutoff) {
                $q->where(fn (Builder $c) => $c->whereNotNull('claim_token')->where('claimed_at', '<=', $claimCutoff))
                    ->orWhere(fn (Builder $c) => $c->whereNull('claim_token')->whereNotNull('claimed_at')->where('claimed_at', '<=', $unclaimedCutoff))
                    ->orWhere(fn (Builder $c) => $c->whereNull('claim_token')->whereNull('claimed_at')->where('created_at', '<=', $unclaimedCutoff));
            });
    }
}
