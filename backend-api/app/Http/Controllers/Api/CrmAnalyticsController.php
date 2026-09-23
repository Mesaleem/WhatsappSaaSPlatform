<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Concerns\ResolvesTenantAccount;
use App\Http\Controllers\Controller;
use App\Models\CrmLead;
use App\Services\Crm\CrmAnalyticsService;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Phase 6 — CRM Task 12. GET /api/crm/analytics
 *
 * Lives inside the /api/crm route group, so it carries exactly the CRM
 * gates (auth:sanctum, tenant.isolation, subscription.guard,
 * module.guard:lead_crm, permission:manage-crm, capability.guard:crm) and
 * no authorization logic of its own. The account comes from
 * requireAccount() — TenantIsolationMiddleware's resolution (Super Admin
 * ?account_id=, an Agent's own sub-clients); a client's ?account_id= is
 * ignored. Read-only: allowed on an expired subscription like every GET.
 *
 * QUERY
 *   from, to          optional, Y-m-d, inclusive, on crm_leads.created_at
 *                     (application timezone). to >= from.
 *   status, source, assigned_user_id (id | none), search, tag_id, tag_ids[]
 *                     the shared CRM lead filter vocabulary
 *                     (CrmLead::filterRules()) — same rules, same messages.
 *
 * RESPONSE 200 { data: { range, filters, totals, by_status, by_source,
 *                        by_assignee, trend } } — see CrmAnalyticsService.
 */
class CrmAnalyticsController extends Controller
{
    use ResolvesTenantAccount;

    public function __construct(private readonly CrmAnalyticsService $analytics)
    {
    }

    public function index(Request $request): JsonResponse
    {
        $account = $this->requireAccount($request);

        $rules = CrmLead::filterRules($account->id);
        unset($rules['per_page']);

        $validated = $request->validate($rules + [
            'from' => ['nullable', 'date_format:Y-m-d'],
            // after_or_equal only when `from` is sent: with no `from`
            // field Laravel would compare against the literal word.
            'to' => array_merge(['nullable', 'date_format:Y-m-d'], $request->filled('from') ? ['after_or_equal:from'] : []),
        ], CrmLead::filterMessages());

        $from = isset($validated['from']) ? CarbonImmutable::createFromFormat('Y-m-d', $validated['from'])->startOfDay() : null;
        $to = isset($validated['to']) ? CarbonImmutable::createFromFormat('Y-m-d', $validated['to'])->startOfDay() : null;
        $filters = array_diff_key($validated, array_flip(['from', 'to']));

        return response()->json([
            'data' => ['filters' => (object) $filters] + $this->analytics->summarize($account, $filters, $from, $to),
        ]);
    }
}
