import { render, screen, waitFor, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { MemoryRouter, Route, Routes } from 'react-router-dom';
import { beforeEach, describe, expect, it, vi, type Mock } from 'vitest';

import CrmTagsPage from './CrmTagsPage';
import crmService from '../../services/crmService';
import { apiError, makeTag, paginated } from '../../test/crmFixtures';

/**
 * Phase 6 — CRM Task 8. Tag management over the Task 7 API. Name rules
 * (trim, case-insensitive duplicates, 50 chars) are the backend's: the
 * page only sends what was typed and shows the 422.
 */

vi.mock('../../services/crmService', () => ({
  default: { listTags: vi.fn(), createTag: vi.fn(), renameTag: vi.fn(), deleteTag: vi.fn() },
}));
const auth = { readOnly: false };
vi.mock('../../core/context/AuthContext', () => ({
  useAuth: () => ({ isSuperAdmin: () => false, isReadOnly: () => auth.readOnly }),
}));
vi.mock('../../core/context/TenantContext', () => ({
  useTenant: () => ({ selectedAccountId: null }),
}));

const crm = crmService as unknown as Record<string, Mock>;

function renderPage(url = '/crm/tags') {
  return render(
    <MemoryRouter initialEntries={[url]}>
      <Routes>
        <Route path="/crm/tags" element={<CrmTagsPage />} />
      </Routes>
    </MemoryRouter>,
  );
}

const hot = makeTag({ id: 3, name: 'Hot', lead_count: 17 });
const vip = makeTag({ id: 5, name: 'VIP', lead_count: 1 });

beforeEach(() => {
  auth.readOnly = false;
  crm.listTags.mockResolvedValue(paginated([hot, vip]));
});

describe('tags', () => {
  it('lists tags with server lead counts linking to the filtered lead list', async () => {
    renderPage();

    const row = await screen.findByTestId('crm-tag-row-3');
    expect(within(row).getByText('Hot')).toBeTruthy();
    const count = screen.getByTestId('crm-tag-count-3');
    expect(count).toHaveTextContent('17');
    expect(count.getAttribute('href')).toBe('/crm/leads?tags=3');
  });

  it('shows the empty state', async () => {
    crm.listTags.mockResolvedValue(paginated([]));
    renderPage();
    expect(await screen.findByText('No tags yet.')).toBeTruthy();
  });

  it('searches on the server', async () => {
    const user = userEvent.setup();
    renderPage();
    await screen.findByTestId('crm-tag-row-3');

    await user.type(screen.getByPlaceholderText('Search tags…'), 'ho');
    await waitFor(() => expect(crm.listTags).toHaveBeenLastCalledWith('ho', 1, 50));
  });

  it('creates a tag by sending the typed name as-is', async () => {
    const user = userEvent.setup();
    crm.createTag.mockResolvedValue({ message: 'Tag created.', data: makeTag({ id: 9, name: 'Follow Up' }) });
    renderPage();
    await screen.findByTestId('crm-tag-row-3');

    await user.type(screen.getByLabelText('New tag name'), '  Follow   Up ');
    await user.click(screen.getByRole('button', { name: 'Add tag' }));

    expect(crm.createTag).toHaveBeenCalledWith('  Follow   Up ');
    expect(await screen.findByText('Tag created.')).toBeTruthy();
    await waitFor(() => expect(crm.listTags).toHaveBeenCalledTimes(2));
  });

  it('shows the backend duplicate 422 and keeps the input', async () => {
    const user = userEvent.setup();
    crm.createTag.mockRejectedValue(apiError(422, { message: 'A tag with this name already exists.', errors: { name: ['A tag with this name already exists.'] } }));
    renderPage();
    await screen.findByTestId('crm-tag-row-3');

    await user.type(screen.getByLabelText('New tag name'), 'hot');
    await user.click(screen.getByRole('button', { name: 'Add tag' }));

    expect(await screen.findByRole('alert')).toHaveTextContent('A tag with this name already exists.');
    expect((screen.getByLabelText('New tag name') as HTMLInputElement).value).toBe('hot');
  });

  it('prevents duplicate submissions while creating', async () => {
    const user = userEvent.setup();
    let resolve: (v: unknown) => void = () => {};
    crm.createTag.mockReturnValue(new Promise((r) => (resolve = r)));
    renderPage();
    await screen.findByTestId('crm-tag-row-3');

    await user.type(screen.getByLabelText('New tag name'), 'New');
    await user.click(screen.getByRole('button', { name: 'Add tag' }));
    await user.click(screen.getByRole('button', { name: 'Add tag' }));

    expect(crm.createTag).toHaveBeenCalledTimes(1);
    resolve({ message: 'Tag created.', data: makeTag({ id: 10, name: 'New' }) });
  });

  it('renames a tag and shows the server name', async () => {
    const user = userEvent.setup();
    crm.renameTag.mockResolvedValue({ message: 'Tag updated.', data: { ...hot, name: 'Very Hot' } });
    renderPage();
    await screen.findByTestId('crm-tag-row-3');

    await user.click(screen.getByLabelText('Rename Hot'));
    const input = screen.getByDisplayValue('Hot');
    await user.clear(input);
    await user.type(input, 'Very Hot');
    await user.click(screen.getByRole('button', { name: 'Save' }));

    expect(crm.renameTag).toHaveBeenCalledWith(3, 'Very Hot');
    expect(await within(screen.getByTestId('crm-tag-row-3')).findByText('Very Hot')).toBeTruthy();
  });

  it('shows a rename duplicate 422', async () => {
    const user = userEvent.setup();
    crm.renameTag.mockRejectedValue(apiError(422, { message: 'A tag with this name already exists.', errors: { name: ['A tag with this name already exists.'] } }));
    renderPage();
    await screen.findByTestId('crm-tag-row-5');

    await user.click(screen.getByLabelText('Rename VIP'));
    const input = screen.getByDisplayValue('VIP');
    await user.clear(input);
    await user.type(input, 'HOT');
    await user.click(screen.getByRole('button', { name: 'Save' }));

    expect(await screen.findByText('A tag with this name already exists.')).toBeTruthy();
  });

  it('deletes only after a confirmation that says leads and contacts are kept', async () => {
    const user = userEvent.setup();
    crm.deleteTag.mockResolvedValue({ message: 'Tag deleted.' });
    renderPage();
    await screen.findByTestId('crm-tag-row-3');

    await user.click(screen.getByLabelText('Delete Hot'));
    expect(crm.deleteTag).not.toHaveBeenCalled();
    expect(screen.getByText(/removed from 17 leads/)).toBeTruthy();
    expect(screen.getByText(/are not deleted or changed/)).toBeTruthy();

    await user.click(screen.getByRole('button', { name: 'Delete tag' }));
    expect(crm.deleteTag).toHaveBeenCalledWith(3);
    expect(await screen.findByText('Tag deleted.')).toBeTruthy();
  });

  it('shows a 404 when deleting a tag that no longer exists', async () => {
    const user = userEvent.setup();
    crm.deleteTag.mockRejectedValue(apiError(404, { message: 'Tag not found.' }));
    renderPage();
    await screen.findByTestId('crm-tag-row-3');

    await user.click(screen.getByLabelText('Delete Hot'));
    await user.click(screen.getByRole('button', { name: 'Delete tag' }));

    expect(await screen.findByText('Tag not found.')).toBeTruthy();
  });

  it('disables mutations when read-only', async () => {
    auth.readOnly = true;
    renderPage();
    await screen.findByTestId('crm-tag-row-3');

    expect((screen.getByLabelText('New tag name') as HTMLInputElement).disabled).toBe(true);
    expect((screen.getByLabelText('Rename Hot') as HTMLButtonElement).disabled).toBe(true);
    expect((screen.getByLabelText('Delete Hot') as HTMLButtonElement).disabled).toBe(true);
  });
});
