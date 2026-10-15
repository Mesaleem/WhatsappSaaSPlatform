<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Concerns\ResolvesTenantAccount;
use App\Http\Controllers\Controller;
use App\Models\AdCampaign;
use App\Services\Ads\Exceptions\AdCampaignConflict;
use App\Services\Ads\Exceptions\AdProviderOutcomeUnknown;
use App\Services\Ads\MetaAdsService;
use Illuminate\Validation\ValidationException;
use Illuminate\Support\Carbon;
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

    /** GET /api/social/ads/lead-forms — QA fixes (2026-10-14): the connected Page's instant forms. */
    public function leadForms(Request $request): JsonResponse
    {
        $account = $this->requireTargetAccount($request);

        return response()->json(['data' => app(MetaAdsService::class)->leadForms($account)]);
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
            // Phase B (2026-10-15): a daily budget, or a total (lifetime) budget that needs an end date.
            'budget_type' => ['nullable', 'string', Rule::in([AdCampaign::BUDGET_DAILY, AdCampaign::BUDGET_LIFETIME])],
            'daily_budget' => ['required_unless:budget_type,'.AdCampaign::BUDGET_LIFETIME, 'nullable', 'numeric', 'min:1'],
            'lifetime_budget' => ['required_if:budget_type,'.AdCampaign::BUDGET_LIFETIME, 'nullable', 'numeric', 'min:1'],
            'start_time' => ['nullable', 'date', 'after_or_equal:-10 minutes'],
            'end_time' => ['required_if:budget_type,'.AdCampaign::BUDGET_LIFETIME, 'nullable', 'date', 'after:now', 'after:start_time'],
            'cpl_threshold' => ['nullable', 'numeric', 'min:0'],
            ...self::targetingRules(),
            'creative' => ['required', 'array'],
            'creative.image_url' => ['nullable', 'url'],
            'creative.headline' => ['required', 'string', 'max:255'],
            'creative.primary_text' => ['required', 'string', 'max:2000'],
            'creative.call_to_action' => ['nullable', 'string', Rule::in(array_unique(array_merge(...array_values(MetaAdsService::CALL_TO_ACTIONS))))],
            // QA fixes (2026-10-14): the instant form a lead-generation ad opens.
            'creative.lead_gen_form_id' => ['nullable', 'string', 'max:64', 'regex:/^[0-9]+$/'],
        ]);

        self::assertHasLocation($data);
        $data = self::normalizeSchedule($data);

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

    /** Targeting + placement rules shared by launch() and reachEstimate(). */
    private static function targetingRules(): array
    {
        return [
            'targeting_specs' => ['required', 'array'],
            // Countries, Meta locations (country / region / city keys from GET /social/ads/locations)
            // and/or pinned points with a radius — at least one, see assertHasLocation().
            'targeting_specs.countries' => ['nullable', 'array'],
            'targeting_specs.countries.*' => ['string', 'size:2'],
            'targeting_specs.locations' => ['nullable', 'array', 'max:50'],
            'targeting_specs.locations.*.key' => ['required', 'string', 'max:64'],
            'targeting_specs.locations.*.type' => ['required', 'string', Rule::in(MetaAdsService::LOCATION_TYPES)],
            'targeting_specs.locations.*.name' => ['nullable', 'string', 'max:255'],
            // Meta: a radius around a city is 17-80 km; only cities take one.
            'targeting_specs.locations.*.radius_km' => ['nullable', 'integer', 'min:17', 'max:80', 'prohibited_unless:targeting_specs.locations.*.type,city'],
            'targeting_specs.custom_locations' => ['nullable', 'array', 'max:50'],
            'targeting_specs.custom_locations.*.latitude' => ['required', 'numeric', 'between:-90,90'],
            'targeting_specs.custom_locations.*.longitude' => ['required', 'numeric', 'between:-180,180'],
            // Meta: a radius around a pinned point is 1-80 km.
            'targeting_specs.custom_locations.*.radius_km' => ['required', 'integer', 'min:1', 'max:80'],
            'targeting_specs.custom_locations.*.name' => ['nullable', 'string', 'max:255'],
            'targeting_specs.gender' => ['nullable', 'string', Rule::in(MetaAdsService::GENDERS)],
            'placements' => ['nullable', 'array'],
            'placements.*' => ['string', 'distinct', Rule::in(array_keys(MetaAdsService::PLACEMENTS))],
            'targeting_specs.age_min' => ['required', 'integer', 'min:13', 'max:65'],
            'targeting_specs.age_max' => ['required', 'integer', 'min:13', 'max:65', 'gte:targeting_specs.age_min'],
            'targeting_specs.interests' => ['nullable', 'array'],
            'targeting_specs.interests.*' => ['string', 'max:100'],
        ];
    }

    /**
     * One budget per campaign, schedule as Carbon. Meta: a daily budget with an end
     * date needs more than 24 hours between start and end.
     */
    private static function normalizeSchedule(array $data): array
    {
        $data['budget_type'] ??= AdCampaign::BUDGET_DAILY;
        $start = isset($data['start_time']) ? Carbon::parse($data['start_time']) : null;
        $end = isset($data['end_time']) ? Carbon::parse($data['end_time']) : null;

        if ($data['budget_type'] === AdCampaign::BUDGET_LIFETIME) {
            $data['daily_budget'] = 0;
        } else {
            $data['lifetime_budget'] = null;
            if ($end !== null && ($start ?? now())->copy()->addDay()->gte($end)) {
                throw ValidationException::withMessages(['end_time' => 'With a daily budget the campaign must run for more than 24 hours. Pick a later end date, or use a total budget.']);
            }
        }

        $data['start_time'] = $start;
        $data['end_time'] = $end;

        return $data;
    }

    private static function assertHasLocation(array $data): void
    {
        $specs = $data['targeting_specs'];

        if (empty($specs['countries']) && empty($specs['locations']) && empty($specs['custom_locations'])) {
            throw ValidationException::withMessages(['targeting_specs.locations' => 'Choose at least one location.']);
        }
    }

    /**
     * POST /api/social/ads/reach-estimate — Meta's estimated audience size for
     * the targeting being built in the launch form. A read; never blocks a launch:
     * when Meta cannot estimate, `data` is null.
     */
    public function reachEstimate(Request $request, MetaAdsService $service): JsonResponse
    {
        $account = $this->requireTargetAccount($request);
        $data = $request->validate(self::targetingRules());
        self::assertHasLocation($data);

        try {
            return response()->json(['data' => $service->reachEstimate($account, $data['targeting_specs'], $data['placements'] ?? [])]);
        } catch (SocialConnectionException $e) {
            return $e->render();
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
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

        // Phase B: say why it cannot run (ended / payment due / spending limit) instead of
        // resuming a campaign Meta will not deliver.
        if ($campaign->status === AdCampaign::STATUS_PAUSED && ($blocker = $service->resumeBlocker($campaign))) {
            return response()->json(['message' => $blocker, 'error_code' => 'AD_CAMPAIGN_CANNOT_RUN', 'data' => $this->present($campaign)], 422);
        }

        return $this->transition($campaign, AdCampaign::STATUS_ACTIVE, $service, 'Campaign resumed.', 'Campaign is already active.');
    }

    /**
     * PATCH /api/social/ads/{id}/budget — Phase B: change the campaign's budget (daily
     * or total, whichever it was launched with) and optionally move its end date.
     */
    public function updateBudget(Request $request, int $id, MetaAdsService $service): JsonResponse
    {
        $account = $this->requireTargetAccount($request);
        $campaign = AdCampaign::query()->forAccount($account->id)->findOrFail($id);

        $data = $request->validate([
            'budget' => ['required', 'numeric', 'min:1'],
            'end_time' => ['nullable', 'date', 'after:now'],
        ]);
        $endTime = isset($data['end_time']) ? Carbon::parse($data['end_time']) : null;

        if (! $campaign->isLifetimeBudget() && $endTime !== null && ($campaign->start_time ?? $campaign->created_at)->copy()->addDay()->gte($endTime)) {
            throw ValidationException::withMessages(['end_time' => 'With a daily budget the campaign must run for more than 24 hours.']);
        }

        try {
            $campaign = $service->updateBudget($campaign, (float) $data['budget'], $endTime);
        } catch (SocialConnectionException $e) {
            return $e->render();
        } catch (AdCampaignConflict $e) {
            return $e->respond($this->present(...));
        } catch (AdProviderOutcomeUnknown) {
            return (new AdCampaignConflict('Meta did not confirm the budget change. Check Meta Ads Manager before trying again.', 'AD_PROVIDER_OUTCOME_UNKNOWN', 502, $campaign->refresh()))->respond($this->present(...));
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['message' => 'Budget updated.', 'data' => $this->present($campaign)]);
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
            'budget_type' => $campaign->budget_type ?? AdCampaign::BUDGET_DAILY,
            'lifetime_budget' => $campaign->lifetime_budget !== null ? (float) $campaign->lifetime_budget : null,
            'start_time' => $campaign->start_time?->toIso8601String(),
            'end_time' => $campaign->end_time?->toIso8601String(),
            'has_ended' => $campaign->hasEnded(),
            'meta_stop_status' => $campaign->meta_stop_status,
            'launch_spec' => $campaign->launch_spec,
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
