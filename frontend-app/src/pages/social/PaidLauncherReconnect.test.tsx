import { render, screen, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { MemoryRouter, Route, Routes } from 'react-router-dom';
import { beforeEach, describe, expect, it, vi, type Mock } from 'vitest';
import MetaAdsPage from './MetaAdsPage';
import socialService from '../../services/socialService';
import adsService from '../../services/adsService';

/**
 * Phase 9 Task 2.1 — the Paid Meta Campaign launcher when the backend
 * answers 409 SOCIAL_CONNECTION_EXPIRED / SOCIAL_CONNECTION_REVOKED
 * (MetaAdsService found the Ad Account connection dead): the safe reconnect
 * notice replaces the generic error, the Social Accounts link opens in a new
 * tab, the wizard stays open with everything typed, and a reconnect path
 * pointing outside the app is never followed.
 */

vi.mock('../../services/socialService', () => ({
  default: { listAccounts: vi.fn(), listProviders: vi.fn(), checkConnection: vi.fn(), getOAuthRedirectUrl: vi.fn(), bindAccounts: vi.fn(), disconnect: vi.fn() },
}));
vi.mock('../../services/adsService', () => ({ default: { list: vi.fn(), launch: vi.fn(), pause: vi.fn(), resume: vi.fn() }, newLaunchKey: () => 'adlaunch-test' }));
vi.mock('../../services/aiService', () => ({ default: { generateAdCopy: vi.fn() } }));
vi.mock('../../services/mediaService', () => ({ default: { upload: vi.fn() } }));
vi.mock('../../services/organicPostService', () => ({ default: { list: () => Promise.resolve([]), insightsSummary: () => Promise.reject(new Error('not mocked')), publish: vi.fn(), cancel: vi.fn(), retry: vi.fn() } }));

const auth = { permissions: ['manage-social-accounts', 'launch-meta-ads'] as string[] };
vi.mock('../../core/context/AuthContext', () => ({
  useAuth: () => ({
    user: { id: 1, name: 'Admin', account: { id: 7 } },
    isSuperAdmin: () => false,
    hasPermission: (p: string) => auth.permissions.includes(p),
    hasModule: () => true,
  }),
}));

vi.mock('../../core/context/TenantContext', () => ({
  useTenant: () => ({ selectedAccountId: null, selectedAccount: null, accounts: [], isLoadingAccounts: false, canSwitchClients: false, selectAccount: vi.fn() }),
}));

const social = socialService as unknown as Record<string, Mock>;
const ads = adsService as unknown as Record<string, Mock>;

const adAccount = { id: 1, asset_type: 'meta_ad_account', provider_id: 'act_1', name: 'Acme Ads', avatar_url: null, health_status: 'connected', connection_status: 'connected' };
const page = { id: 2, asset_type: 'facebook_page', provider_id: 'p_1', name: 'Acme Page', avatar_url: null, health_status: 'connected', connection_status: 'connected' };

function connection409(code: 'SOCIAL_CONNECTION_EXPIRED' | 'SOCIAL_CONNECTION_REVOKED', reconnectPath = '/social/accounts') {
  const revoked = code === 'SOCIAL_CONNECTION_REVOKED';
  return {
    response: {
      status: 409,
      data: {
        success: false,
        error_code: code,
        message: revoked
          ? 'Access to the connected Meta Ad Account "Acme Ads" was revoked. Reconnect it in Social Accounts, then try again.'
          : 'The connected Meta Ad Account "Acme Ads" has expired. Reconnect it in Social Accounts, then try again.',
        connection: { social_account_id: 1, asset_type: 'meta_ad_account', connection_status: revoked ? 'revoked' : 'expired', reason: null },
        reconnect_path: reconnectPath,
      },
    },
  };
}

async function fillAndLaunch(user: ReturnType<typeof userEvent.setup>) {
  render(
    <MemoryRouter initialEntries={['/social/ads']}>
      <Routes>
        <Route path="/social/ads" element={<MetaAdsPage />} />
      </Routes>
    </MemoryRouter>,
  );

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

beforeEach(() => {
  vi.clearAllMocks();
  auth.permissions = ['manage-social-accounts', 'launch-meta-ads'];
  ads.list.mockResolvedValue([]);
  social.listAccounts.mockResolvedValue([adAccount, page]);
});

describe('Paid Meta Campaign launcher — expired/revoked Ad Account connection', () => {
  it('SOCIAL_CONNECTION_EXPIRED shows the safe reconnect notice and keeps the wizard and its values', async () => {
    ads.launch.mockRejectedValue(connection409('SOCIAL_CONNECTION_EXPIRED'));
    const user = userEvent.setup();

    await fillAndLaunch(user);

    const notice = await screen.findByTestId('social-reconnect-notice');
    expect(notice).toHaveTextContent('Connection expired');
    expect(notice).toHaveTextContent('The connected Meta Ad Account "Acme Ads" has expired. Reconnect it in Social Accounts, then try again.');
    const link = within(notice).getByTestId('social-reconnect-link');
    expect(link.getAttribute('href')).toBe('/social/accounts');
    expect(link.getAttribute('target')).toBe('_blank');
    expect(link.getAttribute('rel')).toContain('noopener');

    // The wizard is still open on the last step with what was typed; no generic error banner.
    expect((screen.getByPlaceholderText('Get a Free Quote Today') as HTMLInputElement).value).toBe('Book a visit');
    expect((screen.getByPlaceholderText('Tell people why they should tap your ad…') as HTMLTextAreaElement).value).toBe('Limited slots this week');
    expect(screen.queryByText(/Failed to launch the campaign/)).toBeNull();

    // Going back shows the earlier steps' values intact too.
    await user.click(screen.getByRole('button', { name: 'Back' }));
    await user.click(screen.getByRole('button', { name: 'Back' }));
    expect((screen.getByPlaceholderText('Spring Lead Gen Push') as HTMLInputElement).value).toBe('Monsoon Offer');
    expect((screen.getByLabelText(/Daily Budget/) as HTMLInputElement).value).toBe('40');

    expect(ads.launch).toHaveBeenCalledTimes(1);
    expect(ads.launch.mock.calls[0][0]).toMatchObject({ campaign_name: 'Monsoon Offer', daily_budget: 40 });
  });

  it('SOCIAL_CONNECTION_REVOKED shows Access revoked and lets the user retry after reconnecting', async () => {
    ads.launch.mockRejectedValueOnce(connection409('SOCIAL_CONNECTION_REVOKED')).mockResolvedValueOnce({ data: {} });
    const user = userEvent.setup();

    await fillAndLaunch(user);

    const notice = await screen.findByTestId('social-reconnect-notice');
    expect(notice).toHaveTextContent('Access revoked');
    expect(notice).toHaveTextContent('was revoked. Reconnect it in Social Accounts');
    expect(within(notice).getByTestId('social-reconnect-link').getAttribute('href')).toBe('/social/accounts');

    // After reconnecting in the other tab, the same form is submitted again.
    await user.click(screen.getByRole('button', { name: /launch campaign/i }));
    expect(ads.launch).toHaveBeenCalledTimes(2);
    expect(ads.launch.mock.calls[1][0]).toMatchObject({ campaign_name: 'Monsoon Offer' });
  });

  it('an external reconnect path from the server is never used', async () => {
    ads.launch.mockRejectedValue(connection409('SOCIAL_CONNECTION_EXPIRED', 'https://evil.example/phish'));
    const user = userEvent.setup();

    await fillAndLaunch(user);

    const link = within(await screen.findByTestId('social-reconnect-notice')).getByTestId('social-reconnect-link');
    expect(link.getAttribute('href')).toBe('/social/accounts');
  });

  it('a protocol-relative reconnect path is rejected too', async () => {
    ads.launch.mockRejectedValue(connection409('SOCIAL_CONNECTION_REVOKED', '//evil.example'));
    const user = userEvent.setup();

    await fillAndLaunch(user);

    const link = within(await screen.findByTestId('social-reconnect-notice')).getByTestId('social-reconnect-link');
    expect(link.getAttribute('href')).toBe('/social/accounts');
  });

  it('without access to Social Accounts the notice explains who can reconnect', async () => {
    auth.permissions = ['launch-meta-ads'];
    ads.launch.mockRejectedValue(connection409('SOCIAL_CONNECTION_EXPIRED'));
    const user = userEvent.setup();

    await fillAndLaunch(user);

    const notice = await screen.findByTestId('social-reconnect-notice');
    expect(notice).toHaveTextContent('Ask a team member with access to Social Accounts');
    expect(within(notice).queryByTestId('social-reconnect-link')).toBeNull();
  });

  it('any other launch error keeps the existing generic message', async () => {
    ads.launch.mockRejectedValue({ response: { status: 422, data: { message: 'Invalid targeting spec.' } } });
    const user = userEvent.setup();

    await fillAndLaunch(user);

    expect(await screen.findByText('Invalid targeting spec.')).toBeTruthy();
    expect(screen.queryByTestId('social-reconnect-notice')).toBeNull();
  });
});
