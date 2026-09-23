<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Concerns\PresentsCrmLeads;
use App\Http\Controllers\Concerns\ResolvesTenantAccount;
use App\Http\Controllers\Controller;
use App\Models\CrmLead;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Phase 6 — CRM Task 6. The Kanban/pipeline view of CRM leads.
 *
 * ============================== API DOCS ==============================
 * GET /api/crm/pipeline
 *
 * AUTH      auth:sanctum -> tenant.isolation -> subscription.guard
 * PERMISSION manage-crm      MODULE lead_crm      CAPABILITY crm
 *           Exactly the gates every other /api/crm/* route carries. No
 *           pipeline-specific permission or entitlement was created:
 *           reading the pipeline is reading CRM leads.
 *
 * PARAMETERS (all optional)
 *   status            one of new | contacted | converted | not_converted.
 *                     Absent -> all four columns. Present -> only that
 *                     column. Anything else -> 422.
 *   assigned_user_id  a positive integer, or the literal `none` for
 *                     unassigned. Anything else -> 422.
 *   source            one of manual | whatsapp | meta_ad | api | journey.
 *   search            substring match on the contact's name or phone.
 *   per_page          leads returned PER COLUMN. Default 20, hard cap 100.
 *   page              which page of EACH returned column. Default 1.
 *
 * RESPONSE
 *   { "data": { "pipeline": [
 *       { "status": "new", "label": "New", "order": 1,
 *         "total": 127,          // filtered count for the whole column
 *         "page": 1, "per_page": 20, "last_page": 7, "has_more": true,
 *         "leads": [ <canonical CRM lead> ... ] },
 *       ... ] } }
 *
 * PAGINATION IS PER COLUMN, and `page` applies to every column returned
 * in that request. That is the shape a Kanban actually consumes — each
 * column scrolls independently — and it is why a frontend loading more
 * of one column passes `?status=<that column>&page=2` to fetch just it.
 * `total` is always the full filtered count and never derived from the
 * returned page, so a column can say 127 while returning 20.
 *
 * ERRORS
 *   401 unauthenticated · 403 MODULE_DISABLED · 403 CAPABILITY_NOT_ENTITLED
 *   403 missing manage-crm · 422 invalid filter
 *
 * READ-ONLY, DELIBERATELY. This endpoint never writes. Moving a lead
 * between columns is a lifecycle change, and the canonical mutation API
 * is PATCH /api/crm/leads/{id}/status — which owns the transition
 * matrix, the terminal-outcome bookkeeping, the no-op guard and the
 * audit trail (Task 5). A second status writer here would be a second
 * set of those rules.
 * ======================================================================
 *
 * ONE SOURCE OF TRUTH THROUGHOUT: the columns come from
 * CrmLead::pipelineStatuses(), the filters from CrmLead::filterRules()
 * and scopeFilter(), the ordering from scopeOrderedForList(), and each
 * card from PresentsCrmLeads — the same definitions /api/crm/leads uses.
 * Nothing about statuses, filtering, ordering or serialization is
 * restated in this class.
 */
class CrmPipelineController extends Controller
{
    use PresentsCrmLeads;
    use ResolvesTenantAccount;

    /** Matches every other CRM list endpoint; an unbounded page size is a denial-of-service knob. */
    private const PER_PAGE_MAX = 100;

    private const PER_PAGE_DEFAULT = 20;

    public function index(Request $request): JsonResponse
    {
        $account = $this->requireAccount($request);

        // Task 7 — the shared rules now include tag_id / tag_ids[]; the
        // tag filter flows through scopeFilter() into both the grouped
        // COUNT and every column's cards. Columns remain status-only.
        $filters = $request->validate(array_merge(
            CrmLead::filterRules($account->id),
            ['page' => ['nullable', 'integer', 'min:1']],
        ), CrmLead::filterMessages());

        $perPage = min((int) $request->integer('per_page', self::PER_PAGE_DEFAULT), self::PER_PAGE_MAX);
        $page = max((int) $request->integer('page', 1), 1);

        // A supplied status narrows the pipeline to that one column; the
        // column set itself still comes from pipelineStatuses(), so a
        // filtered response is the same shape as a full one.
        $columns = collect(CrmLead::pipelineStatuses())
            ->when(isset($filters['status']), fn ($c) => $c->where('status', $filters['status']))
            ->values();

        /*
         * ONE grouped COUNT for every column, rather than four separate
         * count queries or a count per returned collection. Totals are
         * therefore tenant-scoped, filter-scoped and completely
         * independent of per_page — a column can report 127 while
         * returning 20 cards.
         *
         * The status filter is removed from the counting query on
         * purpose: when a status IS supplied we only render that column
         * anyway, and leaving it in would not change that column's own
         * total.
         */
        $totals = CrmLead::query()
            ->forAccount($account->id)
            ->filter(collect($filters)->except('status')->all())
            ->selectRaw('status, COUNT(*) as aggregate')
            ->groupBy('status')
            ->pluck('aggregate', 'status');

        $pipeline = $columns->map(function (array $column) use ($account, $filters, $perPage, $page, $totals) {
            $total = (int) ($totals[$column['status']] ?? 0);

            $leads = CrmLead::query()
                ->forAccount($account->id)
                ->where('status', $column['status'])
                ->filter(collect($filters)->except('status')->all())
                // Eager-loaded so serializing N cards costs 3 queries,
                // not 3N — the relations PresentsCrmLeads reads.
                ->with($this->crmLeadRelations())
                ->orderedForList()
                ->forPage($page, $perPage)
                ->get();

            return [
                'status' => $column['status'],
                'label' => $column['label'],
                'order' => $column['order'],
                'total' => $total,
                'page' => $page,
                'per_page' => $perPage,
                'last_page' => $total === 0 ? 1 : (int) ceil($total / $perPage),
                'has_more' => $page * $perPage < $total,
                'leads' => $leads->map(fn (CrmLead $lead) => $this->presentCrmLead($lead))->values(),
            ];
        })->values();

        return response()->json(['data' => ['pipeline' => $pipeline]]);
    }
}
