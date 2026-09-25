<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * IMPLEMENT: Dynamic Route Master with Super-Admin Bypass & Global Audit
 * Tracking — requirement 4's "Super-Admin Audit Trail UI". Read-only:
 * every row here is written exclusively by the LogsActivity trait (see
 * its docblock) — this controller only lists/filters them.
 *
 * Reachable ONLY by whichever role(s) hold the new `view-activity-logs`
 * permission — currently just `super_admin` (RolePermissionSeeder) — so,
 * unlike AuditLogController's per-role scopedQuery() for login history,
 * there is no Agent/Client narrowing to do here: every caller who can
 * reach this endpoint at all is already meant to see every account's
 * rows, filtered by their own explicit choice of Agent/Sub-Client/Date/
 * Module rather than by a forced tenant scope.
 */
class ActivityLogController extends Controller
{
    /** P5-8 — 'allowed' / 'denied' are entitlement-authorization decisions (EntitlementAuditLogger). */
    /** Phase 8 Task 1 — 'replay': a retried credit operation that changed nothing (AdminCreditController). */
    private const ACTION_TYPES = ['create', 'update', 'delete', 'toggle', 'allowed', 'denied', 'replay'];

    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'agent_id' => ['sometimes', 'integer'],
            'account_id' => ['sometimes', 'integer'],
            'module_name' => ['sometimes', 'string', 'max:255'],
            'action_type' => ['sometimes', Rule::in(self::ACTION_TYPES)],
            'from' => ['sometimes', 'date'],
            'to' => ['sometimes', 'date'],
        ]);

        $perPage = min((int) $request->integer('per_page', 25), 100);

        $logs = ActivityLog::query()
            ->with(['user:id,name,email', 'account:id,company_name', 'agent:id,company_name'])
            ->when(! empty($filters['agent_id']), fn ($q) => $q->where('agent_id', $filters['agent_id']))
            ->when(! empty($filters['account_id']), fn ($q) => $q->where('account_id', $filters['account_id']))
            ->when(! empty($filters['module_name']), fn ($q) => $q->where('module_name', $filters['module_name']))
            ->when(! empty($filters['action_type']), fn ($q) => $q->where('action_type', $filters['action_type']))
            ->when(! empty($filters['from']), fn ($q) => $q->whereDate('created_at', '>=', $filters['from']))
            ->when(! empty($filters['to']), fn ($q) => $q->whereDate('created_at', '<=', $filters['to']))
            ->latest('id')
            ->paginate($perPage);

        return response()->json($logs);
    }

    /** GET /api/admin/activity-logs/modules — distinct module_name values, for the UI's Module filter dropdown (independent of the current page's rows). */
    public function modules(): JsonResponse
    {
        $modules = ActivityLog::query()
            ->select('module_name')
            ->distinct()
            ->orderBy('module_name')
            ->pluck('module_name');

        return response()->json(['data' => $modules]);
    }
}
