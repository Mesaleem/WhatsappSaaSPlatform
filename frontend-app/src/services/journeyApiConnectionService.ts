import axiosInstance from '../core/api/axiosInstance';
import type { JourneyApiConnection, SaveJourneyApiConnectionPayload } from '../types/journey';

const BASE = '/whatsapp/api-connections';

/**
 * Phase 8 Task 15 — "Manage All APIs". Axios service for
 * ManageApiConnectionsModal.tsx (opened from JourneyBuilderPage.tsx).
 * Account scoping is the SAME query-param mechanism journeyService.ts's
 * underlying axiosInstance already applies account-wide (TenantIsolation
 * reads it server-side) — nothing special to pass here beyond the normal
 * request.
 */
const journeyApiConnectionService = {
  list() {
    return axiosInstance.get<{ data: JourneyApiConnection[] }>(`${BASE}/`).then((res) => res.data.data);
  },

  create(payload: SaveJourneyApiConnectionPayload) {
    return axiosInstance.post<{ message: string; data: JourneyApiConnection }>(`${BASE}/`, payload).then((res) => res.data.data);
  },

  update(id: number, payload: SaveJourneyApiConnectionPayload) {
    return axiosInstance.put<{ message: string; data: JourneyApiConnection }>(`${BASE}/${id}`, payload).then((res) => res.data.data);
  },

  remove(id: number) {
    return axiosInstance.delete<{ message: string }>(`${BASE}/${id}`).then((res) => res.data);
  },
};

export default journeyApiConnectionService;
