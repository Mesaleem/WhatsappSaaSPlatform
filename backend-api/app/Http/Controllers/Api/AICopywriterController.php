<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Concerns\ResolvesTenantAccount;
use App\Http\Controllers\Controller;
use App\Services\Ai\CopywriterService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Social/Ads Launcher Overhaul — Step 1 (Gemini Pro Engine & Multi-Token
 * Prompt Refactor). AI Ad Copywriter & Creative Builder — embedded as
 * the "Generate with AI" button on MetaAdsPage.tsx's Ad Creation
 * Wizard (creative step). Gated on launch-meta-ads (same permission as
 * the wizard itself), not a new permission — generating draft copy is
 * not a distinct capability from launching the campaign it's for.
 *
 * DISCLOSED CHANGE FROM PRIOR PHASE: this endpoint previously resolved
 * NO tenant/account at all ("reads nothing from and writes nothing to
 * any tenant-scoped table"). That premise no longer holds — Gemini key
 * resolution is now genuinely per-tenant (accounts.gemini_api_key), so
 * CopywriterService needs the calling Account. ResolvesTenantAccount is
 * now used here for exactly that read, same as every other tenant-scoped
 * controller in this codebase; nothing is written to any tenant table.
 */
class AICopywriterController extends Controller
{
    use ResolvesTenantAccount;

    /**
     * POST /api/social/ai/generate
     */
    public function generate(Request $request, CopywriterService $service): JsonResponse
    {
        $account = $this->requireAccount($request);

        $data = $request->validate([
            // Renamed from the prior 'product_name' — the frontend was
            // actually sending the CAMPAIGN name under that key, not a
            // real business/product name; this refactor gives it its own
            // honest field instead of perpetuating that mislabeling.
            'business_name' => ['required', 'string', 'max:255'],
            'target_industry' => ['required', 'string', 'max:255'],
            // New. Optional — a tenant with no specific promotion running
            // still gets useful copy (see CopywriterService::userPrompt()'s
            // "No specific promotion" fallback phrasing).
            'offer_details' => ['nullable', 'string', 'max:500'],
            // New. Shapes tone/CTA framing (organic engagement vs a paid
            // lead ad's conversion push) — see CopywriterService::TARGET_GOALS.
            'target_goal' => ['required', 'string', Rule::in(CopywriterService::TARGET_GOALS)],
            'tone' => ['required', 'string', 'max:100'],
        ]);

        $result = $service->generate(
            $account,
            $data['business_name'],
            $data['target_industry'],
            $data['offer_details'] ?? '',
            $data['target_goal'],
            $data['tone'],
        );

        return response()->json(['data' => $result]);
    }
}
