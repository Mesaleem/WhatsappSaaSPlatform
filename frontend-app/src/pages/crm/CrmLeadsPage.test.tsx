import { render, screen, waitFor, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { MemoryRouter, Route, Routes, useLocation } from 'react-router-dom';
import { beforeEach, describe, expect, it, vi, type Mock } from 'vitest';

import CrmLeadsPage from './CrmLeadsPage';
import crmService from '../../services/crmService';
import { apiError, makeLead, makeTag, networkError, paginated } from '../../test/crmFixtures';

/**
 * Phase 6 — CRM Task 8. The lead list is a faithful client of
 * GET /api/crm/leads and the dedicated mutation endpoints: filters and
 * pagination go to the server (never filtered in memory), rows come from
 * the response, mutations call the dedicated endpoints and the server's
 * row replaces the local one.
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
  },
}));

const auth = { superAdmin: false, readOnly: false, platform: null as { id: number; company_name: string } | null };
vi.mock('../../core/context/AuthContext', () => ({
  useAuth: () => ({
    isSuperAdmin: () => auth.superAdmin,
    isReadOnly: () => auth.readOnly,
    user: { id: 1, platform_crm_account: auth.platform },
  }),
}));

const tenant = { selectedAccountId: null as number | null, selectedAccount: null as { id: number; company_name: string } | null };
vi.mock('../../core/context/TenantContext', () => ({
  useTenant: () => ({ selectedAccountId: tenant.selectedAccountId, selectedAccount: tenant.selectedAccount }),
}));

const crm = crmService as unknown as Record<string, Mock>;

function LocationProbe() {
  const location = useLocation();
  return <div data-testid="location">{location.pathname + location.search}</div>;
}

function renderAt(url = '/crm/leads') {
  return render(
    <MemoryRouter initialEntries={[url]}>
      <Routes>
        <Route path="/crm/leads" element={<CrmLeadsPage />} />
        <Route path="/crm/leads/:id" element={<div>lead detail</div>} />
      </Routes>
      <LocationProbe />
    </MemoryRouter>,
  );
}

const hot = makeTag({ id: 3, name: 'Hot', lead_count: 1 });
const vip = makeTag({ id: 5, name: 'VIP', lead_count: 0 });

beforeEach(() => {
  auth.superAdmin = false;
  auth.readOnly = false;
  auth.platform = null;
  tenant.selectedAccountId = null;
  tenant.selectedAccount = null;
  crm.listLeads.mockResolvedValue(
    paginated([
      makeLead({ id: 1, tags: [{ id: 3, name: 'Hot' }], assigned_user_id: 7, assigned_user: { id: 7, name: 'Asha' } }),
      makeLead({ id: 2, status: 'contacted', source: 'whatsapp', contact: { id: 102, name: 'Ravi', phone_number: '919800000002', email: 'ravi@example.com' } }),
    ], { total: 2 }),
  );
  crm.assignees.mockResolvedValue([{ id: 7, name: 'Asha' }, { id: 8, name: 'Bala' }]);
  crm.listTags.mockResolvedValue(paginated([hot, vip]));
});

async function ready() {
  await screen.findByTestId('crm-lead-row-1');
}

describe('rendering', () => {
  it('renders rows from the API response, with contact, status, source, assignee and tags', async () => {
    renderAt();
    await ready();

    const row = screen.getByTestId('crm-lead-row-2');
    expect(within(row).getByText('Ravi')).toBeTruthy();
    expect(within(row).getByText('919800000002')).toBeTruthy();
    expect(within(row).getByText('ravi@example.com')).toBeTruthy();
    expect(within(row).getByText('WhatsApp')).toBeTruthy();
    expect((within(row).getByLabelText('Change status') as HTMLSelectElement).value).toBe('contacted');

    const first = screen.getByTestId('crm-lead-row-1');
    expect(within(first).getByText('Hot')).toBeTruthy();
    expect((within(first).getByLabelText('Change assignee') as HTMLSelectElement).value).toBe('7');
  });

  it('offers exactly the four canonical statuses', async () => {
    renderAt();
    await ready();

    const select = within(screen.getByTestId('crm-lead-row-1')).getByLabelText('Change status') as HTMLSelectElement;
    expect(Array.from(select.options).map((o) => o.value)).toEqual(['new', 'contacted', 'converted', 'not_converted']);
  });

  it('makes one list request and one assignee request — never one per lead', async () => {
    renderAt();
    await ready();

    expect(crm.listLeads).toHaveBeenCalledTimes(1);
    expect(crm.assignees).toHaveBeenCalledTimes(1);
    expect(crm.listTags).toHaveBeenCalledTimes(1);
  });

  it('shows an empty state when there are no leads', async () => {
    crm.listLeads.mockResolvedValue(paginated([]));
    renderAt();

    expect(await screen.findByText('No CRM leads yet.')).toBeTruthy();
  });

  it('shows a filtered empty state when filters exclude everything', async () => {
    crm.listLeads.mockResolvedValue(paginated([]));
    renderAt('/crm/leads?status=converted');

    expect(await screen.findByText('No leads match these filters.')).toBeTruthy();
  });

  it('asks a Super Admin to pick a client and makes no CRM request', async () => {
    auth.superAdmin = true;
    renderAt();

    expect(await screen.findByTestId('crm-no-client')).toBeTruthy();
    expect(crm.listLeads).not.toHaveBeenCalled();
    expect(crm.assignees).not.toHaveBeenCalled();
  });

  it('loads for a Super Admin once a client is selected', async () => {
    auth.superAdmin = true;
    tenant.selectedAccountId = 42;
    renderAt();
    await ready();

    expect(crm.listLeads).toHaveBeenCalled();
  });
});

describe('Add lead (manual creation)', () => {
  it('Super Admin with NO platform CRM account and no client selected: Add lead disabled, with the reason', async () => {
    auth.superAdmin = true;
    renderAt();
    await screen.findByTestId('crm-no-client');

    const button = screen.getByRole('button', { name: /add lead/i }) as HTMLButtonElement;
    expect(button.disabled).toBe(true);
    expect(button.title).toMatch(/select a client/i);
    await userEvent.setup().click(button);
    expect(screen.queryByTestId('crm-create-lead')).toBeNull();
    expect(crm.createLead).not.toHaveBeenCalled();
  });

  it('Super Admin with no client selected but an own platform CRM account: list loads and Add lead is enabled', async () => {
    const user = userEvent.setup();
    auth.superAdmin = true;
    auth.platform = { id: 5, company_name: 'Platform (Super Admin)' };
    crm.createLead.mockResolvedValue({ message: 'Lead created.', data: makeLead({ id: 9 }) });
    renderAt();
    await ready();

    expect(screen.queryByTestId('crm-no-client')).toBeNull();
    const button = screen.getByRole('button', { name: /add lead/i }) as HTMLButtonElement;
    expect(button.disabled).toBe(false);
    expect(button.title).toBe('');

    await user.click(button);
    const form = screen.getByTestId('crm-create-lead');
    // Own-account mode: no "For client" line, no client-selection message.
    expect(within(form).queryByTestId('crm-create-lead-target')).toBeNull();
    await user.type(within(form).getByLabelText(/phone number/i), '919811111111');
    await user.click(within(form).getByRole('button', { name: 'Create lead' }));

    // No source in the payload: the backend fixes it to `manual`.
    expect(crm.createLead).toHaveBeenCalledWith({ phone_number: '919811111111', name: null, status: 'new', assigned_user_id: null });
    await waitFor(() => expect(screen.queryByTestId('crm-create-lead')).toBeNull());
    await waitFor(() => expect(crm.listLeads).toHaveBeenCalledTimes(2));
  });

  it('disables Add lead when the target account fails the CRM gates (403 from the backend)', async () => {
    auth.superAdmin = true;
    tenant.selectedAccountId = 42;
    tenant.selectedAccount = { id: 42, company_name: 'Demo Account' };
    crm.listLeads.mockRejectedValue(apiError(403, { message: 'Your current plan does not include this feature. Please upgrade your subscription to unlock it.', error_code: 'CAPABILITY_NOT_ENTITLED' }));
    renderAt();

    expect(await screen.findByRole('alert')).toBeTruthy();
    const button = screen.getByRole('button', { name: /add lead/i }) as HTMLButtonElement;
    await waitFor(() => expect(button.disabled).toBe(true));
    expect(button.title).toMatch(/does not have CRM access/i);
  });

  it('Super Admin with a selected client: Add lead is enabled and names the target client', async () => {
    const user = userEvent.setup();
    auth.superAdmin = true;
    tenant.selectedAccountId = 42;
    tenant.selectedAccount = { id: 42, company_name: 'Acme Traders' };
    crm.createLead.mockResolvedValue({ message: 'Lead created.', data: makeLead({ id: 9 }) });
    renderAt();
    await ready();

    const button = screen.getByRole('button', { name: /add lead/i }) as HTMLButtonElement;
    expect(button.disabled).toBe(false);
    await user.click(button);
    const form = screen.getByTestId('crm-create-lead');
    expect(within(form).getByTestId('crm-create-lead-target')).toHaveTextContent('For client: Acme Traders');

    await user.type(within(form).getByLabelText(/phone number/i), '919811111111');
    await user.click(within(form).getByRole('button', { name: 'Create lead' }));

    // No account_id in the body — the selected client travels as ?account_id= (axios interceptor).
    expect(crm.createLead).toHaveBeenCalledWith({ phone_number: '919811111111', name: null, status: 'new', assigned_user_id: null });
    await waitFor(() => expect(crm.listLeads).toHaveBeenCalledTimes(2));
  });

  it('a client Admin sees an enabled Add lead for its own account (no target line)', async () => {
    const user = userEvent.setup();
    renderAt();
    await ready();

    await user.click(screen.getByRole('button', { name: /add lead/i }));
    expect(screen.getByTestId('crm-create-lead')).toBeTruthy();
    expect(screen.queryByTestId('crm-create-lead-target')).toBeNull();
  });

  it('shows a server refusal on create (e.g. capability revoked meanwhile) in the form', async () => {
    const user = userEvent.setup();
    crm.createLead.mockRejectedValue(apiError(403, { message: 'Your current plan does not include this feature. Please upgrade your subscription to unlock it.', error_code: 'CAPABILITY_NOT_ENTITLED' }));
    renderAt();
    await ready();

    await user.click(screen.getByRole('button', { name: /add lead/i }));
    const form = screen.getByTestId('crm-create-lead');
    await user.type(within(form).getByLabelText(/phone number/i), '919811111111');
    await user.click(within(form).getByRole('button', { name: 'Create lead' }));

    expect(await within(form).findByRole('alert')).toBeTruthy();
    expect(crm.listLeads).toHaveBeenCalledTimes(1);
  });
});

describe('filters and pagination (server-side)', () => {
  it('sends URL filters to the server, including AND tag ids', async () => {
    renderAt('/crm/leads?q=ravi&status=contacted&source=whatsapp&assignee=none&tags=3,5&page=2&per_page=50');
    await ready();

    expect(crm.listLeads).toHaveBeenCalledWith(
      { search: 'ravi', status: 'contacted', source: 'whatsapp', assigned_user_id: 'none', tag_ids: [3, 5] },
      2,
      50,
    );
    expect(screen.getByTestId('crm-tag-filter-chips').textContent).toContain('Has all of:');
    expect(screen.getByTestId('crm-tag-filter-chips').textContent).toContain('Hot');
    expect(screen.getByTestId('crm-tag-filter-chips').textContent).toContain('VIP');
  });

  it('drops invented statuses and sources from the URL instead of sending them', async () => {
    renderAt('/crm/leads?status=qualified&source=billboard');
    await ready();

    expect(crm.listLeads).toHaveBeenCalledWith({}, 1, 20);
  });

  it('changing one filter keeps the others and resets to page 1', async () => {
    const user = userEvent.setup();
    renderAt('/crm/leads?source=whatsapp&page=3');
    await ready();

    await user.selectOptions(screen.getByLabelText('Filter by assignee'), 'none');

    await waitFor(() => expect(screen.getByTestId('location').textContent).toBe('/crm/leads?source=whatsapp&assignee=none'));
    await waitFor(() =>
      expect(crm.listLeads).toHaveBeenLastCalledWith({ source: 'whatsapp', assigned_user_id: 'none' }, 1, 20),
    );
  });

  it('adds a tag filter from the server-searched picker', async () => {
    const user = userEvent.setup();
    renderAt();
    await ready();

    await user.click(screen.getByRole('button', { name: 'Filter by tag' }));
    const picker = await screen.findByTestId('crm-tag-picker');
    await user.click(await within(picker).findByText('VIP'));

    await waitFor(() => expect(crm.listLeads).toHaveBeenLastCalledWith({ tag_ids: [5] }, 1, 20));
  });

  it('clearing filters removes all of them', async () => {
    const user = userEvent.setup();
    renderAt('/crm/leads?status=new&tags=3');
    await ready();

    await user.click(screen.getByRole('button', { name: /clear filters/i }));

    await waitFor(() => expect(screen.getByTestId('location').textContent).toBe('/crm/leads'));
  });

  it('paginates through the server', async () => {
    const user = userEvent.setup();
    crm.listLeads.mockResolvedValue(paginated([makeLead({ id: 1 })], { total: 45, last_page: 3, per_page: 20 }));
    renderAt();
    await ready();

    await user.click(screen.getByLabelText('Next page'));
    await waitFor(() => expect(crm.listLeads).toHaveBeenLastCalledWith({}, 2, 20));

    await user.selectOptions(screen.getByDisplayValue('20'), '50');
    await waitFor(() => expect(crm.listLeads).toHaveBeenLastCalledWith({}, 1, 50));
  });
});

describe('mutations', () => {
  it('changes status through the dedicated endpoint and shows the server row', async () => {
    const user = userEvent.setup();
    crm.changeStatus.mockResolvedValue({ message: 'Lead status updated.', data: makeLead({ id: 1, status: 'converted', tags: [{ id: 3, name: 'Hot' }] }) });
    renderAt();
    await ready();

    await user.selectOptions(within(screen.getByTestId('crm-lead-row-1')).getByLabelText('Change status'), 'converted');

    expect(crm.changeStatus).toHaveBeenCalledWith(1, 'converted', undefined);
    expect(await screen.findByText('Lead status updated.')).toBeTruthy();
    await waitFor(() => expect(crm.listLeads).toHaveBeenCalledTimes(2)); // re-read after the mutation
  });

  it('asks for an optional reason when marking not converted', async () => {
    const user = userEvent.setup();
    crm.changeStatus.mockResolvedValue({ message: 'Lead status updated.', data: makeLead({ id: 1, status: 'not_converted' }) });
    renderAt();
    await ready();

    await user.selectOptions(within(screen.getByTestId('crm-lead-row-1')).getByLabelText('Change status'), 'not_converted');
    // PromptModal autofocuses its input.
    await user.keyboard('Budget');
    await user.click(screen.getByRole('button', { name: 'Save status' }));

    expect(crm.changeStatus).toHaveBeenCalledWith(1, 'not_converted', 'Budget');
  });

  it('shows a 422 from the status endpoint without changing the row', async () => {
    const user = userEvent.setup();
    crm.changeStatus.mockRejectedValue(apiError(422, { message: 'The selected status is invalid.', errors: { status: ['The selected status is invalid.'] } }));
    renderAt();
    await ready();

    await user.selectOptions(within(screen.getByTestId('crm-lead-row-2')).getByLabelText('Change status'), 'new');

    expect(await screen.findByText('The selected status is invalid.')).toBeTruthy();
    expect((within(screen.getByTestId('crm-lead-row-2')).getByLabelText('Change status') as HTMLSelectElement).value).toBe('contacted');
  });

  it('assigns, and unassigns with null, through the assignee endpoint', async () => {
    const user = userEvent.setup();
    crm.changeAssignee
      .mockResolvedValueOnce({ message: 'Lead assigned.', data: makeLead({ id: 2, assigned_user_id: 8, assigned_user: { id: 8, name: 'Bala' } }) })
      .mockResolvedValueOnce({ message: 'Lead unassigned.', data: makeLead({ id: 1 }) });
    renderAt();
    await ready();

    await user.selectOptions(within(screen.getByTestId('crm-lead-row-2')).getByLabelText('Change assignee'), '8');
    expect(crm.changeAssignee).toHaveBeenCalledWith(2, 8);
    expect(await screen.findByText('Lead assigned.')).toBeTruthy();

    await user.selectOptions(within(screen.getByTestId('crm-lead-row-1')).getByLabelText('Change assignee'), '');
    expect(crm.changeAssignee).toHaveBeenLastCalledWith(1, null);
  });

  it('only offers the backend-eligible assignees', async () => {
    renderAt();
    await ready();

    const select = within(screen.getByTestId('crm-lead-row-2')).getByLabelText('Change assignee') as HTMLSelectElement;
    expect(Array.from(select.options).map((o) => o.textContent)).toEqual(['Unassigned', 'Asha', 'Bala']);
  });

  it('shows the backend 422 when an assignee is no longer eligible', async () => {
    const user = userEvent.setup();
    crm.changeAssignee.mockRejectedValue(apiError(422, { message: 'The selected assignee is not available.', errors: { assigned_user_id: ['The selected assignee is not available.'] } }));
    renderAt();
    await ready();

    await user.selectOptions(within(screen.getByTestId('crm-lead-row-2')).getByLabelText('Change assignee'), '7');

    expect(await screen.findByText('The selected assignee is not available.')).toBeTruthy();
  });

  it('attaches and detaches tags through the tag endpoints only', async () => {
    const user = userEvent.setup();
    crm.attachTag.mockResolvedValue({ message: 'Tag attached.', data: makeLead({ id: 2, tags: [{ id: 5, name: 'VIP' }] }) });
    crm.detachTag.mockResolvedValue({ message: 'Tag detached.', data: makeLead({ id: 1, tags: [] }) });
    renderAt();
    await ready();

    // After the mutation the page re-reads the list; the server now has the tag.
    crm.listLeads.mockResolvedValue(
      paginated([makeLead({ id: 1, tags: [{ id: 3, name: 'Hot' }] }), makeLead({ id: 2, tags: [{ id: 5, name: 'VIP' }] })]),
    );
    const row2 = screen.getByTestId('crm-lead-row-2');
    await user.click(within(row2).getByRole('button', { name: 'Add tag' }));
    await user.click(await within(await screen.findByTestId('crm-tag-picker')).findByText('VIP'));
    expect(crm.attachTag).toHaveBeenCalledWith(2, 5);
    expect(await screen.findByText('Tag attached.')).toBeTruthy();
    await waitFor(() => expect(within(screen.getByTestId('crm-lead-row-2')).getByText('VIP')).toBeTruthy());

    await user.click(within(screen.getByTestId('crm-lead-row-1')).getByLabelText('Remove tag Hot'));
    expect(crm.detachTag).toHaveBeenCalledWith(1, 3);
    expect(crm.changeStatus).not.toHaveBeenCalled();
  });

  it('the tag picker hides tags the lead already has', async () => {
    const user = userEvent.setup();
    renderAt();
    await ready();

    await user.click(within(screen.getByTestId('crm-lead-row-1')).getByRole('button', { name: 'Add tag' }));
    const picker = await screen.findByTestId('crm-tag-picker');
    await within(picker).findByText('VIP');
    expect(within(picker).queryByText('Hot')).toBeNull();
  });

  it('disables every mutation when the subscription is read-only', async () => {
    auth.readOnly = true;
    renderAt();
    await ready();

    const row = screen.getByTestId('crm-lead-row-1');
    expect((within(row).getByLabelText('Change status') as HTMLSelectElement).disabled).toBe(true);
    expect((within(row).getByLabelText('Change assignee') as HTMLSelectElement).disabled).toBe(true);
    expect((within(row).getByRole('button', { name: 'Add tag' }) as HTMLButtonElement).disabled).toBe(true);
    expect((screen.getByRole('button', { name: /add lead/i }) as HTMLButtonElement).disabled).toBe(true);
  });

  it('creates a lead with only the StoreCrmLeadRequest fields, closes the form and re-reads the list', async () => {
    const user = userEvent.setup();
    crm.createLead.mockResolvedValue({ message: 'Lead created.', data: makeLead({ id: 9 }) });
    renderAt();
    await ready();

    await user.click(screen.getByRole('button', { name: /add lead/i }));
    const form = screen.getByTestId('crm-create-lead');
    await user.type(within(form).getByLabelText(/phone number/i), '919811111111');
    await user.type(within(form).getByLabelText(/^name/i), 'Meera');
    await user.click(within(form).getByRole('button', { name: 'Create lead' }));

    expect(crm.createLead).toHaveBeenCalledWith({
      phone_number: '919811111111',
      name: 'Meera',
      status: 'new',
      assigned_user_id: null,
    });
    await waitFor(() => expect(screen.queryByTestId('crm-create-lead')).toBeNull());
    expect(await screen.findByRole('status')).toHaveTextContent('Lead created.');
    await waitFor(() => expect(crm.listLeads).toHaveBeenCalledTimes(2));
    expect(screen.getByTestId('location').textContent).toBe('/crm/leads');
  });

  it('locks the source to Manual: no source picker, read-only field, never sent', async () => {
    const user = userEvent.setup();
    crm.createLead.mockResolvedValue({ message: 'Lead created.', data: makeLead({ id: 9 }) });
    renderAt();
    await ready();

    await user.click(screen.getByRole('button', { name: /add lead/i }));
    const form = screen.getByTestId('crm-create-lead');
    // Only Status and Assignee are pickers; there is no Source select.
    const selects = within(form).getAllByRole('combobox') as HTMLSelectElement[];
    expect(selects).toHaveLength(2);
    for (const select of selects) {
      expect(Array.from(select.options).map((o) => o.value)).not.toContain('meta_ad');
    }
    const source = within(form).getByTestId('crm-create-lead-source') as HTMLInputElement;
    expect(source.value).toBe('Manual');
    expect(source.readOnly).toBe(true);
    expect(source.disabled).toBe(true);

    await user.type(within(form).getByLabelText(/phone number/i), '919811111111');
    await user.click(within(form).getByRole('button', { name: 'Create lead' }));
    expect(crm.createLead).toHaveBeenCalledTimes(1);
    expect(crm.createLead.mock.calls[0][0]).not.toHaveProperty('source');
  });

  it('shows field errors from a 422 on create', async () => {
    const user = userEvent.setup();
    crm.createLead.mockRejectedValue(apiError(422, { message: 'The phone number field is required.', errors: { phone_number: ['The phone number field is required.'] } }));
    renderAt();
    await ready();

    await user.click(screen.getByRole('button', { name: /add lead/i }));
    const form = screen.getByTestId('crm-create-lead');
    await user.type(within(form).getByLabelText(/phone number/i), 'x');
    await user.click(within(form).getByRole('button', { name: 'Create lead' }));

    expect((await within(form).findAllByText('The phone number field is required.')).length).toBeGreaterThan(0);
  });
});

describe('error handling', () => {
  it('shows the backend capability 403 message', async () => {
    crm.listLeads.mockRejectedValue(apiError(403, { message: 'Your current plan does not include this feature. Please upgrade your subscription to unlock it.', error_code: 'CAPABILITY_NOT_ENTITLED' }));
    renderAt();

    expect(await screen.findByRole('alert')).toHaveTextContent('Your current plan does not include this feature');
  });

  it('shows a foreign/missing tag filter 422 from the server', async () => {
    crm.listLeads.mockRejectedValue(apiError(422, { message: 'The selected tag is not available.', errors: { tag_id: ['The selected tag is not available.'] } }));
    renderAt('/crm/leads?tags=999');

    expect(await screen.findByRole('alert')).toHaveTextContent('The selected tag is not available.');
  });

  it('never shows raw server error details for a 500', async () => {
    crm.listLeads.mockRejectedValue(apiError(500, { message: 'SQLSTATE[42S22]: Column not found', exception: 'QueryException', trace: [] }));
    renderAt();

    const alert = await screen.findByRole('alert');
    expect(alert).toHaveTextContent('Something went wrong. Please try again.');
    expect(alert.textContent).not.toContain('SQLSTATE');
  });

  it('explains a network failure', async () => {
    crm.listLeads.mockRejectedValue(networkError());
    renderAt();

    expect(await screen.findByRole('alert')).toHaveTextContent('Could not reach the server');
  });
});
