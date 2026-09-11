import axiosInstance from '../core/api/axiosInstance';
import type { AdCampaign, AdCampaignResponse, LaunchCampaignPayload } from '../types/ads';

const BASE = '/social/ads';

/**
 * Social Media Marketing & Meta Ads Automation Expansion — Phase 3.
 * Axios service for the Meta Ads Launcher + Auto-Budget Guard.
 */
const adsService = {
  list() {
    return axiosInstance.get<{ data: AdCampaign[] }>(`${BASE}/`).then((res) => res.data.data);
  },

  launch(payload: LaunchCampaignPayload) {
    return axiosInstance.post<AdCampaignResponse>(`${BASE}/launch`, payload).then((res) => res.data);
  },

  pause(id: number) {
    return axiosInstance.post<AdCampaignResponse>(`${BASE}/${id}/pause`).then((res) => res.data);
  },

  resume(id: number) {
    return axiosInstance.post<AdCampaignResponse>(`${BASE}/${id}/resume`).then((res) => res.data);
  },

  updateCplThreshold(id: number, cplThreshold: number | null) {
    return axiosInstance
      .patch<AdCampaignResponse>(`${BASE}/${id}/cpl-threshold`, { cpl_threshold: cplThreshold })
      .then((res) => res.data);
  },
};

export default adsService;
