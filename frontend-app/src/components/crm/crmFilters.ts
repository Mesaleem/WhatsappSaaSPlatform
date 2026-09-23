import {
  CRM_LEAD_SOURCES,
  CRM_LEAD_STATUSES,
  type CrmLeadFilters,
  type CrmLeadSource,
  type CrmLeadStatus,
} from '../../types/crm';

/**
 * Phase 6 — CRM Task 8. URL <-> filter state for the CRM lead surfaces
 * (Leads, Pipeline). Filters live in the query string so a filtered view
 * survives refresh, can be bookmarked and shared, and Back/Forward work.
 *
 * The URL is only a UX carrier: values are shape-checked here so a
 * hand-edited URL cannot put an invented status/source on the wire, and
 * everything that reaches the API is still validated server-side
 * (CrmLead::filterRules()). Tag ids are not checked for ownership here —
 * the backend answers a foreign/missing tag id with a 422, which the page
 * shows.
 *
 * URL keys:  q, status, source, assignee (user id | 'none'), tags (comma
 * separated ids, AND semantics), page, per_page.
 */

export const CRM_PER_PAGE_OPTIONS = [10, 20, 50, 100];
export const CRM_DEFAULT_PER_PAGE = 20;
/** CrmLead::TAG_FILTER_MAX — the backend rejects more than this. */
export const CRM_TAG_FILTER_MAX = 10;

export interface CrmUrlState {
  filters: CrmLeadFilters;
  page: number;
  perPage: number;
}

function positiveInt(raw: string | null): number | null {
  if (raw === null || !/^[1-9][0-9]*$/.test(raw)) return null;
  return Number(raw);
}

export function parseCrmUrl(params: URLSearchParams): CrmUrlState {
  const filters: CrmLeadFilters = {};

  const q = params.get('q');
  if (q && q.trim() !== '') filters.search = q;

  const status = params.get('status');
  if (status && (CRM_LEAD_STATUSES as readonly string[]).includes(status)) {
    filters.status = status as CrmLeadStatus;
  }

  const source = params.get('source');
  if (source && (CRM_LEAD_SOURCES as readonly string[]).includes(source)) {
    filters.source = source as CrmLeadSource;
  }

  const assignee = params.get('assignee');
  if (assignee === 'none' || positiveInt(assignee) !== null) {
    filters.assigned_user_id = assignee as string;
  }

  const tags = (params.get('tags') ?? '')
    .split(',')
    .map((t) => positiveInt(t.trim()))
    .filter((t): t is number => t !== null);
  const uniqueTags = Array.from(new Set(tags)).slice(0, CRM_TAG_FILTER_MAX);
  if (uniqueTags.length > 0) filters.tag_ids = uniqueTags;

  const perPage = positiveInt(params.get('per_page'));

  return {
    filters,
    page: positiveInt(params.get('page')) ?? 1,
    perPage: perPage !== null && CRM_PER_PAGE_OPTIONS.includes(perPage) ? perPage : CRM_DEFAULT_PER_PAGE,
  };
}

export function buildCrmUrl(state: CrmUrlState): URLSearchParams {
  const params = new URLSearchParams();
  const { filters } = state;
  if (filters.search && filters.search.trim() !== '') params.set('q', filters.search);
  if (filters.status) params.set('status', filters.status);
  if (filters.source) params.set('source', filters.source);
  if (filters.assigned_user_id) params.set('assignee', filters.assigned_user_id);
  if (filters.tag_ids && filters.tag_ids.length > 0) params.set('tags', filters.tag_ids.join(','));
  if (state.page > 1) params.set('page', String(state.page));
  if (state.perPage !== CRM_DEFAULT_PER_PAGE) params.set('per_page', String(state.perPage));
  return params;
}

export function hasActiveFilters(filters: CrmLeadFilters): boolean {
  return Boolean(
    (filters.search && filters.search.trim() !== '') ||
      filters.status ||
      filters.source ||
      filters.assigned_user_id ||
      (filters.tag_ids && filters.tag_ids.length > 0),
  );
}

/** Display-only date formatting for CRM timestamps (ISO 8601 from the API). */
export function formatDate(value: string | null | undefined): string {
  if (!value) return '—';
  const date = new Date(value);
  return Number.isNaN(date.getTime()) ? '—' : date.toLocaleString();
}
