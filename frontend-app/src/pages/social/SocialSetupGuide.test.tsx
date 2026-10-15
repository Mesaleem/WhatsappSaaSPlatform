import { act, render, screen, waitFor, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { MemoryRouter } from 'react-router-dom';
import { beforeEach, describe, expect, it, vi, type Mock } from 'vitest';
import SocialAccountsPage from './SocialAccountsPage';
import socialService from '../../services/socialService';
import { diagnoseConnect } from '../../components/social/MetaSetupGuide';
import type { OAuthAssetSummary, OfferedAsset } from '../../types/social';

/**
 * Guided setup for clients with only a personal Facebook profile (2026-10-15): a pre-connect checklist with links to
 * Meta's pages, and after a connect a diagnosis of what is missing (no Page / no linked Instagram / no ad account)
 * instead of a generic error, with "I've done it — check again" re-running the connect.
 */

vi.mock('../../services/socialService', () => ({
  default: { listAccounts: vi.fn(), listProviders: vi.fn(), checkConnection: vi.fn(), getOAuthRedirectUrl: vi.fn(), bindAccounts: vi.fn(), disconnect: vi.fn() },
}));

const auth: { capabilities: Record<string, boolean> | undefined } = { capabilities: { social: true, ads: true } };
vi.mock('../../core/context/AuthContext', () => ({
  useAuth: () => ({
    user: { id: 1, account: { id: 7 }, capabilities: auth.capabilities },
    isSuperAdmin: () => false,
    hasPermission: () => true,
    hasModule: () => true,
  }),
}));

vi.mock('../../core/context/TenantContext', () => ({
  useTenant: () => ({ selectedAccountId: null, selectedAccount: null, accounts: [], isLoadingAccounts: false, canSwitchClients: false, selectAccount: vi.fn() }),
}));

const social = socialService as unknown as Record<string, Mock>;

const page: OfferedAsset = { asset_type: 'facebook_page', provider_id: 'page-1', name: 'Acme Page', avatar_url: null };
const ads: OfferedAsset = { asset_type: 'meta_ad_account', provider_id: 'act_1', name: 'Acme Ads', avatar_url: null };
const summary = (found: OAuthAssetSummary['found'], overrides: Partial<OAuthAssetSummary> = {}): OAuthAssetSummary => ({
  found, enabled: ['facebook_page', 'instagram', 'meta_ad_account'], plan_has_ads: true, ...overrides,
});

function renderPage() {
  return render(
    <MemoryRouter>
      <SocialAccountsPage />
    </MemoryRouter>,
  );
}

function post(data: unknown) {
  act(() => {
    window.dispatchEvent(new MessageEvent('message', { data }));
  });
}

async function connectAndReport(data: unknown) {
  const user = userEvent.setup();
  renderPage();
  await user.click(await screen.findByTestId('meta-setup-ready'));
  await screen.findByTestId('social-connecting');
  post({ type: 'social-oauth-success', provider: 'meta', nonce: 'n1', ...(data as object) });
  return user;
}

beforeEach(() => {
  vi.clearAllMocks();
  vi.restoreAllMocks();
  auth.capabilities = { social: true, ads: true };
  social.listAccounts.mockResolvedValue([]);
  social.listProviders.mockResolvedValue([{ key: 'meta', label: 'Meta', asset_types: [], capabilities: {}, configured: true, enabled_for_account: true }]);
  social.getOAuthRedirectUrl.mockResolvedValue({ url: 'https://facebook.test/oauth' });
  vi.spyOn(window, 'open').mockReturnValue({ closed: false } as Window);
});

describe('Social Accounts — pre-connect checklist', () => {
  it('lists the Page, Instagram and ad account steps with links to Meta, and "I\'m ready" starts the connect', async () => {
    const user = userEvent.setup();
    renderPage();

    const checklist = await screen.findByTestId('meta-setup-checklist');
    expect(within(checklist).getByTestId('meta-setup-item-facebook_page')).toHaveTextContent(/personal Facebook profile cannot be connected/i);
    expect(within(checklist).getByTestId('meta-setup-item-instagram')).toHaveTextContent(/Switch to professional account/i);
    expect(within(checklist).getByTestId('meta-setup-item-meta_ad_account')).toHaveTextContent(/payment method/i);

    const createPage = within(checklist).getByRole('link', { name: /create a facebook page/i });
    expect(createPage.getAttribute('href')).toBe('https://www.facebook.com/pages/create');
    expect(createPage.getAttribute('target')).toBe('_blank');
    expect(within(checklist).getByRole('link', { name: /open meta business suite/i }).getAttribute('href')).toBe('https://business.facebook.com/');
    // Instagram's switch happens in the app: steps only, no link.
    expect(within(within(checklist).getByTestId('meta-setup-item-instagram')).queryByRole('link')).toBeNull();

    await user.click(within(checklist).getByTestId('meta-setup-ready'));
    await waitFor(() => expect(social.getOAuthRedirectUrl).toHaveBeenCalledTimes(1));
  });

  it('hides the ad account step when the plan has no ads', async () => {
    auth.capabilities = { social: true, ads: false };
    renderPage();

    const checklist = await screen.findByTestId('meta-setup-checklist');
    expect(within(checklist).queryByTestId('meta-setup-item-meta_ad_account')).toBeNull();
    expect(within(checklist).getByTestId('meta-setup-item-facebook_page')).toBeTruthy();
  });

  it('is not shown once a Meta account is connected', async () => {
    social.listAccounts.mockResolvedValue([{ id: 5, provider: 'meta', asset_type: 'facebook_page', provider_id: 'page-1', name: 'Acme Page', avatar_url: null, health_status: 'connected', connection_status: 'connected', status_reason: null, token_expires_at: null, created_at: null }]);
    renderPage();

    await screen.findByTestId('social-account-5');
    expect(screen.queryByTestId('meta-setup-checklist')).toBeNull();
  });
});

describe('Social Accounts — what is missing after a connect', () => {
  it('no Page: shows the Page step instead of the technical error, and "check again" re-runs the connect', async () => {
    const user = await connectAndReport({ assets: [], summary: summary({ facebook_page: 0, instagram: 0, meta_ad_account: 0 }) });

    const diagnosis = await screen.findByTestId('meta-setup-diagnosis');
    expect(diagnosis).toHaveTextContent('No Facebook Page found');
    expect(diagnosis).toHaveTextContent(/personal profile cannot be connected/i);
    expect(within(diagnosis).getByRole('link', { name: /create a facebook page/i })).toBeTruthy();
    expect(screen.queryByTestId('social-connect-failed')).toBeNull();
    expect(screen.queryByTestId('meta-setup-checklist')).toBeNull();
    expect(screen.queryByText(/returned no Page, Instagram account or Ad Account/i)).toBeNull();

    await user.click(within(diagnosis).getByTestId('meta-setup-check-again'));
    await waitFor(() => expect(social.getOAuthRedirectUrl).toHaveBeenCalledTimes(2));
    expect(screen.queryByTestId('meta-setup-diagnosis')).toBeNull();
  });

  it('a Page but no Instagram: the choice is offered, then a non-blocking Instagram hint after connecting', async () => {
    social.bindAccounts.mockResolvedValue({});
    const user = await connectAndReport({ assets: [page, ads], summary: summary({ facebook_page: 1, instagram: 0, meta_ad_account: 1 }) });

    expect(screen.queryByTestId('meta-setup-diagnosis')).toBeNull();
    await user.click(await screen.findByText('Acme Page'));
    await user.click(screen.getByRole('button', { name: /^connect 1$/i }));

    const hint = await screen.findByTestId('meta-setup-hint');
    expect(hint).toHaveTextContent(/still missing/i);
    expect(within(hint).getByTestId('meta-setup-item-instagram')).toHaveTextContent(/Linked accounts/i);
    expect(within(hint).queryByTestId('meta-setup-item-facebook_page')).toBeNull();
    expect(within(hint).queryByTestId('meta-setup-item-meta_ad_account')).toBeNull();
  });

  it('no ad account while the plan has ads: the hint shows the ad account step', async () => {
    social.bindAccounts.mockResolvedValue({});
    const user = await connectAndReport({
      assets: [page, { asset_type: 'instagram', provider_id: 'ig-1', name: 'acme', avatar_url: null }],
      summary: summary({ facebook_page: 1, instagram: 1, meta_ad_account: 0 }),
    });

    await user.click(await screen.findByText('Acme Page'));
    await user.click(screen.getByRole('button', { name: /^connect 1$/i }));

    const hint = await screen.findByTestId('meta-setup-hint');
    expect(within(hint).getByTestId('meta-setup-item-meta_ad_account')).toHaveTextContent(/payment method/i);
    expect(within(hint).queryByTestId('meta-setup-item-instagram')).toBeNull();
  });

  it('accounts Facebook shared but not enabled for this client: says to ask the Super Admin', async () => {
    await connectAndReport({ assets: [], summary: summary({ facebook_page: 1, instagram: 1, meta_ad_account: 1 }, { enabled: [] }) });

    const diagnosis = await screen.findByTestId('meta-setup-diagnosis');
    expect(diagnosis).toHaveTextContent(/not enabled for your account/i);
    expect(within(diagnosis).queryByTestId('meta-setup-item-facebook_page')).toBeNull();
  });
});

describe('diagnoseConnect', () => {
  it('reads the backend summary, falling back to the offered assets for an older payload', () => {
    expect(diagnoseConnect([], summary({ facebook_page: 0, instagram: 0, meta_ad_account: 0 }), false)).toEqual({ missing: ['facebook_page', 'instagram', 'meta_ad_account'], blocking: true, notEnabled: false });
    expect(diagnoseConnect([page], summary({ facebook_page: 1, instagram: 0, meta_ad_account: 0 }, { plan_has_ads: false }), true).missing).toEqual(['instagram']);
    expect(diagnoseConnect([page], summary({ facebook_page: 1, instagram: 0, meta_ad_account: 0 }, { enabled: ['facebook_page', 'meta_ad_account'] }), true).missing).toEqual(['meta_ad_account']);
    // No summary: count what was offered, plan from the caller.
    expect(diagnoseConnect([page, ads], undefined, true)).toEqual({ missing: ['instagram'], blocking: false, notEnabled: false });
    expect(diagnoseConnect([], null, false)).toEqual({ missing: ['facebook_page', 'instagram'], blocking: true, notEnabled: false });
  });
});
