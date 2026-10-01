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

/**
 * Super Admin in "All Clients (Global View)": a handful of endpoints belong to exactly one client's data
 * (WhatsApp session, chatbot, journeys, groups, education, billing…) and the server answers 422 with a
 * developer-flavoured sentence ("… pass ?account_id="). Nothing is wrong — the Super Admin just has not
 * picked a client — so say that in plain words. Only the wording changes: status, error_code and the
 * request outcome are untouched, and a selected client never reaches this branch.
 */
export const NEEDS_CLIENT_MESSAGE = 'Select a client from the switcher at the top of the page to use this section. It works on one client at a time.';
const NEEDS_CLIENT_PATTERN = /select a client|selected tenant account|no tenant account|requires a selected tenant/i;

export function friendlyNeedsClientError(error: AxiosError<ApiErrorResponse>, hasSelectedClient: boolean): AxiosError<ApiErrorResponse> {
  const data = error.response?.data;
  if (!hasSelectedClient && error.response?.status === 422 && data && typeof data.message === 'string' && NEEDS_CLIENT_PATTERN.test(data.message)) {
    data.message = NEEDS_CLIENT_MESSAGE;
  }
  return error;
}

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

    return Promise.reject(friendlyNeedsClientError(error, selectedAccountId !== null || error.config?.params?.account_id != null));
  },
);

export default axiosInstance;
