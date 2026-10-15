import axiosInstance from '../core/api/axiosInstance';
import type {
  BindAccountsPayload,
  BindAccountsResponse,
  OAuthRedirectResponse,
  OAuthResultResponse,
  OfferedAsset,
  SocialAccount,
  SocialConnectionCheckResponse,
  SocialProvider,
  SocialProviderInfo,
} from '../types/social';

const BASE = '/social';

/** Fired with every freshly loaded account list so app-wide widgets (the header banner) never show a stale status. */
export const SOCIAL_ACCOUNTS_LOADED_EVENT = 'social-accounts-loaded';

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
    return axiosInstance.get<{ data: SocialAccount[] }>(`${BASE}/accounts`).then((res) => {
      window.dispatchEvent(new CustomEvent<SocialAccount[]>(SOCIAL_ACCOUNTS_LOADED_EVENT, { detail: res.data.data }));
      return res.data.data;
    });
  },

  disconnect(id: number) {
    return axiosInstance.delete<{ message: string }>(`${BASE}/accounts/${id}`).then((res) => res.data);
  },

  getOAuthRedirectUrl(provider: SocialProvider) {
    return axiosInstance
      .get<OAuthRedirectResponse>(`${BASE}/oauth/${provider}/redirect`)
      .then((res) => res.data);
  },

  /** Server-side outcome of a connect attempt — polled while the popup is open. */
  getOAuthResult(provider: SocialProvider, nonce: string) {
    return axiosInstance
      .get<OAuthResultResponse>(`${BASE}/oauth/${provider}/result/${encodeURIComponent(nonce)}`)
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

  /** Ads Phase C — the connecting Facebook user's Business portfolios (open connect attempt). */
  listMetaBusinesses(nonce: string) {
    return axiosInstance.get<{ data: { id: string; name: string }[] }>(`${BASE}/oauth/meta/businesses`, { params: { nonce } }).then((res) => res.data.data);
  },

  /** Ads Phase C — create an ad account (INR, India time) and offer it in the open connect attempt. */
  createMetaAdAccount(nonce: string, businessId: string, name: string) {
    return axiosInstance
      .post<{ message: string; data: OfferedAsset }>(`${BASE}/oauth/meta/ad-accounts`, { nonce, business_id: businessId, name })
      .then((res) => res.data.data);
  },

  bindAccounts(payload: BindAccountsPayload) {
    return axiosInstance.post<BindAccountsResponse>(`${BASE}/accounts/bind`, payload).then((res) => res.data);
  },
};

export default socialService;
