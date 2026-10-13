import { render, screen, waitFor, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { MemoryRouter, Route, Routes, useLocation } from 'react-router-dom';
import { beforeEach, describe, expect, it, vi, type Mock } from 'vitest';

import CrmLeadDetailPage from './CrmLeadDetailPage';
import CrmDealsPage from './CrmDealsPage';
import CrmTasksPage from './CrmTasksPage';
import crmService from '../../services/crmService';
import crmSalesService from '../../services/crmSalesService';
import { formatMoney, type CrmBoardColumn, type CrmDeal } from '../../types/crm';
import { makeLead, paginated } from '../../test/crmFixtures';

/**
 * CRM Sales Phase 1 — Convert / Re-engage on the lead page, the converted-lead lock,
 * the deal board, the activity timeline and the tasks page. Business rules live on the
 * server; these check the UI sends the right calls and shows the server's answer.
 */

vi.mock('../../services/crmService', () => ({
  default: {
    getLead: vi.fn(),
    assignees: vi.fn(),
    listTags: vi.fn(),
    listContacts: vi.fn(),
    changeStatus: vi.fn(),
    changeAssignee: vi.fn(),
    attachTag: vi.fn(),
    detachTag: vi.fn(),
    reassignContact: vi.fn(),
    deleteLead: vi.fn(),
  },
}));
vi.mock('../../services/crmSalesService', () => ({
  default: {
    listActivities: vi.fn(),
    createActivity: vi.fn(),
    updateActivity: vi.fn(),
    convertLead: vi.fn(),
    reEngageLead: vi.fn(),
    updateQualification: vi.fn(),
    board: vi.fn(),
    moveDeal: vi.fn(),
    listCompanies: vi.fn(),
    createDeal: vi.fn(),
  },
}));
vi.mock('../../core/context/AuthContext', () => ({
  useAuth: () => ({ isSuperAdmin: () => false, isReadOnly: () => false, user: { id: 1 } }),
}));
vi.mock('../../core/context/TenantContext', () => ({
  useTenant: () => ({ selectedAccountId: null }),
}));

const crm = crmService as unknown as Record<string, Mock>;
const sales = crmSalesService as unknown as Record<string, Mock>;

function LocationProbe() {
  return <div data-testid="location">{useLocation().pathname}</div>;
}

function renderLead(id = 1) {
  return render(
    <MemoryRouter initialEntries={[`/crm/leads/${id}`]}>
      <Routes>
        <Route path="/crm/leads/:id" element={<CrmLeadDetailPage />} />
      </Routes>
      <LocationProbe />
    </MemoryRouter>,
  );
}

const deal = (overrides: Partial<CrmDeal> = {}): CrmDeal => ({
  id: 9, number: 'DEAL-0001', name: 'Annual plan', value: '12000.00', currency: 'INR', status: 'open', stage_id: 1,
  stage: null, expected_close_date: null, source: 'manual', won_at: null, lost_at: null, lost_reason: null, crm_lead_id: 1,
  contact: null, company: { id: 4, name: 'Sharma Travels' }, owner: null, owner_user_id: null, created_at: null, updated_at: null,
  ...overrides,
});

beforeEach(() => {
  vi.clearAllMocks();
  crm.assignees.mockResolvedValue([]);
  crm.listTags.mockResolvedValue(paginated([]));
  crm.listContacts.mockResolvedValue(paginated([]));
  sales.listActivities.mockResolvedValue(paginated([]));
  sales.listCompanies.mockResolvedValue(paginated([]));
});

describe('formatMoney', () => {
  it('groups Indian style without touching the decimal string', () => {
    expect(formatMoney('12000.50')).toBe('₹12,000.50');
    expect(formatMoney('1234567.00')).toBe('₹12,34,567.00');
    expect(formatMoney('0.30')).toBe('₹0.30');
    expect(formatMoney(null)).toBe('—');
  });
});

describe('lead page: convert and re-engage', () => {
  it('converts with a company and a deal, then shows the links the server returned', async () => {
    crm.getLead.mockResolvedValue(makeLead({ id: 1, status: 'contacted', company_name: 'Sharma Travels', budget: '60000.00' }));
    sales.convertLead.mockResolvedValue({
      message: 'Lead converted.',
      data: makeLead({ id: 1, status: 'converted', company: { id: 4, name: 'Sharma Travels' }, deal: { id: 9, number: 'DEAL-0001', name: 'Sharma Travels', status: 'open' } }),
    });
    const user = userEvent.setup();
    renderLead();

    await user.click(await screen.findByRole('button', { name: /convert lead/i }));
    const modal = screen.getByTestId('crm-convert-modal');
    expect(within(modal).getByDisplayValue('Sharma Travels')).toBeInTheDocument();
    expect(within(modal).getByDisplayValue('60000.00')).toBeInTheDocument();
    await user.click(within(modal).getByRole('button', { name: 'Convert' }));

    await waitFor(() =>
      expect(sales.convertLead).toHaveBeenCalledWith(1, {
        company: { name: 'Sharma Travels', gstin: null },
        create_deal: true,
        deal: { name: undefined, value: '60000.00', expected_close_date: null },
      }),
    );
    const links = await screen.findByTestId('crm-lead-links');
    expect(within(links).getByText(/DEAL-0001/)).toBeInTheDocument();
    expect(screen.getByRole('button', { name: /re-engage/i })).toBeInTheDocument();
  });

  it('locks the status of a converted lead and re-engages into a new lead', async () => {
    crm.getLead.mockResolvedValue(makeLead({ id: 1, status: 'converted', converted_at: '2026-10-01T00:00:00+00:00', deal: { id: 9, number: 'DEAL-0001', name: 'X', status: 'open' } }));
    sales.reEngageLead.mockResolvedValue({ message: 'New lead created for the returning customer.', data: makeLead({ id: 2, status: 'new', previous_lead_id: 1 }) });
    const user = userEvent.setup();
    renderLead();

    const select = await screen.findByLabelText('Change status');
    expect(select).toBeDisabled();
    expect(within(select).getAllByRole('option').map((o) => o.textContent)).toEqual(['Converted']);
    expect(screen.queryByRole('button', { name: /convert lead|add company/i })).not.toBeInTheDocument();

    await user.click(screen.getByRole('button', { name: /re-engage/i }));
    // PromptModal focuses its own input.
    await user.keyboard('Second branch');
    await user.click(screen.getByRole('button', { name: 'Create new lead' }));

    await waitFor(() => expect(sales.reEngageLead).toHaveBeenCalledWith(1, 'Second branch'));
    await waitFor(() => expect(screen.getByTestId('location')).toHaveTextContent('/crm/leads/2'));
  });

  it('adds a follow-up to the lead timeline and marks it done', async () => {
    crm.getLead.mockResolvedValue(makeLead({ id: 1, status: 'new' }));
    const pending = { id: 31, type: 'task', subject: 'Send demo', body: null, status: 'pending', priority: 'normal', outcome: null, due_at: null, completed_at: null, is_overdue: false, crm_lead_id: 1, contact_id: null, crm_company_id: null, crm_deal_id: null, contact: null, company: null, deal: null, assigned_user: null, assigned_user_id: null, created_by: null, created_at: null };
    sales.createActivity.mockResolvedValue({ message: 'Activity added.', data: pending });
    sales.updateActivity.mockResolvedValue({ message: 'Activity updated.', data: { ...pending, status: 'done' } });
    const user = userEvent.setup();
    renderLead();

    const panel = await screen.findByTestId('crm-activity-panel');
    sales.listActivities.mockResolvedValue(paginated([pending]));
    await user.type(within(panel).getByLabelText('Activity subject'), 'Send demo');
    await user.click(within(panel).getByRole('button', { name: 'Add' }));
    await waitFor(() => expect(sales.createActivity).toHaveBeenCalledWith({ crm_lead_id: 1, type: 'task', subject: 'Send demo', due_at: null }));

    await user.click(await within(panel).findByRole('button', { name: /mark "send demo" done/i }));
    await waitFor(() => expect(sales.updateActivity).toHaveBeenCalledWith(31, { status: 'done' }));
  });
});

describe('deals board', () => {
  const columns: CrmBoardColumn[] = [
    { id: 1, name: 'Qualification', position: 1, probability: 10, kind: 'open', deals_count: 1, total_value: '12000.00', deals: [deal()] },
    { id: 5, name: 'Won', position: 5, probability: 100, kind: 'won', deals_count: 0, total_value: '0.00', deals: [] },
    { id: 6, name: 'Lost', position: 6, probability: 0, kind: 'lost', deals_count: 0, total_value: '0.00', deals: [] },
  ];

  it('shows each stage with its total and asks a reason before marking a deal lost', async () => {
    sales.board.mockResolvedValue(columns);
    sales.moveDeal.mockResolvedValue({ message: 'Deal moved.', data: deal({ stage_id: 6, status: 'lost' }) });
    const user = userEvent.setup();
    render(
      <MemoryRouter>
        <CrmDealsPage />
      </MemoryRouter>,
    );

    const qualification = await screen.findByRole('region', { name: 'Qualification' });
    expect(within(qualification).getAllByText('₹12,000.00').length).toBeGreaterThan(0);
    expect(within(screen.getByTestId('crm-deal-card-9')).getByText('Sharma Travels')).toBeInTheDocument();

    await user.selectOptions(screen.getByLabelText('Move DEAL-0001'), '6');
    await screen.findByText(/mark deal-0001 as lost/i);
    await user.keyboard('Budget');
    await user.click(screen.getByRole('button', { name: 'Mark as lost' }));
    await waitFor(() => expect(sales.moveDeal).toHaveBeenCalledWith(9, 6, 'Budget'));
  });

  it('locks the stage of a won deal', async () => {
    sales.board.mockResolvedValue([{ ...columns[0], deals_count: 0, deals: [] }, { ...columns[1], deals_count: 1, deals: [deal({ stage_id: 5, status: 'won' })] }]);
    render(
      <MemoryRouter>
        <CrmDealsPage />
      </MemoryRouter>,
    );
    expect(await screen.findByLabelText('Move DEAL-0001')).toBeDisabled();
  });
});

describe('tasks page', () => {
  it('asks the server for each view', async () => {
    const user = userEvent.setup();
    render(
      <MemoryRouter>
        <CrmTasksPage />
      </MemoryRouter>,
    );
    await waitFor(() => expect(sales.listActivities).toHaveBeenCalledWith({ due: 'overdue' }, 1, 100));
    await user.click(screen.getByRole('tab', { name: 'My open tasks' }));
    await waitFor(() => expect(sales.listActivities).toHaveBeenCalledWith({ status: 'pending', assigned_user_id: 'me' }, 1, 100));
  });
});
