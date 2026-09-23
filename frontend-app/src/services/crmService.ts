import axiosInstance from '../core/api/axiosInstance';
import type {
  CrmAnalytics,
  CrmAssignee,
  CrmBulkSummary,
  CrmDateRange,
  CrmContact,
  CrmLead,
  CrmLeadFilters,
  CrmLeadStatus,
  CrmMessageResponse,
  CrmPaginated,
  CrmPipelineResponse,
  CrmTag,
} from '../types/crm';

/**
 * Phase 6 — CRM Task 8. Thin client over the existing /api/crm/* routes
 * (routes/api.php, Tasks 1–7). One method per endpoint, no business
 * rules: statuses, transitions, assignee eligibility, tag normalization
 * and tenant ownership are all decided server-side.
 *
 * Tenant: never sends account_id itself. A Super Admin's / Agent's
 * selected client is appended by axiosInstance's existing interceptor
 * (TenantContext -> setSelectedAccountId), exactly like every other page.
 */

const BASE = '/crm';

/** Only non-empty filters go on the wire, so the URL mirrors what the user chose. */
function leadParams(filters: CrmLeadFilters, extra: Record<string, unknown> = {}): Record<string, unknown> {
  const params: Record<string, unknown> = { ...extra };
  if (filters.search?.trim()) params.search = filters.search.trim();
  if (filters.status) params.status = filters.status;
  if (filters.source) params.source = filters.source;
  if (filters.assigned_user_id) params.assigned_user_id = filters.assigned_user_id;
  if (filters.tag_ids && filters.tag_ids.length > 0) params.tag_ids = filters.tag_ids;
  return params;
}

export interface CreateLeadPayload {
  phone_number: string;
  name?: string | null;
  /** No source: manual Add lead is always `manual`, set by the backend. */
  status?: CrmLeadStatus;
  assigned_user_id?: number | null;
}

export interface ContactPayload {
  phone_number?: string;
  name?: string | null;
  email?: string | null;
}

const crmService = {
  // ---------------------------------------------------------------- leads
  listLeads(filters: CrmLeadFilters, page = 1, perPage = 20) {
    return axiosInstance
      .get<CrmPaginated<CrmLead>>(`${BASE}/leads`, { params: leadParams(filters, { page, per_page: perPage }) })
      .then((res) => res.data);
  },

  getLead(id: number) {
    return axiosInstance.get<{ data: CrmLead }>(`${BASE}/leads/${id}`).then((res) => res.data.data);
  },

  createLead(payload: CreateLeadPayload) {
    return axiosInstance.post<CrmMessageResponse<CrmLead>>(`${BASE}/leads`, payload).then((res) => res.data);
  },

  deleteLead(id: number) {
    return axiosInstance.delete<{ message: string }>(`${BASE}/leads/${id}`).then((res) => res.data);
  },

  /** PATCH /crm/leads/{id}/status — the dedicated lifecycle endpoint (Task 5). */
  changeStatus(id: number, status: CrmLeadStatus, notConvertedReason?: string | null) {
    const body: Record<string, unknown> = { status };
    if (status === 'not_converted' && notConvertedReason && notConvertedReason.trim() !== '') {
      body.not_converted_reason = notConvertedReason.trim();
    }
    return axiosInstance.patch<CrmMessageResponse<CrmLead>>(`${BASE}/leads/${id}/status`, body).then((res) => res.data);
  },

  /** PATCH /crm/leads/{id}/assignee — null unassigns (Task 4). */
  changeAssignee(id: number, assignedUserId: number | null) {
    return axiosInstance
      .patch<CrmMessageResponse<CrmLead>>(`${BASE}/leads/${id}/assignee`, { assigned_user_id: assignedUserId })
      .then((res) => res.data);
  },

  /** PATCH /crm/leads/{id}/contact (Task 3). */
  reassignContact(id: number, contactId: number) {
    return axiosInstance
      .patch<CrmMessageResponse<CrmLead>>(`${BASE}/leads/${id}/contact`, { contact_id: contactId })
      .then((res) => res.data);
  },

  /** POST /crm/leads/{id}/tags/{tag} — idempotent (Task 7). */
  attachTag(leadId: number, tagId: number) {
    return axiosInstance
      .post<CrmMessageResponse<CrmLead>>(`${BASE}/leads/${leadId}/tags/${tagId}`)
      .then((res) => res.data);
  },

  /** DELETE /crm/leads/{id}/tags/{tag} — idempotent (Task 7). */
  detachTag(leadId: number, tagId: number) {
    return axiosInstance
      .delete<CrmMessageResponse<CrmLead>>(`${BASE}/leads/${leadId}/tags/${tagId}`)
      .then((res) => res.data);
  },

  // ----------------------------------------------------------------- bulk
  // Phase 6 CRM Task 9 — one request per bulk action, never one per lead.
  // All or nothing server-side: a 422 means nothing was changed.

  /** POST /crm/leads/bulk/assignee — null unassigns. */
  bulkAssign(leadIds: number[], assignedUserId: number | null) {
    return axiosInstance
      .post<CrmMessageResponse<CrmBulkSummary>>(`${BASE}/leads/bulk/assignee`, { lead_ids: leadIds, assigned_user_id: assignedUserId })
      .then((res) => res.data);
  },

  /** POST /crm/leads/bulk/status. */
  bulkStatus(leadIds: number[], status: CrmLeadStatus, notConvertedReason?: string | null) {
    const body: Record<string, unknown> = { lead_ids: leadIds, status };
    if (status === 'not_converted' && notConvertedReason && notConvertedReason.trim() !== '') {
      body.not_converted_reason = notConvertedReason.trim();
    }
    return axiosInstance.post<CrmMessageResponse<CrmBulkSummary>>(`${BASE}/leads/bulk/status`, body).then((res) => res.data);
  },

  /** POST /crm/leads/bulk/tags/attach — idempotent. */
  bulkAttachTag(leadIds: number[], tagId: number) {
    return axiosInstance
      .post<CrmMessageResponse<CrmBulkSummary>>(`${BASE}/leads/bulk/tags/attach`, { lead_ids: leadIds, tag_id: tagId })
      .then((res) => res.data);
  },

  /** POST /crm/leads/bulk/tags/detach — idempotent. */
  bulkDetachTag(leadIds: number[], tagId: number) {
    return axiosInstance
      .post<CrmMessageResponse<CrmBulkSummary>>(`${BASE}/leads/bulk/tags/detach`, { lead_ids: leadIds, tag_id: tagId })
      .then((res) => res.data);
  },

  // ------------------------------------------------------------- pipeline
  /** GET /crm/pipeline. `status` narrows the response to that single column (used for "load more"). */
  pipeline(filters: CrmLeadFilters, page = 1, perPage = 20) {
    return axiosInstance
      .get<CrmPipelineResponse>(`${BASE}/pipeline`, { params: leadParams(filters, { page, per_page: perPage }) })
      .then((res) => res.data.data.pipeline);
  },

  // ------------------------------------------------------------ analytics
  /** GET /crm/analytics — Task 12. Same lead filters as the list, plus an inclusive from/to (Y-m-d). */
  analytics(filters: CrmLeadFilters, range: CrmDateRange) {
    const params = leadParams(filters);
    if (range.from) params.from = range.from;
    if (range.to) params.to = range.to;
    return axiosInstance.get<{ data: CrmAnalytics }>(`${BASE}/analytics`, { params }).then((res) => res.data.data);
  },

  // ------------------------------------------------------------ assignees
  assignees() {
    return axiosInstance.get<{ data: CrmAssignee[] }>(`${BASE}/assignees`).then((res) => res.data.data);
  },

  // ------------------------------------------------------------- contacts
  listContacts(search: string, page = 1, perPage = 20) {
    const params: Record<string, unknown> = { page, per_page: perPage };
    if (search.trim()) params.search = search.trim();
    return axiosInstance.get<CrmPaginated<CrmContact>>(`${BASE}/contacts`, { params }).then((res) => res.data);
  },

  getContact(id: number) {
    return axiosInstance.get<{ data: CrmContact }>(`${BASE}/contacts/${id}`).then((res) => res.data.data);
  },

  createContact(payload: ContactPayload) {
    return axiosInstance.post<CrmMessageResponse<CrmContact>>(`${BASE}/contacts`, payload).then((res) => res.data);
  },

  updateContact(id: number, payload: ContactPayload) {
    return axiosInstance.patch<CrmMessageResponse<CrmContact>>(`${BASE}/contacts/${id}`, payload).then((res) => res.data);
  },

  deleteContact(id: number) {
    return axiosInstance.delete<{ message: string }>(`${BASE}/contacts/${id}`).then((res) => res.data);
  },

  /** POST /crm/contacts/{source}/merge/{target} — source is removed, target survives. */
  mergeContacts(sourceId: number, targetId: number, confirmPhoneDiscard: boolean) {
    return axiosInstance
      .post<CrmMessageResponse<CrmContact>>(`${BASE}/contacts/${sourceId}/merge/${targetId}`, {
        confirm_phone_discard: confirmPhoneDiscard,
      })
      .then((res) => res.data);
  },

  /** GET /crm/contacts/{id}/leads — accepts status/source only (the backend's own rules for this endpoint). */
  contactLeads(id: number, filters: Pick<CrmLeadFilters, 'status' | 'source'>, page = 1, perPage = 20) {
    const params: Record<string, unknown> = { page, per_page: perPage };
    if (filters.status) params.status = filters.status;
    if (filters.source) params.source = filters.source;
    return axiosInstance.get<CrmPaginated<CrmLead>>(`${BASE}/contacts/${id}/leads`, { params }).then((res) => res.data);
  },

  // ----------------------------------------------------------------- tags
  listTags(search = '', page = 1, perPage = 50) {
    const params: Record<string, unknown> = { page, per_page: perPage };
    if (search.trim()) params.search = search.trim();
    return axiosInstance.get<CrmPaginated<CrmTag>>(`${BASE}/tags`, { params }).then((res) => res.data);
  },

  createTag(name: string) {
    return axiosInstance.post<CrmMessageResponse<CrmTag>>(`${BASE}/tags`, { name }).then((res) => res.data);
  },

  renameTag(id: number, name: string) {
    return axiosInstance.patch<CrmMessageResponse<CrmTag>>(`${BASE}/tags/${id}`, { name }).then((res) => res.data);
  },

  deleteTag(id: number) {
    return axiosInstance.delete<{ message: string }>(`${BASE}/tags/${id}`).then((res) => res.data);
  },
};

export default crmService;
