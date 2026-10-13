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
export const CRM_LEAD_SOURCES = ['manual', 'whatsapp', 'meta_ad', 'api', 'journey', 'website'] as const;
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
  if (source === 'website') return 'Website';
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
  // CRM Sales Phase 1 — qualification, attribution and the conversion record.
  company_name?: string | null;
  requirement?: string | null;
  /** Decimal string, e.g. "5000.00". */
  budget?: string | null;
  timeline?: string | null;
  temperature?: CrmTemperature | null;
  next_follow_up_at?: string | null;
  attribution?: Record<string, unknown> | null;
  previous_lead_id?: number | null;
  company?: { id: number; name: string } | null;
  deal?: { id: number; number: string; name: string; status: CrmDealStatus } | null;
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
  // CRM Sales — consent and company.
  designation?: string | null;
  preferred_channel?: 'whatsapp' | 'email' | 'phone' | null;
  marketing_opt_in?: boolean | null;
  opted_out_at?: string | null;
  crm_company_id?: number | null;
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

// ---------------------------------------------------------------- CRM Sales Phase 1

export const CRM_TEMPERATURES = ['hot', 'warm', 'cold'] as const;
export type CrmTemperature = (typeof CRM_TEMPERATURES)[number];

/** Owner rule: a converted lead is final (re-engage creates a new lead instead). */
export function crmAllowedStatuses(current: CrmLeadStatus): CrmLeadStatus[] {
  return current === 'converted' ? ['converted'] : [...CRM_LEAD_STATUSES];
}

export const CRM_COMPANY_TYPES = ['prospect', 'customer', 'partner'] as const;
export type CrmCompanyType = (typeof CRM_COMPANY_TYPES)[number];

export interface CrmCompany {
  id: number;
  name: string;
  industry: string | null;
  website: string | null;
  gstin: string | null;
  phone: string | null;
  email: string | null;
  address_line1: string | null;
  address_line2: string | null;
  city: string | null;
  state: string | null;
  pincode: string | null;
  country: string | null;
  type: CrmCompanyType;
  status: 'active' | 'inactive';
  owner: { id: number; name: string } | null;
  owner_user_id: number | null;
  contacts_count: number | null;
  deals_count: number | null;
  created_at: string | null;
  updated_at: string | null;
}

export interface CrmCompanyDetail extends CrmCompany {
  contacts: { id: number; name: string | null; phone_number: string; email: string | null; designation: string | null }[];
  deals: CrmDeal[];
}

export type CrmStageKind = 'open' | 'won' | 'lost';

export interface CrmStage {
  id: number;
  name: string;
  position: number;
  probability: number;
  kind: CrmStageKind;
}

export type CrmDealStatus = 'open' | 'won' | 'lost';

export interface CrmDeal {
  id: number;
  number: string;
  name: string;
  /** Decimal string, e.g. "12000.00". */
  value: string;
  currency: string;
  status: CrmDealStatus;
  stage_id: number;
  stage: CrmStage | null;
  expected_close_date: string | null;
  source: string | null;
  won_at: string | null;
  lost_at: string | null;
  lost_reason: string | null;
  crm_lead_id: number | null;
  contact: { id: number; name: string | null; phone_number: string } | null;
  company: { id: number; name: string } | null;
  owner: { id: number; name: string } | null;
  owner_user_id: number | null;
  created_at: string | null;
  updated_at: string | null;
}

export interface CrmBoardColumn extends CrmStage {
  deals_count: number;
  total_value: string;
  deals: CrmDeal[];
}

export const CRM_ACTIVITY_TYPES = ['call', 'meeting', 'task', 'email', 'whatsapp', 'note'] as const;
export type CrmActivityType = (typeof CRM_ACTIVITY_TYPES)[number] | 'event';
export type CrmActivityStatus = 'pending' | 'done' | 'cancelled';
export type CrmActivityPriority = 'low' | 'normal' | 'high';

export interface CrmActivity {
  id: number;
  type: CrmActivityType;
  subject: string;
  body: string | null;
  status: CrmActivityStatus;
  priority: CrmActivityPriority;
  outcome: string | null;
  due_at: string | null;
  completed_at: string | null;
  is_overdue: boolean;
  crm_lead_id: number | null;
  contact_id: number | null;
  crm_company_id: number | null;
  crm_deal_id: number | null;
  contact: { id: number; name: string | null; phone_number: string } | null;
  company: { id: number; name: string } | null;
  deal: { id: number; number: string; name: string } | null;
  assigned_user: { id: number; name: string } | null;
  assigned_user_id: number | null;
  created_by: { id: number; name: string } | null;
  created_at: string | null;
}

/** Which record an activity list or new activity belongs to. */
export type CrmActivityLink = Partial<Pick<CrmActivity, 'crm_lead_id' | 'contact_id' | 'crm_company_id' | 'crm_deal_id'>>;

/** "12000.50" -> "₹12,000.50" (display only; the value stays a string). */
export function formatMoney(value: string | null | undefined, currency = 'INR'): string {
  if (value === null || value === undefined || value === '') return '—';
  const [whole, fraction = '00'] = value.split('.');
  const negative = whole.startsWith('-');
  const digits = negative ? whole.slice(1) : whole;
  // Indian grouping: last 3 digits, then groups of 2.
  const last3 = digits.slice(-3);
  const rest = digits.slice(0, -3).replace(/\B(?=(\d{2})+(?!\d))/g, ',');
  const grouped = rest ? `${rest},${last3}` : last3;
  const symbol = currency === 'INR' ? '₹' : `${currency} `;
  return `${negative ? '-' : ''}${symbol}${grouped}.${fraction.padEnd(2, '0').slice(0, 2)}`;
}
