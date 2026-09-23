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

    private const VALID_SOURCES = ['web_ui', 'web_template', 'api', 'chatbot', 'journey'];

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
