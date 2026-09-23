import axiosInstance from '../core/api/axiosInstance';
import type {
  CreatePlanPayload,
  ManagedPlansResponse,
  UpdatePlanPayload,
  UpdatePlanResult,
} from '../types/plan';

const BASE = '/admin/plans-management';

/**
 * Phase 5 Task 12 — Super Admin plan management.
 *
 * Thin wrapper over the endpoints Task 10 and Task 11 already shipped.
 * No business logic lives here: pricing, quota, provider compatibility
 * and entitlement reconciliation are all decided server-side, and this
 * file only carries the request and hands back the response.
 *
 * Deliberately separate from billingService, which reads the
 * CUSTOMER-facing /billing/plans listing. Same subject, different
 * audience and different authorization.
 */
const planService = {
  /** Plans with their bundles and account counts, plus the capability vocabulary. */
  list() {
    return axiosInstance.get<ManagedPlansResponse>(`${BASE}`).then((res) => res.data);
  },

  create(payload: CreatePlanPayload) {
    return axiosInstance.post<{ message: string }>(`${BASE}`, payload).then((res) => res.data);
  },

  /**
   * Partial update. Pass ONLY the dimensions being changed — the server
   * leaves every omitted key untouched, and omitting `capabilities`
   * is how a price edit avoids disturbing the bundle.
   */
  update(slug: string, payload: UpdatePlanPayload) {
    return axiosInstance.put<UpdatePlanResult>(`${BASE}/${slug}`, payload).then((res) => res.data);
  },
};

export default planService;
