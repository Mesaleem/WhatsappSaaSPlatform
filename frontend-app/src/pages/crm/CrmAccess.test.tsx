import { render, screen } from '@testing-library/react';
import { MemoryRouter, Route, Routes, useLocation } from 'react-router-dom';
import { beforeEach, describe, expect, it, vi } from 'vitest';

import AppLayout from '../../components/layout/AppLayout';
import { ProtectedRoute } from '../../core/guards/ProtectedRoute';
import UnauthorizedPage from '../errors/UnauthorizedPage';

/**
 * Phase 6 — CRM Task 8. CRM visibility follows the same three gates the
 * backend puts on /api/crm/*: `manage-crm` permission, the `lead_crm`
 * module and the `crm` capability (from /auth/me). Super Admin bypasses
 * the UI gates, like every other route. These are UX gates only — the
 * backend refusal is covered by the Laravel CRM suites.
 */

vi.mock('../../components/layout/Header', () => ({ default: () => null }));

interface AuthFixture {
  superAdmin: boolean;
  permissions: string[];
  modules: string[] | null;
  capabilities: Record<string, boolean> | undefined;
}

const auth: AuthFixture = { superAdmin: false, permissions: [], modules: null, capabilities: {} };

vi.mock('../../core/context/AuthContext', () => ({
  useAuth: () => ({
    isLoading: false,
    isAuthenticated: true,
    user: { id: 1, account_id: 1, account: { account_type: 'client' }, permissions: auth.permissions, capabilities: auth.capabilities, roles: [] },
    hasPermission: (p: string) => auth.permissions.includes(p),
    hasRole: () => false,
    isSuperAdmin: () => auth.superAdmin,
    hasModule: (m: string) => auth.superAdmin || auth.modules === null || auth.modules.includes(m),
    isReadOnly: () => false,
    refreshUser: () => Promise.resolve(),
  }),
}));

const CRM_ROUTES = ['/crm/leads', '/crm/leads/5', '/crm/pipeline', '/crm/contacts', '/crm/contacts/7', '/crm/tags', '/crm/analytics'];

function Where() {
  const location = useLocation();
  return <div data-testid="where">{location.pathname}</div>;
}

function renderGuarded(url: string) {
  return render(
    <MemoryRouter initialEntries={[url]}>
      <Routes>
        {CRM_ROUTES.map((path) => (
          <Route
            key={path}
            path={path.replace(/\/\d+$/, '/:id')}
            element={
              <ProtectedRoute permission="manage-crm" module="lead_crm" capability="crm">
                <div>crm page</div>
              </ProtectedRoute>
            }
          />
        ))}
        <Route path="/unauthorized" element={<UnauthorizedPage />} />
      </Routes>
      <Where />
    </MemoryRouter>,
  );
}

function renderNav() {
  return render(
    <MemoryRouter initialEntries={['/']}>
      <Routes>
        <Route element={<AppLayout />}>
          <Route path="/" element={<div>home</div>} />
        </Route>
      </Routes>
    </MemoryRouter>,
  );
}

const CRM_NAV = ['CRM Leads', 'CRM Pipeline', 'CRM Contacts', 'CRM Tags', 'CRM Analytics'];

beforeEach(() => {
  auth.superAdmin = false;
  auth.permissions = ['manage-crm'];
  auth.modules = null;
  auth.capabilities = { crm: true };
});

describe('CRM access', () => {
  it('allows every CRM route with manage-crm + lead_crm + crm capability', () => {
    for (const url of CRM_ROUTES) {
      const { unmount } = renderGuarded(url);
      expect(screen.getByText('crm page')).toBeTruthy();
      unmount();
    }
  });

  it('shows the CRM navigation when all three gates pass', () => {
    renderNav();
    for (const label of CRM_NAV) {
      expect(screen.getByRole('link', { name: label })).toBeTruthy();
    }
  });

  it('denies and hides CRM when the crm capability is not granted (plan/revoked)', () => {
    auth.capabilities = { crm: false };

    renderGuarded('/crm/leads');
    expect(screen.getByTestId('where').textContent).toBe('/unauthorized');
    expect(screen.getByText('Not included in your plan')).toBeTruthy();
  });

  it('hides the CRM navigation without the crm capability', () => {
    auth.capabilities = { crm: false };
    renderNav();
    for (const label of CRM_NAV) {
      expect(screen.queryByRole('link', { name: label })).toBeNull();
    }
  });

  it('denies and hides CRM without manage-crm', () => {
    auth.permissions = ['manage-social-leads'];

    renderGuarded('/crm/pipeline');
    expect(screen.getByTestId('where').textContent).toBe('/unauthorized');
    expect(screen.getByText('You don’t have access')).toBeTruthy();

    renderNav();
    expect(screen.queryByRole('link', { name: 'CRM Pipeline' })).toBeNull();
  });

  it('denies and hides CRM when the lead_crm module is disabled', () => {
    auth.modules = ['analytics'];

    renderGuarded('/crm/tags');
    expect(screen.getByTestId('where').textContent).toBe('/unauthorized');
    expect(screen.getByText('This feature is disabled')).toBeTruthy();

    renderNav();
    expect(screen.queryByRole('link', { name: 'CRM Tags' })).toBeNull();
  });

  it('lets a Super Admin through (the page then asks for a client to be selected)', () => {
    auth.superAdmin = true;
    auth.permissions = [];
    auth.capabilities = { crm: false };

    renderGuarded('/crm/leads');
    expect(screen.getByText('crm page')).toBeTruthy();
  });

  it('does not invent a denial when /auth/me carries no capability map', () => {
    auth.capabilities = undefined;

    renderGuarded('/crm/contacts');
    expect(screen.getByText('crm page')).toBeTruthy();
  });
});
