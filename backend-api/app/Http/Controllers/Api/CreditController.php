<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Concerns\ResolvesTenantAccount;
use App\Http\Controllers\Controller;
use App\Models\CreditLedgerEntry;
use App\Services\Credits\CreditAccountSummary;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Phase 8 Task 1 — a tenant's OWN credits, read-only.
 *
 * Mounted in the existing /api/billing group (auth:sanctum → tenant.isolation
 * → permission:manage-subscriptions → module.guard:billing), so the account
 * is the one TenantIsolationMiddleware resolved: a client's own account, an
 * Agent's own account or one of its sub-clients (?account_id=, else 404), a
 * Super Admin's selected client. A client's ?account_id= or any body
 * account_id is never read. No mutation endpoint exists here: credits change
 * only through CreditService (Super Admin operations: AdminCreditController).
 */
class CreditController extends Controller
{
    use ResolvesTenantAccount;

    /** GET /api/billing/credits */
    public function balance(Request $request, CreditAccountSummary $summary): JsonResponse
    {
        $account = $this->requireAccount($request, 'Select a client from the header to view their credits.');

        // Phase 8 Task 2 — balances + plan/period context + the two separate
        // checks (AI capability, available credits). See CreditAccountSummary.
        return response()->json(['data' => $summary->for($account)]);
    }

    /** GET /api/billing/credits/ledger — newest first; ?type= filter. */
    public function ledger(Request $request): JsonResponse
    {
        $account = $this->requireAccount($request, 'Select a client from the header to view their credits.');

        $filters = $request->validate([
            'type' => ['sometimes', Rule::in(CreditLedgerEntry::TYPES)],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);

        $entries = CreditLedgerEntry::query()
            ->forAccount($account->id)
            ->when(isset($filters['type']), fn ($q) => $q->where('type', $filters['type']))
            ->orderByDesc('id')
            ->paginate((int) ($filters['per_page'] ?? 25));

        return response()->json($entries);
    }
}
