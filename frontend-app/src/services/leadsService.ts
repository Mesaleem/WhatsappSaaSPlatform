import axiosInstance from '../core/api/axiosInstance';
import type { LeadDetail, PaginatedLeads } from '../types/leads';

const BASE = '/social/leads';

export interface ListLeadsParams {
  page?: number;
  per_page?: number;
  search?: string;
}

/**
 * Social Media Marketing & Meta Ads Automation Expansion — Final Phase.
 * Axios service for the Instant Lead CRM (LeadsPage). Read-only —
 * mirrors LeadController, which has no store/update/destroy endpoints.
 */
const leadsService = {
  list(params: ListLeadsParams = {}) {
    return axiosInstance.get<PaginatedLeads>(BASE, { params }).then((res) => res.data);
  },

  get(id: number) {
    return axiosInstance.get<LeadDetail>(`${BASE}/${id}`).then((res) => res.data);
  },
};

export default leadsService;
