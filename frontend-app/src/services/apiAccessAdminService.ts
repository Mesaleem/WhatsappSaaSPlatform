import axiosInstance from '../core/api/axiosInstance';
import type { AdminApiAccessRow, AdminChangeRequestRow, ApiSecurityEvent, IpPolicy, ServerBindingSummary } from '../types/developer';

/** Super Admin API-access governance. None of these responses carries a key, secret, credential or hash. */
const apiAccessAdminService = {
  list: () => axiosInstance.get<{ data: AdminApiAccessRow[] }>('/admin/api-access').then((r) => r.data.data),
  changeRequests: () => axiosInstance.get<{ data: AdminChangeRequestRow[] }>('/admin/api-access/change-requests').then((r) => r.data.data),
  approve: (id: number, note?: string) => axiosInstance.post(`/admin/api-access/change-requests/${id}/approve`, { note }).then((r) => r.data),
  reject: (id: number, note?: string) => axiosInstance.post(`/admin/api-access/change-requests/${id}/reject`, { note }).then((r) => r.data),
  revoke: (keyId: number, reason?: string) => axiosInstance.post<{ message: string; data: ServerBindingSummary }>(`/admin/api-access/${keyId}/revoke`, { reason }).then((r) => r.data),
  rebind: (keyId: number, payload: { label?: string; ip_policy: IpPolicy; authorized_ips?: string[] }) =>
    axiosInstance.post<{ message: string; data: ServerBindingSummary }>(`/admin/api-access/${keyId}/rebind`, payload).then((r) => r.data),
  disable: (keyId: number, reason?: string) => axiosInstance.post(`/admin/api-access/${keyId}/disable`, { reason }).then((r) => r.data),
  enable: (keyId: number) => axiosInstance.post(`/admin/api-access/${keyId}/enable`).then((r) => r.data),
  events: (keyId: number) => axiosInstance.get<{ data: ApiSecurityEvent[] }>(`/admin/api-access/${keyId}/events`).then((r) => r.data.data),
};

export default apiAccessAdminService;
