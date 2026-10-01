<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Account;
use App\Services\Industry\IndustryModuleResolver;
use App\Services\Industry\IndustryRegistry;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Phase 11 Task 1 — read-only industry foundation API.
 *
 *   GET /api/industries                   the registry (static metadata, no tenant data)
 *   GET /api/industry/context             the TARGET account's industries and what is usable now
 *   GET /api/industry/{industry}/modules  one industry's modules (behind industry.guard)
 *
 * Target account = requireTargetAccount(): a Super Admin must pass ?account_id= (422), no fallback.
 */
class IndustryController extends Controller
{
    use \App\Http\Controllers\Concerns\ResolvesTenantAccount;

    public function catalog(IndustryRegistry $registry): JsonResponse
    {
        return response()->json(['data' => $registry->catalog()]);
    }

    public function context(Request $request, IndustryModuleResolver $resolver): JsonResponse
    {
        $account = $this->requireTargetAccount($request);

        return response()->json(['data' => $resolver->contextFor($account, $request->user())]);
    }

    public function modules(Request $request, string $industry, IndustryModuleResolver $resolver): JsonResponse
    {
        $account = $this->requireTargetAccount($request);
        $context = collect($resolver->contextFor($account, $request->user()))->firstWhere('industry', $industry);

        return response()->json(['data' => $context]);
    }
}
