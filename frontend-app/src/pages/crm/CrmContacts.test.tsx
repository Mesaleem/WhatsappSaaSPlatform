import { render, screen, waitFor, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { MemoryRouter, Route, Routes, useLocation } from 'react-router-dom';
import { beforeEach, describe, expect, it, vi, type Mock } from 'vitest';

import CrmContactsPage from './CrmContactsPage';
import CrmContactDetailPage from './CrmContactDetailPage';
import crmService from '../../services/crmService';
import { apiError, makeContact, makeLead, paginated } from '../../test/crmFixtures';

/**
 * Phase 6 — CRM Task 8. Contacts list (GET /crm/contacts) and contact
 * detail (GET /crm/contacts/{id} + GET /crm/contacts/{id}/leads with the
 * endpoint's own status/source filters).
 */

vi.mock('../../services/crmService', () => ({
  default: {
    listContacts: vi.fn(),
    getContact: vi.fn(),
    createContact: vi.fn(),
    updateContact: vi.fn(),
    deleteContact: vi.fn(),
    mergeContacts: vi.fn(),
    contactLeads: vi.fn(),
  },
}));
vi.mock('../../core/context/AuthContext', () => ({
  useAuth: () => ({ isSuperAdmin: () => false, isReadOnly: () => false }),
}));
vi.mock('../../core/context/TenantContext', () => ({
  useTenant: () => ({ selectedAccountId: null }),
}));

const crm = crmService as unknown as Record<string, Mock>;

function LocationProbe() {
  const location = useLocation();
  return <div data-testid="location">{location.pathname + location.search}</div>;
}

function renderAt(url: string) {
  return render(
    <MemoryRouter initialEntries={[url]}>
      <Routes>
        <Route path="/crm/contacts" element={<CrmContactsPage />} />
        <Route path="/crm/contacts/:id" element={<CrmContactDetailPage />} />
        <Route path="/crm/leads/:id" element={<div>lead page</div>} />
      </Routes>
      <LocationProbe />
    </MemoryRouter>,
  );
}

const ada = makeContact({ id: 101, name: 'Ada', phone_number: '919876543210', email: 'ada@example.com', crm_leads_count: 2 });
const grace = makeContact({ id: 202, name: 'Grace', phone_number: '919111111111', crm_leads_count: 0 });

beforeEach(() => {
  crm.listContacts.mockResolvedValue(paginated([ada, grace]));
  crm.getContact.mockResolvedValue(structuredClone(ada));
  crm.contactLeads.mockResolvedValue(
    paginated([
      makeLead({ id: 1, status: 'converted', tags: [{ id: 3, name: 'Hot' }] }),
      makeLead({ id: 2, source: 'api', assigned_user_id: 7, assigned_user: { id: 7, name: 'Asha' } }),
    ]),
  );
});

describe('contacts list', () => {
  it('renders contacts from the API with their lead counts', async () => {
    renderAt('/crm/contacts');

    const row = await screen.findByTestId('crm-contact-row-101');
    expect(within(row).getByText('Ada')).toBeTruthy();
    expect(within(row).getByText('919876543210')).toBeTruthy();
    expect(within(row).getByText('ada@example.com')).toBeTruthy();
    expect(within(row).getByText('2')).toBeTruthy();
  });

  it('searches on the server', async () => {
    const user = userEvent.setup();
    renderAt('/crm/contacts');
    await screen.findByTestId('crm-contact-row-101');

    await user.type(screen.getByPlaceholderText(/search name, phone or email/i), 'gra');

    await waitFor(() => expect(crm.listContacts).toHaveBeenLastCalledWith('gra', 1, 20));
    expect(screen.getByTestId('location').textContent).toBe('/crm/contacts?q=gra');
  });

  it('shows empty states', async () => {
    crm.listContacts.mockResolvedValue(paginated([]));
    renderAt('/crm/contacts');
    expect(await screen.findByText('No CRM contacts yet.')).toBeTruthy();
  });

  it('opens a contact', async () => {
    const user = userEvent.setup();
    renderAt('/crm/contacts');
    await user.click(await screen.findByTestId('crm-contact-row-202'));

    expect(screen.getByTestId('location').textContent).toBe('/crm/contacts/202');
  });

  it('creates a contact and reports the server message (existing number reused)', async () => {
    const user = userEvent.setup();
    crm.createContact.mockResolvedValue({ message: 'Contact already exists.', data: ada });
    renderAt('/crm/contacts');
    await screen.findByTestId('crm-contact-row-101');

    await user.click(screen.getByRole('button', { name: /new contact/i }));
    const form = screen.getByTestId('crm-contact-form');
    await user.type(within(form).getByLabelText(/phone number/i), '919876543210');
    await user.click(within(form).getByRole('button', { name: 'Create contact' }));

    expect(crm.createContact).toHaveBeenCalledWith({ phone_number: '919876543210', name: null, email: null });
    await waitFor(() => expect(screen.getByTestId('location').textContent).toBe('/crm/contacts/101'));
  });
});

describe('contact detail', () => {
  it('renders the contact and its leads', async () => {
    renderAt('/crm/contacts/101');

    expect(await screen.findByRole('heading', { name: 'Ada' })).toBeTruthy();
    const table = await screen.findByTestId('crm-contact-leads');
    expect(await within(table).findByText('Lead #1')).toBeTruthy();
    expect(within(table).getByText('Converted')).toBeTruthy();
    expect(within(table).getByText('Hot')).toBeTruthy();
    expect(within(table).getByText('Asha')).toBeTruthy();
    expect(crm.contactLeads).toHaveBeenCalledWith(101, { status: undefined, source: undefined }, 1, 20);
  });

  it('filters its leads by status and source on the server', async () => {
    const user = userEvent.setup();
    renderAt('/crm/contacts/101');
    await screen.findByTestId('crm-contact-leads');

    await user.selectOptions(screen.getByDisplayValue('All statuses'), 'converted');
    await waitFor(() => expect(crm.contactLeads).toHaveBeenLastCalledWith(101, { status: 'converted', source: undefined }, 1, 20));

    await user.selectOptions(screen.getByDisplayValue('All sources'), 'api');
    await waitFor(() => expect(crm.contactLeads).toHaveBeenLastCalledWith(101, { status: 'converted', source: 'api' }, 1, 20));
    expect(screen.getByTestId('location').textContent).toBe('/crm/contacts/101?status=converted&source=api');
  });

  it('shows the empty lead state', async () => {
    crm.contactLeads.mockResolvedValue(paginated([]));
    renderAt('/crm/contacts/101');

    expect(await screen.findByText('This contact has no CRM leads.')).toBeTruthy();
  });

  it('shows not found for a 404', async () => {
    crm.getContact.mockRejectedValue(apiError(404, { message: 'Contact not found.' }));
    crm.contactLeads.mockRejectedValue(apiError(404, { message: 'Contact not found.' }));
    renderAt('/crm/contacts/999');

    expect(await screen.findByText(/This contact could not be found/)).toBeTruthy();
  });

  it('edits the contact with only the contact fields', async () => {
    const user = userEvent.setup();
    crm.updateContact.mockResolvedValue({ message: 'Contact updated.', data: { ...ada, name: 'Ada L.' } });
    renderAt('/crm/contacts/101');
    await screen.findByRole('heading', { name: 'Ada' });

    await user.click(screen.getByRole('button', { name: 'Edit' }));
    const form = screen.getByTestId('crm-contact-form');
    const name = within(form).getByLabelText(/^name/i);
    await user.clear(name);
    await user.type(name, 'Ada L.');
    await user.click(within(form).getByRole('button', { name: 'Save changes' }));

    expect(crm.updateContact).toHaveBeenCalledWith(101, { phone_number: '919876543210', name: 'Ada L.', email: 'ada@example.com' });
    expect(await screen.findByRole('heading', { name: 'Ada L.' })).toBeTruthy();
  });

  it('asks for confirmation and shows the backend 409 when the contact still has leads', async () => {
    const user = userEvent.setup();
    crm.deleteContact.mockRejectedValue(
      apiError(409, {
        message: 'This contact still has related records. Remove them before deleting the contact.',
        error_code: 'CONTACT_HAS_DEPENDENTS',
      }),
    );
    renderAt('/crm/contacts/101');
    await screen.findByRole('heading', { name: 'Ada' });

    await user.click(screen.getByRole('button', { name: 'Delete' }));
    expect(crm.deleteContact).not.toHaveBeenCalled();
    await user.click(screen.getByRole('button', { name: 'Delete contact' }));

    expect(crm.deleteContact).toHaveBeenCalledWith(101);
    expect(await screen.findByText(/still has related records/)).toBeTruthy();
    expect(screen.getByTestId('location').textContent).toBe('/crm/contacts/101');
  });

  it('merges into another contact only with the phone-discard confirmation', async () => {
    const user = userEvent.setup();
    crm.mergeContacts.mockResolvedValue({ message: 'Contacts merged.', data: grace });
    renderAt('/crm/contacts/101');
    await screen.findByRole('heading', { name: 'Ada' });

    await user.click(screen.getByRole('button', { name: /merge into/i }));
    const modal = screen.getByTestId('crm-merge-contact');
    await user.click(await within(modal).findByText('Grace'));
    const merge = within(modal).getByRole('button', { name: 'Merge' }) as HTMLButtonElement;
    expect(merge.disabled).toBe(true);

    await user.click(within(modal).getByRole('checkbox'));
    await user.click(merge);

    expect(crm.mergeContacts).toHaveBeenCalledWith(101, 202, true);
    await waitFor(() => expect(screen.getByTestId('location').textContent).toBe('/crm/contacts/202'));
  });
});
