import axiosInstance from '../core/api/axiosInstance';
import type {
  CreateRouteCategoryPayload,
  CreateSystemRoutePayload,
  PermissionsTreeCategory,
  RouteCategory,
  SystemRoute,
  UpdateRouteCategoryPayload,
  UpdateSystemRoutePayload,
} from '../types/routeMaster';

const BASE = '/admin';

/**
 * BUILD: Fully Dynamic Categorized Route Master & Nested Permission
 * Matrix UI. See RouteMasterController's docblock for the disclosed
 * `/api/v1/dynamic-permissions-tree` -> `/api/admin/permissions-tree`
 * naming correction — `/v1` in this codebase is reserved for the
 * external Developer API and would be unreachable from this
 * Sanctum-session-authenticated web app.
 */
const routeMasterService = {
  /** Read by BOTH Super Admin and Agent — powers ModulePermissionMatrix.tsx inside CreateAccountModal.tsx. */
  fetchPermissionsTree() {
    return axiosInstance
      .get<{ data: PermissionsTreeCategory[] }>(`${BASE}/permissions-tree`)
      .then((res) => res.data.data);
  },

  // --- Route Master management (Super Admin only — RouteMasterPage.tsx) ---

  listCategories() {
    return axiosInstance.get<{ data: RouteCategory[] }>(`${BASE}/route-categories`).then((res) => res.data.data);
  },

  createCategory(payload: CreateRouteCategoryPayload) {
    return axiosInstance.post<RouteCategory>(`${BASE}/route-categories`, payload).then((res) => res.data);
  },

  updateCategory(id: number, payload: UpdateRouteCategoryPayload) {
    return axiosInstance.put<RouteCategory>(`${BASE}/route-categories/${id}`, payload).then((res) => res.data);
  },

  deleteCategory(id: number) {
    return axiosInstance.delete<{ success: boolean }>(`${BASE}/route-categories/${id}`).then((res) => res.data);
  },

  listRoutes(categoryId?: number) {
    return axiosInstance
      .get<{ data: SystemRoute[] }>(`${BASE}/system-routes`, { params: categoryId ? { category_id: categoryId } : undefined })
      .then((res) => res.data.data);
  },

  createRoute(payload: CreateSystemRoutePayload) {
    return axiosInstance.post<SystemRoute>(`${BASE}/system-routes`, payload).then((res) => res.data);
  },

  updateRoute(id: number, payload: UpdateSystemRoutePayload) {
    return axiosInstance.put<SystemRoute>(`${BASE}/system-routes/${id}`, payload).then((res) => res.data);
  },

  deleteRoute(id: number) {
    return axiosInstance.delete<{ success: boolean }>(`${BASE}/system-routes/${id}`).then((res) => res.data);
  },
};

export default routeMasterService;
