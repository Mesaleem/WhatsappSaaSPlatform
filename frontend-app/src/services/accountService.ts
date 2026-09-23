import axiosInstance from '../core/api/axiosInstance';
import type {
  Account,
  AccountDetail,
  AccountEntitlementsResponse,
  AccountStatus,
  AccountType,
  CreateAccountPayload,
  PaginatedResponse,
  UpdateAccountPayload,
  UpdateAccountPermissionsPayload,
  UpdateQuotaPayload,
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
  /**
   * 3-Tier Hierarchy (Phase 3 UI) — Super Admin's "Filter by Agent"
   * dropdown on AccountsPage. Backend honors this only for a
   * Super-Admin caller (AccountController::index()); silently ignored
   * for an Agent caller, whose own results are always forced to their
   * own ownedByAgent() scope regardless of what is sent here.
   */
  agent_id?: number;
  /**
   * 3-Tier Hierarchy (Phase 3 UI) — restricts the listing to one
   * account_type ('client' for the ordinary Clients table so any
   * existing Agent-type account row no longer leaks into it; 'agent'
   * for the Agent-picker dropdowns, see listAgents() below). Same
   * Super-Admin-only backend restriction as agent_id above.
   */
  account_type?: AccountType;
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

  /**
   * 3-Tier Hierarchy (Phase 3 UI) — every Agent-type account, for the
   * Super Admin's "Parent Agent" (CreateAccountModal) and "Filter by
   * Agent" (AccountsPage) pickers. Super Admin only — see
   * ListAccountsParams.account_type. [Disclosed limit]: no dedicated
   * "all agents" endpoint exists yet, so this is a plain list() call
   * capped at the endpoint's own per_page=100 ceiling
   * (AccountController::index()); a platform with more than 100 Agent
   * accounts needs a real search/autocomplete here instead of this
   * flat dropdown — out of this phase's stated frontend-only scope.
   */
  listAgents() {
    return axiosInstance
      .get<PaginatedResponse<Account>>(BASE, { params: { account_type: 'agent', per_page: 100 } })
      .then((res) => res.data.data);
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

  /**
   * 3-Tier Hierarchy & Agent-Client Scope Engine (Phase 4) — Agent Quota
   * Pool & Allocation. Deliberately narrower than updateSubscription()
   * above (numeric quota only) — see UpdateQuotaModal.tsx and
   * AccountController::updateQuota()'s docblock. A 422 here (an Agent
   * over-allocating past their own pool) carries a field-level message
   * on total_allocated_messages — surface it with extractErrorMessage().
   */
  updateQuota(id: number, payload: UpdateQuotaPayload) {
    return axiosInstance
      .put<Subscription>(`${BASE}/${id}/quota`, payload)
      .then((res) => res.data);
  },

  /** Absolute Super Admin Control — start/stop feature modules for a client. */
  updatePermissions(id: number, payload: UpdateAccountPermissionsPayload) {
    return axiosInstance
      .patch<Account>(`${BASE}/${id}/permissions`, payload)
      .then((res) => res.data);
  },

  /**
   * Phase 5 Task 8 — an account's effective capability entitlements.
   * The READ half of the grant/revoke pair that already existed; see
   * AccountController::listEntitlements(). Every seeded capability is
   * returned, entitled or not, so "not entitled" renders as a state
   * rather than an absent row.
   */
  entitlements(id: number) {
    return axiosInstance
      .get<AccountEntitlementsResponse>(`${BASE}/${id}/entitlements`)
      .then((res) => res.data);
  },

  /** Phase 5 Task 8 — Super Admin / Agent capability grant. */
  grantEntitlement(id: number, capability: string) {
    return axiosInstance
      .post<{ message: string }>(`${BASE}/${id}/entitlements`, { capability })
      .then((res) => res.data);
  },

  /** Phase 5 Task 8 — Super Admin capability revoke. */
  revokeEntitlement(id: number, capability: string) {
    return axiosInstance
      .delete<{ message: string }>(`${BASE}/${id}/entitlements/${capability}`)
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
