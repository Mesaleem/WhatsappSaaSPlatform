import type {
  CrmContact,
  CrmLead,
  CrmPaginated,
  CrmPipelineColumn,
  CrmTag,
} from '../types/crm';

/**
 * Phase 6 — CRM Task 8. Test fixtures shaped exactly like the Laravel CRM
 * responses (PresentsCrmLeads, CrmContactController::present(),
 * CrmTagController::presentTag(), CrmPipelineController). Test-only.
 */

export function makeLead(overrides: Partial<CrmLead> = {}): CrmLead {
  const id = overrides.id ?? 1;
  return {
    id,
    status: 'new',
    source: 'manual',
    assigned_user_id: null,
    assigned_user: null,
    contact: { id: 100 + id, name: `Contact ${id}`, phone_number: `91900000000${id}`, email: null },
    capture_lead: null,
    tags: [],
    converted_at: null,
    not_converted_at: null,
    not_converted_reason: null,
    created_at: '2026-09-20T10:00:00+00:00',
    updated_at: '2026-09-20T10:00:00+00:00',
    ...overrides,
  };
}

export function makeContact(overrides: Partial<CrmContact> = {}): CrmContact {
  const id = overrides.id ?? 101;
  return {
    id,
    name: `Contact ${id}`,
    phone_number: `9190000${id}`,
    email: null,
    crm_leads_count: 1,
    created_at: '2026-09-20T10:00:00+00:00',
    updated_at: '2026-09-20T10:00:00+00:00',
    ...overrides,
  };
}

export function makeTag(overrides: Partial<CrmTag> = {}): CrmTag {
  const id = overrides.id ?? 1;
  return {
    id,
    name: `Tag ${id}`,
    lead_count: 0,
    created_at: '2026-09-20T10:00:00+00:00',
    updated_at: '2026-09-20T10:00:00+00:00',
    ...overrides,
  };
}

export function paginated<T>(data: T[], overrides: Partial<CrmPaginated<T>> = {}): CrmPaginated<T> {
  return { data, current_page: 1, last_page: 1, per_page: 20, total: data.length, ...overrides };
}

export function pipelineColumns(
  leadsByStatus: Partial<Record<CrmPipelineColumn['status'], CrmLead[]>> = {},
  totals: Partial<Record<CrmPipelineColumn['status'], number>> = {},
  page = 1,
  perPage = 20,
): CrmPipelineColumn[] {
  const order: CrmPipelineColumn['status'][] = ['new', 'contacted', 'converted', 'not_converted'];
  const labels = { new: 'New', contacted: 'Contacted', converted: 'Converted', not_converted: 'Not Converted' };
  return order.map((status, index) => {
    const leads = leadsByStatus[status] ?? [];
    const total = totals[status] ?? leads.length;
    return {
      status,
      label: labels[status],
      order: index + 1,
      total,
      page,
      per_page: perPage,
      last_page: total === 0 ? 1 : Math.ceil(total / perPage),
      has_more: page * perPage < total,
      leads,
    };
  });
}

/** An object shaped like the AxiosError describeApiError() reads. */
export function apiError(status: number, data: Record<string, unknown> = {}) {
  return Object.assign(new Error(`HTTP ${status}`), { response: { status, data, headers: {} } });
}

/** A request that never reached the server. */
export function networkError() {
  return Object.assign(new Error('Network Error'), { response: undefined });
}
