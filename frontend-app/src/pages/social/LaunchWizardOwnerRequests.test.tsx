import { render, screen, waitFor, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { MemoryRouter, Route, Routes } from 'react-router-dom';
import { beforeEach, describe, expect, it, vi, type Mock } from 'vitest';
import MetaAdsPage from './MetaAdsPage';
import socialService from '../../services/socialService';
import adsService from '../../services/adsService';
import type { AdAccountInfo } from '../../types/ads';

/**
 * Owner requests of 2026-09-30 in the Meta Ads launcher: an in-page form (no
 * modal) with errors shown at the top and under their fields; budgets in the
 * ad account's currency (INR by default) with its spend / cap / balance;
 * Meta location search; manual placements (FB / IG feed, stories, reels);
 * a chosen button; and a Super Admin working on its own Platform account.
 */

vi.mock('../../services/socialService', () => ({
  default: { listAccounts: vi.fn(), listProviders: vi.fn(), checkConnection: vi.fn(), getOAuthRedirectUrl: vi.fn(), bindAccounts: vi.fn(), disconnect: vi.fn() },
}));
vi.mock('../../services/adsService', () => ({
  default: { list: vi.fn(), launch: vi.fn(), pause: vi.fn(), resume: vi.fn(), account: vi.fn(), locations: vi.fn() },
  newLaunchKey: () => 'adlaunch-test',
}));
vi.mock('../../services/aiService', () => ({ default: { generate: vi.fn() } }));
vi.mock('../../services/mediaService', () => ({ default: { upload: vi.fn() } }));
vi.mock('../../services/organicPostService', () => ({ default: { list: () => Promise.resolve([]), insightsSummary: () => Promise.reject(new Error('not mocked')), publish: vi.fn(), cancel: vi.fn(), retry: vi.fn() } }));

const auth = { superAdmin: false, platform: null as { id: number; company_name: string } | null };
vi.mock('../../core/context/AuthContext', () => ({
  useAuth: () => ({
    user: { id: 1, name: 'Admin', account: auth.superAdmin ? null : { id: 7 }, platform_crm_account: auth.platform, capabilities: { ads: true, social: true } },
    isSuperAdmin: () => auth.superAdmin,
    hasPermission: () => true,
    hasModule: () => true,
  }),
}));
vi.mock('../../core/context/TenantContext', () => ({
  useTenant: () => ({ selectedAccountId: null, selectedAccount: null, accounts: [], isLoadingAccounts: false, canSwitchClients: auth.superAdmin, selectAccount: vi.fn() }),
}));

const social = socialService as unknown as Record<string, Mock>;
const ads = adsService as unknown as Record<string, Mock>;

const adAccount = { id: 1, asset_type: 'meta_ad_account', provider_id: 'act_1', name: 'Acme Ads', avatar_url: null, health_status: 'connected', connection_status: 'connected' };
const page = { id: 2, asset_type: 'facebook_page', provider_id: 'p_1', name: 'Acme Page', avatar_url: null, health_status: 'connected', connection_status: 'connected' };
const inrAccount: AdAccountInfo = { name: 'Acme INR', currency: 'INR', account_status: 1, account_status_label: 'active', runnable: true, amount_spent: 1234.5, spend_cap: 10000, remaining_spend_cap: 8765.5, balance: 50 };

function renderPage() {
  return render(
    <MemoryRouter initialEntries={['/social/ads']}>
      <Routes>
        <Route path="/social/ads" element={<MetaAdsPage />} />
      </Routes>
    </MemoryRouter>,
  );
}

async function openWizard(user: ReturnType<typeof userEvent.setup>) {
  renderPage();
  await user.click(screen.getByTestId('open-paid-campaign'));
  expect(await screen.findByTestId('launch-wizard')).toBeTruthy();
}

async function stepOne(user: ReturnType<typeof userEvent.setup>, budget = '500') {
  await user.type(screen.getByPlaceholderText('Spring Lead Gen Push'), 'Diwali');
  await user.type(screen.getByLabelText(/Daily Budget/), budget);
  await user.click(screen.getByRole('button', { name: 'Next' }));
}

async function stepThree(user: ReturnType<typeof userEvent.setup>) {
  await user.type(screen.getByPlaceholderText('Get a Free Quote Today'), 'Book a visit');
  await user.type(screen.getByPlaceholderText('Tell people why they should tap your ad…'), 'Limited slots');
}

beforeEach(() => {
  vi.clearAllMocks();
  auth.superAdmin = false;
  auth.platform = null;
  ads.list.mockResolvedValue([]);
  ads.account.mockResolvedValue(inrAccount);
  ads.locations.mockResolvedValue([]);
  ads.launch.mockResolvedValue({ message: 'Campaign launched.', data: {} });
  social.listAccounts.mockResolvedValue([adAccount, page]);
});

describe('Launch form', () => {
  it('opens in the page (no modal) and shows the ad account in its own currency', async () => {
    const user = userEvent.setup();
    await openWizard(user);

    expect(document.querySelector('.fixed.inset-0')).toBeNull();
    expect(screen.queryByText('No campaigns launched yet.')).toBeNull();
    const info = await screen.findByTestId('ad-account-info');
    await waitFor(() => expect(within(info).getByText('Acme INR')).toBeTruthy());
    expect(within(info).getByText('₹1,234.50')).toBeTruthy();
    expect(within(info).getByText('₹8,765.50')).toBeTruthy();
    expect(screen.getByLabelText(/Daily Budget \(INR\)/)).toBeTruthy();
  });

  it('assumes INR when the ad account cannot be read', async () => {
    ads.account.mockRejectedValue({ response: { status: 422, data: { message: 'No Meta Ad Account is connected for this tenant.' } } });
    const user = userEvent.setup();
    await openWizard(user);

    expect(await screen.findByText(/Amounts are assumed to be in INR/)).toBeTruthy();
    expect(screen.getByLabelText(/Daily Budget \(INR\)/)).toBeTruthy();
  });

  it('refuses a budget above the spend cap left, before calling the server', async () => {
    const user = userEvent.setup();
    await openWizard(user);
    await screen.findByText('Acme INR');
    await stepOne(user, '9000');

    expect(await screen.findByRole('alert')).toHaveTextContent('more than the ₹8,765.50 left');
    expect(ads.launch).not.toHaveBeenCalled();
  });

  it('targets locations picked from Meta search, manual placements and the chosen button', async () => {
    ads.locations.mockResolvedValue([{ key: '2295411', name: 'Mumbai', type: 'city', country_code: 'IN', country_name: 'India', region: 'Maharashtra' }]);
    const user = userEvent.setup();
    await openWizard(user);
    await screen.findByText('Acme INR');
    await stepOne(user);

    await user.click(screen.getByRole('button', { name: 'Remove India' }));
    await user.type(screen.getByLabelText('Search locations'), 'Mum');
    await user.click(await screen.findByRole('button', { name: /Mumbai, Maharashtra, India/ }));
    expect(ads.locations).toHaveBeenCalledWith('Mum');

    await user.click(screen.getByLabelText('Choose placements'));
    await user.click(screen.getByRole('button', { name: 'Next' }));
    expect(screen.getByRole('alert')).toHaveTextContent('Choose at least one placement');
    await user.click(screen.getByLabelText('Instagram Reels'));
    await user.click(screen.getByLabelText('Instagram Stories'));
    await user.click(screen.getByRole('button', { name: 'Next' }));

    await stepThree(user);
    await user.selectOptions(screen.getByLabelText('Button'), 'GET_QUOTE');
    expect(screen.getAllByText('Get Quote').length).toBeGreaterThan(0); // the live preview follows the choice
    await user.click(screen.getByRole('button', { name: /launch campaign/i }));

    await waitFor(() => expect(ads.launch).toHaveBeenCalledTimes(1));
    const payload = ads.launch.mock.calls[0][0];
    expect(payload.targeting_specs.locations).toEqual([{ key: '2295411', type: 'city', name: 'Mumbai' }]);
    expect(payload.placements).toEqual(['instagram_reels', 'instagram_stories']);
    expect(payload.creative.call_to_action).toBe('GET_QUOTE');
  });

  it('click-to-WhatsApp always uses the WhatsApp button', async () => {
    const user = userEvent.setup();
    await openWizard(user);
    await user.selectOptions(screen.getByRole('combobox', { name: /Objective/ }), 'CLICK_TO_WHATSAPP');
    await stepOne(user);
    await user.click(screen.getByRole('button', { name: 'Next' }));

    const button = screen.getByLabelText('Button') as HTMLSelectElement;
    expect(button.value).toBe('WHATSAPP_MESSAGE');
    expect(button.disabled).toBe(true);
  });

  it('server validation errors take the user to the right step, at the top and under the field', async () => {
    ads.launch.mockRejectedValue({
      response: { status: 422, data: { message: 'The given data was invalid.', errors: { 'targeting_specs.locations': ['Choose at least one location.'] } } },
    });
    const user = userEvent.setup();
    await openWizard(user);
    await screen.findByText('Acme INR');
    await stepOne(user);
    await user.click(screen.getByRole('button', { name: 'Next' }));
    await stepThree(user);
    await user.click(screen.getByRole('button', { name: /launch campaign/i }));

    const banner = await screen.findByTestId('launch-submit-error');
    expect(banner).toHaveTextContent('Some details need attention');
    expect(within(banner).getByText('Choose at least one location.')).toBeTruthy();
    // Back on the Audience step, with the message under the location field.
    expect(screen.getByTestId('ad-location-picker')).toBeTruthy();
    expect(screen.getAllByText('Choose at least one location.').length).toBe(2);
  });
});

describe('Super Admin own Platform account', () => {
  it('with a Platform account and no client selected, the launcher works without picking a client', async () => {
    auth.superAdmin = true;
    auth.platform = { id: 99, company_name: 'Platform (Super Admin)' };
    const user = userEvent.setup();
    renderPage();

    await waitFor(() => expect(ads.list).toHaveBeenCalled());
    expect(screen.queryByText(/Select one to view or launch their campaigns/)).toBeNull();
    await user.click(screen.getByTestId('open-paid-campaign'));
    expect(await screen.findByTestId('launch-wizard')).toBeTruthy();
  });

  it('without a Platform account a Super Admin is still asked to pick a client', () => {
    auth.superAdmin = true;
    renderPage();

    expect(screen.getByText(/Select one to view or launch their campaigns/)).toBeTruthy();
    expect(ads.list).not.toHaveBeenCalled();
  });
});
