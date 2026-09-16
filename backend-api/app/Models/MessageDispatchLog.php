<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

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
        ];
    }

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
            'has_media' => false,
            'template_name' => $templateName,
            'message_preview' => $messagePreview !== null
                ? mb_substr(trim($messagePreview), 0, self::PREVIEW_MAX_LENGTH)
                : null,
            'sent_at' => null,
        ]);
    }

    /**
     * Called exactly once, by ProcessGroupDispatchJob::handle(), once
     * every member in the batch has been attempted. Resolves the
     * 'queued' row recordGroupDispatchQueued() created above into its
     * final state by UPDATING that same row rather than creating a
     * second one — so dispatch_id (returned synchronously to the API
     * caller at enqueue time) identifies exactly one row across its
     * whole lifecycle. 'sent' if at least one recipient succeeded,
     * 'failed' only if every recipient failed — a disclosed,
     * reasonable choice for a partial-success batch, since this column
     * has no native "partial" state.
     */
    public function resolveGroupDispatch(int $successCount, int $failureCount): void
    {
        $total = $successCount + $failureCount;

        $this->forceFill([
            'status' => $successCount > 0 ? 'sent' : 'failed',
            'sent_at' => $successCount > 0 ? now() : null,
            'error_reason' => $failureCount > 0 ? "{$failureCount} of {$total} recipient(s) failed." : null,
            // Dashboard Analytics Upgrade (Phase 5) — persisted so a
            // "Total Group Messages Sent/Failed" KPI can sum actual
            // per-recipient outcomes instead of counting this one
            // summary row by its coarse sent/failed status (see the
            // migration adding these two columns for the full
            // root-cause explanation).
            'success_count' => $successCount,
            'failure_count' => $failureCount,
        ])->save();
    }
}
