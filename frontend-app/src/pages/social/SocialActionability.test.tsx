import { act, render, screen, waitFor, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { MemoryRouter, Route, Routes, useLocation } from 'react-router-dom';
import { beforeEach, describe, expect, it, vi, type Mock } from 'vitest';
import SocialAccountsPage from './SocialAccountsPage';
import MetaAdsPage from './MetaAdsPage';
import socialService from '../../services/socialService';
import adsService from '../../services/adsService';
import { safeReturnTo } from '../../components/common/actionGateHooks';

/**
 * Final hardening §23 — "Connect Meta Account" and "Paid Meta Ad Campaign"
 * are always actionable: a Super Admin in Global View picks the client
 * first, a missing Meta prerequisite is explained with the connect flow as
 * the next step (and the launcher reopens afterwards), and the backend stays
 * the authority (a check that cannot run never blocks the flow).
 */

vi.mock('../../services/socialService', () => ({
  default: { listAccounts: vi.fn(), listProviders: vi.fn(), checkConnection: vi.fn(), getOAuthRedirectUrl: vi.fn(), bindAccounts: vi.fn(), disconnect: vi.fn() },
}));
vi.mock('../../services/adsService', () => ({ default: { list: vi.fn(), launch: vi.fn(), pause: vi.fn(), resume: vi.fn() }, newLaunchKey: () => 'adlaunch-test' }));
vi.mock('../../services/aiService', () => ({ default: { generateAdCopy: vi.fn() } }));
vi.mock('../../services/mediaService', () => ({ default: { upload: vi.fn() } }));
vi.mock('../../services/organicPostService', () => ({ default: { list: () => Promise.resolve([]), insightsSummary: () => Promise.reject(new Error('not mocked')), publish: vi.fn(), cancel: vi.fn(), retry: vi.fn() } }));

const auth = { superAdmin: false, permissions: ['manage-social-accounts', 'launch-meta-ads'] as string[] };
vi.mock('../../core/context/AuthContext', () => ({
  useAuth: () => ({
    user: { id: 1, name: 'Admin', account: { id: 7 } },
    isSuperAdmin: () => auth.superAdmin,
    hasPermission: (p: string) => auth.permissions.includes(p),
    hasModule: () => true,
  }),
}));

const tenant = { selectedAccountId: null as number | null };
vi.mock('../../core/context/TenantContext', () => ({
  useTenant: () => ({
    selectedAccountId: tenant.selectedAccountId,
    selectedAccount: null,
    accounts: [{ id: 42, company_name: 'Acme Traders' }],
    isLoadingAccounts: false,
    canSwitchClients: auth.superAdmin,
    selectAccount: (id: number | null) => {
      tenant.selectedAccountId = id;
    },
  }),
}));

const social = socialService as unknown as Record<string, Mock>;
const ads = adsService as unknown as Record<string, Mock>;

function Where() {
  const location = useLocation();
  return <div data-testid="where">{location.pathname + location.search}</div>;
}

function renderAt(path: string) {
  return render(
    <MemoryRouter initialEntries={[path]}>
      <Routes>
        <Route path="/social/accounts" element={<><SocialAccountsPage /><Where /></>} />
        <Route path="/social/ads" element={<><MetaAdsPage /><Where /></>} />
      </Routes>
    </MemoryRouter>,
  );
}

const adAccount = (health = 'connected') => ({ id: 1, asset_type: 'meta_ad_account', provider_id: 'act_1', name: 'Ads', avatar_url: null, health_status: health });
const page = { id: 2, asset_type: 'facebook_page', provider_id: 'p_1', name: 'Page', avatar_url: null, health_status: 'connected' };

async function choosePicker(user: ReturnType<typeof userEvent.setup>) {
  const picker = await screen.findByTestId('client-picker');
  await user.selectOptions(within(picker).getByTestId('client-picker-select'), '42');
  await user.click(within(picker).getByRole('button', { name: 'Continue' }));
}

beforeEach(() => {
  vi.clearAllMocks();
  auth.superAdmin = false;
  auth.permissions = ['manage-social-accounts', 'launch-meta-ads'];
  tenant.selectedAccountId = null;
  social.listAccounts.mockResolvedValue([]);
  social.getOAuthRedirectUrl.mockResolvedValue({ url: 'https://facebook.test/oauth' });
  // Phase 9 Task 1 — the connect preflight (provider configured + enabled for this account).
  social.listProviders.mockResolvedValue([{ key: 'meta', label: 'Meta', asset_types: [], capabilities: {}, configured: true, enabled_for_account: true }]);
  ads.list.mockResolvedValue([]);
});

describe('Social Accounts — Connect Meta Account', () => {
  it('is enabled for a tenant and opens the Meta connect popup', async () => {
    const open = vi.spyOn(window, 'open').mockReturnValue({ closed: false } as Window);
    const user = userEvent.setup();
    renderAt('/social/accounts');

    const button = screen.getByTestId('connect-meta-account') as HTMLButtonElement;
    expect(button.disabled).toBe(false);
    await user.click(button);

    await waitFor(() => expect(open).toHaveBeenCalledWith('https://facebook.test/oauth', 'social-oauth-popup', expect.any(String)));
    open.mockRestore();
  });

  it('is clickable for a Super Admin in Global View: pick the client, then the connect flow starts', async () => {
    const open = vi.spyOn(window, 'open').mockReturnValue({ closed: false } as Window);
    auth.superAdmin = true;
    const user = userEvent.setup();
    renderAt('/social/accounts');

    const button = screen.getByTestId('connect-meta-account') as HTMLButtonElement;
    expect(button.disabled).toBe(false);
    await user.click(button);
    expect(social.getOAuthRedirectUrl).not.toHaveBeenCalled();

    await choosePicker(user);
    await waitFor(() => expect(social.getOAuthRedirectUrl).toHaveBeenCalledWith('meta'));
    expect(tenant.selectedAccountId).toBe(42);
    open.mockRestore();
  });

  it('a popup closed before finishing re-enables the button and says so', async () => {
    const open = vi.spyOn(window, 'open').mockReturnValue({ closed: true } as Window);
    const user = userEvent.setup();
    renderAt('/social/accounts');

    await user.click(screen.getByTestId('connect-meta-account'));

    await waitFor(() => expect(screen.getByText(/closed before the connection finished/i)).toBeTruthy(), { timeout: 4000 });
    expect((screen.getByTestId('connect-meta-account') as HTMLButtonElement).disabled).toBe(false);
    open.mockRestore();
  });

  it('the empty state carries the connect action, and a finished bind offers to continue where the user left off', async () => {
    vi.spyOn(window, 'open').mockReturnValue({ closed: false } as Window);
    social.bindAccounts.mockResolvedValue({});
    const user = userEvent.setup();
    renderAt(`/social/accounts?returnTo=${encodeURIComponent('/social/ads?open=paid')}`);

    const empty = await screen.findByTestId('social-empty');
    await user.click(within(empty).getByRole('button', { name: /connect meta account/i }));
    await waitFor(() => expect(social.getOAuthRedirectUrl).toHaveBeenCalled());

    act(() => {
      window.dispatchEvent(new MessageEvent('message', { data: { type: 'social-oauth-success', nonce: 'n1', assets: [{ asset_type: 'meta_ad_account', provider_id: 'act_1', name: 'Ads' }] } }));
    });
    await user.click(await screen.findByText('Ads'));
    await user.click(screen.getByRole('button', { name: /^connect 1$/i }));

    const continueLink = await screen.findByTestId('social-continue');
    await user.click(continueLink);
    await waitFor(() => expect(screen.getByTestId('where')).toHaveTextContent('/social/ads'));
  });

  it('never follows an external returnTo', () => {
    expect(safeReturnTo('/social/ads?open=paid')).toBe('/social/ads?open=paid');
    expect(safeReturnTo('https://evil.test')).toBeNull();
    expect(safeReturnTo('//evil.test')).toBeNull();
    expect(safeReturnTo('/\\evil.test')).toBeNull();
    expect(safeReturnTo(null)).toBeNull();
  });
});

describe('Meta Ads Launcher — Paid Meta Ad Campaign', () => {
  it('is enabled and, with no Ad Account connected, explains it and links to the connect flow (returning here)', async () => {
    const user = userEvent.setup();
    renderAt('/social/ads');

    const button = screen.getByTestId('open-paid-campaign') as HTMLButtonElement;
    expect(button.disabled).toBe(false);
    await user.click(button);

    const notice = await screen.findByTestId('ads-prerequisite');
    expect(notice).toHaveTextContent(/connect a meta ad account first/i);
    expect(screen.queryByText('Objective & Budget')).toBeNull();
    const link = within(notice).getByRole('link', { name: /connect meta account/i });
    expect(link.getAttribute('href')).toBe(`/social/accounts?returnTo=${encodeURIComponent('/social/ads?open=paid')}`);
  });

  it('asks to reconnect an Ad Account whose token expired', async () => {
    social.listAccounts.mockResolvedValue([adAccount('token_expired'), page]);
    const user = userEvent.setup();
    renderAt('/social/ads');

    await user.click(screen.getByTestId('open-paid-campaign'));

    expect(await screen.findByTestId('ads-prerequisite')).toHaveTextContent(/reconnect your meta ad account/i);
  });

  it('opens the campaign builder when the prerequisites are met', async () => {
    social.listAccounts.mockResolvedValue([adAccount(), page]);
    const user = userEvent.setup();
    renderAt('/social/ads');

    await user.click(screen.getByTestId('open-paid-campaign'));

    expect(await screen.findByText('Objective & Budget')).toBeTruthy();
    expect(screen.queryByTestId('ads-prerequisite')).toBeNull();
  });

  it('a missing Page is only a recommendation: the user may continue', async () => {
    social.listAccounts.mockResolvedValue([adAccount()]);
    const user = userEvent.setup();
    renderAt('/social/ads');

    await user.click(screen.getByTestId('open-paid-campaign'));
    const notice = await screen.findByTestId('ads-prerequisite');
    await user.click(within(notice).getByRole('button', { name: /continue without a page/i }));

    expect(await screen.findByText('Objective & Budget')).toBeTruthy();
  });

  it('a Super Admin in Global View picks the client, then the prerequisites are checked for it', async () => {
    auth.superAdmin = true;
    social.listAccounts.mockResolvedValue([adAccount(), page]);
    const user = userEvent.setup();
    renderAt('/social/ads');

    expect(screen.getByTestId('select-client-notice')).toBeTruthy();
    await user.click(screen.getByTestId('open-paid-campaign'));
    expect(social.listAccounts).not.toHaveBeenCalled();

    await choosePicker(user);
    expect(await screen.findByText('Objective & Budget')).toBeTruthy();
    expect(tenant.selectedAccountId).toBe(42);
  });

  it('never blocks on a check it cannot run — the backend decides on launch', async () => {
    social.listAccounts.mockRejectedValue({ response: { status: 403, data: { message: 'Forbidden' } } });
    const user = userEvent.setup();
    renderAt('/social/ads');

    await user.click(screen.getByTestId('open-paid-campaign'));

    expect(await screen.findByText('Objective & Budget')).toBeTruthy();
  });

  it('without access to Social Accounts, explains who can fix it and offers no link it could not open', async () => {
    auth.permissions = ['launch-meta-ads'];
    const user = userEvent.setup();
    renderAt('/social/ads');

    await user.click(screen.getByTestId('open-paid-campaign'));

    const notice = await screen.findByTestId('ads-prerequisite');
    expect(notice).toHaveTextContent(/ask a team member with access to social accounts/i);
    expect(within(notice).queryByRole('link')).toBeNull();
  });

  it('coming back from the connect flow (?open=paid) re-runs the check and opens the builder', async () => {
    social.listAccounts.mockResolvedValue([adAccount(), page]);
    renderAt('/social/ads?open=paid');

    expect(await screen.findByText('Objective & Budget')).toBeTruthy();
    await waitFor(() => expect(screen.getByTestId('where')).toHaveTextContent(/^\/social\/ads$/));
  });

  it('the empty campaign list offers the launch action; Organic Post is clickable too', async () => {
    const user = userEvent.setup();
    renderAt('/social/ads');

    const empty = await screen.findByTestId('ads-empty');
    await user.click(within(empty).getByRole('button', { name: /launch your first campaign/i }));
    expect(await screen.findByTestId('ads-prerequisite')).toBeTruthy();
    await user.click(within(screen.getByTestId('ads-prerequisite')).getByRole('button', { name: 'Close' }));

    const organic = screen.getByTestId('open-organic-post') as HTMLButtonElement;
    expect(organic.disabled).toBe(false);
    await user.click(organic);
    expect(await screen.findByTestId('ads-prerequisite')).toHaveTextContent(/connect a page or instagram account first/i);
  });
});
