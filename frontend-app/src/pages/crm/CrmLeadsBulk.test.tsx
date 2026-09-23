import { render, screen, waitFor, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { MemoryRouter, Route, Routes, useLocation, useNavigate } from 'react-router-dom';
import { beforeEach, describe, expect, it, vi, type Mock } from 'vitest';

import CrmLeadsPage from './CrmLeadsPage';
import crmService from '../../services/crmService';
import { apiError, makeLead, makeTag, networkError, paginated } from '../../test/crmFixtures';

/**
 * Phase 6 — CRM Task 9. Page-scoped selection and the bulk action bar on
 * the CRM lead list. Every bulk action is ONE request to a
 * /crm/leads/bulk/* endpoint with the selected ids — never one request per
 * lead — and a rejected batch is never shown as a partial success.
 */

vi.mock('../../services/crmService', () => ({
  default: {
    listLeads: vi.fn(),
    assignees: vi.fn(),
    listTags: vi.fn(),
    changeStatus: vi.fn(),
    changeAssignee: vi.fn(),
    attachTag: vi.fn(),
    detachTag: vi.fn(),
    createLead: vi.fn(),
    bulkAssign: vi.fn(),
    bulkStatus: vi.fn(),
    bulkAttachTag: vi.fn(),
    bulkDetachTag: vi.fn(),
  },
}));

const auth = { readOnly: false };
vi.mock('../../core/context/AuthContext', () => ({
  useAuth: () => ({ isSuperAdmin: () => false, isReadOnly: () => auth.readOnly }),
}));
const tenant = { selectedAccountId: null as number | null };
vi.mock('../../core/context/TenantContext', () => ({
  useTenant: () => ({ selectedAccountId: tenant.selectedAccountId }),
}));

const crm = crmService as unknown as Record<string, Mock>;

function Probe() {
  const location = useLocation();
  const navigate = useNavigate();
  return (
    <>
      <div data-testid="location">{location.pathname + location.search}</div>
      <button type="button" onClick={() => navigate('/elsewhere')}>
        leave
      </button>
      <button type="button" onClick={() => navigate('/crm/leads')}>
        back
      </button>
    </>
  );
}

function renderAt(url = '/crm/leads') {
  return render(
    <MemoryRouter initialEntries={[url]}>
      <Routes>
        <Route path="/crm/leads" element={<CrmLeadsPage />} />
        <Route path="/elsewhere" element={<div>elsewhere</div>} />
      </Routes>
      <Probe />
    </MemoryRouter>,
  );
}

const leads = [makeLead({ id: 1 }), makeLead({ id: 2 }), makeLead({ id: 3 })];
const summary = (operation: string, requested: number, changed: number) => ({
  message: 'ok',
  data: { operation, requested, changed, unchanged: requested - changed },
});

beforeEach(() => {
  auth.readOnly = false;
  tenant.selectedAccountId = null;
  crm.listLeads.mockResolvedValue(paginated(leads, { total: 45, last_page: 3 }));
  crm.assignees.mockResolvedValue([{ id: 7, name: 'Asha' }]);
  crm.listTags.mockResolvedValue(paginated([makeTag({ id: 3, name: 'Hot' }), makeTag({ id: 5, name: 'VIP' })]));
});

async function ready() {
  await screen.findByTestId('crm-lead-row-1');
}

const rowBox = (id: number) => within(screen.getByTestId(`crm-lead-row-${id}`)).getByRole('checkbox') as HTMLInputElement;
const allBox = () => screen.getByLabelText(/select all 3 leads on this page/i) as HTMLInputElement;

/** Every individual mutation endpoint — none may be used by a bulk action. */
function expectNoIndividualCalls() {
  for (const name of ['changeStatus', 'changeAssignee', 'attachTag', 'detachTag']) {
    expect(crm[name]).not.toHaveBeenCalled();
  }
}

describe('selection', () => {
  it('selects rows, shows the page-scoped count and clears', async () => {
    const user = userEvent.setup();
    renderAt();
    await ready();

    expect(screen.queryByTestId('crm-bulk-bar')).toBeNull();

    await user.click(rowBox(1));
    await user.click(rowBox(3));
    expect(screen.getByTestId('crm-bulk-count')).toHaveTextContent('2 selected');
    expect(screen.getByTestId('crm-bulk-bar')).toHaveTextContent('on this page only');
    expect(allBox().indeterminate).toBe(true);

    await user.click(rowBox(1));
    expect(screen.getByTestId('crm-bulk-count')).toHaveTextContent('1 selected');

    await user.click(screen.getByRole('button', { name: /clear selection/i }));
    expect(screen.queryByTestId('crm-bulk-bar')).toBeNull();
    expect(rowBox(3).checked).toBe(false);
  });

  it('select-all selects only the visible page, and says so', async () => {
    const user = userEvent.setup();
    renderAt();
    await ready();

    await user.click(allBox());
    expect(screen.getByTestId('crm-bulk-count')).toHaveTextContent('3 selected');
    expect([1, 2, 3].every((id) => rowBox(id).checked)).toBe(true);
    // 45 leads match the filter; only the 3 on this page are selected.
    expect(screen.getByTestId('crm-bulk-bar').textContent).not.toContain('45');

    await user.click(allBox());
    expect(screen.queryByTestId('crm-bulk-bar')).toBeNull();
  });

  it('changing a filter clears the selection', async () => {
    const user = userEvent.setup();
    renderAt();
    await ready();
    await user.click(rowBox(2));

    await user.selectOptions(screen.getByLabelText('Filter by assignee'), 'none');

    await waitFor(() => expect(screen.queryByTestId('crm-bulk-bar')).toBeNull());
    await ready();
    expect(rowBox(2).checked).toBe(false);
  });

  it('changing page (or page size) clears the selection', async () => {
    const user = userEvent.setup();
    renderAt();
    await ready();
    await user.click(rowBox(2));

    await user.click(screen.getByLabelText('Next page'));
    await waitFor(() => expect(crm.listLeads).toHaveBeenLastCalledWith({}, 2, 20));
    await ready();
    expect(screen.queryByTestId('crm-bulk-bar')).toBeNull();

    await user.click(rowBox(1));
    await user.selectOptions(screen.getByDisplayValue('20'), '50');
    await waitFor(() => expect(screen.queryByTestId('crm-bulk-bar')).toBeNull());
  });

  it('switching client (account context) clears the selection', async () => {
    const user = userEvent.setup();
    tenant.selectedAccountId = 1;
    const view = renderAt();
    await ready();
    await user.click(rowBox(1));
    expect(screen.getByTestId('crm-bulk-bar')).toBeTruthy();

    tenant.selectedAccountId = 2;
    view.rerender(
      <MemoryRouter initialEntries={['/crm/leads']}>
        <Routes>
          <Route path="/crm/leads" element={<CrmLeadsPage />} />
        </Routes>
      </MemoryRouter>,
    );
    await ready();

    expect(screen.queryByTestId('crm-bulk-bar')).toBeNull();
  });

  it('navigating away and back clears the selection', async () => {
    const user = userEvent.setup();
    renderAt();
    await ready();
    await user.click(rowBox(1));

    await user.click(screen.getByRole('button', { name: 'leave' }));
    await user.click(screen.getByRole('button', { name: 'back' }));
    await ready();

    expect(screen.queryByTestId('crm-bulk-bar')).toBeNull();
  });
});

describe('bulk actions — one request each', () => {
  it('bulk assign sends one request with the selected ids', async () => {
    const user = userEvent.setup();
    crm.bulkAssign.mockResolvedValue(summary('assign', 2, 2));
    renderAt();
    await ready();
    await user.click(rowBox(1));
    await user.click(rowBox(3));

    await user.click(screen.getByRole('button', { name: 'Assign' }));
    const modal = screen.getByTestId('crm-bulk-modal');
    expect((within(modal).getByRole('button', { name: 'Assign' }) as HTMLButtonElement).disabled).toBe(true);
    await user.selectOptions(within(modal).getByLabelText('Bulk assignee'), '7');
    await user.click(within(modal).getByRole('button', { name: 'Assign' }));

    expect(crm.bulkAssign).toHaveBeenCalledTimes(1);
    expect(crm.bulkAssign).toHaveBeenCalledWith([1, 3], 7);
    expectNoIndividualCalls();
    expect(await screen.findByText('Assigned: 2 changed, 0 unchanged.')).toBeTruthy();
    await waitFor(() => expect(screen.queryByTestId('crm-bulk-bar')).toBeNull());
    await waitFor(() => expect(crm.listLeads).toHaveBeenCalledTimes(2)); // list re-read
  });

  it('only offers backend-eligible assignees', async () => {
    const user = userEvent.setup();
    renderAt();
    await ready();
    await user.click(rowBox(1));
    await user.click(screen.getByRole('button', { name: 'Assign' }));

    const select = within(screen.getByTestId('crm-bulk-modal')).getByLabelText('Bulk assignee') as HTMLSelectElement;
    expect(Array.from(select.options).map((o) => o.textContent)).toEqual(['Choose a team member…', 'Asha']);
  });

  it('bulk unassign confirms and sends null', async () => {
    const user = userEvent.setup();
    crm.bulkAssign.mockResolvedValue(summary('unassign', 3, 1));
    renderAt();
    await ready();
    await user.click(allBox());

    await user.click(screen.getByRole('button', { name: 'Unassign' }));
    expect(crm.bulkAssign).not.toHaveBeenCalled();
    expect(screen.getByText(/Remove the owner from 3 selected leads/)).toBeTruthy();
    await user.click(screen.getAllByRole('button', { name: 'Unassign' }).at(-1)!);

    expect(crm.bulkAssign).toHaveBeenCalledTimes(1);
    expect(crm.bulkAssign).toHaveBeenCalledWith([1, 2, 3], null);
    expect(await screen.findByText('Unassigned: 1 changed, 2 unchanged.')).toBeTruthy();
    expectNoIndividualCalls();
  });

  it('bulk status offers the four statuses and confirms count and target', async () => {
    const user = userEvent.setup();
    crm.bulkStatus.mockResolvedValue(summary('status', 3, 3));
    renderAt();
    await ready();
    await user.click(allBox());

    await user.click(screen.getByRole('button', { name: 'Change status' }));
    const modal = screen.getByTestId('crm-bulk-modal');
    const select = within(modal).getByLabelText('Bulk status') as HTMLSelectElement;
    expect(Array.from(select.options).map((o) => o.value)).toEqual(['new', 'contacted', 'converted', 'not_converted']);

    await user.selectOptions(select, 'not_converted');
    await user.type(within(modal).getByLabelText(/reason/i), 'Budget');
    expect(screen.getByTestId('crm-bulk-status-confirm')).toHaveTextContent('This will set 3 selected leads to Not Converted');
    expect(screen.getByTestId('crm-bulk-status-confirm')).toHaveTextContent('if any cannot change, none are changed');

    await user.click(within(modal).getByRole('button', { name: 'Set 3 to Not Converted' }));

    expect(crm.bulkStatus).toHaveBeenCalledTimes(1);
    expect(crm.bulkStatus).toHaveBeenCalledWith([1, 2, 3], 'not_converted', 'Budget');
    expect(await screen.findByText('Status set to Not Converted: 3 changed, 0 unchanged.')).toBeTruthy();
    expectNoIndividualCalls();
  });

  it('bulk add tag and remove tag use the server-searched picker, one request each', async () => {
    const user = userEvent.setup();
    crm.bulkAttachTag.mockResolvedValue(summary('tag_attach', 2, 1));
    crm.bulkDetachTag.mockResolvedValue(summary('tag_detach', 2, 2));
    renderAt();
    await ready();
    await user.click(rowBox(1));
    await user.click(rowBox(2));

    const bar = screen.getByTestId('crm-bulk-bar');
    await user.click(within(bar).getByRole('button', { name: 'Add tag' }));
    await user.click(await within(await screen.findByTestId('crm-tag-picker')).findByText('VIP'));
    expect(crm.bulkAttachTag).toHaveBeenCalledTimes(1);
    expect(crm.bulkAttachTag).toHaveBeenCalledWith([1, 2], 5);
    expect(await screen.findByText('Tag “VIP” added: 1 changed, 1 unchanged.')).toBeTruthy();

    await waitFor(() => expect(screen.queryByTestId('crm-bulk-bar')).toBeNull());
    await user.click(rowBox(1));
    await user.click(rowBox(2));
    await user.click(within(screen.getByTestId('crm-bulk-bar')).getByRole('button', { name: 'Remove tag' }));
    await user.click(await within(await screen.findByTestId('crm-tag-picker')).findByText('Hot'));
    expect(crm.bulkDetachTag).toHaveBeenCalledTimes(1);
    expect(crm.bulkDetachTag).toHaveBeenCalledWith([1, 2], 3);
    expectNoIndividualCalls();
    // The tag picker searches the server (prefix search), it does not load every tag.
    expect(crm.listTags).toHaveBeenCalledWith('', 1, 20);
  });

  it('disables the actions while a request is in flight (no double submit)', async () => {
    const user = userEvent.setup();
    let resolve: (v: unknown) => void = () => {};
    crm.bulkAssign.mockReturnValue(new Promise((r) => (resolve = r)));
    renderAt();
    await ready();
    await user.click(allBox());

    await user.click(screen.getByRole('button', { name: 'Unassign' }));
    await user.click(screen.getAllByRole('button', { name: 'Unassign' }).at(-1)!);

    expect(screen.getByLabelText('Working')).toBeTruthy();
    const bar = screen.getByTestId('crm-bulk-bar');
    expect((within(bar).getByRole('button', { name: 'Change status' }) as HTMLButtonElement).disabled).toBe(true);
    expect((within(bar).getByRole('button', { name: 'Assign' }) as HTMLButtonElement).disabled).toBe(true);
    expect(crm.bulkAssign).toHaveBeenCalledTimes(1);

    resolve(summary('unassign', 3, 0));
    await waitFor(() => expect(screen.queryByTestId('crm-bulk-bar')).toBeNull());
  });

  it('read-only (expired subscription) disables every bulk action', async () => {
    auth.readOnly = true;
    const user = userEvent.setup();
    renderAt();
    await ready();
    await user.click(rowBox(1));

    const bar = screen.getByTestId('crm-bulk-bar');
    for (const name of ['Assign', 'Unassign', 'Change status', 'Add tag', 'Remove tag']) {
      expect((within(bar).getByRole('button', { name }) as HTMLButtonElement).disabled).toBe(true);
    }
  });

  it('never offers bulk delete or contact reassignment', async () => {
    const user = userEvent.setup();
    renderAt();
    await ready();
    await user.click(rowBox(1));

    const bar = screen.getByTestId('crm-bulk-bar');
    expect(within(bar).queryByRole('button', { name: /delete/i })).toBeNull();
    expect(within(bar).queryByRole('button', { name: /contact/i })).toBeNull();
  });
});

describe('rejected batches', () => {
  async function failStatusWith(error: unknown) {
    const user = userEvent.setup();
    crm.bulkStatus.mockRejectedValue(error);
    renderAt();
    await ready();
    await user.click(allBox());
    await user.click(screen.getByRole('button', { name: 'Change status' }));
    await user.click(within(screen.getByTestId('crm-bulk-modal')).getByRole('button', { name: /Set 3 to/ }));
  }

  it('shows the server 422, keeps the rows and the selection, and re-reads', async () => {
    await failStatusWith(apiError(422, { message: 'One or more selected leads are not available.', errors: { lead_ids: ['One or more selected leads are not available.'] } }));

    expect(await screen.findByRole('alert')).toHaveTextContent('One or more selected leads are not available.');
    expect(screen.queryByText(/changed/)).toBeNull();
    expect(screen.getByTestId('crm-bulk-count')).toHaveTextContent('3 selected');
    await waitFor(() => expect(crm.listLeads).toHaveBeenCalledTimes(2));
  });

  it('shows the capability 403 (plan revoked)', async () => {
    await failStatusWith(apiError(403, { message: 'Your current plan does not include this feature. Please upgrade your subscription to unlock it.', error_code: 'CAPABILITY_NOT_ENTITLED' }));
    expect(await screen.findByRole('alert')).toHaveTextContent('Your current plan does not include this feature');
  });

  it('shows a 429 without claiming success', async () => {
    await failStatusWith(apiError(429, { message: 'Too Many Attempts.' }));
    expect(await screen.findByRole('alert')).toHaveTextContent('Too many requests');
  });

  it('never leaks raw server errors', async () => {
    await failStatusWith(apiError(500, { message: 'SQLSTATE[40001]: deadlock', exception: 'QueryException' }));
    const alert = await screen.findByRole('alert');
    expect(alert).toHaveTextContent('Something went wrong. Please try again.');
    expect(alert.textContent).not.toContain('SQLSTATE');
  });

  it('explains a network failure', async () => {
    await failStatusWith(networkError());
    expect(await screen.findByRole('alert')).toHaveTextContent('Could not reach the server');
  });
});
