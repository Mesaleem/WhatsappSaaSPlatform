import { act, render, screen, waitFor } from '@testing-library/react';
import { MemoryRouter, Route, Routes } from 'react-router-dom';
import { beforeEach, describe, expect, it, vi, type Mock } from 'vitest';

import AppLayout from './AppLayout';
import { IndustryModuleRoute } from '../../core/guards/IndustryModuleRoute';
import industryService from '../../services/industryService';

/**
 * Phase 11 Task 2 — Education navigation gating: the sidebar entry and the route appear only when the
 * backend says the module is usable (industry assigned + capability + module + permission + shipped),
 * for the right client, with no fallback for a Super Admin who has not selected one.
 */

vi.mock('./Header', () => ({ default: () => null }));
vi.mock('../../services/industryService', () => ({ default: { context: vi.fn() } }));

const auth: { superAdmin: boolean; agent: boolean; permissions: string[]; keys: string[] | undefined } = {
  superAdmin: false, agent: false, permissions: ['view-education'], keys: ['education.students', 'education.batches'],
};
vi.mock('../../core/context/AuthContext', () => ({
  useAuth: () => ({
    isLoading: false,
    isAuthenticated: true,
    user: {
      id: 1, account_id: auth.superAdmin ? null : 7, account: auth.superAdmin ? null : { id: 7, account_type: auth.agent ? 'agent' : 'client' },
      permissions: [], capabilities: { social: true, ads: true, crm: true }, roles: [], industry_modules: auth.keys,
    },
    hasPermission: (p: string) => auth.superAdmin || auth.permissions.includes(p) || p !== 'view-education',
    hasRole: () => false,
    isSuperAdmin: () => auth.superAdmin,
    hasModule: () => true,
    isReadOnly: () => false,
    refreshUser: () => Promise.resolve(),
  }),
}));
const tenant: { selectedAccountId: number | null } = { selectedAccountId: null };
vi.mock('../../core/context/TenantContext', () => ({
  useTenant: () => ({ selectedAccountId: tenant.selectedAccountId }),
}));

const ctx = industryService.context as unknown as Mock;

const allowed = [{ industry: 'education', label: 'Education', subtype: 'school', subtype_label: 'School', allowed: true, modules: [{ key: 'students', label: 'Students', allowed: true }, { key: 'batches', label: 'Batches', allowed: true }] }];
const denied = [{ industry: 'education', label: 'Education', subtype: 'school', subtype_label: 'School', allowed: false, modules: [{ key: 'students', label: 'Students', allowed: false }] }];

function tree() {
  return (
    <MemoryRouter initialEntries={['/']}>
      <Routes>
        <Route element={<AppLayout />}>
          <Route path="/" element={<div>home</div>} />
        </Route>
      </Routes>
    </MemoryRouter>
  );
}
const labels = () => screen.getAllByRole('link').map((a) => (a.textContent ?? '').trim());

beforeEach(() => {
  auth.superAdmin = false;
  auth.agent = false;
  auth.permissions = ['view-education'];
  auth.keys = ['education.students', 'education.batches'];
  tenant.selectedAccountId = null;
  ctx.mockReset();
});

describe('Education sidebar entry', () => {
  it('shows for a tenant user whose account may use Education, between CRM and billing', () => {
    render(tree());
    const l = labels();

    expect(l).toContain('Education');
    expect(l.indexOf('Education')).toBeGreaterThan(l.indexOf('CRM Analytics'));
    expect(l.indexOf('Education')).toBeLessThan(l.indexOf('Billing & Plans'));
    expect(ctx).not.toHaveBeenCalled();
  });

  it.each([
    ['no module key (industry/capability/module/subscription not satisfied)', { keys: [] as string[] }],
    ['an older /auth/me without the list', { keys: undefined }],
    ['only another industry module', { keys: ['healthcare.patients'] }],
    ['the key but not the view-education permission', { permissions: [] as string[] }],
  ])('is hidden with %s', (_name, patch) => {
    Object.assign(auth, patch);
    render(tree());

    expect(labels()).not.toContain('Education');
  });

  it('is hidden for a Super Admin with no selected client — no fallback, no request', () => {
    auth.superAdmin = true;
    render(tree());

    expect(labels()).not.toContain('Education');
    expect(ctx).not.toHaveBeenCalled();
  });

  it('shows for a Super Admin once the selected client may use Education', async () => {
    auth.superAdmin = true;
    tenant.selectedAccountId = 42;
    ctx.mockResolvedValue(allowed);
    render(tree());

    await waitFor(() => expect(labels()).toContain('Education'));
    expect(ctx).toHaveBeenCalledTimes(1);
  });

  it('stays hidden for a client that may not, or when the lookup fails', async () => {
    auth.superAdmin = true;
    tenant.selectedAccountId = 42;
    ctx.mockResolvedValue(denied);
    const { unmount } = render(tree());
    await waitFor(() => expect(ctx).toHaveBeenCalled());
    expect(labels()).not.toContain('Education');
    unmount();

    ctx.mockRejectedValue(new Error('403'));
    render(tree());
    await waitFor(() => expect(ctx).toHaveBeenCalledTimes(2));
    expect(labels()).not.toContain('Education');
  });

  it('an Agent acting as itself uses its own keys; acting as a client uses that client\'s', async () => {
    auth.agent = true;
    auth.keys = [];
    const { rerender } = render(tree());
    expect(labels()).not.toContain('Education');
    expect(ctx).not.toHaveBeenCalled();

    tenant.selectedAccountId = 9;
    ctx.mockResolvedValue(allowed);
    rerender(tree());
    await waitFor(() => expect(labels()).toContain('Education'));
  });

  it('switching clients re-asks, and a late answer for the previous client is ignored', async () => {
    auth.superAdmin = true;
    tenant.selectedAccountId = 1;
    let resolveFirst!: (v: unknown) => void;
    ctx.mockReturnValueOnce(new Promise((r) => { resolveFirst = r; })).mockResolvedValue(denied);
    const { rerender } = render(tree());
    await waitFor(() => expect(ctx).toHaveBeenCalledTimes(1));

    tenant.selectedAccountId = 2;
    rerender(tree());
    await waitFor(() => expect(ctx).toHaveBeenCalledTimes(2));

    // Client 1 (allowed) answers last: it must not light up the entry for client 2 (denied).
    await act(async () => resolveFirst(allowed));
    expect(labels()).not.toContain('Education');
  });
});

describe('IndustryModuleRoute', () => {
  const page = (key = 'education.students') => (
    <MemoryRouter initialEntries={['/education']}>
      <Routes>
        <Route path="/education" element={<IndustryModuleRoute moduleKey={key}><div>education page</div></IndustryModuleRoute>} />
        <Route path="/unauthorized" element={<div>unauthorized</div>} />
      </Routes>
    </MemoryRouter>
  );

  it('admits a usable module', () => {
    render(page());
    expect(screen.getByText('education page')).toBeTruthy();
  });

  it('redirects an unusable one, and a Super Admin with no selected client', () => {
    render(page('education.fees'));
    expect(screen.getByText('unauthorized')).toBeTruthy();
  });

  it('redirects a Super Admin with no selected client', () => {
    auth.superAdmin = true;
    render(page());
    expect(screen.getByText('unauthorized')).toBeTruthy();
  });

  it('waits for the selected client\'s answer before deciding', async () => {
    auth.superAdmin = true;
    tenant.selectedAccountId = 5;
    let resolve!: (v: unknown) => void;
    ctx.mockReturnValue(new Promise((r) => { resolve = r; }));
    render(page());

    expect(screen.getByRole('status', { name: 'Loading' })).toBeTruthy();
    expect(screen.queryByText('unauthorized')).toBeNull();
    await act(async () => resolve(allowed));
    expect(await screen.findByText('education page')).toBeTruthy();
  });
});
