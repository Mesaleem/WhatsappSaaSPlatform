<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Concerns\ResolvesTenantAccount;
use App\Http\Controllers\Controller;
use App\Models\MessageDispatchLog;
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

    // 'web_template_bulk' = SendWhatsAppTemplateJob (queue 'whatsapp-bulk').
    // Public so SourceWhitelistTest can assert every source a dispatcher
    // writes is filterable here and known to the frontend.
    public const VALID_SOURCES = ['web_ui', 'web_template', 'web_template_bulk', 'api', 'chatbot', 'journey'];

    // Group Messaging Phase 5 — Dashboard Analytics Upgrade. Matches
    // the recipient_type column's own DB default ('individual', see
    // the migration adding it) plus the one other value
    // recordGroupDispatchQueued() ever writes ('group').
    private const VALID_RECIPIENT_TYPES = ['individual', 'group'];

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
     * GET /api/message-logs/{id}/template — "View Message" detail for one
     * template send: exactly what went out at the time.
     *
     * Access is identical to index(): same route group (auth, tenant
     * isolation, permission:view-logs, module.guard:message_logs) and the
     * same account scoping. The account comes from TenantIsolationMiddleware
     * (a tenant user's own account; a Super Admin's or Agent's validated
     * ?account_id= selection), never from a raw client value. A row from
     * another account is a 404 via forAccount()->findOrFail(), the same
     * convention as MessageLogController::show(), so another tenant's IDs
     * can't even be confirmed to exist. A Super Admin with no client
     * selected sees every tenant, as in index().
     *
     * Historical accuracy: content comes ONLY from the row itself
     * (template_snapshot, or the legacy truncated message_preview). The
     * current message_templates row is never read, because it may have
     * been edited after the send. Rows sent before the snapshot migration
     * return snapshot_available=false with whatever the row itself holds.
     */
    public function template(Request $request, int $id): JsonResponse
    {
        $account = $this->resolveAccount($request);

        $log = $account
            ? MessageDispatchLog::forAccount($account->id)->findOrFail($id)
            : MessageDispatchLog::with('account:id,company_name')->findOrFail($id);

        if (! $log->isTemplateMessage()) {
            return response()->json([
                'message' => 'This log entry is not a template message.',
                'error_code' => 'NOT_A_TEMPLATE_MESSAGE',
            ], 422);
        }

        $snapshot = is_array($log->template_snapshot) ? $log->template_snapshot : null;
        $media = $snapshot['media'] ?? null;
        $preview = $log->message_preview;

        return response()->json([
            'id' => $log->id,
            'account' => $account ? null : $log->account?->only(['id', 'company_name']),
            'source' => $log->source,
            'status' => $log->status,
            'error_reason' => $log->error_reason,
            'recipient_type' => $log->recipient_type ?? 'individual',
            'recipient' => $log->recipient_phone,
            'group_name' => $log->group_name,
            'recipient_count' => $log->recipient_count,
            'sent_at' => $log->sent_at?->toIso8601String(),
            'created_at' => $log->created_at?->toIso8601String(),
            'provider' => $snapshot['engine'] ?? null,
            'provider_message_id' => $log->gateway_message_id,

            'snapshot_available' => $snapshot !== null,
            'snapshot_scope' => $snapshot['scope'] ?? null,
            'captured_at' => $snapshot['captured_at'] ?? null,

            'template' => [
                'id' => $snapshot['template_id'] ?? ($log->reference_type === 'template' ? $log->reference_id : null),
                'name' => $snapshot['template_title'] ?? $log->template_name,
                'code' => $snapshot['template_code'] ?? null,
                'header_type' => $snapshot['header_type'] ?? null,
                'body' => $snapshot['template_body'] ?? null,
            ],
            'parameters' => $snapshot['parameters'] ?? [],
            'rendered_content' => $snapshot['rendered_message'] ?? null,
            'group_preview' => $snapshot['group_preview'] ?? null,
            // Always the row's own stored snippet (max 160 chars). Flagged
            // so the UI can say it may be cut short when no full rendered
            // content exists.
            'message_preview' => $preview,
            'message_preview_possibly_truncated' => $preview !== null && mb_strlen($preview) >= 160,
            'media' => [
                'has_media' => (bool) $log->has_media,
                'type' => $media['type'] ?? null,
                'url' => $media['url'] ?? $log->media_url,
                'filename' => $media['filename'] ?? null,
            ],
            // This app's templates are its own text templates, sent as plain
            // text (plus an attachment on the QR engine). They have no
            // language, category, namespace, text header, footer or buttons,
            // so none of that exists to return. Listed so it is explicit.
            'not_applicable' => ['template_language', 'template_category', 'template_namespace', 'header_text', 'footer', 'buttons'],
        ]);
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
