<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Capability;
use App\Models\Plan;
use App\Services\Access\PlanEntitlementReconciliationService;
use App\Services\Access\PlanManagementService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

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
 */
class PlanManagementController extends Controller
{
    public function __construct(
        private readonly PlanManagementService $plans,
        private readonly PlanEntitlementReconciliationService $reconciler,
    ) {
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
            'is_active' => $plan->is_active,
            'capabilities' => $plan->capabilities->pluck('slug')->sort()->values()->all(),
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
            'is_active' => ['sometimes', 'boolean'],
            'capabilities' => ['sometimes', 'array'],
            // distinct stops a payload listing the same capability twice;
            // exists stops an invented slug reaching the service.
            'capabilities.*' => ['string', 'distinct', Rule::exists('capabilities', 'slug')],
        ]);

        $plan = $this->plans->create($data['slug'], $data, $data['capabilities'] ?? []);

        return response()->json([
            'message' => 'Plan created.',
            'data' => [
                'slug' => $plan->slug,
                'capabilities' => $plan->capabilities->pluck('slug')->sort()->values()->all(),
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
            'is_active' => ['sometimes', 'boolean'],
            'capabilities' => ['sometimes', 'array'],
            'capabilities.*' => ['string', 'distinct', Rule::exists('capabilities', 'slug')],
        ]);

        $result = $this->plans->modify(
            $plan,
            $data,
            // array_key_exists, not ??: an explicit [] must clear the
            // bundle, while an absent key must leave it untouched.
            array_key_exists('capabilities', $data) ? $data['capabilities'] : null,
            $request->user()?->id,
        );

        return response()->json([
            'message' => $result['bundle_changed']
                ? 'Plan updated. Affected accounts are being reconciled.'
                : 'Plan updated.',
            'data' => [
                'slug' => $result['plan']->slug,
                'capabilities' => $result['plan']->capabilities->pluck('slug')->sort()->values()->all(),
                'bundle_changed' => $result['bundle_changed'],
                'added' => $result['added'],
                'removed' => $result['removed'],
            ],
        ]);
    }
}
