<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Capability;
use App\Models\Plan;
use App\Services\ApiAccess\InstallationAllowanceResolver;
use App\Services\Access\PlanEntitlementReconciliationService;
use App\Services\Access\PlanManagementService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Phase 5 Task 10 — Super-Admin plan management.
 *
 * The platform had no plan CRUD at all before this: plans existed as a
 * static PlanCatalog constant plus seeder-written rows, so changing what
 * a plan sells meant a code change and a re-seed, with nothing
 * reconciling the customers already on it.
 *
 * SUPER ADMIN ONLY, deliberately and without exception. A plan is a
 * GLOBAL object — every tenant on it is affected by an edit — so unlike
 * AccountController's entitlement endpoints there is no Agent path here
 * at all: an Agent may resell capabilities to its own sub-clients
 * (AgentSellingEntitlement, unchanged), but it may not redefine what a
 * platform plan contains for everyone. There is no tenant-scoped
 * variant of these routes and no customer-facing exposure; the existing
 * read-only `/plans` listing that checkout uses is untouched.
 *
 * PRICING AND CAPABILITIES ARE SEPARATE INPUTS. `capabilities` absent
 * means "do not touch the bundle"; an explicit empty array means "clear
 * it". Those are different requests and the service treats them so —
 * a price edit can never silently re-bundle, and a bundle edit can
 * never silently re-price.
 *
 * PHASE 4 TASK 2 UPDATE: a new, optional `capability_limits` input
 * (slug => non-negative int|null) lets a bundled capability carry a
 * concrete `plan_entitlements.usage_limit`, the same pivot column
 * `whatsapp_send` already uses via the seeder — nothing new in the
 * schema, only the first admin-facing write path for it. Every
 * capability NOT named in `capability_limits` keeps the prior
 * unconditional-null behavior, so `external_api` and every other
 * unmetered capability is unaffected.
 *
 * REQUIREMENT 7 (api_installations foundation): a plan that bundles
 * `external_api` must also bundle `api_installations` with an explicit,
 * concrete, non-negative usage_limit before that plan is considered a
 * valid installation-enforcement source. Checked on both store() and
 * update(), against the FINAL bundle/limit the request would produce —
 * mirrors assertCreditsNeedAi()'s existing "final state" pattern below.
 */
class PlanManagementController extends Controller
{
    public function __construct(
        private readonly PlanManagementService $plans,
        private readonly PlanEntitlementReconciliationService $reconciler,
    ) {
    }

    /** Phase 8 Task 2 — per-period cap; fits usage_quotas.allocated (unsigned int). */
    private const MAX_INCLUDED_CREDITS = 1_000_000_000;

    /**
     * Phase 8 Task 2 — a plan may include credits only if it also sells the
     * `ai` capability: credits are AI credits, and a plan that should not
     * give AI must not hand out credits by accident (a zero-credit plan with
     * or without `ai` is always fine). Capability and credits stay separate
     * checks at run time (CreditEntitlementService); this only stops a
     * misconfigured PLAN.
     *
     * @param  array<int, string>  $capabilities  the plan's resulting bundle
     */
    private function assertCreditsNeedAi(int $includedCredits, array $capabilities): void
    {
        if ($includedCredits > 0 && ! in_array(\App\Services\Credits\CreditEntitlementService::CAPABILITY, $capabilities, true)) {
            throw ValidationException::withMessages([
                'included_credits' => ['A plan can include credits only if it also includes the "ai" capability.'],
            ]);
        }
    }

    /**
     * Phase 4 Task 2, requirement 7 — a plan selling `external_api` must
     * also sell `api_installations` with a concrete, non-negative limit.
     * `usage_limit = 0` IS concrete (explicit deny, requirement 3) — only
     * an absent capability or a NULL limit fails this check.
     *
     * @param  array<int, string>  $finalCapabilities  the plan's resulting bundle
     * @param  array<string, int|null>  $finalCapabilityLimits  slug => usage_limit, resulting state
     */
    private function assertApiInstallationsConcreteWhenExternalApi(array $finalCapabilities, array $finalCapabilityLimits): void
    {
        if (! in_array('external_api', $finalCapabilities, true)) {
            return;
        }

        if (! in_array(InstallationAllowanceResolver::CAPABILITY, $finalCapabilities, true)) {
            throw ValidationException::withMessages([
                'capabilities' => ['A plan that includes external_api must also include api_installations.'],
            ]);
        }

        $limit = $finalCapabilityLimits[InstallationAllowanceResolver::CAPABILITY] ?? null;

        if ($limit === null) {
            throw ValidationException::withMessages([
                'capability_limits.'.InstallationAllowanceResolver::CAPABILITY => ['A plan that includes external_api must set a concrete, non-negative usage_limit for api_installations (0 or greater).'],
            ]);
        }
    }

    /**
     * Every key of `capability_limits` must name a capability that is
     * actually in the plan's resulting bundle — a limit for a capability
     * the plan does not (or no longer) sell is meaningless and silently
     * dropped by the service's pivot write otherwise, which would hide a
     * typo'd slug from the caller instead of rejecting it.
     *
     * @param  array<string, int|null>  $capabilityLimits
     * @param  array<int, string>  $finalCapabilities
     */
    private function assertCapabilityLimitKeysAreBundled(array $capabilityLimits, array $finalCapabilities): void
    {
        $unbundled = array_diff(array_keys($capabilityLimits), $finalCapabilities);

        if ($unbundled !== []) {
            throw ValidationException::withMessages([
                'capability_limits' => ['capability_limits names a capability not in this plan\'s bundle: '.implode(', ', $unbundled).'.'],
            ]);
        }
    }

    private function assertSuperAdmin(Request $request): void
    {
        abort_unless($request->user()?->isSuperAdmin(), 403, 'Only Super Admin can manage plans.');
    }

    /** GET /api/admin/plans-management — plans with their capability bundles. */
    public function index(Request $request): JsonResponse
    {
        $this->assertSuperAdmin($request);

        $plans = Plan::with('capabilities')->orderBy('price')->get()->map(fn (Plan $plan) => [
            'slug' => $plan->slug,
            'label' => $plan->label,
            'price' => (float) $plan->price,
            'duration_days' => $plan->duration_days,
            'description' => $plan->description,
            // Phase 5 Task 11 — what makes the plan purchasable.
            'engine_type' => $plan->engine_type,
            'billing_model' => $plan->billing_model,
            'rate_per_message' => $plan->rate_per_message,
            'total_allocated_messages' => $plan->total_allocated_messages,
            // Phase 8 Task 2 — AI credits allocated per purchased period.
            'included_credits' => (int) $plan->included_credits,
            'is_active' => $plan->is_active,
            'capabilities' => $plan->capabilities->pluck('slug')->sort()->values()->all(),
            // Phase 4 Task 2 — the pivot usage_limit per capability, so an
            // admin UI (or this task's tests) can see what each bundled
            // capability's concrete limit is, not only whether it's sold.
            'capability_limits' => $plan->capabilities->mapWithKeys(
                fn (Capability $c) => [$c->slug => $c->pivot->usage_limit === null ? null : (int) $c->pivot->usage_limit]
            ),
            'accounts' => $this->reconciler->candidateAccountIdsForPlan($plan->slug)->count(),
        ]);

        /*
         * Phase 5 Task 12 — each capability carries the providers that
         * CANNOT run it, straight from provider_capabilities.
         *
         * This exists so the admin UI can warn "this plan is QR but the
         * bundle includes journey_automation" WITHOUT encoding the
         * compatibility matrix in React. The frontend renders what the
         * server says; the server remains the only authority, and
         * PlanEntitlementReconciliationService still refuses an
         * incompatible pairing whatever any UI shows.
         *
         * Only explicit `supported = false` rows count — an unstated
         * pairing is not a restriction, matching
         * ProviderCapabilityService::supportsOrNull()'s own semantics.
         */
        $unsupported = DB::table('provider_capabilities')
            ->join('providers', 'providers.id', '=', 'provider_capabilities.provider_id')
            ->join('capabilities', 'capabilities.id', '=', 'provider_capabilities.capability_id')
            ->where('provider_capabilities.supported', false)
            ->get(['capabilities.slug as capability', 'providers.slug as provider'])
            ->groupBy('capability')
            ->map(fn ($rows) => $rows->pluck('provider')->values()->all());

        $capabilities = Capability::orderBy('category')->orderBy('slug')
            ->get(['slug', 'label', 'category'])
            ->map(fn (Capability $capability) => [
                'slug' => $capability->slug,
                'label' => $capability->label,
                'category' => $capability->category,
                'unsupported_providers' => $unsupported->get($capability->slug, []),
            ]);

        return response()->json([
            'data' => $plans,
            // The full vocabulary, so a UI can offer only real slugs.
            'available_capabilities' => $capabilities,
        ]);
    }

    /** POST /api/admin/plans-management */
    public function store(Request $request): JsonResponse
    {
        $this->assertSuperAdmin($request);

        $data = $request->validate([
            'slug' => ['required', 'string', 'max:64', 'regex:/^[a-z0-9_\-]+$/', Rule::unique('plans', 'slug')],
            'label' => ['required', 'string', 'max:255'],
            'price' => ['required', 'numeric', 'min:0'],
            'duration_days' => ['required', 'integer', 'min:1'],
            'description' => ['nullable', 'string', 'max:1000'],
            'engine_type' => ['required', 'string', Rule::in(['qr', 'meta'])],
            'billing_model' => ['required', 'string', Rule::in(['flat_quota', 'per_message', 'unlimited'])],
            'rate_per_message' => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'total_allocated_messages' => ['sometimes', 'nullable', 'integer', 'min:1'],
            // Phase 8 Task 2 — 0 = the plan includes no credits.
            'included_credits' => ['sometimes', 'integer', 'min:0', 'max:'.self::MAX_INCLUDED_CREDITS],
            'is_active' => ['sometimes', 'boolean'],
            'capabilities' => ['sometimes', 'array'],
            // distinct stops a payload listing the same capability twice;
            // exists stops an invented slug reaching the service.
            'capabilities.*' => ['string', 'distinct', Rule::exists('capabilities', 'slug')],
            // Phase 4 Task 2 — slug => usage_limit. min:0 rejects a
            // negative value here, at the HTTP boundary, before it ever
            // reaches the service (requirement 6).
            'capability_limits' => ['sometimes', 'array'],
            'capability_limits.*' => ['nullable', 'integer', 'min:0'],
        ]);

        $finalCapabilities = $data['capabilities'] ?? [];
        $finalCapabilityLimits = $data['capability_limits'] ?? [];

        $this->assertCreditsNeedAi((int) ($data['included_credits'] ?? 0), $finalCapabilities);
        $this->assertCapabilityLimitKeysAreBundled($finalCapabilityLimits, $finalCapabilities);
        $this->assertApiInstallationsConcreteWhenExternalApi($finalCapabilities, $finalCapabilityLimits);

        $plan = $this->plans->create($data['slug'], $data, $finalCapabilities, $finalCapabilityLimits);

        return response()->json([
            'message' => 'Plan created.',
            'data' => [
                'slug' => $plan->slug,
                'included_credits' => (int) $plan->included_credits,
                'capabilities' => $plan->capabilities->pluck('slug')->sort()->values()->all(),
                'capability_limits' => $plan->capabilities->mapWithKeys(
                    fn (Capability $c) => [$c->slug => $c->pivot->usage_limit === null ? null : (int) $c->pivot->usage_limit]
                ),
            ],
        ], 201);
    }

    /**
     * PUT /api/admin/plans-management/{slug}
     *
     * A bundle change queues ReconcilePlanAccountsJob for every account
     * on the plan — the response says so rather than leaving the
     * administrator to wonder whether customers were updated.
     */
    public function update(Request $request, string $slug): JsonResponse
    {
        $this->assertSuperAdmin($request);

        $plan = Plan::where('slug', $slug)->firstOrFail();

        $data = $request->validate([
            'label' => ['sometimes', 'string', 'max:255'],
            'price' => ['sometimes', 'numeric', 'min:0'],
            'duration_days' => ['sometimes', 'integer', 'min:1'],
            'description' => ['sometimes', 'nullable', 'string', 'max:1000'],
            'engine_type' => ['sometimes', 'string', Rule::in(['qr', 'meta'])],
            'billing_model' => ['sometimes', 'string', Rule::in(['flat_quota', 'per_message', 'unlimited'])],
            'rate_per_message' => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'total_allocated_messages' => ['sometimes', 'nullable', 'integer', 'min:1'],
            // Phase 8 Task 2 — 0 = the plan includes no credits.
            'included_credits' => ['sometimes', 'integer', 'min:0', 'max:'.self::MAX_INCLUDED_CREDITS],
            'is_active' => ['sometimes', 'boolean'],
            'capabilities' => ['sometimes', 'array'],
            'capabilities.*' => ['string', 'distinct', Rule::exists('capabilities', 'slug')],
            // Phase 4 Task 2 — see store().
            'capability_limits' => ['sometimes', 'array'],
            'capability_limits.*' => ['nullable', 'integer', 'min:0'],
        ]);

        $bundleTouched = array_key_exists('capabilities', $data);
        $finalCapabilities = $bundleTouched ? $data['capabilities'] : $plan->capabilities()->pluck('slug')->all();

        // The resulting usage_limit per capability after this request:
        // an explicitly supplied limit wins; otherwise, if the capability
        // remains bundled and the bundle itself is being rewritten in
        // this call, its existing pivot value survives unless overwritten
        // (modify() only touches the pivot when $capabilities is
        // supplied at all, in which case every bundled capability's
        // limit is resolved here the same way PlanManagementService
        // resolves it, so this check sees the TRUE resulting state).
        $existingLimitsBySlug = $plan->capabilities->mapWithKeys(
            fn (Capability $c) => [$c->slug => $c->pivot->usage_limit === null ? null : (int) $c->pivot->usage_limit]
        )->all();
        $suppliedLimits = $data['capability_limits'] ?? [];
        $finalCapabilityLimits = [];
        foreach ($finalCapabilities as $capSlug) {
            $finalCapabilityLimits[$capSlug] = array_key_exists($capSlug, $suppliedLimits)
                ? $suppliedLimits[$capSlug]
                : ($bundleTouched ? null : ($existingLimitsBySlug[$capSlug] ?? null));
        }

        $this->assertCreditsNeedAi(
            (int) ($data['included_credits'] ?? $plan->included_credits),
            $finalCapabilities,
        );
        $this->assertCapabilityLimitKeysAreBundled($suppliedLimits, $finalCapabilities);
        $this->assertApiInstallationsConcreteWhenExternalApi($finalCapabilities, $finalCapabilityLimits);

        $result = $this->plans->modify(
            $plan,
            $data,
            // array_key_exists, not ??: an explicit [] must clear the
            // bundle, while an absent key must leave it untouched.
            $bundleTouched ? $data['capabilities'] : null,
            $request->user()?->id,
            // Pass only what was actually supplied this call, not the
            // merged final-state map computed above for validation — the
            // service resolves any omitted slug itself (to null on a
            // bundle rewrite, or by leaving it untouched on a limit-only
            // update), so resending every existing value here would cause
            // needless pivot writes/updated_at churn for capabilities the
            // admin never touched.
            $suppliedLimits,
        );

        return response()->json([
            'message' => $result['bundle_changed']
                ? 'Plan updated. Affected accounts are being reconciled.'
                : 'Plan updated.',
            'data' => [
                'slug' => $result['plan']->slug,
                'included_credits' => (int) $result['plan']->included_credits,
                'capabilities' => $result['plan']->capabilities->pluck('slug')->sort()->values()->all(),
                'capability_limits' => $result['plan']->capabilities->mapWithKeys(
                    fn (Capability $c) => [$c->slug => $c->pivot->usage_limit === null ? null : (int) $c->pivot->usage_limit]
                ),
                'bundle_changed' => $result['bundle_changed'],
                'added' => $result['added'],
                'removed' => $result['removed'],
            ],
        ]);
    }
}
