<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Account;
use App\Services\Industry\IndustryResolver;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use InvalidArgumentException;

/**
 * Phase 11 Task 1 — assign industries to an account. Behind permission:manage-accounts, with the same
 * scoping as every other {id} action in AccountController: a Super Admin reaches any account, an Agent
 * only its OWN sub-clients (anything else is the same 404 as "does not exist"). Assigning an industry
 * grants no access: that also needs the industry's capability (granted through the existing
 * entitlement / plan machinery), the `industry_modules` module, and a permission.
 */
class AccountIndustryController extends Controller
{
    public function show(Request $request, int $id, IndustryResolver $resolver): JsonResponse
    {
        $account = $this->accessibleAccount($request, $id);

        return response()->json(['data' => $this->present($resolver, $account)]);
    }

    public function update(Request $request, int $id, IndustryResolver $resolver): JsonResponse
    {
        $account = $this->accessibleAccount($request, $id);

        $data = $request->validate([
            'industries' => ['present', 'array', 'max:10'],
            'industries.*.industry' => ['required', 'string', 'max:40'],
            'industries.*.subtype' => ['nullable', 'string', 'max:40'],
        ]);

        try {
            $resolver->sync($account, $data['industries'], $request->user());
        } catch (InvalidArgumentException $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage(), 'error_code' => 'INDUSTRY_INVALID'], 422);
        }

        return response()->json(['data' => $this->present($resolver, $account)]);
    }

    private function accessibleAccount(Request $request, int $id): Account
    {
        $account = Account::findOrFail($id);
        $user = $request->user();

        if (! $user->isSuperAdmin()) {
            $agentId = $user->account?->account_type === 'agent' ? $user->account_id : null;
            abort_if($agentId === null || $account->agent_id !== $agentId, 404);
        }

        return $account;
    }

    /** @return list<array{industry: string, subtype: string|null}> */
    private function present(IndustryResolver $resolver, Account $account): array
    {
        return $resolver->forAccount($account)->map(fn ($row) => ['industry' => $row->industry, 'subtype' => $row->subtype])->values()->all();
    }
}
