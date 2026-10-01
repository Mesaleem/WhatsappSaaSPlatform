import { render, screen } from '@testing-library/react';
import { MemoryRouter, Route, Routes } from 'react-router-dom';
import { beforeEach, describe, expect, it, vi, type Mock } from 'vitest';

import AdsDashboardPage from './AdsDashboardPage';
import MetaAdsPage from './MetaAdsPage';
import adsDashboardService from '../../services/adsDashboardService';
import adsService from '../../services/adsService';
import type { AdCampaign } from '../../types/ads';
import type { AdsDashboard } from '../../types/adsDashboard';

/**
 * Phase 10 Task 6 — frontend authorization consistency and error states:
 * a suspended account / lapsed subscription keeps read access but is offered
 * no Ads write control (the backend rejects them all); every definitive API
 * error is shown and none leaves the previous target account's data behind.
 */

vi.mock('../../components/layout/Header', () => ({ default: () => null }));
vi.mock('../../services/adsDashboardService', () => ({ default: { dashboard: vi.fn() } }));
vi.mock('../../services/adsConversionValueService', () => ({ default: { listConverted: vi.fn(() => Promise.resolve([])), setValue: vi.fn() } }));
vi.mock('../../services/socialService', () => ({
  default: { listAccounts: () => Promise.resolve([]), listProviders: vi.fn(), checkConnection: vi.fn(), getOAuthRedirectUrl: vi.fn(), bindAccounts: vi.fn(), disconnect: vi.fn() },
}));
vi.mock('../../services/adsService', () => ({ default: { list: vi.fn(), launch: vi.fn(), pause: vi.fn(), resume: vi.fn() }, newLaunchKey: () => 'adlaunch-test' }));
vi.mock('../../services/aiService', () => ({ default: { generateAdCopy: vi.fn() } }));
vi.mock('../../services/mediaService', () => ({ default: { upload: vi.fn() } }));
vi.mock('../../services/organicPostService', () => ({ default: { list: () => Promise.resolve([]), insightsSummary: () => Promise.reject(new Error('not mocked')), publish: vi.fn(), cancel: vi.fn(), retry: vi.fn() } }));

const auth = {
  readOnly: false,
  leadCrmModule: true,
  permissions: ['launch-meta-ads', 'social_ads.view', 'manage-crm'] as string[],
  capabilities: { ads: true, crm: true } as Record<string, boolean>,
};
vi.mock('../../core/context/AuthContext', () => ({
  useAuth: () => ({
    isLoading: false,
    isAuthenticated: true,
    user: { id: 1, account_id: 7, account: { id: 7, account_type: 'client' }, permissions: auth.permissions, capabilities: auth.capabilities, roles: [] },
    hasPermission: (p: string) => auth.permissions.includes(p),
    hasRole: () => false,
    isSuperAdmin: () => false,
    hasModule: (m: string) => (m === 'lead_crm' ? auth.leadCrmModule : true),
    isReadOnly: () => auth.readOnly,
    refreshUser: () => Promise.resolve(),
  }),
}));
vi.mock('../../core/context/TenantContext', () => ({
  useTenant: () => ({ selectedAccountId: null, selectedAccount: null, accounts: [], isLoadingAccounts: false, canSwitchClients: false, selectAccount: vi.fn() }),
}));

const dash = adsDashboardService as unknown as Record<'dashboard', Mock>;
const ads = adsService as unknown as Record<string, Mock>;

const campaign = (id: number, name: string, status: AdCampaign['status']): AdCampaign => ({
  id, meta_campaign_id: `C${id}`, name, objective: 'TRAFFIC', status, daily_budget: 10, cpl_threshold: 5,
  spend: 0, impressions: 0, leads: 0, cpl: null, last_checked_at: null, auto_paused_at: null, auto_pause_reason: null, created_at: null,
});

const emptyDashboard = (): AdsDashboard => ({
  range: { from: '2026-09-01', to: '2026-09-30', days: 30 },
  campaigns_summary: { total: 0, active: 0, paused: 0, launching: 0, failed: 0, unconfirmed: 0, unavailable: 0 },
  spend: { total: { value: null, status: 'not_applicable' }, impressions: { value: null, status: 'not_applicable' }, campaigns_with_spend: 0, daily: [] },
  attribution: {
    ads: 0, referrals: 0, conversations: 0, leads: 0, journeys: 0, conversions: 0,
    conversion_rate: { value: null, status: 'not_applicable' }, conversion_value: { value: null, status: 'unavailable' },
    unlinked_referrals: 0, cost_per_lead: { value: null, status: 'not_fetched' }, roas: { value: null, status: 'unavailable' },
  },
  funnel: [], campaigns: [], campaigns_truncated: false, notes: { source: 'Figures come from stored data only.' },
});

const renderAds = () => render(<MemoryRouter initialEntries={['/social/ads']}><Routes><Route path="/social/ads" element={<MetaAdsPage />} /></Routes></MemoryRouter>);
const renderDashboard = () => render(<MemoryRouter initialEntries={['/d']}><Routes><Route path="/d" element={<AdsDashboardPage />} /></Routes></MemoryRouter>);

beforeEach(() => {
  vi.clearAllMocks();
  auth.readOnly = false;
  auth.leadCrmModule = true;
  auth.permissions = ['launch-meta-ads', 'social_ads.view', 'manage-crm'];
  ads.list.mockResolvedValue([campaign(1, 'Running', 'ACTIVE'), campaign(2, 'Stopped', 'PAUSED')]);
});

describe('Ads controls on a suspended account or lapsed subscription', () => {
  it('offers launch, pause/resume and budget editing when the account is writable', async () => {
    renderAds();

    expect(await screen.findByText('Running')).toBeTruthy();
    expect(screen.getByTestId('open-paid-campaign')).toBeTruthy();
    expect(screen.getByRole('button', { name: /^Pause$/ })).toBeTruthy();
    expect(screen.getByRole('button', { name: /^Resume$/ })).toBeTruthy();
  });

  it('keeps the campaign list readable but offers no launch, pause, resume or budget control', async () => {
    auth.readOnly = true;
    renderAds();

    expect(await screen.findByText('Running')).toBeTruthy();
    expect(screen.getByText('Stopped')).toBeTruthy();
    expect(screen.queryByTestId('open-paid-campaign')).toBeNull();
    expect(screen.queryByRole('button', { name: /^Pause$/ })).toBeNull();
    expect(screen.queryByRole('button', { name: /^Resume$/ })).toBeNull();
    expect(screen.queryByPlaceholderText('none')).toBeNull();
    expect(screen.getByTestId('open-ads-dashboard')).toBeTruthy();
  });

  it('keeps the dashboard readable but does not mount the conversion-value editor', async () => {
    dash.dashboard.mockResolvedValue(emptyDashboard());
    auth.readOnly = true;
    renderDashboard();

    expect(await screen.findByText(/Figures come from stored data only/)).toBeTruthy();
    expect(screen.queryByTestId('ads-conversion-values')).toBeNull();
  });
});

describe('Conversion-value editor follows the backend route gates', () => {
  it('is offered with manage-crm + lead_crm + crm capability and hidden when any is missing', async () => {
    dash.dashboard.mockResolvedValue(emptyDashboard());
    const { unmount } = renderDashboard();
    expect(await screen.findByTestId('ads-conversion-values')).toBeTruthy();
    unmount();

    auth.leadCrmModule = false; // backend: EnsureModuleEnabled:lead_crm → 403
    const second = renderDashboard();
    expect(await screen.findByText(/Figures come from stored data only/)).toBeTruthy();
    expect(screen.queryByTestId('ads-conversion-values')).toBeNull();
    second.unmount();

    auth.leadCrmModule = true;
    auth.capabilities = { ads: true, crm: false };
    renderDashboard();
    expect(await screen.findByText(/Figures come from stored data only/)).toBeTruthy();
    expect(screen.queryByTestId('ads-conversion-values')).toBeNull();
    auth.capabilities = { ads: true, crm: true };
  });
});

describe('Ads dashboard error states', () => {
  it.each([
    [401, 'Your session has expired. Please sign in again.'],
    [403, 'Your current plan does not include this feature.'],
    [404, 'Account not found.'],
    [422, 'Select a client account to view Ads.'],
  ])('shows the server message for HTTP %i and no data', async (status, message) => {
    dash.dashboard.mockRejectedValue({ response: { status, data: { success: false, message } } });
    renderDashboard();

    expect(await screen.findByRole('alert')).toHaveTextContent(message);
    expect(screen.queryByTestId('ads-conversion-values')).toBeNull();
  });
});
