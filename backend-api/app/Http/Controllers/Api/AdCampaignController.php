<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Concerns\ResolvesTenantAccount;
use App\Http\Controllers\Controller;
use App\Models\AdCampaign;
use App\Services\Ads\Exceptions\AdCampaignConflict;
use App\Services\Ads\Exceptions\AdProviderOutcomeUnknown;
use App\Services\Ads\MetaAdsService;
use Illuminate\Validation\ValidationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use App\Services\SocialAuth\Exceptions\SocialConnectionException;
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
        $account = $this->requireTargetAccount($request);

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
        $account = $this->requireTargetAccount($request);

        $data = $request->validate([
            'campaign_name' => ['required', 'string', 'max:255'],
            'objective' => ['required', 'string', Rule::in(AdCampaign::OBJECTIVES)],
            'daily_budget' => ['required', 'numeric', 'min:1'],
            'cpl_threshold' => ['nullable', 'numeric', 'min:0'],
            'targeting_specs' => ['required', 'array'],
            // Owner request (2026-09-30): countries and/or Meta locations (country / region / city
            // keys from GET /social/ads/locations) — at least one of the two.
            'targeting_specs.countries' => ['nullable', 'array', 'required_without:targeting_specs.locations'],
            'targeting_specs.countries.*' => ['string', 'size:2'],
            'targeting_specs.locations' => ['nullable', 'array', 'max:50', 'required_without:targeting_specs.countries'],
            'targeting_specs.locations.*.key' => ['required', 'string', 'max:64'],
            'targeting_specs.locations.*.type' => ['required', 'string', Rule::in(MetaAdsService::LOCATION_TYPES)],
            'targeting_specs.locations.*.name' => ['nullable', 'string', 'max:255'],
            'placements' => ['nullable', 'array'],
            'placements.*' => ['string', 'distinct', Rule::in(array_keys(MetaAdsService::PLACEMENTS))],
            'targeting_specs.age_min' => ['required', 'integer', 'min:13', 'max:65'],
            'targeting_specs.age_max' => ['required', 'integer', 'min:13', 'max:65', 'gte:targeting_specs.age_min'],
            'targeting_specs.interests' => ['nullable', 'array'],
            'targeting_specs.interests.*' => ['string', 'max:100'],
            'creative' => ['required', 'array'],
            'creative.image_url' => ['nullable', 'url'],
            'creative.headline' => ['required', 'string', 'max:255'],
            'creative.primary_text' => ['required', 'string', 'max:2000'],
            'creative.call_to_action' => ['nullable', 'string', Rule::in(array_unique(array_merge(...array_values(MetaAdsService::CALL_TO_ACTIONS))))],
        ]);

        if (empty($data['targeting_specs']['countries']) && empty($data['targeting_specs']['locations'])) {
            throw ValidationException::withMessages(['targeting_specs.locations' => 'Choose at least one location.']);
        }

        // Phase 10 Task 2 — optional Idempotency-Key: a repeated launch replays the stored row.
        $requestKey = $this->idempotencyKey($request);
        $replayed = false;

        try {
            $campaign = $service->launch($account, $data, $requestKey, $replayed);
        } catch (SocialConnectionException $e) {
            // Phase 9 Task 2 — expired/revoked Meta connection: safe 409 with the reconnect path.
            return $e->render();
        } catch (AdProviderOutcomeUnknown $e) {
            return $this->unconfirmed($e->campaign);
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return $replayed ? $this->replay($campaign) : response()->json(['message' => 'Campaign launched.', 'data' => $this->present($campaign)], 201);
    }

    /**
     * Phase 10 Task 2 — a repeated launch (same Idempotency-Key) never calls
     * Meta again; it reports the stored launch's own state.
     */
    private function replay(AdCampaign $campaign): JsonResponse
    {
        return match ($campaign->status) {
            AdCampaign::STATUS_LAUNCHING => (new AdCampaignConflict('This launch request is still being processed.', 'AD_LAUNCH_IN_PROGRESS', 409, $campaign))->respond($this->present(...)),
            AdCampaign::STATUS_FAILED => (new AdCampaignConflict('This launch request already failed at Meta. Start a new launch to try again.', 'AD_LAUNCH_FAILED', 422, $campaign))->respond($this->present(...)),
            AdCampaign::STATUS_UNCONFIRMED => $this->unconfirmed($campaign),
            default => response()->json(['message' => 'Campaign already launched for this request.', 'replayed' => true, 'data' => $this->present($campaign)]),
        };
    }

    private function unconfirmed(?AdCampaign $campaign): JsonResponse
    {
        return (new AdCampaignConflict(
            'Meta did not confirm the launch. It was not resent automatically — check Meta Ads Manager before launching again.',
            'AD_PROVIDER_OUTCOME_UNKNOWN',
            502,
            $campaign,
        ))->respond($this->present(...));
    }

    private function idempotencyKey(Request $request): ?string
    {
        $key = trim((string) $request->header('Idempotency-Key', ''));

        if ($key === '') {
            return null;
        }

        if (mb_strlen($key) > 100 || preg_match('/^[A-Za-z0-9._:\-]+$/', $key) !== 1) {
            throw ValidationException::withMessages(['Idempotency-Key' => 'The Idempotency-Key header must be 1-100 letters, digits, ".", "_", ":" or "-".']);
        }

        return $key;
    }

    /**
     * GET /api/social/ads/account — owner request (2026-09-30): the connected
     * Meta ad account's currency, status, amount spent, spend cap and balance
     * (read from Meta; nothing is changed there).
     */
    public function account(Request $request, MetaAdsService $service): JsonResponse
    {
        $account = $this->requireTargetAccount($request);

        try {
            return response()->json(['data' => $service->adAccountInfo($account)]);
        } catch (SocialConnectionException $e) {
            return $e->render();
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    /** GET /api/social/ads/locations?q= — Meta location search (countries, regions, cities) for the launch form. */
    public function locations(Request $request, MetaAdsService $service): JsonResponse
    {
        $account = $this->requireTargetAccount($request);
        $data = $request->validate(['q' => ['required', 'string', 'min:2', 'max:100']]);

        try {
            return response()->json(['data' => $service->searchLocations($account, $data['q'])]);
        } catch (SocialConnectionException $e) {
            return $e->render();
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    /** POST /api/social/ads/{id}/pause — manual pause, distinct from the cron's auto-pause. */
    public function pause(Request $request, int $id, MetaAdsService $service): JsonResponse
    {
        $account = $this->requireTargetAccount($request);
        $campaign = AdCampaign::query()->forAccount($account->id)->findOrFail($id);

        return $this->transition($campaign, AdCampaign::STATUS_PAUSED, $service, 'Campaign paused.', 'Campaign is already paused.');
    }

    /**
     * POST /api/social/ads/{id}/resume — clears any auto-pause record, since
     * a manual resume is an explicit tenant decision to override the guard
     * for now; CheckAdPerformanceRules will auto-pause it again on the next
     * cycle if the same rule still fires.
     */
    public function resume(Request $request, int $id, MetaAdsService $service): JsonResponse
    {
        $account = $this->requireTargetAccount($request);
        $campaign = AdCampaign::query()->forAccount($account->id)->findOrFail($id);

        return $this->transition($campaign, AdCampaign::STATUS_ACTIVE, $service, 'Campaign resumed.', 'Campaign is already active.');
    }

    /**
     * Phase 10 Task 2 — pause/resume through MetaAdsService::changeStatus():
     * one change at a time, no Meta call when already in the target state,
     * status written only after Meta confirms, an unknown outcome never retried.
     */
    private function transition(AdCampaign $campaign, string $target, MetaAdsService $service, string $done, string $already): JsonResponse
    {
        try {
            $changed = $service->changeStatus($campaign, $target);
        } catch (SocialConnectionException $e) {
            // Phase 9 Task 2 — expired/revoked Meta connection: safe 409 with the reconnect path.
            return $e->render();
        } catch (AdCampaignConflict $e) {
            return $e->respond($this->present(...));
        } catch (AdProviderOutcomeUnknown) {
            return (new AdCampaignConflict(
                'Meta did not confirm the change, so the campaign status was left unchanged. Check Meta Ads Manager; repeating the action is safe.',
                'AD_PROVIDER_OUTCOME_UNKNOWN',
                502,
                $campaign->refresh(),
            ))->respond($this->present(...));
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['message' => $changed ? $done : $already, 'data' => $this->present($campaign)]);
    }

    /** PATCH /api/social/ads/{id}/cpl-threshold — Auto-Pause Rule Settings (frontend spec item 3). */
    public function updateCplThreshold(Request $request, int $id): JsonResponse
    {
        $account = $this->requireTargetAccount($request);
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
            'currency' => $campaign->currency,
            'cpl_threshold' => $campaign->cpl_threshold !== null ? (float) $campaign->cpl_threshold : null,
            'spend' => (float) $campaign->last_spend,
            'impressions' => $campaign->last_impressions,
            'leads' => $campaign->last_leads,
            'cpl' => $campaign->last_cpl !== null ? (float) $campaign->last_cpl : null,
            'last_checked_at' => $campaign->last_checked_at?->toIso8601String(),
            'auto_paused_at' => $campaign->auto_paused_at?->toIso8601String(),
            'auto_pause_reason' => $campaign->auto_pause_reason,
            'last_provider_error' => $campaign->last_provider_error,
            'last_provider_error_at' => $campaign->last_provider_error_at?->toIso8601String(),
            'created_at' => $campaign->created_at?->toIso8601String(),
        ];
    }
}
