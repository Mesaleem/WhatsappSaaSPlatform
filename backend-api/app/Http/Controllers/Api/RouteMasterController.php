<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\RouteCategory;
use App\Models\SystemRoute;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * BUILD: Fully Dynamic Categorized Route Master & Nested Permission
 * Matrix UI.
 *
 * Two distinct audiences share this controller:
 *  - tree() is the read endpoint every account-provisioning caller
 *    (Super Admin AND Agent — both hold `manage-accounts`, the
 *    permission this controller's whole route group is gated on, same
 *    as AccountController) uses to render the "Module & Feature Access"
 *    checklist boxes in CreateAccountModal.tsx.
 *  - every other method here (category/route CRUD) is the Super-Admin-
 *    only Route Master management surface (RouteMasterPage.tsx). These
 *    are NOT split into their own permission tier — `manage-accounts`
 *    already gates the whole group at the route level — so each one
 *    additionally self-guards with an inline isSuperAdmin() check,
 *    mirroring the exact precedent MessageTemplateController::test()/
 *    destroy() already established for "same permission tier as the
 *    rest of the group, but this one action is Super-Admin-only".
 *
 * DISCLOSED NAMING CORRECTION: the originating spec asked for this at
 * `/api/v1/dynamic-permissions-tree`. `/api/v1/*` in this codebase is
 * exclusively the external, `auth.apikey`-gated Developer API surface
 * (SHA-256-hashed API keys, no Laravel session — see routes/api.php's
 * V1 group and its own docblock) — a Sanctum-session-authenticated UI
 * endpoint placed there would be unreachable from the web app, exactly
 * the same class of mistake this codebase's own history already made
 * and corrected once for `/api/v1/groups` -> `/api/groups`. This ships
 * at `GET /api/admin/permissions-tree` instead, alongside the rest of
 * this feature's routes, all inside the existing `permission:
 * manage-accounts` + `admin` prefix group in routes/api.php.
 */
class RouteMasterController extends Controller
{
    private function assertSuperAdmin(Request $request): void
    {
        abort_unless((bool) $request->user()?->isSuperAdmin(), 403, 'Only Super Admin may manage the Route Master.');
    }

    /**
     * GET /api/admin/permissions-tree
     *
     * Nested `{ category_id, category_name, icon, routes: [...] }` tree
     * of every ACTIVE route under every ACTIVE category — the dynamic
     * replacement for CreateAccountModal.tsx's hardcoded
     * CORE_COMMON_MODULES / WHATSAPP_SUITE_MODULES / SOCIAL_SUITE_MODULES
     * arrays. A category with zero routes left after filtering is
     * omitted entirely so ModulePermissionMatrix.tsx never has to render
     * an empty box.
     *
     * Agent-vs-Super-Admin filtering, per spec ("If Agent, return only
     * permissions assigned to that Agent"): a Super Admin caller sees
     * every active route; an Agent caller sees only routes flagged
     * `is_agent_assignable` AND whose permission_key is currently inside
     * their OWN account's effectiveModules() — i.e. exactly the set an
     * Agent could actually delegate to a Sub-Client, matching the
     * existing "Agent Module Delegation UI Matrix" canDelegateModule
     * concept CreateAccountModal.tsx's SuiteSection already enforces,
     * now applied server-side too rather than only hiding/disabling the
     * checkbox client-side.
     */
    public function tree(Request $request): JsonResponse
    {
        $user = $request->user();
        $isSuperAdmin = (bool) $user?->isSuperAdmin();
        $agentAccount = (! $isSuperAdmin && $user?->account?->account_type === 'agent') ? $user->account : null;
        $agentEffectiveModules = $agentAccount?->effectiveModules();

        $categories = RouteCategory::query()
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->with(['routes' => function ($query) {
                $query->where('is_active', true)->orderBy('sort_order');
            }])
            ->get();

        $data = $categories
            ->map(function (RouteCategory $category) use ($agentAccount, $agentEffectiveModules) {
                $routes = $category->routes
                    ->when($agentAccount !== null, function ($routes) use ($agentEffectiveModules) {
                        return $routes->filter(
                            fn (SystemRoute $route) => $route->is_agent_assignable
                                && in_array($route->permission_key, $agentEffectiveModules ?? [], true)
                        );
                    })
                    ->values()
                    ->map(fn (SystemRoute $route) => [
                        'id' => $route->id,
                        'route_title' => $route->route_title,
                        'route_path' => $route->route_path,
                        'permission_key' => $route->permission_key,
                        'is_agent_assignable' => $route->is_agent_assignable,
                    ]);

                return [
                    'category_id' => $category->id,
                    'category_name' => $category->category_name,
                    'category_code' => $category->category_code,
                    'icon' => $category->icon_name,
                    'routes' => $routes,
                ];
            })
            ->filter(fn (array $category) => count($category['routes']) > 0)
            ->values();

        return response()->json(['data' => $data]);
    }

    /**
     * GET /api/admin/route-categories — Route Master management list
     * (includes inactive categories, unlike tree() above). Super-Admin
     * only.
     */
    public function categories(Request $request): JsonResponse
    {
        $this->assertSuperAdmin($request);

        $categories = RouteCategory::query()
            ->withCount('routes')
            ->orderBy('sort_order')
            ->get();

        return response()->json(['data' => $categories]);
    }

    public function storeCategory(Request $request): JsonResponse
    {
        $this->assertSuperAdmin($request);

        $data = $request->validate([
            'category_name' => ['required', 'string', 'max:255'],
            'category_code' => ['required', 'string', 'max:100', 'alpha_dash', Rule::unique('route_categories', 'category_code')],
            'icon_name' => ['nullable', 'string', 'max:100'],
            'sort_order' => ['nullable', 'integer', 'min:0'],
            'is_active' => ['sometimes', 'boolean'],
        ]);

        $category = RouteCategory::create($data);

        return response()->json($category, 201);
    }

    public function updateCategory(Request $request, int $id): JsonResponse
    {
        $this->assertSuperAdmin($request);

        $category = RouteCategory::findOrFail($id);

        $data = $request->validate([
            'category_name' => ['sometimes', 'required', 'string', 'max:255'],
            'category_code' => ['sometimes', 'required', 'string', 'max:100', 'alpha_dash', Rule::unique('route_categories', 'category_code')->ignore($category->id)],
            'icon_name' => ['nullable', 'string', 'max:100'],
            'sort_order' => ['sometimes', 'integer', 'min:0'],
            'is_active' => ['sometimes', 'boolean'],
        ]);

        $category->update($data);

        return response()->json($category->fresh());
    }

    /**
     * State Mutation Protocol — a category with existing routes is never
     * deleted outright (no cascade anywhere in this feature; see the
     * system_routes migration's docblock): the Super Admin must first
     * move or delete its child routes via destroyRoute() below, so a
     * route can never end up pointing at a category_id that no longer
     * exists.
     */
    public function destroyCategory(Request $request, int $id): JsonResponse
    {
        $this->assertSuperAdmin($request);

        $category = RouteCategory::withCount('routes')->findOrFail($id);

        abort_if($category->routes_count > 0, 422, 'Remove or reassign every route in this category before deleting it.');

        $category->delete();

        return response()->json(['success' => true]);
    }

    /**
     * GET /api/admin/system-routes?category_id= — Route Master
     * management list (includes inactive routes). Super-Admin only.
     */
    public function routes(Request $request): JsonResponse
    {
        $this->assertSuperAdmin($request);

        $routes = SystemRoute::query()
            ->with('category:id,category_name')
            ->when($request->filled('category_id'), fn ($q) => $q->where('category_id', $request->integer('category_id')))
            ->orderBy('category_id')
            ->orderBy('sort_order')
            ->get();

        return response()->json(['data' => $routes]);
    }

    public function storeRoute(Request $request): JsonResponse
    {
        $this->assertSuperAdmin($request);

        $data = $request->validate([
            'category_id' => ['required', 'integer', Rule::exists('route_categories', 'id')],
            'route_title' => ['required', 'string', 'max:255'],
            'route_path' => ['nullable', 'string', 'max:255'],
            'permission_key' => ['required', 'string', 'max:150'],
            'is_agent_assignable' => ['sometimes', 'boolean'],
            'is_active' => ['sometimes', 'boolean'],
            'sort_order' => ['nullable', 'integer', 'min:0'],
        ]);

        $route = SystemRoute::create($data);

        return response()->json($route->load('category:id,category_name'), 201);
    }

    public function updateRoute(Request $request, int $id): JsonResponse
    {
        $this->assertSuperAdmin($request);

        $route = SystemRoute::findOrFail($id);

        $data = $request->validate([
            'category_id' => ['sometimes', 'required', 'integer', Rule::exists('route_categories', 'id')],
            'route_title' => ['sometimes', 'required', 'string', 'max:255'],
            'route_path' => ['nullable', 'string', 'max:255'],
            'permission_key' => ['sometimes', 'required', 'string', 'max:150'],
            'is_agent_assignable' => ['sometimes', 'boolean'],
            'is_active' => ['sometimes', 'boolean'],
            'sort_order' => ['sometimes', 'integer', 'min:0'],
        ]);

        $route->update($data);

        return response()->json($route->fresh()->load('category:id,category_name'));
    }

    public function destroyRoute(Request $request, int $id): JsonResponse
    {
        $this->assertSuperAdmin($request);

        $route = SystemRoute::findOrFail($id);
        $route->delete();

        return response()->json(['success' => true]);
    }
}
