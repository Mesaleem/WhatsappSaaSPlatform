<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Ai\CopywriterService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Social Media Marketing & Meta Ads Automation Expansion — Final Phase.
 * AI Ad Copywriter & Creative Builder — embedded as the "Generate with
 * AI" button on MetaAdsPage.tsx's Ad Creation Wizard (creative step).
 * Gated on launch-meta-ads (same permission as the wizard itself), not a
 * new permission — generating draft copy is not a distinct capability
 * from launching the campaign it's for.
 */
class AICopywriterController extends Controller
{
    /**
     * POST /api/social/ai/generate
     *
     * Deliberately requires NO tenant/account resolution — see
     * ResolvesTenantAccount's docblock: this endpoint reads nothing from
     * and writes nothing to any tenant-scoped table, it only proxies a
     * (possibly external) text-generation call, so there is no
     * tenant-isolation concern to enforce here.
     */
    public function generate(Request $request, CopywriterService $service): JsonResponse
    {
        $data = $request->validate([
            'product_name' => ['required', 'string', 'max:255'],
            'target_industry' => ['required', 'string', 'max:255'],
            'tone' => ['required', 'string', 'max:100'],
        ]);

        $result = $service->generate($data['product_name'], $data['target_industry'], $data['tone']);

        return response()->json(['data' => $result]);
    }
}
