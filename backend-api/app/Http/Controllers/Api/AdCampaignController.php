<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Concerns\ResolvesTenantAccount;
use App\Http\Controllers\Controller;
use App\Models\AdCampaign;
use App\Services\Ads\MetaAdsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use RuntimeException;

/**
 * Social Media Marketing & Meta Ads Automation Expansion — Phase 3.
 * Meta Ads Launcher.
 *
 * DISCLOSED DEVIATION from the literal spec: the spec's launch() payload
 * lists `account_id` as a request field. Every other endpoint in this
 * codebase (SocialAuthController, WhatsAppController, ...) deliberately
 * NEVER trusts a client-supplied account_id for tenant resolution —
 * TenantIsolationMiddleware's own docblock states this explicitly
 * ("account_id is resolved from the authenticated user server-side;
 * never accepted from the client"), and it is what lets a Super Admin
 * safely act on a chosen tenant via ?account_id= without a malicious or
 * buggy client being able to spoof a DIFFERENT tenant's account_id in a
 * POST body. This controller follows that same established security
 * model via ResolvesTenantAccount::requireAccount() (honors ?account_id=
 * for Super Admin, the header tenant selector for everyone else) instead
 * of reading `account_id` out of the request body — see the Phase 3
 * audit report for this deviation's full reasoning.
 */
class AdCampaignController extends Controller
{
    use ResolvesTenantAccount;

    /** GET /api/social/ads */
    public function index(Request $request): JsonResponse
    {
        $account = $this->requireAccount($request);

        $campaigns = AdCampaign::query()->forAccount($account->id)->latest()->get();

        return response()->json(['data' => $campaigns->map(fn (AdCampaign $c) => $this->present($c))]);
    }

    /**
     * POST /api/social/ads/launch
     *
     * Nothing is persisted until MetaAdsService::launch() has already
     * succeeded end-to-end on Meta's side (see that method's docblock) —
     * a RuntimeException from it (missing connected assets, Meta
     * rejecting the request, network failure, ...) is caught here and
     * returned as a 422 with Meta's own error message where available,
     * never a generic 500.
     */
    public function launch(Request $request, MetaAdsService $service): JsonResponse
    {
        $account = $this->requireAccount($request);

        $data = $request->validate([
            'campaign_name' => ['required', 'string', 'max:255'],
            'objective' => ['required', 'string', Rule::in(AdCampaign::OBJECTIVES)],
            'daily_budget' => ['required', 'numeric', 'min:1'],
            'cpl_threshold' => ['nullable', 'numeric', 'min:0'],
            'targeting_specs' => ['required', 'array'],
            'targeting_specs.countries' => ['required', 'array', 'min:1'],
            'targeting_specs.countries.*' => ['string', 'size:2'],
            'targeting_specs.age_min' => ['required', 'integer', 'min:13', 'max:65'],
            'targeting_specs.age_max' => ['required', 'integer', 'min:13', 'max:65', 'gte:targeting_specs.age_min'],
            'targeting_specs.interests' => ['nullable', 'array'],
            'targeting_specs.interests.*' => ['string', 'max:100'],
            'creative' => ['required', 'array'],
            'creative.image_url' => ['nullable', 'url'],
            'creative.headline' => ['required', 'string', 'max:255'],
            'creative.primary_text' => ['required', 'string', 'max:2000'],
        ]);

        try {
            $campaign = $service->launch($account, $data);
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['message' => 'Campaign launched.', 'data' => $this->present($campaign)], 201);
    }

    /** POST /api/social/ads/{id}/pause — manual pause, distinct from the cron's auto-pause. */
    public function pause(Request $request, int $id, MetaAdsService $service): JsonResponse
    {
        $account = $this->requireAccount($request);
        $campaign = AdCampaign::query()->forAccount($account->id)->findOrFail($id);

        try {
            $service->pause($campaign);
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        $campaign->forceFill(['status' => AdCampaign::STATUS_PAUSED])->save();

        return response()->json(['message' => 'Campaign paused.', 'data' => $this->present($campaign)]);
    }

    /**
     * POST /api/social/ads/{id}/resume — clears any auto-pause record, since
     * a manual resume is an explicit tenant decision to override the guard
     * for now; CheckAdPerformanceRules will auto-pause it again on the next
     * cycle if the same rule still fires.
     */
    public function resume(Request $request, int $id, MetaAdsService $service): JsonResponse
    {
        $account = $this->requireAccount($request);
        $campaign = AdCampaign::query()->forAccount($account->id)->findOrFail($id);

        try {
            $service->resume($campaign);
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        $campaign->forceFill([
            'status' => AdCampaign::STATUS_ACTIVE,
            'auto_paused_at' => null,
            'auto_pause_reason' => null,
        ])->save();

        return response()->json(['message' => 'Campaign resumed.', 'data' => $this->present($campaign)]);
    }

    /** PATCH /api/social/ads/{id}/cpl-threshold — Auto-Pause Rule Settings (frontend spec item 3). */
    public function updateCplThreshold(Request $request, int $id): JsonResponse
    {
        $account = $this->requireAccount($request);
        $campaign = AdCampaign::query()->forAccount($account->id)->findOrFail($id);

        $data = $request->validate([
            'cpl_threshold' => ['nullable', 'numeric', 'min:0'],
        ]);

        $campaign->forceFill(['cpl_threshold' => $data['cpl_threshold']])->save();

        return response()->json(['message' => 'CPL threshold updated.', 'data' => $this->present($campaign)]);
    }

    /**
     * @return array<string, mixed>
     */
    private function present(AdCampaign $campaign): array
    {
        return [
            'id' => $campaign->id,
            'meta_campaign_id' => $campaign->meta_campaign_id,
            'name' => $campaign->name,
            'objective' => $campaign->objective,
            'status' => $campaign->status,
            'daily_budget' => (float) $campaign->daily_budget,
            'cpl_threshold' => $campaign->cpl_threshold !== null ? (float) $campaign->cpl_threshold : null,
            'spend' => (float) $campaign->last_spend,
            'impressions' => $campaign->last_impressions,
            'leads' => $campaign->last_leads,
            'cpl' => $campaign->last_cpl !== null ? (float) $campaign->last_cpl : null,
            'last_checked_at' => $campaign->last_checked_at?->toIso8601String(),
            'auto_paused_at' => $campaign->auto_paused_at?->toIso8601String(),
            'auto_pause_reason' => $campaign->auto_pause_reason,
            'created_at' => $campaign->created_at?->toIso8601String(),
        ];
    }
}
