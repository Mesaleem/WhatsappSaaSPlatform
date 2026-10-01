import axiosInstance from '../core/api/axiosInstance';
import type { AdAccountInfo, AdCampaign, AdCampaignResponse, AdLocation, LaunchCampaignPayload } from '../types/ads';

const BASE = '/social/ads';

/**
 * Social Media Marketing & Meta Ads Automation Expansion — Phase 3.
 * Axios service for the Meta Ads Launcher + Auto-Budget Guard.
 */
const adsService = {
  list() {
    return axiosInstance.get<{ data: AdCampaign[] }>(`${BASE}/`).then((res) => res.data.data);
  },

  /**
   * Phase 10 Task 2 — one Idempotency-Key per launch attempt: a repeat of the
   * SAME attempt (a lost response, a double submit) replays the stored launch
   * instead of creating a second campaign at Meta. The caller keeps the key
   * until it gets a definitive answer.
   */
  launch(payload: LaunchCampaignPayload, idempotencyKey: string = newLaunchKey()) {
    return axiosInstance
      .post<AdCampaignResponse>(`${BASE}/launch`, payload, { headers: { 'Idempotency-Key': idempotencyKey } })
      .then((res) => res.data);
  },

  pause(id: number) {
    return axiosInstance.post<AdCampaignResponse>(`${BASE}/${id}/pause`).then((res) => res.data);
  },

  resume(id: number) {
    return axiosInstance.post<AdCampaignResponse>(`${BASE}/${id}/resume`).then((res) => res.data);
  },

  /** The connected Meta ad account: currency, status, amount spent, spend cap, balance (read from Meta). */
  account() {
    return axiosInstance.get<{ data: AdAccountInfo }>(`${BASE}/account`).then((res) => res.data.data);
  },

  /** Meta location search (countries, regions, cities) for the launch form. */
  locations(q: string) {
    return axiosInstance.get<{ data: AdLocation[] }>(`${BASE}/locations`, { params: { q } }).then((res) => res.data.data);
  },

  updateCplThreshold(id: number, cplThreshold: number | null) {
    return axiosInstance
      .patch<AdCampaignResponse>(`${BASE}/${id}/cpl-threshold`, { cpl_threshold: cplThreshold })
      .then((res) => res.data);
  },
};

export function newLaunchKey(): string {
  if (typeof crypto !== 'undefined' && typeof crypto.randomUUID === 'function') {
    return `adlaunch-${crypto.randomUUID()}`;
  }
  return `adlaunch-${Date.now().toString(36)}-${Math.random().toString(36).slice(2, 12)}`;
}

export default adsService;
