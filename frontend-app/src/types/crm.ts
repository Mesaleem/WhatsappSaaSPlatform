/**
 * Phase 6 — CRM Task 8 (CRM frontend). Mirrors the Laravel CRM API
 * contracts exactly (Tasks 1–7): CrmLeadController + PresentsCrmLeads,
 * CrmContactController::present(), CrmTagController::presentTag(),
 * CrmAssigneeController and CrmPipelineController. Nothing here is a
 * frontend-invented field.
 */

/** The four canonical statuses — CrmLead::STATUSES. The pipeline columns are these, in this order. */
export const CRM_LEAD_STATUSES = ['new', 'contacted', 'converted', 'not_converted'] as const;
export type CrmLeadStatus = (typeof CRM_LEAD_STATUSES)[number];

/** CrmLead::SOURCES. */
export const CRM_LEAD_SOURCES = ['manual', 'whatsapp', 'meta_ad', 'api', 'journey'] as const;
export type CrmLeadSource = (typeof CRM_LEAD_SOURCES)[number];

/** Same derivation the backend uses for pipeline labels: ucwords(str_replace('_', ' ', slug)). */
export function crmLabel(slug: string): string {
  return slug
    .split('_')
    .map((part) => (part.length > 0 ? part[0].toUpperCase() + part.slice(1) : part))
    .join(' ');
}

/** Source slugs read better with a couple of exceptions; display only — the value sent is always the slug. */
export function crmSourceLabel(source: string): string {
  if (source === 'meta_ad') return 'Meta Ad';
  if (source === 'api') return 'API';
  if (source === 'whatsapp') return 'WhatsApp';
  return crmLabel(source);
}

export interface CrmTagRef {
  id: number;
  name: string;
}

export interface CrmLead {
  id: number;
  status: CrmLeadStatus;
  source: CrmLeadSource;
  assigned_user_id: number | null;
  assigned_user: { id: number; name: string } | null;
  contact: {
    id: number;
    name: string | null;
    phone_number: string;
    email: string | null;
  } | null;
  capture_lead: {
    id: number;
    provider: string | null;
    provider_lead_id: string | null;
    captured_at: string | null;
  } | null;
  tags: CrmTagRef[];
  converted_at: string | null;
  not_converted_at: string | null;
  not_converted_reason: string | null;
  created_at: string | null;
  updated_at: string | null;
}

export interface CrmContact {
  id: number;
  name: string | null;
  phone_number: string;
  email: string | null;
  crm_leads_count: number | null;
  created_at: string | null;
  updated_at: string | null;
}

export interface CrmTag {
  id: number;
  name: string;
  lead_count: number;
  created_at: string | null;
  updated_at: string | null;
}

export interface CrmAssignee {
  id: number;
  name: string;
}

/** Laravel paginate() response. */
export interface CrmPaginated<T> {
  data: T[];
  current_page: number;
  last_page: number;
  per_page: number;
  total: number;
}

export interface CrmPipelineColumn {
  status: CrmLeadStatus;
  label: string;
  order: number;
  total: number;
  page: number;
  per_page: number;
  last_page: number;
  has_more: boolean;
  leads: CrmLead[];
}

export interface CrmPipelineResponse {
  data: { pipeline: CrmPipelineColumn[] };
}

/**
 * The shared lead filter vocabulary — CrmLead::filterRules(). `tag_ids` is
 * AND semantics server-side (a lead must carry every tag).
 * `assigned_user_id` is a user id or the literal 'none' (unassigned).
 */
export interface CrmLeadFilters {
  search?: string;
  status?: CrmLeadStatus;
  source?: CrmLeadSource;
  assigned_user_id?: string;
  tag_ids?: number[];
}

export interface CrmMessageResponse<T> {
  message: string;
  data: T;
}

/** Phase 6 CRM Task 9 — CrmLeadBulkController response `data`. */
export interface CrmBulkSummary {
  operation: 'assign' | 'unassign' | 'status' | 'tag_attach' | 'tag_detach';
  requested: number;
  changed: number;
  unchanged: number;
}

/** CrmBulkLeadSelection::MAX_LEADS — the server rejects larger batches. */
export const CRM_BULK_MAX = 100;

/**
 * Phase 6 CRM Task 12 — GET /api/crm/analytics `data` (CrmAnalyticsService).
 * Every number is computed server-side over one filtered lead set; the UI
 * only displays them.
 */
export interface CrmAnalytics {
  range: { from: string; to: string; interval: 'day' | 'week' | 'month'; timezone: string };
  filters: Record<string, unknown>;
  totals: {
    total: number;
    new: number;
    contacted: number;
    converted: number;
    not_converted: number;
    /** converted / total * 100, 2 dp; null when total is 0. */
    conversion_rate: number | null;
  };
  by_status: { status: string; label: string; count: number }[];
  by_source: { source: string; label: string; count: number }[];
  by_assignee: { assigned_user_id: number | null; name: string; count: number }[];
  trend: { period: string; total: number; converted: number }[];
}

/** Inclusive Y-m-d range on crm_leads.created_at; either end may be open. */
export interface CrmDateRange {
  from?: string;
  to?: string;
}
