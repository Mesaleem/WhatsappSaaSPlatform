/**
 * BUILD: Fully Dynamic Categorized Route Master & Nested Permission
 * Matrix UI. Mirrors backend-api's route_categories/system_routes
 * tables + RouteMasterController's JSON shapes.
 *
 * `permission_key` deliberately reuses the exact same string space as
 * the existing AccountModule slugs (see types/account.ts) — this
 * feature is additive infrastructure on top of accounts.allowed_modules
 * / Account::effectiveModules(), not a replacement of it. It is typed
 * as `string` here rather than `AccountModule` because the whole point
 * of a Route Master is that a Super Admin can add a new row (and thus a
 * new permission_key) at runtime, which a closed frontend union type
 * could never represent.
 */

export interface RouteCategory {
  id: number;
  category_name: string;
  category_code: string;
  icon_name: string | null;
  sort_order: number;
  is_active: boolean;
  /** Present only on GET /api/admin/route-categories (RouteMasterPage's management list). */
  routes_count?: number;
  created_at?: string;
  updated_at?: string;
}

export interface SystemRoute {
  id: number;
  category_id: number;
  /** Present only on GET /api/admin/system-routes (eager-loaded for the management table). */
  category?: { id: number; category_name: string } | null;
  route_title: string;
  route_path: string | null;
  permission_key: string;
  is_agent_assignable: boolean;
  is_active: boolean;
  sort_order: number;
  created_at?: string;
  updated_at?: string;
}

/** One route row as returned inside GET /api/admin/permissions-tree — deliberately narrower than SystemRoute above (no is_active/timestamps: the tree only ever contains already-active rows). */
export interface PermissionsTreeRoute {
  id: number;
  route_title: string;
  route_path: string | null;
  permission_key: string;
  is_agent_assignable: boolean;
}

/** One category group as returned inside GET /api/admin/permissions-tree. */
export interface PermissionsTreeCategory {
  category_id: number;
  category_name: string;
  category_code: string;
  icon: string | null;
  routes: PermissionsTreeRoute[];
}

export interface CreateRouteCategoryPayload {
  category_name: string;
  category_code: string;
  icon_name?: string | null;
  sort_order?: number;
  is_active?: boolean;
}

export type UpdateRouteCategoryPayload = Partial<CreateRouteCategoryPayload>;

export interface CreateSystemRoutePayload {
  category_id: number;
  route_title: string;
  route_path?: string | null;
  permission_key: string;
  is_agent_assignable?: boolean;
  is_active?: boolean;
  sort_order?: number;
}

export type UpdateSystemRoutePayload = Partial<CreateSystemRoutePayload>;
