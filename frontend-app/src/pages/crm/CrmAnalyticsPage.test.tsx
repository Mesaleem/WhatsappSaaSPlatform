import { render, screen, waitFor, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { MemoryRouter, Route, Routes, useLocation } from 'react-router-dom';
import { beforeEach, describe, expect, it, vi, type Mock } from 'vitest';

import CrmAnalyticsPage from './CrmAnalyticsPage';
import crmService from '../../services/crmService';
import { apiError, paginated } from '../../test/crmFixtures';
import type { CrmAnalytics } from '../../types/crm';

/**
 * Phase 6 — CRM Task 12. The analytics page displays GET /api/crm/analytics
 * as returned (no client-side recomputation), sends the filters and date
 * range to the server, and shows loading / empty / error states.
 */

vi.mock('../../services/crmService', () => ({
  default: { analytics: vi.fn(), assignees: vi.fn(), listTags: vi.fn() },
}));

const ctx = { superAdmin: false, selected: null as number | null };
vi.mock('../../core/context/AuthContext', () => ({
  useAuth: () => ({ isSuperAdmin: () => ctx.superAdmin, isReadOnly: () => false }),
}));
vi.mock('../../core/context/TenantContext', () => ({
  useTenant: () => ({ selectedAccountId: ctx.selected }),
}));

const crm = crmService as unknown as Record<string, Mock>;

function Where() {
  const location = useLocation();
  return <div data-testid="where">{location.search}</div>;
}

function renderAt(url = '/crm/analytics') {
  return render(
    <MemoryRouter initialEntries={[url]}>
      <Routes>
        <Route path="/crm/analytics" element={<><CrmAnalyticsPage /><Where /></>} />
      </Routes>
    </MemoryRouter>,
  );
}

function analytics(overrides: Partial<CrmAnalytics> = {}): CrmAnalytics {
  return {
    range: { from: '2026-09-21', to: '2026-09-23', interval: 'day', timezone: 'UTC' },
    filters: {},
    totals: { total: 7, new: 2, contacted: 1, converted: 3, not_converted: 1, conversion_rate: 42.86 },
    by_status: [
      { status: 'new', label: 'New', count: 2 },
      { status: 'contacted', label: 'Contacted', count: 1 },
      { status: 'converted', label: 'Converted', count: 3 },
      { status: 'not_converted', label: 'Not Converted', count: 1 },
    ],
    by_source: [
      { source: 'manual', label: 'Manual', count: 1 },
      { source: 'whatsapp', label: 'Whatsapp', count: 0 },
      { source: 'meta_ad', label: 'Meta Ad', count: 5 },
      { source: 'api', label: 'Api', count: 1 },
      { source: 'journey', label: 'Journey', count: 0 },
    ],
    by_assignee: [
      { assigned_user_id: 7, name: 'Asha', count: 4 },
      { assigned_user_id: null, name: 'Unassigned', count: 3 },
    ],
    trend: [
      { period: '2026-09-21', total: 2, converted: 1 },
      { period: '2026-09-22', total: 0, converted: 0 },
      { period: '2026-09-23', total: 5, converted: 2 },
    ],
    ...overrides,
  };
}

beforeEach(() => {
  vi.clearAllMocks();
  ctx.superAdmin = false;
  ctx.selected = null;
  crm.analytics.mockResolvedValue(analytics());
  crm.assignees.mockResolvedValue([{ id: 7, name: 'Asha' }]);
  crm.listTags.mockResolvedValue(paginated([]));
});

describe('CRM analytics', () => {
  it('renders the server totals and conversion rate exactly', async () => {
    renderAt();

    expect(await screen.findByTestId('crm-analytics-total')).toHaveTextContent('Total leads7');
    expect(screen.getByTestId('crm-analytics-new')).toHaveTextContent('2');
    expect(screen.getByTestId('crm-analytics-contacted')).toHaveTextContent('1');
    expect(screen.getByTestId('crm-analytics-converted')).toHaveTextContent('3');
    expect(screen.getByTestId('crm-analytics-not_converted')).toHaveTextContent('1');
    expect(screen.getByTestId('crm-analytics-conversion_rate')).toHaveTextContent('42.86%');
  });

  it('renders the status, source and assignee breakdowns and the trend from the response', async () => {
    renderAt();
    await screen.findByTestId('crm-analytics-cards');

    expect(screen.getByTestId('crm-analytics-status-converted')).toHaveTextContent('Converted3');
    expect(screen.getByTestId('crm-analytics-source-meta_ad')).toHaveTextContent('Meta Ad5');
    expect(screen.getByTestId('crm-analytics-assignee-7')).toHaveTextContent('Asha4');
    expect(screen.getByTestId('crm-analytics-assignee-none')).toHaveTextContent('Unassigned3');

    const bars = within(screen.getByRole('list', { name: 'Lead trend' })).getAllByRole('listitem');
    expect(bars.map((b) => b.getAttribute('aria-label'))).toEqual([
      '2026-09-21: 2 leads, 1 converted',
      '2026-09-22: 0 leads, 0 converted',
      '2026-09-23: 5 leads, 2 converted',
    ]);
  });

  it('shows — for a null conversion rate and an empty state when there are no leads', async () => {
    crm.analytics.mockResolvedValue(
      analytics({
        totals: { total: 0, new: 0, contacted: 0, converted: 0, not_converted: 0, conversion_rate: null },
        by_assignee: [],
        trend: [{ period: '2026-09-23', total: 0, converted: 0 }],
      }),
    );
    renderAt();

    expect(await screen.findByTestId('crm-analytics-conversion_rate')).toHaveTextContent('—');
    expect(screen.getByText('No CRM leads yet')).toBeInTheDocument();
    expect(screen.queryByTestId('crm-analytics-trend')).toBeNull();
  });

  it('says the filters matched nothing when a filtered view is empty', async () => {
    crm.analytics.mockResolvedValue(analytics({ totals: { total: 0, new: 0, contacted: 0, converted: 0, not_converted: 0, conversion_rate: null } }));
    renderAt('/crm/analytics?status=converted&from=2026-09-01');

    expect(await screen.findByText('No leads match these filters')).toBeInTheDocument();
  });

  it('sends URL filters and the date range to the server', async () => {
    renderAt('/crm/analytics?status=converted&source=meta_ad&assignee=7&from=2026-09-01&to=2026-09-20');
    await screen.findByTestId('crm-analytics-cards');

    expect(crm.analytics).toHaveBeenCalledWith(
      { status: 'converted', source: 'meta_ad', assigned_user_id: '7' },
      { from: '2026-09-01', to: '2026-09-20' },
    );
  });

  it('re-queries when a date or a filter changes, keeping the other', async () => {
    const user = userEvent.setup();
    renderAt('/crm/analytics?status=new');
    await screen.findByTestId('crm-analytics-cards');

    await user.type(screen.getByLabelText('From date'), '2026-09-01');
    await waitFor(() => expect(crm.analytics).toHaveBeenLastCalledWith({ status: 'new' }, { from: '2026-09-01' }));
    expect(screen.getByTestId('where').textContent).toContain('from=2026-09-01');
    expect(screen.getByTestId('where').textContent).toContain('status=new');
  });

  it('does not query an inverted range and explains why', async () => {
    renderAt('/crm/analytics?from=2026-09-20&to=2026-09-01');

    expect(await screen.findByText('The end date must be on or after the start date.')).toBeInTheDocument();
    expect(crm.analytics).not.toHaveBeenCalled();
  });

  it('shows a loading state before the response arrives', async () => {
    crm.analytics.mockReturnValue(new Promise(() => {}));
    renderAt();

    expect(await screen.findByTestId('crm-loading')).toBeInTheDocument();
    expect(screen.queryByTestId('crm-analytics-cards')).toBeNull();
  });

  it('shows the server refusal (a 403 from the backend gates) instead of numbers', async () => {
    crm.analytics.mockRejectedValue(apiError(403, { message: 'Your current plan does not include this feature. Please upgrade your subscription to unlock it.', error_code: 'CAPABILITY_NOT_ENTITLED' }));
    renderAt();

    expect(await screen.findByRole('alert')).toBeInTheDocument();
    expect(screen.queryByTestId('crm-analytics-cards')).toBeNull();
  });

  it('shows a validation error from the server', async () => {
    crm.analytics.mockRejectedValue(apiError(422, { message: 'The selected tag is not available.', errors: { tag_id: ['The selected tag is not available.'] } }));
    renderAt('/crm/analytics?tags=99');

    expect(await screen.findByRole('alert')).toHaveTextContent(/not available/i);
  });

  it('asks a Super Admin to select a client and makes no request', async () => {
    ctx.superAdmin = true;
    renderAt();

    expect(await screen.findByText(/select a client/i)).toBeInTheDocument();
    expect(crm.analytics).not.toHaveBeenCalled();
  });
});
