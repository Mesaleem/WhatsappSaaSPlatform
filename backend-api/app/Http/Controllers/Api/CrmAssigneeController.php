<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Concerns\ResolvesTenantAccount;
use App\Http\Controllers\Controller;
use App\Models\CrmLead;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Phase 6 — CRM Task 4. The eligible-assignee list behind an ownership
 * selector.
 *
 * WHY NOT REUSE /api/team/users: inspected first, and it is the wrong
 * endpoint for this. It is gated on `manage-team`, a different and
 * higher privilege than `manage-crm` — so a CRM user who may assign
 * leads often cannot call it at all — and it returns every user of the
 * account including inactive ones and those with no CRM access, plus
 * email, phone number, roles and creation dates. Populating a CRM
 * selector from it would either 403 or offer people the assignment
 * write path then refuses, while leaking contact details the selector
 * has no use for.
 *
 * ELIGIBILITY IS NOT RE-IMPLEMENTED HERE. The filter is
 * CrmLead::assigneeIsEligible(), the very predicate CrmLead's saving
 * guard uses, so this list and the write path cannot disagree: anyone
 * this endpoint offers can be assigned, and anyone it omits will be
 * refused.
 *
 * WHAT IT RETURNS: id and name. Nothing else — no email, no phone, no
 * roles, no permissions, no timestamps, and obviously no password hash
 * or token. A selector needs a label and a value; anything more is
 * tenant data leaking through a convenience endpoint.
 *
 * WHAT IT CANNOT DO: reach another tenant. The account comes from
 * TenantIsolationMiddleware, never from input — there is no account_id,
 * agent_id or user_id parameter, so there is no query shape that widens
 * the scope. ?search= filters within the already-scoped set.
 */
class CrmAssigneeController extends Controller
{
    use ResolvesTenantAccount;

    /** GET /api/crm/assignees */
    public function index(Request $request): JsonResponse
    {
        $account = $this->requireAccount($request);

        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:255'],
        ]);

        $assignees = User::query()
            ->where('account_id', $account->id)
            ->where('is_active', true)
            ->when(isset($filters['search']), fn ($q) => $q->where('name', 'like', '%'.$filters['search'].'%'))
            /*
             * Roles and their permissions, plus direct per-user grants,
             * are eager-loaded so the ->can() inside
             * assigneeIsEligible() resolves from memory instead of
             * issuing a query per user. Spatie's permission cache
             * covers the rest.
             */
            ->with(['roles.permissions', 'permissions'])
            ->orderBy('name')
            ->get()
            ->filter(fn (User $user) => CrmLead::assigneeIsEligible($user, (int) $account->id))
            ->map(fn (User $user) => ['id' => $user->id, 'name' => $user->name])
            ->values();

        // {data, scope} mirrors TeamController::index()'s envelope, the
        // closest existing sibling, so the frontend reads one shape.
        return response()->json(['data' => $assignees, 'scope' => 'account']);
    }
}
