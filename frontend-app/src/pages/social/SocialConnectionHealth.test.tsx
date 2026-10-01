import { act, render, screen, waitFor, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { MemoryRouter } from 'react-router-dom';
import { afterEach, beforeEach, describe, expect, it, vi, type Mock } from 'vitest';
import SocialAccountsPage from './SocialAccountsPage';
import SocialInboxPage from './SocialInboxPage';
import OrganicPostModal from '../../components/social/OrganicPostModal';
import socialService from '../../services/socialService';
import organicPostService from '../../services/organicPostService';
import inboxService from '../../services/inboxService';
import { socialConnectionError } from '../../utils/socialConnectionError';
import type { SocialAccount } from '../../types/social';

/**
 * Phase 9 Task 2 — connection health in the UI: Expired / Access revoked
 * with a safe explanation and Reconnect, Connected with its last check and
 * Check, a quiet re-read of the statuses when the user comes back to the
 * tab (the scheduled backend check is the source of truth), and the safe
 * reconnect notice a feature shows when its connection turns out to be
 * expired/revoked (form kept; the reconnect link opens a new tab).
 */

vi.mock('../../services/socialService', () => ({
  default: { listAccounts: vi.fn(), listProviders: vi.fn(), checkConnection: vi.fn(), getOAuthRedirectUrl: vi.fn(), bindAccounts: vi.fn(), disconnect: vi.fn() },
}));
vi.mock('../../services/organicPostService', () => ({ default: { publish: vi.fn() } }));
vi.mock('../../services/mediaService', () => ({ default: { upload: vi.fn() } }));
vi.mock('../../services/inboxService', () => ({ default: { listThreads: vi.fn(), getMessages: vi.fn(), send: vi.fn() } }));

vi.mock('../../core/context/AuthContext', () => ({
  useAuth: () => ({
    user: { id: 1, account: { id: 7 } },
    isSuperAdmin: () => false,
    hasPermission: () => true,
    hasModule: () => true,
  }),
}));

vi.mock('../../core/context/TenantContext', () => ({
  useTenant: () => ({ selectedAccountId: null, selectedAccount: null, accounts: [], isLoadingAccounts: false, canSwitchClients: false, selectAccount: vi.fn() }),
}));

const social = socialService as unknown as Record<string, Mock>;
const organic = organicPostService as unknown as { publish: Mock };
const inbox = inboxService as unknown as Record<string, Mock>;

const account = (overrides: Partial<SocialAccount> = {}): SocialAccount => ({
  id: 5, provider: 'meta', asset_type: 'meta_ad_account', provider_id: 'act_1', name: 'Acme Ads', avatar_url: null,
  health_status: 'connected', connection_status: 'connected', status_reason: null, status_checked_at: null, token_expires_at: null, created_at: null,
  ...overrides,
});

const connectionError = (code: 'SOCIAL_CONNECTION_EXPIRED' | 'SOCIAL_CONNECTION_REVOKED', reconnectPath = '/social/accounts') => ({
  response: {
    status: 409,
    data: {
      success: false,
      error_code: code,
      message: code === 'SOCIAL_CONNECTION_REVOKED'
        ? 'Access to the connected Facebook Page "Acme Page" was revoked. Reconnect it in Social Accounts, then try again.'
        : 'The connected Facebook Page "Acme Page" has expired. Reconnect it in Social Accounts, then try again.',
      connection: { social_account_id: 9, asset_type: 'facebook_page', connection_status: code === 'SOCIAL_CONNECTION_REVOKED' ? 'revoked' : 'expired' },
      reconnect_path: reconnectPath,
    },
  },
});

function renderAccounts() {
  return render(
    <MemoryRouter>
      <SocialAccountsPage />
    </MemoryRouter>,
  );
}

beforeEach(() => {
  vi.clearAllMocks();
  social.listProviders.mockResolvedValue([{ key: 'meta', label: 'Meta', asset_types: [], capabilities: {}, configured: true, enabled_for_account: true }]);
  social.getOAuthRedirectUrl.mockResolvedValue({ url: 'https://facebook.test/oauth' });
});

afterEach(() => {
  vi.restoreAllMocks();
});

describe('Social Accounts — connection health', () => {
  it('shows an expired connection as Expired with a safe explanation and Reconnect', async () => {
    vi.spyOn(window, 'open').mockReturnValue({ closed: false } as Window);
    social.listAccounts.mockResolvedValue([account({ connection_status: 'expired', health_status: 'token_expired', status_reason: null })]);
    const user = userEvent.setup();
    renderAccounts();

    expect(await screen.findByTestId('social-status-5')).toHaveTextContent(/^Expired$/);
    expect(screen.getByTestId('social-status-reason-5')).toHaveTextContent('The access token has expired. Reconnect to keep using this account.');

    await user.click(screen.getByTestId('social-reconnect-5'));
    await waitFor(() => expect(social.getOAuthRedirectUrl).toHaveBeenCalledWith('meta'));
  });

  it('shows a revoked connection as Access revoked with the stored reason and Reconnect', async () => {
    social.listAccounts.mockResolvedValue([account({ connection_status: 'revoked', health_status: 'reauth_required', status_reason: 'A Meta permission this connection needs was removed.' })]);
    renderAccounts();

    expect(await screen.findByTestId('social-status-5')).toHaveTextContent(/^Access revoked$/);
    expect(screen.getByTestId('social-status-reason-5')).toHaveTextContent('A Meta permission this connection needs was removed.');
    expect(screen.getByTestId('social-reconnect-5')).toBeInTheDocument();
  });

  it('shows a healthy connection as Connected with its last check and a Check action, without Reconnect', async () => {
    social.listAccounts.mockResolvedValue([account({ status_checked_at: new Date(Date.now() - 5 * 60_000).toISOString() })]);
    renderAccounts();

    expect(await screen.findByTestId('social-status-5')).toHaveTextContent(/^Connected$/);
    expect(screen.getByTestId('social-last-checked-5')).toHaveTextContent('last checked 5 minutes ago');
    expect(within(screen.getByTestId('social-account-5')).getByRole('button', { name: /check/i })).toBeInTheDocument();
    expect(screen.queryByTestId('social-reconnect-5')).toBeNull();
    expect(screen.queryByTestId('social-status-reason-5')).toBeNull();
  });

  it('re-reads the statuses quietly when the user returns to the tab, at most once a minute', async () => {
    const now = vi.spyOn(Date, 'now');
    let clock = 1_000_000;
    now.mockImplementation(() => clock);
    social.listAccounts
      .mockResolvedValueOnce([account()])
      .mockResolvedValueOnce([account({ connection_status: 'revoked', health_status: 'reauth_required', status_reason: 'Meta no longer accepts this connection.' })]);
    renderAccounts();
    expect(await screen.findByTestId('social-status-5')).toHaveTextContent('Connected');

    // Within a minute: no extra request.
    clock += 30_000;
    act(() => {
      window.dispatchEvent(new Event('focus'));
    });
    expect(social.listAccounts).toHaveBeenCalledTimes(1);

    // After a minute: the scheduler's new state appears without a reload.
    clock += 31_000;
    act(() => {
      window.dispatchEvent(new Event('focus'));
    });
    await waitFor(() => expect(screen.getByTestId('social-status-5')).toHaveTextContent('Access revoked'));
    expect(social.listAccounts).toHaveBeenCalledTimes(2);
    expect(screen.getByTestId('social-reconnect-5')).toBeInTheDocument();
  });
});

describe('Feature errors for an expired/revoked connection', () => {
  it('publishing an organic post shows the safe reconnect notice and keeps the form', async () => {
    organic.publish.mockRejectedValue(connectionError('SOCIAL_CONNECTION_EXPIRED'));
    const user = userEvent.setup();
    render(<OrganicPostModal onClose={() => {}} onPublished={() => {}} canManageSocialAccounts />);

    await user.type(screen.getByPlaceholderText('Write your post…'), 'Spring sale');
    await user.click(screen.getByRole('button', { name: 'Publish Now' }));

    const notice = await screen.findByTestId('social-reconnect-notice');
    expect(notice).toHaveTextContent('Connection expired');
    expect(notice).toHaveTextContent('has expired. Reconnect it in Social Accounts');
    const link = within(notice).getByTestId('social-reconnect-link');
    expect(link.getAttribute('href')).toBe('/social/accounts');
    expect(link.getAttribute('target')).toBe('_blank');
    expect((screen.getByPlaceholderText('Write your post…') as HTMLTextAreaElement).value).toBe('Spring sale');
  });

  it('without access to Social Accounts, the notice says who can reconnect instead of linking', async () => {
    organic.publish.mockRejectedValue(connectionError('SOCIAL_CONNECTION_REVOKED'));
    const user = userEvent.setup();
    render(<OrganicPostModal onClose={() => {}} onPublished={() => {}} />);

    await user.type(screen.getByPlaceholderText('Write your post…'), 'Hi');
    await user.click(screen.getByRole('button', { name: 'Publish Now' }));

    const notice = await screen.findByTestId('social-reconnect-notice');
    expect(notice).toHaveTextContent('Access revoked');
    expect(notice).toHaveTextContent('Ask a team member with access to Social Accounts');
    expect(within(notice).queryByTestId('social-reconnect-link')).toBeNull();
  });

  it('only the documented connection errors are treated as reconnect errors, and only in-app paths are followed', () => {
    expect(socialConnectionError({ response: { status: 422, data: { message: 'Invalid parameter' } } })).toBeNull();
    expect(socialConnectionError(new Error('Network Error'))).toBeNull();
    expect(socialConnectionError(connectionError('SOCIAL_CONNECTION_REVOKED'))?.status).toBe('revoked');
    expect(socialConnectionError(connectionError('SOCIAL_CONNECTION_EXPIRED', 'https://evil.test/phish'))?.reconnectPath).toBe('/social/accounts');
    expect(socialConnectionError(connectionError('SOCIAL_CONNECTION_EXPIRED', '//evil.test'))?.reconnectPath).toBe('/social/accounts');
  });

  it('the inbox lists connections that need reconnecting', async () => {
    inbox.listThreads.mockResolvedValue({
      threads: [],
      connectionIssues: [{
        social_account_id: 9, asset_type: 'facebook_page', name: 'Acme Page', connection_status: 'revoked',
        message: 'Access to the connected Facebook Page "Acme Page" was revoked. Reconnect it in Social Accounts, then try again.',
        reconnect_path: '/social/accounts',
      }],
    });
    render(
      <MemoryRouter>
        <SocialInboxPage />
      </MemoryRouter>,
    );

    const issues = await screen.findByTestId('inbox-connection-issues');
    expect(issues).toHaveTextContent('Access revoked');
    expect(within(issues).getByRole('link', { name: 'Reconnect' }).getAttribute('href')).toBe('/social/accounts');
  });
});
