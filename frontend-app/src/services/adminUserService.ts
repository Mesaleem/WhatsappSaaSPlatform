import axiosInstance from '../core/api/axiosInstance';

/**
 * Absolute Super Admin Control — Password Override.
 * Backed by POST /api/admin/users/{id}/change-password
 * (AdminUserController::changePassword — Super Admin only, both
 * route-gated and re-checked server-side).
 */
const adminUserService = {
  changePassword(id: number, password: string) {
    return axiosInstance
      .post<{ message: string }>(`/admin/users/${id}/change-password`, { password })
      .then((res) => res.data);
  },
};

export default adminUserService;
