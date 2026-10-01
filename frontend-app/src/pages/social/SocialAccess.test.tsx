import { render, screen, waitFor, within } from '@testing-library/react';
import { MemoryRouter, Route, Routes, useLocation } from 'react-router-dom';
import { beforeEach, describe, expect, it, vi, type Mock } from 'vitest';

import AppLayout from '../../components/layout/AppLayout';
import { ProtectedRoute } from '../../core/guards/ProtectedRoute';
import UnauthorizedPage from '../errors/UnauthorizedPage';
import MetaAdsPage from './MetaAdsPage';
import organicPostService from '../../services/organicPostService';
import type { OrganicPost } from '../../types/organic';

/**
 * Phase 9 Task 6 — Social navigation / route consistency with the backend:
 * Social Analytics follows view-social-analytics (+ social_accounts module
 * + social capability) and stays open to view-only users, who see no
 * management entry; Social Accounts needs the management permission; the
 * Organic Posts panel needs the social capability and is rebuilt when a
 * Super Admin switches client, so no previous client's posts remain.
 */

vi.mock('../../components/layout/Header', () => ({ default: () => null }));
vi.mock('../../services/socialService', () => ({
  default: { listAccounts: () => Promise.resolve([]), listProviders: vi.fn(), checkConnection: vi.fn(), getOAuthRedirectUrl: vi.fn(), bindAccounts: vi.fn(), disconnect: vi.fn() },
}));
vi.mock('../../services/adsService', () => ({ default: { list: () => Promise.resolve([]), launch: vi.fn(), pause: vi.fn(), resume: vi.fn() }, newLaunchKey: () => 'adlaunch-test' }));
vi.mock('../../services/aiService', () => ({ default: { generateAdCopy: vi.fn() } }));
vi.mock('../../services/mediaService', () => ({ default: { upload: vi.fn() } }));
vi.mock('../../services/organicPostService', () => ({ default: { list: vi.fn(), insightsSummary: vi.fn(), publish: vi.fn(), cancel: vi.fn(), retry: vi.fn() } }));

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
    user: { id: 1, account_id: 1, account: { id: 1, account_type: 'client' }, permissions: auth.permissions, capabilities: auth.capabilities, roles: [] },
    hasPermission: (p: string) => auth.permissions.includes(p),
    hasRole: () => false,
    isSuperAdmin: () => auth.superAdmin,
    hasModule: (m: string) => auth.superAdmin || auth.modules === null || auth.modules.includes(m),
    isReadOnly: () => false,
    refreshUser: () => Promise.resolve(),
  }),
}));

const tenant = { selectedAccountId: null as number | null };
vi.mock('../../core/context/TenantContext', () => ({
  useTenant: () => ({
    selectedAccountId: tenant.selectedAccountId,
    selectedAccount: null,
    accounts: [],
    isLoadingAccounts: false,
    canSwitchClients: auth.superAdmin,
    selectAccount: vi.fn(),
  }),
}));

const organic = organicPostService as unknown as Record<'list' | 'insightsSummary', Mock>;

function Where() {
  return <div data-testid="where">{useLocation().pathname}</div>;
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

function renderGuarded(url: string) {
  return render(
    <MemoryRouter initialEntries={[url]}>
      <Routes>
        <Route path="/social/analytics" element={<ProtectedRoute permission="view-social-analytics" module="social_accounts" capability="social"><div>analytics page</div></ProtectedRoute>} />
        <Route path="/social/accounts" element={<ProtectedRoute permission="manage-social-accounts" module="social_accounts"><div>accounts page</div></ProtectedRoute>} />
        <Route path="/unauthorized" element={<UnauthorizedPage />} />
      </Routes>
      <Where />
    </MemoryRouter>,
  );
}

const post = (id: number, caption: string): OrganicPost => ({
  id, social_account_id: 1, provider: 'meta', platform: 'facebook', caption, media_url: null, media_type: null,
  status: 'published', external_post_id: `PG_${id}`, error_message: null, published_at: null, created_at: null,
  origin: 'manual', scheduled_at: null, failure_code: null, attempts: 1, next_attempt_at: null, cancelled_at: null,
  can_cancel: false, can_retry: false,
});

beforeEach(() => {
  vi.clearAllMocks();
  auth.superAdmin = false;
  auth.permissions = [];
  auth.modules = null;
  auth.capabilities = { social: true };
  tenant.selectedAccountId = null;
  organic.list.mockResolvedValue([]);
  organic.insightsSummary.mockRejectedValue(new Error('not needed'));
});

describe('Social navigation and routes', () => {
  it('a view-only analytics user sees and opens Social Analytics but no management entry', () => {
    auth.permissions = ['view-social-analytics'];
    renderNav();

    expect(screen.getByRole('link', { name: 'Social Analytics' })).toBeTruthy();
    expect(screen.queryByRole('link', { name: 'Social Accounts' })).toBeNull();
    expect(screen.queryByRole('link', { name: 'Meta Ads Launcher' })).toBeNull();
  });

  it('the analytics route admits view-only users and refuses the accounts route to them', () => {
    auth.permissions = ['view-social-analytics'];
    const { unmount } = renderGuarded('/social/analytics');
    expect(screen.getByText('analytics page')).toBeTruthy();
    unmount();

    renderGuarded('/social/accounts');
    expect(screen.getByTestId('where').textContent).toBe('/unauthorized');
  });

  it('Social Analytics is hidden and refused without view-social-analytics', () => {
    auth.permissions = ['manage-social-accounts'];
    renderNav();
    expect(screen.queryByRole('link', { name: 'Social Analytics' })).toBeNull();
    expect(screen.getByRole('link', { name: 'Social Accounts' })).toBeTruthy();

    renderGuarded('/social/analytics');
    expect(screen.getByTestId('where').textContent).toBe('/unauthorized');
  });

  it('Social Analytics is hidden without the social capability or the social_accounts module', () => {
    auth.permissions = ['view-social-analytics'];
    auth.capabilities = { social: false };
    const { unmount } = renderNav();
    expect(screen.queryByRole('link', { name: 'Social Analytics' })).toBeNull();
    unmount();

    auth.capabilities = { social: true };
    auth.modules = ['campaigns'];
    renderNav();
    expect(screen.queryByRole('link', { name: 'Social Analytics' })).toBeNull();
  });
});

describe('Meta Ads page — Organic Posts panel', () => {
  function renderAds() {
    return render(
      <MemoryRouter initialEntries={['/social/ads']}>
        <Routes>
          <Route path="/social/ads" element={<MetaAdsPage />} />
        </Routes>
      </MemoryRouter>,
    );
  }

  it('is not shown (and not loaded) without the social capability', async () => {
    auth.permissions = ['launch-meta-ads', 'manage-social-accounts'];
    auth.capabilities = { social: false };
    renderAds();

    await screen.findByTestId('open-organic-post');
    expect(screen.queryByTestId('organic-posts-panel')).toBeNull();
    expect(organic.list).not.toHaveBeenCalled();
  });

  it('switching client rebuilds the panel: the previous client\'s posts never stay on screen', async () => {
    auth.superAdmin = true;
    tenant.selectedAccountId = 5;
    organic.list.mockResolvedValueOnce([post(1, 'Client five post')]);
    const view = renderAds();
    expect(await screen.findByText('Client five post')).toBeTruthy();

    let resolveSix: (posts: OrganicPost[]) => void = () => {};
    organic.list.mockReturnValueOnce(new Promise<OrganicPost[]>((r) => { resolveSix = r; }));
    tenant.selectedAccountId = 6;
    view.rerender(
      <MemoryRouter initialEntries={['/social/ads']}>
        <Routes>
          <Route path="/social/ads" element={<MetaAdsPage />} />
        </Routes>
      </MemoryRouter>,
    );

    // While client six is loading, client five's post is already gone.
    await waitFor(() => expect(screen.queryByText('Client five post')).toBeNull());
    resolveSix([post(2, 'Client six post')]);
    const panel = await screen.findByTestId('organic-posts-panel');
    expect(await within(panel).findByText('Client six post')).toBeTruthy();
    expect(organic.list).toHaveBeenCalledTimes(2);
  });
});
