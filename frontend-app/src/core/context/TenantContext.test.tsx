import { render, screen, waitFor } from '@testing-library/react';
import { beforeEach, describe, expect, it, vi, type Mock } from 'vitest';

import { TenantProvider, useTenant } from './TenantContext';
import accountService from '../../services/accountService';

/**
 * The Super Admin / Agent client selection persisted in localStorage must
 * survive a page reload: it may only be dropped once the account list has
 * actually loaded and does not contain it.
 */

vi.mock('../../services/accountService', () => ({ default: { list: vi.fn() } }));
vi.mock('./AuthContext', () => ({
  useAuth: () => ({ isAuthenticated: true, isSuperAdmin: () => true, user: { id: 1, account: { account_type: 'client' } } }),
}));

const KEY = 'super_admin_selected_account_id';
const list = (accountService as unknown as { list: Mock }).list;

function Probe() {
  const { selectedAccountId } = useTenant();
  return <div data-testid="selected">{selectedAccountId === null ? 'none' : String(selectedAccountId)}</div>;
}

function deferred<T>() {
  let resolve!: (v: T) => void;
  const promise = new Promise<T>((r) => {
    resolve = r;
  });
  return { promise, resolve };
}

beforeEach(() => {
  vi.clearAllMocks();
  window.localStorage.clear();
});

describe('TenantProvider selection persistence', () => {
  it('keeps a stored selection while the account list is still loading (page reload)', async () => {
    window.localStorage.setItem(KEY, '7');
    const pending = deferred<{ data: { id: number }[] }>();
    list.mockReturnValue(pending.promise);

    render(<TenantProvider><Probe /></TenantProvider>);

    expect(screen.getByTestId('selected').textContent).toBe('7');
    expect(window.localStorage.getItem(KEY)).toBe('7');

    pending.resolve({ data: [{ id: 7 }, { id: 8 }] });
    await waitFor(() => expect(list).toHaveBeenCalled());
    await waitFor(() => expect(screen.getByTestId('selected').textContent).toBe('7'));
    expect(window.localStorage.getItem(KEY)).toBe('7');
  });

  it('drops a stored selection the loaded list does not contain', async () => {
    window.localStorage.setItem(KEY, '99');
    list.mockResolvedValue({ data: [{ id: 7 }] });

    render(<TenantProvider><Probe /></TenantProvider>);

    await waitFor(() => expect(screen.getByTestId('selected').textContent).toBe('none'));
    expect(window.localStorage.getItem(KEY)).toBeNull();
  });

  it('keeps the selection when the list request fails (nothing to compare against)', async () => {
    window.localStorage.setItem(KEY, '7');
    list.mockRejectedValue(new Error('network'));

    render(<TenantProvider><Probe /></TenantProvider>);

    await waitFor(() => expect(list).toHaveBeenCalled());
    await new Promise((r) => setTimeout(r, 0));
    expect(screen.getByTestId('selected').textContent).toBe('7');
  });
});
