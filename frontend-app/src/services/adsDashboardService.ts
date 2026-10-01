import axiosInstance from '../core/api/axiosInstance';
import type { AdsDashboard, AdsDashboardQuery } from '../types/adsDashboard';

/**
 * Phase 10 Task 3 — Ads dashboard (stored campaign / spend / attribution data;
 * the backend never calls Meta for it). A Super Admin's selected client is
 * attached by axiosInstance (?account_id=).
 */
const adsDashboardService = {
  dashboard(query: AdsDashboardQuery) {
    const params = query.range === 'custom' ? { range: 'custom', from: query.from, to: query.to } : { range: query.range };
    return axiosInstance.get<{ data: AdsDashboard }>('/social/ads/dashboard', { params }).then((res) => res.data.data);
  },
};

export default adsDashboardService;
