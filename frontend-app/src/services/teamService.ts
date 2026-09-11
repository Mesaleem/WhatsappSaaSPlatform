import axiosInstance from '../core/api/axiosInstance';
import type { AssignableRole, InviteTeamUserPayload, TeamUser, TeamUsersScope, UpdateTeamUserPayload } from '../types/team';
import type { TeamMemberPermissions, UpdateTeamMemberPermissionsPayload } from '../types/permissions';

/**
 * Client-User Mapping — every mutating call below now takes an optional
 * trailing `accountId`. TenantIsolationMiddleware resolves the effective
 * tenant from the request's OWN ?account_id= query param for a Super
 * Admin caller (never from the ambient page-level tenant selector for
 * anyone else — see that middleware's docblock), and axiosInstance's
 * request interceptor already lets a caller-supplied params.account_id
 * override the ambient TenantContext selection for exactly one request
 * (see axiosInstance.ts's own comment anticipating this). So the
 * Create/Edit User modal's own mandatory Client dropdown can target a
 * specific client explicitly, request by request, without needing the
 * user to first change the header's client switcher (and without
 * risking a mismatch between the two). Omit `accountId` to fall back to
 * the ambient selection exactly as before.
 */
function accountParams(accountId?: number) {
  return accountId != null ? { params: { account_id: accountId } } : undefined;
}

const teamService = {
  listUsers() {
    return axiosInstance
      .get<{ data: TeamUser[]; scope: TeamUsersScope }>('/team/users')
      .then((res) => res.data);
  },

  inviteUser(payload: InviteTeamUserPayload, accountId?: number) {
    return axiosInstance
      .post<{ message: string; data: TeamUser }>('/team/users', payload, accountParams(accountId))
      .then((res) => res.data);
  },

  updateUser(id: number, payload: UpdateTeamUserPayload, accountId?: number) {
    return axiosInstance
      .patch<{ message: string; data: TeamUser }>(`/team/users/${id}`, payload, accountParams(accountId))
      .then((res) => res.data);
  },

  toggleUser(id: number, accountId?: number) {
    return axiosInstance
      .patch<{ message: string; data: TeamUser }>(`/team/users/${id}/toggle`, undefined, accountParams(accountId))
      .then((res) => res.data);
  },

  removeUser(id: number, accountId?: number) {
    return axiosInstance
      .delete<{ message: string }>(`/team/users/${id}`, accountParams(accountId))
      .then((res) => res.data);
  },

  listAssignableRoles() {
    return axiosInstance.get<{ data: AssignableRole[] }>('/team/roles').then((res) => res.data.data);
  },

  /** Client Admin Granular Permission Matrix. */
  getPermissions(id: number, accountId?: number) {
    return axiosInstance
      .get<{ data: TeamMemberPermissions }>(`/team/users/${id}/permissions`, accountParams(accountId))
      .then((res) => res.data.data);
  },

  updatePermissions(id: number, payload: UpdateTeamMemberPermissionsPayload, accountId?: number) {
    return axiosInstance
      .patch<{ message: string; data: TeamMemberPermissions }>(`/team/users/${id}/permissions`, payload, accountParams(accountId))
      .then((res) => res.data);
  },
};

export default teamService;
