import axiosInstance from '../core/api/axiosInstance';
import type {
  Account,
  AccountDetail,
  AccountStatus,
  CreateAccountPayload,
  PaginatedResponse,
  UpdateAccountPayload,
  UpdateAccountPermissionsPayload,
} from '../types/account';
import type { Subscription, UpdateSubscriptionPayload } from '../types/subscription';
import type { ExpiringSoonAccount } from '../types/analytics';

const BASE = '/admin/accounts';

export interface ListAccountsParams {
  page?: number;
  per_page?: number;
  /** Real-time search bar — matches company_name/primary_phone (see AccountController::index()). */
  search?: string;
  status?: AccountStatus;
  /** Date Range Pickers — filters by the account's own provisioning date (created_at), inclusive, 'YYYY-MM-DD'. */
  from?: string;
  to?: string;
}

/**
 * Axios service methods for Module 3's account-provisioning endpoints.
 * All routes require auth:sanctum + permission:manage-accounts on the backend.
 */
const accountService = {
  list(params: ListAccountsParams = {}) {
    return axiosInstance
      .get<PaginatedResponse<Account>>(BASE, { params })
      .then((res) => res.data);
  },

  get(id: number) {
    return axiosInstance.get<AccountDetail>(`${BASE}/${id}`).then((res) => res.data);
  },

  create(payload: CreateAccountPayload) {
    return axiosInstance.post<Account>(BASE, payload).then((res) => res.data);
  },

  /** Account-level fields only (company_name, primary_phone, status). */
  update(id: number, payload: UpdateAccountPayload) {
    return axiosInstance.put<Account>(`${BASE}/${id}`, payload).then((res) => res.data);
  },

  /** Subscription/billing fields on the account's current subscription. */
  updateSubscription(id: number, payload: UpdateSubscriptionPayload) {
    return axiosInstance
      .put<Subscription>(`${BASE}/${id}/subscription`, payload)
      .then((res) => res.data);
  },

  /** Absolute Super Admin Control — start/stop feature modules for a client. */
  updatePermissions(id: number, payload: UpdateAccountPermissionsPayload) {
    return axiosInstance
      .patch<Account>(`${BASE}/${id}/permissions`, payload)
      .then((res) => res.data);
  },

  /** Super Admin Dashboard Enhancement — "Expiring in 7 Days" modal's list. */
  expiringSoon() {
    return axiosInstance
      .get<{ data: ExpiringSoonAccount[] }>(`${BASE}/expiring-soon`)
      .then((res) => res.data.data);
  },
};

export default accountService;
