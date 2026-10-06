<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Concerns\ResolvesTenantAccount;
use App\Http\Controllers\Controller;
use App\Models\MessageDispatchLog;
use App\Models\MessageTemplate;
use App\Services\WhatsApp\DirectMessageDispatcher;
use App\Support\WhatsAppMediaPayloadBuilder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;

/**
 * [New feature, disclosed] — Message Logs / Audit Trail sidebar page's
 * backend. Deliberately a SEPARATE controller/table from the pre-existing
 * MessageLogController (`/api/alerts/logs`, backed by `payment_alerts`) —
 * that endpoint already feeds the live Analytics page's "Recent
 * Transactions" grid and CSV/PDF exports (ExportController) and is left
 * completely untouched to avoid any regression risk to a working, actively
 * used feature. This controller is the new, unified view across EVERY
 * dispatch pathway (Send Alert, Send Template, Chatbot, Journey Builder —
 * web and external-API entry points alike) via `message_dispatch_logs`.
 *
 * Gated on the same `permission:view-logs` tier as MessageLogController —
 * see routes/api.php — since this exposes the same class of row-level PII
 * (recipient phone numbers).
 */
class MessageDispatchLogController extends Controller
{
    use ResolvesTenantAccount;

    // Group Messaging Phase 4 — 'queued' added: a group-dispatch
    // batch's MessageDispatchLog row is created at enqueue time in this
    // in-flight state (see MessageDispatchLog::recordGroupDispatchQueued())
    // and only later resolves to 'sent'/'failed'; a tenant filtering
    // this grid needs to be able to select it like any other status.
    private const VALID_STATUSES = ['sent', 'failed', 'queued'];

    // Phase 5 P5-A — 'social_inbox' (P5-2's Social Inbox lead reply) and
    // 'meta_lead_ads' (the Lead Ads notice/welcome) are sources the
    // unified direct-send path now writes; filterable like any other.
    private const VALID_SOURCES = ['web_ui', 'web_template', 'api', 'chatbot', 'journey', 'social_inbox', 'meta_lead_ads'];

    // Group Messaging Phase 5 — Dashboard Analytics Upgrade. Matches
    // the recipient_type column's own DB default ('individual', see
    // the migration adding it) plus the one other value
    // recordGroupDispatchQueued() ever writes ('group').
    // Phase 5 Task 5 -- 'group_recipient' added: a group batch now also
    // writes one row per actual recipient underneath its aggregate row
    // (MessageDispatchLog::recordGroupRecipient()), and a tenant needs to
    // be able to select that slice of the grid like any other. Purely
    // additive: the two pre-existing values are unchanged, and a request
    // that sends no recipient_type filter still sees every row.
    private const VALID_RECIPIENT_TYPES = ['individual', 'group', MessageDispatchLog::RECIPIENT_TYPE_GROUP_RECIPIENT];

    /** GET /api/message-logs — paginated, filterable data grid. */
    public function index(Request $request): JsonResponse
    {
        $account = $this->resolveAccount($request);
        $filters = $this->validateFilters($request);

        $perPage = min((int) $request->integer('per_page', 15), 100);

        $query = $this->filteredQuery($account?->id, $filters);

        if (! $account) {
            // Super Admin, no client selected: label every row with its
            // tenant — mirrors MessageLogController::index()'s identical
            // pattern for the pre-existing log grid.
            $query->with('account:id,company_name');
        }

        $logs = $query->latest('id')->paginate($perPage)->withQueryString();

        return response()->json($logs->toArray() + ['scope' => $account ? 'account' : 'global']);
    }

    /**
     * GET /api/message-logs/{id} -- the complete record behind one grid row for the "View" action.
     *
     * Tenant scoping is identical to index(): the account resolved by tenant.isolation (a tenant's own account, a
     * Super Admin's selected client, an Agent's own/sub-client account) constrains the query, and a Super Admin with
     * no client selected sees every tenant. A row outside that scope is a 404 -- indistinguishable from a missing id --
     * so changing the id in the URL discloses nothing. Nothing is returned that the list does not already expose except
     * the full resolved message text, the template code and a sanitised media descriptor (never a storage path).
     */
    public function show(Request $request, int $id): JsonResponse
    {
        $account = $this->resolveAccount($request);

        $log = ($account ? MessageDispatchLog::forAccount($account->id) : MessageDispatchLog::query())
            ->with(['account:id,company_name', 'apiKey:id,name,key_prefix'])
            ->findOrFail($id);

        $templateCode = null;
        if ($log->reference_type === 'template' && $log->reference_id) {
            // Looked up through the log's own account scope: a template is only reported when it belongs to, or is
            // shared with, the tenant that sent it.
            $templateCode = MessageTemplate::query()
                ->whereKey($log->reference_id)
                ->where(fn ($q) => $q->where('account_id', $log->account_id)->orWhereNull('account_id'))
                ->value('template_code');
        }

        $log->makeVisible('message_body');
        $data = $log->toArray();
        // Rows written before message_body existed fall back to the preview they did keep.
        $data['message_body'] = $log->message_body ?? $log->message_preview;
        $data['message_body_is_complete'] = $log->message_body !== null || $log->message_preview === null;
        $data['template_code'] = $templateCode;
        $data['media'] = $this->mediaDescriptor($log);
        $data['api_key'] = $log->apiKey ? ['name' => $log->apiKey->name, 'key_prefix' => $log->apiKey->key_prefix] : null;
        unset($data['api_key_id']);

        return response()->json(['data' => $data, 'scope' => $account ? 'account' : 'global']);
    }

    /**
     * POST /api/message-logs/{id}/resend — "Resend" action (owner request
     * 2026-10-05: "if someone user failed the message so need to one
     * button for resend in ui in message logs").
     *
     * Deliberately resends the ALREADY-RESOLVED text the failed attempt
     * carried (message_body, falling back to message_preview for a row
     * logged before that column existed) rather than re-rendering from a
     * template. That's what makes this work identically no matter which
     * pathway produced the original row (Send Alert with a template,
     * Send Alert's "No template" option, chatbot, journey, or the
     * Developer API) -- message_body/message_preview already hold the
     * final, fully-resolved text for every one of those, and a template
     * row's own template could since have been edited, unapproved, or
     * deleted, which would make a live re-render unreliable anyway.
     *
     * Writes a BRAND NEW MessageDispatchLog row for this attempt via
     * DirectMessageDispatcher (the same dispatcher Send Alert's "No
     * template" option and the Developer API's no_template sentinel both
     * already use) -- the original failed row is left exactly as it was,
     * so the audit trail keeps both the original failure and this retry
     * as separate, honest entries.
     *
     * Scoped the same as show()/index() for WHICH rows are visible
     * (tenant isolation via resolveAccount()), plus the route's own
     * additional permission:send-messages gate -- view-logs alone lets a
     * role see this grid, but resending is a real outbound send and
     * costs quota, so it needs the same permission Send Alert itself
     * requires.
     */
    public function resend(Request $request, int $id): JsonResponse
    {
        $account = $this->resolveAccount($request);

        $log = ($account ? MessageDispatchLog::forAccount($account->id) : MessageDispatchLog::query())
            ->findOrFail($id);

        if ($log->status !== 'failed') {
            return response()->json(['message' => 'Only a failed message can be resent.'], 422);
        }

        // Group Messaging's aggregate batch row ('group') has no single
        // recipient -- recipient_phone is the "group:{id}" placeholder,
        // not a real number. A 'group_recipient' row (one member of a
        // batch) DOES carry a real recipient_phone and is resendable like
        // any individual send.
        if ($log->recipient_type === 'group' || str_starts_with((string) $log->recipient_phone, 'group:')) {
            return response()->json(['message' => "A group batch can't be resent as a whole — resend its individual recipients instead."], 422);
        }

        $text = trim((string) ($log->message_body ?? $log->message_preview ?? ''));
        $mediaUrl = trim((string) ($log->media_url ?? ''));

        if ($text === '' && $mediaUrl === '') {
            return response()->json(['message' => 'This message has no stored content to resend (it was logged before full message text was kept).'], 422);
        }

        if ($mediaUrl !== '') {
            $messageType = 'media';
            $content = [
                'media_type' => WhatsAppMediaPayloadBuilder::inferMediaType($mediaUrl),
                'url' => $mediaUrl,
                'caption' => $text !== '' ? $text : null,
            ];
        } else {
            $messageType = 'text';
            $content = ['body' => $text];
        }

        $result = DirectMessageDispatcher::dispatch(
            $log->account_id,
            $log->recipient_phone,
            $messageType,
            $content,
            source: 'web_ui',
        );

        return match ($result['status']) {
            'sent' => response()->json(['message' => 'Message resent.', 'dispatch_log_id' => $result['dispatch_log_id'] ?? null]),
            'not_found' => response()->json(['message' => $result['message']], 404),
            'disconnected' => response()->json(['message' => 'WhatsApp account is disconnected. Please connect your device first.'], 422),
            'quota_exhausted' => response()->json(['message' => $result['message']], 402),
            default => response()->json(['message' => $result['message'] ?? 'Could not resend this message.'], 422),
        };
    }

    /** @return array{has_media: bool, name: ?string, type: ?string, url: ?string} */
    private function mediaDescriptor(MessageDispatchLog $log): array
    {
        $url = $log->media_url;
        if (! $log->has_media || ! $url) {
            return ['has_media' => (bool) $log->has_media, 'name' => null, 'type' => null, 'url' => null];
        }

        $path = (string) parse_url($url, PHP_URL_PATH);
        $name = $path !== '' ? rawurldecode(basename($path)) : null;
        $ext = strtolower(pathinfo((string) $name, PATHINFO_EXTENSION));
        $type = match (true) {
            in_array($ext, ['jpg', 'jpeg', 'png', 'gif', 'webp'], true) => 'image',
            in_array($ext, ['mp4', 'mov', '3gp', 'webm'], true) => 'video',
            in_array($ext, ['mp3', 'ogg', 'wav', 'm4a', 'aac'], true) => 'audio',
            $ext !== '' => 'document',
            default => null,
        };
        // Only an absolute http(s) URL is ever handed to the browser; a filesystem path or internal scheme is not.
        $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));
        $safeUrl = in_array($scheme, ['http', 'https'], true) ? $url : null;

        return ['has_media' => true, 'name' => $name, 'type' => $type, 'url' => $safeUrl];
    }

    /**
     * @return array{search?: string, status?: string, source?: string, recipient_type?: string, from?: string, to?: string}
     */
    private function validateFilters(Request $request): array
    {
        return $request->validate([
            'search' => ['sometimes', 'string', 'max:255'],
            'status' => ['sometimes', Rule::in(self::VALID_STATUSES)],
            'source' => ['sometimes', Rule::in(self::VALID_SOURCES)],
            'recipient_type' => ['sometimes', Rule::in(self::VALID_RECIPIENT_TYPES)],
            'from' => ['sometimes', 'date'],
            'to' => ['sometimes', 'date'],
        ]);
    }

    /** Null $accountId = every tenant (Super Admin, no client selected). */
    private function filteredQuery(?int $accountId, array $filters)
    {
        $query = $accountId ? MessageDispatchLog::forAccount($accountId) : MessageDispatchLog::query();

        if (! empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        if (! empty($filters['source'])) {
            $query->where('source', $filters['source']);
        }

        if (! empty($filters['recipient_type'])) {
            $query->where('recipient_type', $filters['recipient_type']);
        }

        if (! empty($filters['search'])) {
            // [New feature, disclosed]: also matches template_name and,
            // since Group Messaging Phase 5, group_name — a tenant
            // searching "Vendor Group A" should find that group's
            // dispatch batches the same way searching "Order
            // Confirmation" already finds every send of that template.
            $search = $filters['search'];
            $query->where(function ($q) use ($search) {
                $q->where('recipient_phone', 'like', "%{$search}%")
                    ->orWhere('template_name', 'like', "%{$search}%")
                    ->orWhere('group_name', 'like', "%{$search}%");
            });
        }

        if (! empty($filters['from'])) {
            $query->where('created_at', '>=', Carbon::parse($filters['from'])->startOfDay());
        }

        if (! empty($filters['to'])) {
            $query->where('created_at', '<=', Carbon::parse($filters['to'])->endOfDay());
        }

        return $query;
    }
}
