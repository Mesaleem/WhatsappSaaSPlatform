import { act, render, screen, waitFor, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { MemoryRouter } from 'react-router-dom';
import { beforeEach, describe, expect, it, vi, type Mock } from 'vitest';
import SocialAccountsPage from './SocialAccountsPage';
import socialService from '../../services/socialService';
import type { SocialAccount } from '../../types/social';

/**
 * Phase 9 Task 1 — the Social Accounts connection lifecycle in the UI:
 * connecting → success / failed (recoverable) / cancelled, popup closed,
 * provider not configured / not enabled explained on click, not-entitled
 * plan, per-connection expired / revoked with reason + Reconnect, Check,
 * and a confirmed Disconnect with a disconnecting state. Only the popup
 * this page opened may report a result.
 */

vi.mock('../../services/socialService', () => ({
  default: { listAccounts: vi.fn(), listProviders: vi.fn(), checkConnection: vi.fn(), getOAuthRedirectUrl: vi.fn(), bindAccounts: vi.fn(), disconnect: vi.fn() },
}));

const auth = { superAdmin: false };
vi.mock('../../core/context/AuthContext', () => ({
  useAuth: () => ({
    user: { id: 1, account: { id: 7 } },
    isSuperAdmin: () => auth.superAdmin,
    hasPermission: () => true,
    hasModule: () => true,
  }),
}));

vi.mock('../../core/context/TenantContext', () => ({
  useTenant: () => ({ selectedAccountId: null, selectedAccount: null, accounts: [], isLoadingAccounts: false, canSwitchClients: false, selectAccount: vi.fn() }),
}));

const social = socialService as unknown as Record<string, Mock>;

const account = (overrides: Partial<SocialAccount> = {}): SocialAccount => ({
  id: 5, provider: 'meta', asset_type: 'meta_ad_account', provider_id: 'act_1', name: 'Acme Ads', avatar_url: null,
  health_status: 'connected', connection_status: 'connected', status_reason: null, token_expires_at: null, created_at: null, ...overrides,
});

const provider = (overrides = {}) => [{ key: 'meta', label: 'Meta', asset_types: [], capabilities: {}, configured: true, enabled_for_account: true, ...overrides }];

function renderPage() {
  return render(
    <MemoryRouter>
      <SocialAccountsPage />
    </MemoryRouter>,
  );
}

function post(data: unknown, source: MessageEventSource | null = null) {
  act(() => {
    window.dispatchEvent(new MessageEvent('message', { data, source }));
  });
}

beforeEach(() => {
  vi.clearAllMocks();
  vi.restoreAllMocks();
  auth.superAdmin = false;
  social.listAccounts.mockResolvedValue([]);
  social.listProviders.mockResolvedValue(provider());
  social.getOAuthRedirectUrl.mockResolvedValue({ url: 'https://facebook.test/oauth' });
});

describe('Social Accounts — connect lifecycle', () => {
  it('shows the connecting state while the window is open, then the asset choice on success', async () => {
    const popup = { closed: false } as Window;
    vi.spyOn(window, 'open').mockReturnValue(popup);
    const user = userEvent.setup();
    renderPage();

    await user.click(screen.getByTestId('connect-meta-account'));

    expect(await screen.findByTestId('social-connecting')).toBeTruthy();
    const button = screen.getByTestId('connect-meta-account') as HTMLButtonElement;
    expect(button).toHaveTextContent('Connecting…');
    expect(button.getAttribute('aria-busy')).toBe('true');

    post({ type: 'social-oauth-success', provider: 'meta', nonce: 'n1', assets: [{ asset_type: 'meta_ad_account', provider_id: 'act_1', name: 'Acme Ads', avatar_url: null }] });

    expect(await screen.findByText('Acme Ads')).toBeTruthy();
    expect(screen.queryByTestId('social-connecting')).toBeNull();
  });

  it('a failed callback is recoverable: the message is shown and "Try again" restarts the flow', async () => {
    vi.spyOn(window, 'open').mockReturnValue({ closed: false } as Window);
    const user = userEvent.setup();
    renderPage();
    await user.click(screen.getByTestId('connect-meta-account'));
    await screen.findByTestId('social-connecting');

    post({ type: 'social-oauth-error', message: 'The connection could not be completed.' });

    const failed = await screen.findByTestId('social-connect-failed');
    expect(failed).toHaveTextContent('could not be completed');
    expect((screen.getByTestId('connect-meta-account') as HTMLButtonElement).disabled).toBe(false);

    await user.click(within(failed).getByRole('button', { name: /try again/i }));
    await waitFor(() => expect(social.getOAuthRedirectUrl).toHaveBeenCalledTimes(2));
  });

  it('a cancelled consent is reported as cancelled, not as an error', async () => {
    vi.spyOn(window, 'open').mockReturnValue({ closed: false } as Window);
    const user = userEvent.setup();
    renderPage();
    await user.click(screen.getByTestId('connect-meta-account'));
    await screen.findByTestId('social-connecting');

    post({ type: 'social-oauth-cancelled', message: 'The Meta connection was cancelled. Nothing was connected.' });

    expect(await screen.findByTestId('social-connect-cancelled')).toHaveTextContent('cancelled');
    expect(screen.queryByTestId('social-connect-failed')).toBeNull();
  });

  it('closing the window without finishing returns to a usable state', async () => {
    vi.spyOn(window, 'open').mockReturnValue({ closed: true } as Window);
    const user = userEvent.setup();
    renderPage();

    await user.click(screen.getByTestId('connect-meta-account'));

    expect(await screen.findByTestId('social-connect-cancelled', {}, { timeout: 4000 })).toHaveTextContent(/closed before the connection finished/i);
    expect((screen.getByTestId('connect-meta-account') as HTMLButtonElement).disabled).toBe(false);
  });

  it('ignores a result posted by any window other than the one it opened', async () => {
    vi.spyOn(window, 'open').mockReturnValue({ closed: false } as Window);
    const user = userEvent.setup();
    renderPage();
    await user.click(screen.getByTestId('connect-meta-account'));
    await screen.findByTestId('social-connecting');

    post({ type: 'social-oauth-success', provider: 'meta', nonce: 'forged', assets: [{ asset_type: 'meta_ad_account', provider_id: 'x', name: 'Forged', avatar_url: null }] }, {} as MessageEventSource);

    expect(screen.queryByText('Forged')).toBeNull();
    expect(screen.getByTestId('social-connecting')).toBeTruthy();
  });

  it('explains an unconfigured provider or a provider not enabled for the account instead of failing later', async () => {
    const open = vi.spyOn(window, 'open');
    social.listProviders.mockResolvedValueOnce(provider({ configured: false })).mockResolvedValueOnce(provider({ enabled_for_account: false }));
    const user = userEvent.setup();
    renderPage();

    await user.click(screen.getByTestId('connect-meta-account'));
    expect(await screen.findByTestId('social-provider-notice')).toHaveTextContent(/not set up on this platform/i);
    await user.click(within(screen.getByTestId('social-provider-notice')).getByRole('button', { name: 'Close' }));

    await user.click(screen.getByTestId('connect-meta-account'));
    expect(await screen.findByTestId('social-provider-notice')).toHaveTextContent(/not enabled for this account/i);
    expect(social.getOAuthRedirectUrl).not.toHaveBeenCalled();
    expect(open).not.toHaveBeenCalled();
  });

  it("explains a plan without Social Media, with the upgrade path", async () => {
    social.listAccounts.mockRejectedValue({ response: { status: 403, data: { message: 'Your current plan does not include this feature.', error_code: 'CAPABILITY_NOT_ENTITLED' } } });
    renderPage();

    const panel = await screen.findByTestId('social-not-entitled');
    expect(panel).toHaveTextContent(/does not include Social Media/i);
    expect(within(panel).getByRole('link', { name: /view plans/i }).getAttribute('href')).toBe('/billing');
  });
});

describe('Social Accounts — connection states', () => {
  it('an expired or revoked connection shows its reason and a Reconnect action', async () => {
    vi.spyOn(window, 'open').mockReturnValue({ closed: false } as Window);
    social.listAccounts.mockResolvedValue([
      account({ id: 5, connection_status: 'expired', health_status: 'token_expired', status_reason: 'Meta reports that the access token has expired.' }),
      account({ id: 6, provider_id: 'page-1', asset_type: 'facebook_page', name: 'Acme Page', connection_status: 'revoked', health_status: 'reauth_required' }),
    ]);
    const user = userEvent.setup();
    renderPage();

    expect(await screen.findByTestId('social-status-5')).toHaveTextContent(/expired/i);
    expect(screen.getByTestId('social-status-6')).toHaveTextContent(/revoked/i);
    expect(screen.getByText('Meta reports that the access token has expired.')).toBeTruthy();

    await user.click(screen.getByTestId('social-reconnect-5'));
    await waitFor(() => expect(social.getOAuthRedirectUrl).toHaveBeenCalledWith('meta'));
  });

  it('Check asks the provider and updates the connection in place', async () => {
    social.listAccounts.mockResolvedValue([account()]);
    social.checkConnection.mockResolvedValue({
      data: account({ connection_status: 'revoked', health_status: 'reauth_required', status_reason: 'A Meta permission was removed.' }),
      check: { status: 'revoked', reason: 'A Meta permission was removed.' },
    });
    const user = userEvent.setup();
    renderPage();

    await user.click(within(await screen.findByTestId('social-account-5')).getByRole('button', { name: /check/i }));

    await waitFor(() => expect(screen.getByTestId('social-status-5')).toHaveTextContent(/revoked/i));
    expect(screen.getByTestId('social-row-message-5')).toHaveTextContent('A Meta permission was removed.');
    expect(screen.getByTestId('social-reconnect-5')).toBeTruthy();
  });

  it('Disconnect asks for confirmation, shows the disconnecting state, then removes the row', async () => {
    social.listAccounts.mockResolvedValue([account()]);
    let finish: (v: unknown) => void = () => {};
    social.disconnect.mockReturnValue(new Promise((resolve) => (finish = resolve)));
    const user = userEvent.setup();
    renderPage();

    await user.click(within(await screen.findByTestId('social-account-5')).getByRole('button', { name: 'Disconnect' }));
    expect(social.disconnect).not.toHaveBeenCalled();
    await user.click(screen.getAllByRole('button', { name: 'Disconnect' }).at(-1)!);

    expect(social.disconnect).toHaveBeenCalledWith(5);
    expect(await screen.findByText('Disconnecting…')).toBeTruthy();
    await act(async () => finish({ message: 'Disconnected.' }));
    await waitFor(() => expect(screen.queryByTestId('social-account-5')).toBeNull());
  });
});
