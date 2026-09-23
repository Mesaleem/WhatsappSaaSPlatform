import { render, screen, waitFor, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { MemoryRouter, Route, Routes, useLocation } from 'react-router-dom';
import { beforeEach, describe, expect, it, vi, type Mock } from 'vitest';

import CrmLeadDetailPage from './CrmLeadDetailPage';
import crmService from '../../services/crmService';
import { apiError, makeContact, makeLead, makeTag, networkError, paginated } from '../../test/crmFixtures';

/**
 * Phase 6 — CRM Task 8. Lead detail: GET /api/crm/leads/{id} plus the
 * dedicated status / assignee / tag / contact / delete endpoints. The
 * server's returned lead always replaces the page's copy.
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

const auth = { readOnly: false };
vi.mock('../../core/context/AuthContext', () => ({
  useAuth: () => ({ isSuperAdmin: () => false, isReadOnly: () => auth.readOnly }),
}));
vi.mock('../../core/context/TenantContext', () => ({
  useTenant: () => ({ selectedAccountId: null }),
}));

const crm = crmService as unknown as Record<string, Mock>;

function LocationProbe() {
  const location = useLocation();
  return <div data-testid="location">{location.pathname}</div>;
}

function renderAt(url = '/crm/leads/1') {
  return render(
    <MemoryRouter initialEntries={[url]}>
      <Routes>
        <Route path="/crm/leads/:id" element={<CrmLeadDetailPage />} />
        <Route path="/crm/leads" element={<div>lead list</div>} />
        <Route path="/crm/contacts/:id" element={<div>contact page</div>} />
      </Routes>
      <LocationProbe />
    </MemoryRouter>,
  );
}

const lead = makeLead({
  id: 1,
  status: 'contacted',
  source: 'meta_ad',
  assigned_user_id: 7,
  assigned_user: { id: 7, name: 'Asha' },
  contact: { id: 101, name: 'Ada', phone_number: '919876543210', email: 'ada@example.com' },
  capture_lead: { id: 55, provider: 'meta', provider_lead_id: 'lead-abc', captured_at: '2026-09-19T08:00:00+00:00' },
  tags: [{ id: 3, name: 'Hot' }],
});

beforeEach(() => {
  auth.readOnly = false;
  crm.getLead.mockResolvedValue(structuredClone(lead));
  crm.assignees.mockResolvedValue([{ id: 7, name: 'Asha' }, { id: 8, name: 'Bala' }]);
  crm.listTags.mockResolvedValue(paginated([makeTag({ id: 3, name: 'Hot' }), makeTag({ id: 5, name: 'VIP' })]));
  crm.listContacts.mockResolvedValue(paginated([makeContact({ id: 101, name: 'Ada' }), makeContact({ id: 202, name: 'Grace', phone_number: '919111111111' })]));
});

describe('lead detail', () => {
  it('renders the lead from the API: contact, status, source, assignee, tags, capture info', async () => {
    renderAt();

    expect(await screen.findByRole('heading', { name: 'Ada' })).toBeTruthy();
    expect(screen.getByText('919876543210')).toBeTruthy();
    expect(screen.getByText('ada@example.com')).toBeTruthy();
    expect(screen.getByTestId('crm-status-badge')).toHaveTextContent('Contacted');
    expect(screen.getByText('Meta Ad')).toBeTruthy();
    expect((screen.getByLabelText('Change assignee') as HTMLSelectElement).value).toBe('7');
    expect(screen.getByTestId('crm-tag-chip')).toHaveTextContent('Hot');
    expect(screen.getByTestId('crm-capture-info')).toHaveTextContent('meta');
    expect(screen.getByTestId('crm-capture-info')).toHaveTextContent('lead-abc');
    expect(crm.getLead).toHaveBeenCalledWith(1);
  });

  it('links to the associated contact', async () => {
    const user = userEvent.setup();
    renderAt();
    await screen.findByRole('heading', { name: 'Ada' });

    await user.click(screen.getByRole('link', { name: 'Open contact' }));
    expect(screen.getByTestId('location').textContent).toBe('/crm/contacts/101');
  });

  it('shows "not found" for a 404 (foreign or missing lead) without leaking anything', async () => {
    crm.getLead.mockRejectedValue(apiError(404, { message: 'Lead not found.' }));
    renderAt('/crm/leads/999');

    expect(await screen.findByRole('alert')).toHaveTextContent('This lead could not be found');
  });

  it('treats a non-numeric id as not found without calling the API', async () => {
    renderAt('/crm/leads/abc');

    expect(await screen.findByRole('alert')).toHaveTextContent('This lead could not be found');
    expect(crm.getLead).not.toHaveBeenCalled();
  });

  it('shows a 403 message from the backend', async () => {
    crm.getLead.mockRejectedValue(apiError(403, { message: 'This feature has been disabled for your account by the Super Admin.', error_code: 'MODULE_DISABLED' }));
    renderAt();

    expect(await screen.findByRole('alert')).toHaveTextContent('disabled for your account');
  });

  it('shows a network failure', async () => {
    crm.getLead.mockRejectedValue(networkError());
    renderAt();

    expect(await screen.findByRole('alert')).toHaveTextContent('Could not reach the server');
  });

  it('changes status and shows the server’s lead', async () => {
    const user = userEvent.setup();
    crm.changeStatus.mockResolvedValue({ message: 'Lead status updated.', data: { ...lead, status: 'converted', converted_at: '2026-09-23T09:00:00+00:00' } });
    renderAt();
    await screen.findByRole('heading', { name: 'Ada' });

    await user.selectOptions(screen.getByLabelText('Change status'), 'converted');

    expect(crm.changeStatus).toHaveBeenCalledWith(1, 'converted', undefined);
    await waitFor(() => expect(screen.getByTestId('crm-status-badge')).toHaveTextContent('Converted'));
    expect(screen.getByText('Lead status updated.')).toBeTruthy();
  });

  it('reassigns and unassigns', async () => {
    const user = userEvent.setup();
    crm.changeAssignee
      .mockResolvedValueOnce({ message: 'Lead assigned.', data: { ...lead, assigned_user_id: 8, assigned_user: { id: 8, name: 'Bala' } } })
      .mockResolvedValueOnce({ message: 'Lead unassigned.', data: { ...lead, assigned_user_id: null, assigned_user: null } });
    renderAt();
    await screen.findByRole('heading', { name: 'Ada' });

    await user.selectOptions(screen.getByLabelText('Change assignee'), '8');
    await waitFor(() => expect((screen.getByLabelText('Change assignee') as HTMLSelectElement).value).toBe('8'));

    await user.selectOptions(screen.getByLabelText('Change assignee'), '');
    expect(crm.changeAssignee).toHaveBeenLastCalledWith(1, null);
    await waitFor(() => expect((screen.getByLabelText('Change assignee') as HTMLSelectElement).value).toBe(''));
  });

  it('keeps showing a current owner who is no longer eligible, without offering them', async () => {
    crm.getLead.mockResolvedValue({ ...lead, assigned_user_id: 99, assigned_user: { id: 99, name: 'Former' } });
    renderAt();
    await screen.findByRole('heading', { name: 'Ada' });

    const select = screen.getByLabelText('Change assignee') as HTMLSelectElement;
    await waitFor(() => expect(select.value).toBe('99'));
    const former = Array.from(select.options).find((o) => o.value === '99');
    expect(former?.textContent).toBe('Former (not eligible)');
    expect(former?.disabled).toBe(true);
  });

  it('attaches and detaches tags; the reply is idempotent-safe', async () => {
    const user = userEvent.setup();
    crm.attachTag.mockResolvedValue({ message: 'Tag attached.', data: { ...lead, tags: [{ id: 3, name: 'Hot' }, { id: 5, name: 'VIP' }] } });
    crm.detachTag.mockResolvedValue({ message: 'Tag was not attached.', data: { ...lead, tags: [{ id: 5, name: 'VIP' }] } });
    renderAt();
    await screen.findByRole('heading', { name: 'Ada' });

    await user.click(screen.getByRole('button', { name: 'Add tag' }));
    await user.click(await within(await screen.findByTestId('crm-tag-picker')).findByText('VIP'));
    expect(crm.attachTag).toHaveBeenCalledWith(1, 5);
    await waitFor(() => expect(screen.getAllByTestId('crm-tag-chip')).toHaveLength(2));

    await user.click(screen.getByLabelText('Remove tag Hot'));
    expect(crm.detachTag).toHaveBeenCalledWith(1, 3);
    expect(await screen.findByText('Tag was not attached.')).toBeTruthy();
    await waitFor(() => expect(screen.getAllByTestId('crm-tag-chip')).toHaveLength(1));
  });

  it('moves the lead to another contact through the contact endpoint', async () => {
    const user = userEvent.setup();
    crm.reassignContact.mockResolvedValue({
      message: 'Lead reassigned.',
      data: { ...lead, contact: { id: 202, name: 'Grace', phone_number: '919111111111', email: null } },
    });
    renderAt();
    await screen.findByRole('heading', { name: 'Ada' });

    await user.click(screen.getByRole('button', { name: /move to another contact/i }));
    const modal = screen.getByTestId('crm-reassign-contact');
    // The current contact is not offered.
    await within(modal).findByText('Grace');
    expect(within(modal).queryByText('Ada')).toBeNull();

    await user.click(within(modal).getByText('Grace'));
    expect(crm.reassignContact).toHaveBeenCalledWith(1, 202);
    expect(await screen.findByRole('heading', { name: 'Grace' })).toBeTruthy();
  });

  it('deletes only after confirmation, then returns to the list', async () => {
    const user = userEvent.setup();
    crm.deleteLead.mockResolvedValue({ message: 'Lead deleted.' });
    renderAt();
    await screen.findByRole('heading', { name: 'Ada' });

    await user.click(screen.getByRole('button', { name: /delete lead/i }));
    expect(crm.deleteLead).not.toHaveBeenCalled();
    expect(screen.getByText(/The contact itself is kept/)).toBeTruthy();

    await user.click(screen.getAllByRole('button', { name: 'Delete lead' }).at(-1)!);
    expect(crm.deleteLead).toHaveBeenCalledWith(1);
    await waitFor(() => expect(screen.getByTestId('location').textContent).toBe('/crm/leads'));
  });

  it('disables every action when read-only', async () => {
    auth.readOnly = true;
    renderAt();
    await screen.findByRole('heading', { name: 'Ada' });

    expect((screen.getByLabelText('Change status') as HTMLSelectElement).disabled).toBe(true);
    expect((screen.getByLabelText('Change assignee') as HTMLSelectElement).disabled).toBe(true);
    expect((screen.getByRole('button', { name: 'Add tag' }) as HTMLButtonElement).disabled).toBe(true);
    expect((screen.getByRole('button', { name: /delete lead/i }) as HTMLButtonElement).disabled).toBe(true);
    expect((screen.getByRole('button', { name: /move to another contact/i }) as HTMLButtonElement).disabled).toBe(true);
  });
});
