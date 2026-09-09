import axiosInstance from '../core/api/axiosInstance';
import type { AssignableRole, InviteTeamUserPayload, TeamUser, TeamUsersScope } from '../types/team';

const teamService = {
  listUsers() {
    return axiosInstance
      .get<{ data: TeamUser[]; scope: TeamUsersScope }>('/team/users')
      .then((res) => res.data);
  },

  inviteUser(payload: InviteTeamUserPayload) {
    return axiosInstance
      .post<{ message: string; data: TeamUser }>('/team/users', payload)
      .then((res) => res.data);
  },

  toggleUser(id: number) {
    return axiosInstance
      .patch<{ message: string; data: TeamUser }>(`/team/users/${id}/toggle`)
      .then((res) => res.data);
  },

  removeUser(id: number) {
    return axiosInstance.delete<{ message: string }>(`/team/users/${id}`).then((res) => res.data);
  },

  listAssignableRoles() {
    return axiosInstance.get<{ data: AssignableRole[] }>('/team/roles').then((res) => res.data.data);
  },
};

export default teamService;
