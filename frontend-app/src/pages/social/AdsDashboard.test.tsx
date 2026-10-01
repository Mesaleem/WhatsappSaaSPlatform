import { render, screen, waitFor, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { MemoryRouter, Route, Routes, useLocation } from 'react-router-dom';
import { beforeEach, describe, expect, it, vi, type Mock } from 'vitest';

import AppLayout from '../../components/layout/AppLayout';
import { ProtectedRoute } from '../../core/guards/ProtectedRoute';
import UnauthorizedPage from '../errors/UnauthorizedPage';
import AdsDashboardPage from './AdsDashboardPage';
import MetaAdsPage from './MetaAdsPage';
import adsDashboardService from '../../services/adsDashboardService';
import adsService from '../../services/adsService';
import type { AdsCampaignReportRow, AdsDashboard } from '../../types/adsDashboard';
import type { AdCampaign } from '../../types/ads';

/**
 * Phase 10 Task 3 — Ads Dashboard UI and the read-only Ads page: a
 * social_ads.view user can open /social/ads (no launch / pause / budget
 * actions) and the dashboard; both need the ads capability; client switches
 * never show the previous client's data; unknown values are labelled, never 0.
 */

vi.mock('../../components/layout/Header', () => ({ default: () => null }));
vi.mock('../../services/adsDashboardService', () => ({ default: { dashboard: vi.fn() } }));
vi.mock('../../services/socialService', () => ({
  default: { listAccounts: () => Promise.resolve([]), listProviders: vi.fn(), checkConnection: vi.fn(), getOAuthRedirectUrl: vi.fn(), bindAccounts: vi.fn(), disconnect: vi.fn() },
}));
vi.mock('../../services/adsService', () => ({ default: { list: vi.fn(), launch: vi.fn(), pause: vi.fn(), resume: vi.fn() }, newLaunchKey: () => 'adlaunch-test' }));
vi.mock('../../services/aiService', () => ({ default: { generateAdCopy: vi.fn() } }));
vi.mock('../../services/mediaService', () => ({ default: { upload: vi.fn() } }));
vi.mock('../../services/organicPostService', () => ({ default: { list: () => Promise.resolve([]), insightsSummary: () => Promise.reject(new Error('not mocked')), publish: vi.fn(), cancel: vi.fn(), retry: vi.fn() } }));

const auth = { superAdmin: false, permissions: ['social_ads.view'] as string[], capabilities: { ads: true } as Record<string, boolean> };
vi.mock('../../core/context/AuthContext', () => ({
  useAuth: () => ({
    isLoading: false,
    isAuthenticated: true,
    user: { id: 1, account_id: 7, account: { id: 7, account_type: 'client' }, permissions: auth.permissions, capabilities: auth.capabilities, roles: [] },
    hasPermission: (p: string) => auth.permissions.includes(p),
    hasRole: () => false,
    isSuperAdmin: () => auth.superAdmin,
    hasModule: () => true,
    isReadOnly: () => false,
    refreshUser: () => Promise.resolve(),
  }),
}));

const tenant = { selectedAccountId: null as number | null };
vi.mock('../../core/context/TenantContext', () => ({
  useTenant: () => ({ selectedAccountId: tenant.selectedAccountId, selectedAccount: null, accounts: [], isLoadingAccounts: false, canSwitchClients: auth.superAdmin, selectAccount: vi.fn() }),
}));

const dash = adsDashboardService as unknown as Record<'dashboard', Mock>;
const ads = adsService as unknown as Record<string, Mock>;

const row = (id: number, name: string, extra: Partial<AdsCampaignReportRow> = {}): AdsCampaignReportRow => ({
  id, name, objective: 'CLICK_TO_WHATSAPP', status: 'ACTIVE', spend: 40, spend_status: 'available', impressions: 1000,
  referrals: 4, conversations: 3, leads: 2, journeys: 1, conversions: 1, conversion_rate: 0.25, cost_per_lead: 20,
  conversion_value: null, roas: null, last_checked_at: null, ...extra,
});

function dashboard(overrides: Partial<AdsDashboard> = {}): AdsDashboard {
  return {
    range: { from: '2026-09-01', to: '2026-09-30', days: 30 },
    campaigns_summary: { total: 2, active: 1, paused: 1, launching: 0, failed: 0, unconfirmed: 0, unavailable: 0 },
    spend: {
      total: { value: 45, status: 'available' },
      impressions: { value: 1700, status: 'available' },
      campaigns_with_spend: 2,
      daily: [{ date: '2026-09-29', spend: 10 }, { date: '2026-09-30', spend: 35 }],
    },
    attribution: {
      ads: 2, referrals: 5, conversations: 4, leads: 3, journeys: 1, conversions: 1,
      conversion_rate: { value: 0.2, status: 'available' },
      conversion_value: { value: null, status: 'unavailable' },
      unlinked_referrals: 1,
      cost_per_lead: { value: 20, status: 'available' },
      roas: { value: null, status: 'unavailable' },
    },
    funnel: [
      { stage: 'ads', label: 'Ads', count: 2 },
      { stage: 'referrals', label: 'Referrals', count: 5 },
      { stage: 'conversations', label: 'Conversations', count: 4 },
      { stage: 'leads', label: 'CRM leads', count: 3 },
      { stage: 'journeys', label: 'Journey starts', count: 1 },
      { stage: 'conversions', label: 'Conversions', count: 1 },
    ],
    campaigns: [row(1, 'Alpha'), row(2, 'Beta', { status: 'PAUSED', spend: 5, referrals: 0, conversations: 0, leads: 0, journeys: 0, conversions: 0, conversion_rate: null, cost_per_lead: null })],
    campaigns_truncated: false,
    notes: { source: 'Figures come from stored data only.' },
    ...overrides,
  };
}

const emptyDashboard = (): AdsDashboard =>
  dashboard({
    campaigns_summary: { total: 0, active: 0, paused: 0, launching: 0, failed: 0, unconfirmed: 0, unavailable: 0 },
    spend: { total: { value: null, status: 'not_applicable' }, impressions: { value: null, status: 'not_applicable' }, campaigns_with_spend: 0, daily: [{ date: '2026-09-30', spend: null }] },
    attribution: {
      ads: 0, referrals: 0, conversations: 0, leads: 0, journeys: 0, conversions: 0,
      conversion_rate: { value: null, status: 'not_applicable' }, conversion_value: { value: null, status: 'unavailable' },
      unlinked_referrals: 0, cost_per_lead: { value: null, status: 'not_fetched' }, roas: { value: null, status: 'unavailable' },
    },
    funnel: [],
    campaigns: [],
  });

function renderDashboard() {
  return render(
    <MemoryRouter initialEntries={['/social/ads/dashboard']}>
      <Routes>
        <Route path="/social/ads/dashboard" element={<AdsDashboardPage />} />
      </Routes>
    </MemoryRouter>,
  );
}

function Where() {
  return <div data-testid="where">{useLocation().pathname}</div>;
}

function renderGuarded(url: string) {
  const guard = (el: React.ReactNode) => (
    <ProtectedRoute anyPermission={['launch-meta-ads', 'social_ads.view']} module="meta_ads" capability="ads">
      {el}
    </ProtectedRoute>
  );
  return render(
    <MemoryRouter initialEntries={[url]}>
      <Routes>
        <Route path="/social/ads" element={guard(<div>ads page</div>)} />
        <Route path="/social/ads/dashboard" element={guard(<div>dashboard page</div>)} />
        <Route path="/unauthorized" element={<UnauthorizedPage />} />
      </Routes>
      <Where />
    </MemoryRouter>,
  );
}

beforeEach(() => {
  vi.clearAllMocks();
  auth.superAdmin = false;
  auth.permissions = ['social_ads.view'];
  auth.capabilities = { ads: true };
  tenant.selectedAccountId = null;
  dash.dashboard.mockResolvedValue(dashboard());
  ads.list.mockResolvedValue([]);
});

describe('Ads access for view-only users', () => {
  it('a social_ads.view user can open the Ads page and the dashboard, and sees both in the nav', () => {
    const { unmount } = renderGuarded('/social/ads');
    expect(screen.getByText('ads page')).toBeTruthy();
    unmount();

    const second = renderGuarded('/social/ads/dashboard');
    expect(screen.getByText('dashboard page')).toBeTruthy();
    second.unmount();

    render(
      <MemoryRouter initialEntries={['/']}>
        <Routes>
          <Route element={<AppLayout />}>
            <Route path="/" element={<div>home</div>} />
          </Route>
        </Routes>
      </MemoryRouter>,
    );
    expect(screen.getByRole('link', { name: 'Meta Ads Launcher' })).toBeTruthy();
    expect(screen.getByRole('link', { name: 'Ads Dashboard' })).toBeTruthy();
  });

  it('both routes are refused without the ads capability or without an Ads permission', () => {
    auth.capabilities = { ads: false, social: true };
    const { unmount } = renderGuarded('/social/ads/dashboard');
    expect(screen.getByTestId('where').textContent).toBe('/unauthorized');
    unmount();

    auth.capabilities = { ads: true };
    auth.permissions = ['view-social-analytics'];
    renderGuarded('/social/ads');
    expect(screen.getByTestId('where').textContent).toBe('/unauthorized');
  });

  it('the Ads page is read-only for a view-only user: no launch, pause or budget editing', async () => {
    const campaign: AdCampaign = {
      id: 1, meta_campaign_id: 'C1', name: 'Running', objective: 'TRAFFIC', status: 'ACTIVE', daily_budget: 10, cpl_threshold: 5,
      spend: 0, impressions: 0, leads: 0, cpl: null, last_checked_at: null, auto_paused_at: null, auto_pause_reason: null, created_at: null,
    };
    ads.list.mockResolvedValue([campaign]);
    render(
      <MemoryRouter initialEntries={['/social/ads']}>
        <Routes>
          <Route path="/social/ads" element={<MetaAdsPage />} />
        </Routes>
      </MemoryRouter>,
    );

    expect(await screen.findByText('Running')).toBeTruthy();
    expect(screen.queryByTestId('open-paid-campaign')).toBeNull();
    expect(screen.queryByTestId('open-organic-post')).toBeNull();
    expect(screen.queryByRole('button', { name: /^Pause$/ })).toBeNull();
    expect(screen.queryByPlaceholderText('none')).toBeNull();
    expect(screen.getByText('₹5.00')).toBeTruthy();
    expect(screen.getByTestId('open-ads-dashboard')).toBeTruthy();
  });
});

describe('Ads Dashboard', () => {
  it('renders summary cards, spend trend, funnel and campaign table from the response', async () => {
    renderDashboard();

    expect(await screen.findByTestId('ads-summary')).toBeTruthy();
    expect(within(screen.getByTestId('card-spend')).getByText('₹45.00')).toBeTruthy();
    expect(within(screen.getByTestId('card-campaigns')).getByText('1 active · 1 paused')).toBeTruthy();
    expect(within(screen.getByTestId('card-conversions')).getByText('Rate: 20.0%')).toBeTruthy();
    expect(within(screen.getByTestId('card-cpl')).getByText('₹20.00')).toBeTruthy();
    expect(screen.getByTestId('ads-spend-trend')).toBeTruthy();
    expect(screen.queryByTestId('ads-spend-empty')).toBeNull();

    const funnel = screen.getByTestId('ads-funnel');
    expect(within(funnel).getAllByRole('listitem').map((li) => li.textContent)).toEqual(['Ads2', 'Referrals5', 'Conversations4', 'CRM leads3', 'Journey starts1', 'Conversions1']);
    expect(screen.getByTestId('ads-unlinked').textContent).toContain('1 referral(s)');

    const table = screen.getByTestId('ads-campaign-table');
    const alpha = within(table).getByText('Alpha').closest('tr') as HTMLElement;
    expect(within(alpha).getByText('₹40.00')).toBeTruthy();
    expect(within(alpha).getByText('25.0%')).toBeTruthy();
    const beta = within(table).getByText('Beta').closest('tr') as HTMLElement;
    expect(within(beta).getByText('Paused')).toBeTruthy();
    expect(within(beta).getAllByText('—').length).toBeGreaterThanOrEqual(3);
  });

  it('labels unknown values instead of showing 0', async () => {
    dash.dashboard.mockResolvedValue(
      dashboard({
        spend: { total: { value: null, status: 'not_fetched' }, impressions: { value: null, status: 'not_fetched' }, campaigns_with_spend: 0, daily: [{ date: '2026-09-30', spend: null }] },
        campaigns: [
          row(1, 'No insights', { spend: null, spend_status: 'not_fetched', cost_per_lead: null }),
          row(2, 'Failed launch', { status: 'FAILED', spend: null, spend_status: 'not_applicable', referrals: 0, cost_per_lead: null, conversion_rate: null }),
          row(3, 'Gone', { status: 'UNAVAILABLE', spend: null, spend_status: 'unavailable', cost_per_lead: null }),
        ],
      }),
    );
    renderDashboard();

    expect(await screen.findByTestId('ads-summary')).toBeTruthy();
    expect(within(screen.getByTestId('card-spend')).getByText('Not fetched yet')).toBeTruthy();
    expect(within(screen.getByTestId('card-value')).getByText('Not available')).toBeTruthy();
    expect(within(screen.getByTestId('card-roas')).getByText('Not available')).toBeTruthy();
    expect(screen.getByTestId('ads-spend-empty').textContent).toContain('No spend stored for this period yet');
    const table = screen.getByTestId('ads-campaign-table');
    expect(within(within(table).getByText('No insights').closest('tr') as HTMLElement).getByText('Not fetched yet')).toBeTruthy();
    expect(within(within(table).getByText('Failed launch').closest('tr') as HTMLElement).getByText('Not applicable')).toBeTruthy();
    expect(within(within(table).getByText('Gone').closest('tr') as HTMLElement).getByText('Not available')).toBeTruthy();
    expect(within(screen.getByTestId('card-spend')).queryByText('$0.00')).toBeNull();
  });

  it('shows empty states when there are no campaigns and no referrals', async () => {
    dash.dashboard.mockResolvedValue(emptyDashboard());
    renderDashboard();

    expect(await screen.findByTestId('ads-dashboard-empty')).toBeTruthy();
    expect(screen.getByTestId('ads-funnel-empty')).toBeTruthy();
    expect(screen.getByTestId('ads-campaigns-empty')).toBeTruthy();
    expect(screen.getByTestId('ads-spend-empty').textContent).toContain('No campaigns yet');
    expect(within(screen.getByTestId('card-conversions')).getByText('Not applicable')).toBeTruthy();
  });

  it('shows the server error', async () => {
    dash.dashboard.mockRejectedValue({ response: { status: 403, data: { success: false, error_code: 'CAPABILITY_NOT_ENTITLED', message: 'Your current plan does not include this feature.' } } });
    renderDashboard();

    expect(await screen.findByRole('alert')).toHaveTextContent('Your current plan does not include this feature.');
  });

  it('changes the period and validates a custom range before requesting it', async () => {
    const user = userEvent.setup();
    renderDashboard();
    await screen.findByTestId('ads-summary');
    expect(dash.dashboard).toHaveBeenLastCalledWith({ range: '30d' });

    await user.selectOptions(screen.getByLabelText('Period'), '7d');
    await waitFor(() => expect(dash.dashboard).toHaveBeenLastCalledWith({ range: '7d' }));

    const calls = dash.dashboard.mock.calls.length;
    await user.selectOptions(screen.getByLabelText('Period'), 'custom');
    expect(screen.getByText('Choose a start and an end date.')).toBeTruthy();
    expect(dash.dashboard.mock.calls.length).toBe(calls);

    await user.type(screen.getByLabelText('From date'), '2026-09-10');
    await user.type(screen.getByLabelText('To date'), '2026-09-05');
    expect(screen.getByText('The end date must be on or after the start date.')).toBeTruthy();
    expect(dash.dashboard.mock.calls.length).toBe(calls);

    await user.clear(screen.getByLabelText('To date'));
    await user.type(screen.getByLabelText('To date'), '2026-09-20');
    await waitFor(() => expect(dash.dashboard).toHaveBeenLastCalledWith({ range: 'custom', from: '2026-09-10', to: '2026-09-20' }));
  });

  it('a Super Admin must pick a client first; nothing is requested without one', () => {
    auth.superAdmin = true;
    renderDashboard();

    expect(screen.getByText(/Select one to view their Ads dashboard/)).toBeTruthy();
    expect(dash.dashboard).not.toHaveBeenCalled();
  });

  it('switching client clears the old data and ignores a late answer for the previous client', async () => {
    auth.superAdmin = true;
    tenant.selectedAccountId = 5;
    let resolveFive: (d: AdsDashboard) => void = () => {};
    dash.dashboard.mockReturnValueOnce(new Promise<AdsDashboard>((r) => { resolveFive = r; }));
    const view = renderDashboard();
    expect(screen.getByTestId('ads-dashboard-loading')).toBeTruthy();

    tenant.selectedAccountId = 6;
    dash.dashboard.mockResolvedValueOnce(dashboard({ campaigns: [row(9, 'Client six campaign')] }));
    view.rerender(
      <MemoryRouter initialEntries={['/social/ads/dashboard']}>
        <Routes>
          <Route path="/social/ads/dashboard" element={<AdsDashboardPage />} />
        </Routes>
      </MemoryRouter>,
    );
    expect(await screen.findByText('Client six campaign')).toBeTruthy();

    // Client five's answer arrives late: it must not replace client six's data.
    resolveFive(dashboard({ campaigns: [row(8, 'Client five campaign')] }));
    await new Promise((r) => setTimeout(r, 0));
    expect(screen.queryByText('Client five campaign')).toBeNull();
    expect(screen.getByText('Client six campaign')).toBeTruthy();
    expect(dash.dashboard).toHaveBeenCalledTimes(2);
  });
});
