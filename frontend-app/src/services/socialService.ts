import axiosInstance from '../core/api/axiosInstance';
import type {
  BindAccountsPayload,
  BindAccountsResponse,
  OAuthRedirectResponse,
  SocialAccount,
  SocialConnectionCheckResponse,
  SocialProvider,
  SocialProviderInfo,
} from '../types/social';

const BASE = '/social';

/**
 * Social Media Marketing & Meta Ads Automation Expansion — Phase 1.
 * Axios service for the tenant-scoped Social Accounts hub. The OAuth
 * popup itself is opened by SocialAccountsPage directly on the URL
 * returned by getOAuthRedirectUrl() — it is NOT an axios call, since the
 * provider's consent screen and its redirect back to
 * /api/social/callback/{provider} must happen in a real browser
 * navigation, not an XHR.
 */
const socialService = {
  listAccounts() {
    return axiosInstance.get<{ data: SocialAccount[] }>(`${BASE}/accounts`).then((res) => res.data.data);
  },

  disconnect(id: number) {
    return axiosInstance.delete<{ message: string }>(`${BASE}/accounts/${id}`).then((res) => res.data);
  },

  getOAuthRedirectUrl(provider: SocialProvider) {
    return axiosInstance
      .get<OAuthRedirectResponse>(`${BASE}/oauth/${provider}/redirect`)
      .then((res) => res.data);
  },

  /** Phase 9 Task 1 — which providers this account can connect (configured / enabled). */
  listProviders() {
    return axiosInstance.get<{ data: SocialProviderInfo[] }>(`${BASE}/providers`).then((res) => res.data.data);
  },

  /** Phase 9 Task 1 — ask the provider whether a stored connection still works. */
  checkConnection(id: number) {
    return axiosInstance.post<SocialConnectionCheckResponse>(`${BASE}/accounts/${id}/check`).then((res) => res.data);
  },

  bindAccounts(payload: BindAccountsPayload) {
    return axiosInstance.post<BindAccountsResponse>(`${BASE}/accounts/bind`, payload).then((res) => res.data);
  },
};

export default socialService;
