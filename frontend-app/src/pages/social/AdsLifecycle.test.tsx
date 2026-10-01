import { render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { MemoryRouter, Route, Routes, useLocation } from 'react-router-dom';
import { beforeEach, describe, expect, it, vi, type Mock } from 'vitest';

import AppLayout from '../../components/layout/AppLayout';
import { ProtectedRoute } from '../../core/guards/ProtectedRoute';
import UnauthorizedPage from '../errors/UnauthorizedPage';
import MetaAdsPage from './MetaAdsPage';
import socialService from '../../services/socialService';
import adsService from '../../services/adsService';
import type { AdCampaign } from '../../types/ads';

/**
 * Phase 10 Task 2 — Ads entitlement & campaign lifecycle in the UI: the
 * launcher (nav + route) needs the `ads` capability like the backend; a launch
 * keeps its Idempotency-Key until a definitive answer; an unconfirmed outcome
 * is explained instead of "try again"; only ACTIVE/PAUSED campaigns can be
 * paused/resumed; a campaign-state error updates the row.
 */

const keys = vi.hoisted(() => ({ n: 0 }));

vi.mock('../../components/layout/Header', () => ({ default: () => null }));
vi.mock('../../services/socialService', () => ({
  default: { listAccounts: vi.fn(), listProviders: vi.fn(), checkConnection: vi.fn(), getOAuthRedirectUrl: vi.fn(), bindAccounts: vi.fn(), disconnect: vi.fn() },
}));
vi.mock('../../services/adsService', () => ({
  default: { list: vi.fn(), launch: vi.fn(), pause: vi.fn(), resume: vi.fn() },
  newLaunchKey: () => `adlaunch-${++keys.n}`,
}));
vi.mock('../../services/aiService', () => ({ default: { generateAdCopy: vi.fn() } }));
vi.mock('../../services/mediaService', () => ({ default: { upload: vi.fn() } }));
vi.mock('../../services/organicPostService', () => ({ default: { list: () => Promise.resolve([]), insightsSummary: () => Promise.reject(new Error('not mocked')), publish: vi.fn(), cancel: vi.fn(), retry: vi.fn() } }));

const auth = { permissions: ['launch-meta-ads'] as string[], capabilities: { ads: true } as Record<string, boolean> };
vi.mock('../../core/context/AuthContext', () => ({
  useAuth: () => ({
    isLoading: false,
    isAuthenticated: true,
    user: { id: 1, account_id: 7, account: { id: 7, account_type: 'client' }, permissions: auth.permissions, capabilities: auth.capabilities, roles: [] },
    hasPermission: (p: string) => auth.permissions.includes(p),
    hasRole: () => false,
    isSuperAdmin: () => false,
    hasModule: () => true,
    isReadOnly: () => false,
    refreshUser: () => Promise.resolve(),
  }),
}));

vi.mock('../../core/context/TenantContext', () => ({
  useTenant: () => ({ selectedAccountId: null, selectedAccount: null, accounts: [], isLoadingAccounts: false, canSwitchClients: false, selectAccount: vi.fn() }),
}));

const social = socialService as unknown as Record<string, Mock>;
const ads = adsService as unknown as Record<string, Mock>;

const adAccount = { id: 1, asset_type: 'meta_ad_account', provider_id: 'act_1', name: 'Acme Ads', avatar_url: null, health_status: 'connected', connection_status: 'connected' };
const page = { id: 2, asset_type: 'facebook_page', provider_id: 'p_1', name: 'Acme Page', avatar_url: null, health_status: 'connected', connection_status: 'connected' };

const campaign = (id: number, name: string, status: AdCampaign['status'], extra: Partial<AdCampaign> = {}): AdCampaign => ({
  id, meta_campaign_id: status === 'FAILED' ? null : `CMP-${id}`, name, objective: 'TRAFFIC', status, daily_budget: 10, cpl_threshold: null,
  spend: 0, impressions: 0, leads: 0, cpl: null, last_checked_at: null, auto_paused_at: null, auto_pause_reason: null, created_at: null, ...extra,
});

function renderPage() {
  return render(
    <MemoryRouter initialEntries={['/social/ads']}>
      <Routes>
        <Route path="/social/ads" element={<MetaAdsPage />} />
      </Routes>
    </MemoryRouter>,
  );
}

async function fillAndLaunch(user: ReturnType<typeof userEvent.setup>) {
  renderPage();
  await user.click(screen.getByTestId('open-paid-campaign'));
  expect(await screen.findByText('Objective & Budget')).toBeTruthy();
  await user.type(screen.getByPlaceholderText('Spring Lead Gen Push'), 'Monsoon Offer');
  await user.type(screen.getByLabelText(/Daily Budget/), '40');
  await user.click(screen.getByRole('button', { name: 'Next' }));
  await user.click(screen.getByRole('button', { name: 'Next' }));
  await user.type(screen.getByPlaceholderText('Get a Free Quote Today'), 'Book a visit');
  await user.type(screen.getByPlaceholderText('Tell people why they should tap your ad…'), 'Limited slots this week');
  await user.click(screen.getByRole('button', { name: /launch campaign/i }));
}

function Where() {
  return <div data-testid="where">{useLocation().pathname}</div>;
}

beforeEach(() => {
  vi.clearAllMocks();
  keys.n = 0;
  auth.permissions = ['launch-meta-ads'];
  auth.capabilities = { ads: true };
  ads.list.mockResolvedValue([]);
  social.listAccounts.mockResolvedValue([adAccount, page]);
});

describe('Ads entitlement gating', () => {
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

  it('shows the launcher only with the ads capability', () => {
    const { unmount } = renderNav();
    expect(screen.getByRole('link', { name: 'Meta Ads Launcher' })).toBeTruthy();
    unmount();

    auth.capabilities = { ads: false, social: true };
    renderNav();
    expect(screen.queryByRole('link', { name: 'Meta Ads Launcher' })).toBeNull();
  });

  it('refuses the launcher route without the ads capability (the social capability is not enough)', () => {
    auth.capabilities = { ads: false, social: true };
    render(
      <MemoryRouter initialEntries={['/social/ads']}>
        <Routes>
          <Route path="/social/ads" element={<ProtectedRoute permission="launch-meta-ads" module="meta_ads" capability="ads"><div>ads page</div></ProtectedRoute>} />
          <Route path="/unauthorized" element={<UnauthorizedPage />} />
        </Routes>
        <Where />
      </MemoryRouter>,
    );

    expect(screen.getByTestId('where').textContent).toBe('/unauthorized');
    expect(screen.queryByText('ads page')).toBeNull();
  });
});

describe('Launch idempotency', () => {
  it('reuses the key after a lost response and starts a fresh one after a definitive refusal', async () => {
    ads.launch
      .mockRejectedValueOnce(new Error('Network Error'))
      .mockRejectedValueOnce({ response: { status: 422, data: { message: 'Invalid targeting spec.' } } })
      .mockResolvedValueOnce({ message: 'Campaign launched.', data: campaign(9, 'Monsoon Offer', 'ACTIVE') });
    const user = userEvent.setup();

    await fillAndLaunch(user);
    await waitFor(() => expect(ads.launch).toHaveBeenCalledTimes(1));
    await user.click(screen.getByRole('button', { name: /launch campaign/i }));
    expect(await screen.findByText('Invalid targeting spec.')).toBeTruthy();
    await user.click(screen.getByRole('button', { name: /launch campaign/i }));
    await waitFor(() => expect(ads.launch).toHaveBeenCalledTimes(3));

    expect(ads.launch.mock.calls[0][1]).toBe('adlaunch-1');
    expect(ads.launch.mock.calls[1][1]).toBe('adlaunch-1');
    expect(ads.launch.mock.calls[2][1]).toBe('adlaunch-2');
  });

  it('explains an unconfirmed launch instead of inviting a blind retry, and keeps the key', async () => {
    ads.launch.mockRejectedValue({ response: { status: 502, data: { success: false, error_code: 'AD_PROVIDER_OUTCOME_UNKNOWN', message: 'x' } } });
    const user = userEvent.setup();

    await fillAndLaunch(user);

    expect(await screen.findByText(/Meta did not confirm the launch\. It was not resent automatically/)).toBeTruthy();
    expect(screen.queryByText(/Something went wrong/)).toBeNull();
    await user.click(screen.getByRole('button', { name: /launch campaign/i }));
    await waitFor(() => expect(ads.launch).toHaveBeenCalledTimes(2));
    expect(ads.launch.mock.calls[1][1]).toBe(ads.launch.mock.calls[0][1]);
  });
});

describe('Campaign lifecycle actions', () => {
  it('offers pause/resume only for active or paused campaigns and shows the safe provider error', async () => {
    ads.list.mockResolvedValue([
      campaign(1, 'Running', 'ACTIVE'),
      campaign(2, 'Stopped', 'PAUSED'),
      campaign(3, 'Rejected', 'FAILED', { last_provider_error: 'Meta rejected the launch (HTTP 400, code 100).' }),
      campaign(4, 'Lost', 'UNCONFIRMED'),
      campaign(5, 'Gone', 'UNAVAILABLE'),
      campaign(6, 'Starting', 'LAUNCHING'),
    ]);
    renderPage();

    expect(await screen.findByText('Running')).toBeTruthy();
    expect(screen.getAllByRole('button', { name: /^Pause$/ })).toHaveLength(1);
    expect(screen.getAllByRole('button', { name: /^Resume$/ })).toHaveLength(1);
    expect(screen.getByText('Meta rejected the launch (HTTP 400, code 100).')).toBeTruthy();
  });

  it('a campaign-state error replaces the row, so a campaign gone at Meta loses its action', async () => {
    ads.list.mockResolvedValue([campaign(1, 'Running', 'ACTIVE')]);
    ads.pause.mockRejectedValue({
      response: { status: 409, data: { success: false, error_code: 'AD_CAMPAIGN_UNAVAILABLE', message: 'Meta reports this campaign no longer exists.', data: campaign(1, 'Running', 'UNAVAILABLE') } },
    });
    const user = userEvent.setup();
    renderPage();

    await user.click(await screen.findByRole('button', { name: /^Pause$/ }));

    expect(await screen.findByText('Meta reports this campaign no longer exists.')).toBeTruthy();
    expect(screen.getByText('UNAVAILABLE')).toBeTruthy();
    expect(screen.queryByRole('button', { name: /^Pause$/ })).toBeNull();
  });

  it('an unconfirmed pause is explained and the row stays as it was', async () => {
    ads.list.mockResolvedValue([campaign(1, 'Running', 'ACTIVE')]);
    ads.pause.mockRejectedValue({ response: { status: 502, data: { success: false, error_code: 'AD_PROVIDER_OUTCOME_UNKNOWN', message: 'x', data: campaign(1, 'Running', 'ACTIVE') } } });
    const user = userEvent.setup();
    renderPage();

    await user.click(await screen.findByRole('button', { name: /^Pause$/ }));

    expect(await screen.findByText(/Meta did not confirm the change/)).toBeTruthy();
    expect(screen.getByRole('button', { name: /^Pause$/ })).toBeTruthy();
  });
});
