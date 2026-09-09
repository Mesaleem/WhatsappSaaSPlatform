import axios, { AxiosError, type InternalAxiosRequestConfig } from 'axios';
import type { ApiErrorResponse } from '../../types/auth';

export const AUTH_TOKEN_KEY = 'auth_token';

/**
 * Fired on window when the API rejects the current token (401).
 * AuthContext listens for this and clears local session state.
 */
export const AUTH_EVENT_UNAUTHORIZED = 'auth:unauthorized';

/**
 * Super Admin Multi-Tenant Scoping — module-level singleton (not React
 * state) holding the currently "acted as" client account_id, set by
 * TenantContext. Every outgoing request below picks it up automatically,
 * so Dashboard/Send Alert/Analytics/Team Users never need to thread
 * account_id through their own service calls by hand; a page-level
 * request that already sets its own `params.account_id` still wins (see
 * the request interceptor below).
 */
let selectedAccountId: number | null = null;

export function setSelectedAccountId(id: number | null): void {
  selectedAccountId = id;
}

const baseURL = import.meta.env.VITE_API_BASE_URL ?? 'http://localhost:8000/api';

const axiosInstance = axios.create({
  baseURL,
  headers: {
    Accept: 'application/json',
  },
  withCredentials: false,
});

axiosInstance.interceptors.request.use((config: InternalAxiosRequestConfig) => {
  const token = localStorage.getItem(AUTH_TOKEN_KEY);
  if (token) {
    config.headers = config.headers ?? {};
    config.headers.Authorization = `Bearer ${token}`;
  }

  // Super Admin Multi-Tenant Scoping: attach ?account_id= to every request
  // when a client is selected. Spread order matters — a caller-supplied
  // params.account_id (none currently exist, but this keeps the contract
  // safe for future callers) overrides this default rather than the
  // reverse.
  if (selectedAccountId !== null) {
    config.params = { account_id: selectedAccountId, ...(config.params ?? {}) };
  }

  return config;
});

axiosInstance.interceptors.response.use(
  (response) => response,
  (error: AxiosError<ApiErrorResponse>) => {
    // Read-Only Subscription Expired Mode: a 403 SUBSCRIPTION_EXPIRED on a
    // mutating request (SubscriptionGuardMiddleware's server-side backstop
    // — see that middleware's docblock) is no longer turned into a global
    // "blocked" flag/redirect here. AuthContext::isReadOnly() already
    // predicts this outcome synchronously from the cached user, so pages
    // disable their own mutation controls before the request is ever
    // sent; this interceptor's only remaining job is the 401 case below.
    if (error.response?.status === 401) {
      window.dispatchEvent(new CustomEvent(AUTH_EVENT_UNAUTHORIZED));
    }

    return Promise.reject(error);
  },
);

export default axiosInstance;
