import axiosInstance from '../core/api/axiosInstance';
import type { OrganicPost, OrganicPostStatus, PublishOrganicPostPayload } from '../types/organic';
import type { InsightsSummary, PostInsights, RefreshOutcome } from '../types/organicInsights';

const BASE = '/social/organic-posts';

/**
 * Social/Ads Launcher Overhaul — Step 3. Axios service for the Organic
 * Multi-Channel Publishing Engine (Mode Toggle's "Organic Post" side).
 * Phase 9 Task 3 — scheduling, cancel and retry.
 */
const organicPostService = {
  list(status?: OrganicPostStatus) {
    return axiosInstance
      .get<{ data: OrganicPost[] }>(`${BASE}/`, { params: status ? { status } : undefined })
      .then((res) => res.data.data);
  },

  /** Publish now, or schedule when `scheduled_at` is set (same endpoint). */
  publish(payload: PublishOrganicPostPayload) {
    return axiosInstance.post<{ data: OrganicPost }>(`${BASE}/`, payload).then((res) => res.data.data);
  },

  cancel(id: number) {
    return axiosInstance.post<{ data: OrganicPost }>(`${BASE}/${id}/cancel`).then((res) => res.data.data);
  },

  retry(id: number) {
    return axiosInstance.post<{ data: OrganicPost }>(`${BASE}/${id}/retry`).then((res) => res.data.data);
  },

  /** Phase 9 Task 4 — stored insights snapshot (never calls the platform). */
  insights(id: number) {
    return axiosInstance.get<{ data: PostInsights }>(`${BASE}/${id}/insights`).then((res) => res.data.data);
  },

  /** Phase 9 Task 4 — one controlled refresh from the platform. */
  refreshInsights(id: number) {
    return axiosInstance
      .post<{ data: PostInsights; outcome: RefreshOutcome }>(`${BASE}/${id}/insights/refresh`)
      .then((res) => ({ insights: res.data.data, outcome: res.data.outcome }));
  },

  insightsSummary() {
    return axiosInstance.get<{ data: InsightsSummary }>(`${BASE}/insights/summary`).then((res) => res.data.data);
  },
};

export default organicPostService;
