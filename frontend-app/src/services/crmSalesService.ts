import axiosInstance from '../core/api/axiosInstance';
import type {
  CrmActivity,
  CrmActivityLink,
  CrmActivityPriority,
  CrmActivityStatus,
  CrmActivityType,
  CrmBoardColumn,
  CrmCompany,
  CrmCompanyDetail,
  CrmCompanyType,
  CrmDeal,
  CrmLead,
  CrmMessageResponse,
  CrmPaginated,
  CrmStage,
  CrmStageKind,
  CrmTemperature,
} from '../types/crm';

/**
 * CRM Sales Phase 1 — thin client over /api/crm/companies|stages|deals|activities and
 * leads/{id}/convert|re-engage. No business rules here: stage/status bookkeeping, dedupe,
 * numbering and tenant ownership are all decided by the backend. The selected client's
 * account_id is added by axiosInstance, as for every other page.
 */
const BASE = '/crm';

export interface CompanyPayload {
  name?: string;
  industry?: string | null;
  website?: string | null;
  gstin?: string | null;
  phone?: string | null;
  email?: string | null;
  address_line1?: string | null;
  address_line2?: string | null;
  city?: string | null;
  state?: string | null;
  pincode?: string | null;
  country?: string | null;
  type?: CrmCompanyType;
  owner_user_id?: number | null;
}

export interface DealPayload {
  name?: string;
  value?: string;
  expected_close_date?: string | null;
  owner_user_id?: number | null;
  contact_id?: number | null;
  crm_company_id?: number | null;
  stage_id?: number | null;
}

export interface ActivityPayload extends CrmActivityLink {
  type?: Exclude<CrmActivityType, 'event'>;
  subject?: string;
  body?: string | null;
  due_at?: string | null;
  priority?: CrmActivityPriority;
  status?: CrmActivityStatus;
  outcome?: string | null;
  assigned_user_id?: number | null;
}

export interface ConvertPayload {
  crm_company_id?: number | null;
  company?: { name: string; gstin?: string | null } | null;
  create_deal?: boolean;
  deal?: { name?: string; value?: string; stage_id?: number | null; expected_close_date?: string | null };
}

export interface QualificationPayload {
  company_name?: string | null;
  requirement?: string | null;
  budget?: string | null;
  timeline?: string | null;
  temperature?: CrmTemperature | null;
  next_follow_up_at?: string | null;
}

export interface ActivityFilters extends CrmActivityLink {
  status?: CrmActivityStatus;
  assigned_user_id?: string;
  due?: 'overdue' | 'today' | 'upcoming';
}

/** Drop empty values so the query string mirrors what the user chose. */
function clean(params: Record<string, unknown>): Record<string, unknown> {
  return Object.fromEntries(Object.entries(params).filter(([, v]) => v !== undefined && v !== null && v !== ''));
}

const crmSalesService = {
  // ---------------------------------------------------------------- leads
  updateQualification(leadId: number, payload: QualificationPayload) {
    return axiosInstance.patch<CrmMessageResponse<CrmLead>>(`${BASE}/leads/${leadId}`, payload).then((res) => res.data);
  },
  convertLead(leadId: number, payload: ConvertPayload) {
    return axiosInstance.post<CrmMessageResponse<CrmLead>>(`${BASE}/leads/${leadId}/convert`, payload).then((res) => res.data);
  },
  reEngageLead(leadId: number, requirement?: string | null) {
    return axiosInstance
      .post<CrmMessageResponse<CrmLead>>(`${BASE}/leads/${leadId}/re-engage`, clean({ requirement }))
      .then((res) => res.data);
  },

  // ---------------------------------------------------------------- companies
  listCompanies(search = '', page = 1, perPage = 20, type?: CrmCompanyType) {
    return axiosInstance
      .get<CrmPaginated<CrmCompany>>(`${BASE}/companies`, { params: clean({ search: search.trim(), page, per_page: perPage, type }) })
      .then((res) => res.data);
  },
  getCompany(id: number) {
    return axiosInstance.get<{ data: CrmCompanyDetail }>(`${BASE}/companies/${id}`).then((res) => res.data.data);
  },
  createCompany(payload: CompanyPayload) {
    return axiosInstance.post<CrmMessageResponse<CrmCompany>>(`${BASE}/companies`, payload).then((res) => res.data);
  },
  updateCompany(id: number, payload: CompanyPayload) {
    return axiosInstance.patch<CrmMessageResponse<CrmCompany>>(`${BASE}/companies/${id}`, payload).then((res) => res.data);
  },
  deleteCompany(id: number) {
    return axiosInstance.delete<{ message: string }>(`${BASE}/companies/${id}`).then((res) => res.data);
  },

  // ---------------------------------------------------------------- stages
  listStages() {
    return axiosInstance.get<{ data: CrmStage[] }>(`${BASE}/stages`).then((res) => res.data.data);
  },
  createStage(payload: { name: string; probability?: number; kind?: CrmStageKind }) {
    return axiosInstance.post<CrmMessageResponse<CrmStage>>(`${BASE}/stages`, payload).then((res) => res.data);
  },
  updateStage(id: number, payload: { name?: string; probability?: number; kind?: CrmStageKind }) {
    return axiosInstance.patch<CrmMessageResponse<CrmStage>>(`${BASE}/stages/${id}`, payload).then((res) => res.data);
  },
  deleteStage(id: number) {
    return axiosInstance.delete<{ message: string }>(`${BASE}/stages/${id}`).then((res) => res.data);
  },
  reorderStages(ids: number[]) {
    return axiosInstance.post<CrmMessageResponse<CrmStage[]>>(`${BASE}/stages/reorder`, { ids }).then((res) => res.data);
  },

  // ---------------------------------------------------------------- deals
  board(search = '') {
    return axiosInstance.get<{ data: CrmBoardColumn[] }>(`${BASE}/deals/board`, { params: clean({ search: search.trim() }) }).then((res) => res.data.data);
  },
  listDeals(params: { search?: string; status?: string; crm_company_id?: number; contact_id?: number; page?: number; per_page?: number } = {}) {
    return axiosInstance.get<CrmPaginated<CrmDeal>>(`${BASE}/deals`, { params: clean(params) }).then((res) => res.data);
  },
  getDeal(id: number) {
    return axiosInstance.get<{ data: CrmDeal }>(`${BASE}/deals/${id}`).then((res) => res.data.data);
  },
  createDeal(payload: DealPayload) {
    return axiosInstance.post<CrmMessageResponse<CrmDeal>>(`${BASE}/deals`, payload).then((res) => res.data);
  },
  updateDeal(id: number, payload: DealPayload) {
    return axiosInstance.patch<CrmMessageResponse<CrmDeal>>(`${BASE}/deals/${id}`, payload).then((res) => res.data);
  },
  moveDeal(id: number, stageId: number, lostReason?: string | null) {
    return axiosInstance
      .patch<CrmMessageResponse<CrmDeal>>(`${BASE}/deals/${id}/stage`, clean({ stage_id: stageId, lost_reason: lostReason }))
      .then((res) => res.data);
  },
  deleteDeal(id: number) {
    return axiosInstance.delete<{ message: string }>(`${BASE}/deals/${id}`).then((res) => res.data);
  },

  // ---------------------------------------------------------------- activities
  listActivities(filters: ActivityFilters, page = 1, perPage = 25) {
    return axiosInstance
      .get<CrmPaginated<CrmActivity>>(`${BASE}/activities`, { params: clean({ ...filters, page, per_page: perPage }) })
      .then((res) => res.data);
  },
  createActivity(payload: ActivityPayload) {
    return axiosInstance.post<CrmMessageResponse<CrmActivity>>(`${BASE}/activities`, payload).then((res) => res.data);
  },
  updateActivity(id: number, payload: ActivityPayload) {
    return axiosInstance.patch<CrmMessageResponse<CrmActivity>>(`${BASE}/activities/${id}`, payload).then((res) => res.data);
  },
  deleteActivity(id: number) {
    return axiosInstance.delete<{ message: string }>(`${BASE}/activities/${id}`).then((res) => res.data);
  },
};

export default crmSalesService;
