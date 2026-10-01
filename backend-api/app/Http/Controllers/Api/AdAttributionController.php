<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Concerns\ResolvesTenantAccount;
use App\Http\Controllers\Controller;
use App\Models\Account;
use App\Models\AdAttribution;
use App\Services\Ads\AdAttributionService;
use App\Services\Social\Publishing\Exceptions\PublishingDenied;
use App\Services\Social\SocialTargetGate;
use Carbon\CarbonImmutable;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * Phase 10 Task 1 — read-only Ads attribution foundation (no dashboard yet).
 *
 * Authorization, in order: route middleware checks the CALLER
 * (launch-meta-ads | social_ads.view, the meta_ads module, the `ads`
 * capability; target.account for a Super Admin); requireTargetAccount()
 * resolves the target (a Super Admin without ?account_id= gets 422 — never
 * the first client); SocialTargetGate::denialFor(meta_ads, ads) checks the
 * TARGET (active, active subscription, module, capability; no Super Admin
 * bypass). Every query is scoped to that account. The `social` capability
 * does NOT grant this: Ads has its own module + capability.
 */
class AdAttributionController extends Controller
{
    use ResolvesTenantAccount;

    public const MODULE = 'meta_ads';

    public const CAPABILITY = 'ads';

    public const MAX_RANGE_DAYS = 366;

    public function __construct(private readonly AdAttributionService $attribution)
    {
    }

    /** GET /api/social/ads/attribution — latest attribution rows (paginated). */
    public function index(Request $request): JsonResponse
    {
        $account = $this->targetAccount($request);
        $data = $request->validate([
            'source_id' => ['nullable', 'string', 'max:191'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
            'converted' => ['nullable', 'boolean'],
        ]);

        $page = AdAttribution::query()
            ->forAccount($account->id)
            ->when($data['source_id'] ?? null, fn ($q, $sourceId) => $q->where('source_id', $sourceId))
            ->when($request->boolean('converted'), fn ($q) => $q->whereNotNull('converted_at'))
            ->with('adCampaign:id,name,meta_campaign_id,meta_adset_id,meta_ad_id')
            ->orderByDesc('referral_received_at')
            ->orderByDesc('id')
            ->paginate((int) ($data['per_page'] ?? 25));

        return response()->json([
            'data' => collect($page->items())->map(fn (AdAttribution $a) => $this->present($a))->values(),
            'meta' => ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'per_page' => $page->perPage(), 'total' => $page->total()],
        ]);
    }

    /** GET /api/social/ads/attribution/summary?from=&to= — per-ad funnel (reporting foundation). */
    public function summary(Request $request): JsonResponse
    {
        $account = $this->targetAccount($request);
        $data = $request->validate([
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d'],
        ]);

        $to = isset($data['to']) ? CarbonImmutable::createFromFormat('Y-m-d', $data['to'])->startOfDay() : CarbonImmutable::now()->startOfDay();
        $from = isset($data['from']) ? CarbonImmutable::createFromFormat('Y-m-d', $data['from'])->startOfDay() : $to->subDays(29);

        if ($to->lt($from)) {
            throw ValidationException::withMessages(['to' => 'The end date must be on or after the start date.']);
        }
        if ($from->diffInDays($to) + 1 > self::MAX_RANGE_DAYS) {
            throw ValidationException::withMessages(['from' => 'A range can cover at most '.self::MAX_RANGE_DAYS.' days.']);
        }

        return response()->json(['data' => $this->attribution->summary($account->id, $from, $to)]);
    }

    private function targetAccount(Request $request): Account
    {
        $account = $this->requireTargetAccount($request);

        if ($denial = app(SocialTargetGate::class)->denialFor($account, self::MODULE, self::CAPABILITY, 'view ad attribution', [
            'MODULE_DISABLED' => 'Meta Ads is switched off for this account.',
            'CAPABILITY_NOT_ENTITLED' => 'Your current plan does not include Ads. Please upgrade your subscription to unlock it.',
        ], null, true)) { // read: a lapsed subscription still reads, like the launcher list and the dashboard (Phase 10 Task 6)
            throw new HttpResponseException(PublishingDenied::target($denial)->render());
        }

        return $account;
    }

    /** @return array<string, mixed> never the raw provider payload */
    private function present(AdAttribution $a): array
    {
        return [
            'id' => $a->id,
            'provider' => $a->provider,
            'channel' => $a->channel,
            'source_type' => $a->source_type,
            'source_id' => $a->source_id,
            'click_id' => $a->click_id,
            'contact_phone' => $a->contact_phone,
            'campaign' => $a->adCampaign ? [
                'id' => $a->adCampaign->id, 'name' => $a->adCampaign->name, 'external_campaign_id' => $a->adCampaign->meta_campaign_id,
                'external_adset_id' => $a->adCampaign->meta_adset_id, 'external_ad_id' => $a->adCampaign->meta_ad_id,
            ] : null,
            'crm_lead_id' => $a->crm_lead_id,
            'flow_session_id' => $a->flow_session_id,
            'referral_received_at' => $a->referral_received_at?->toIso8601String(),
            'lead_linked_at' => $a->lead_linked_at?->toIso8601String(),
            'journey_started_at' => $a->journey_started_at?->toIso8601String(),
            'converted_at' => $a->converted_at?->toIso8601String(),
            'conversion_value' => $a->conversion_value !== null ? (float) $a->conversion_value : null,
            'conversion_currency' => $a->conversion_currency,
            'headline' => $a->metadata['headline'] ?? null,
            'status' => $a->diagnosticStatus(),
            'match' => $a->metadata['match'] ?? null,
            'campaign_match' => $a->metadata['campaign_match'] ?? null,
        ];
    }
}
