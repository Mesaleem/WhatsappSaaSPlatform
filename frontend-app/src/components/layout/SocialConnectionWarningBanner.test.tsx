import { act, render, screen, waitFor } from '@testing-library/react';
import { MemoryRouter } from 'react-router-dom';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import SocialConnectionWarningBanner from './SocialConnectionWarningBanner';
import socialService, { SOCIAL_ACCOUNTS_LOADED_EVENT } from '../../services/socialService';
import type { SocialAccount } from '../../types/social';

vi.mock('../../core/context/AuthContext', () => ({
  useAuth: () => ({ user: { capabilities: { social: true } }, hasPermission: () => true, hasModule: () => true }),
}));

const page = (health_status: string) =>
  ({ id: 1, provider: 'meta', asset_type: 'facebook_page', name: 'Acme Page', health_status }) as unknown as SocialAccount;

describe('SocialConnectionWarningBanner', () => {
  beforeEach(() => vi.restoreAllMocks());

  it('clears the reconnect warning as soon as a fresh account list shows the Page connected', async () => {
    vi.spyOn(socialService, 'listAccounts').mockResolvedValue([page('reauth_required')]);

    render(
      <MemoryRouter>
        <SocialConnectionWarningBanner />
      </MemoryRouter>,
    );
    act(() => {
      window.dispatchEvent(new CustomEvent(SOCIAL_ACCOUNTS_LOADED_EVENT, { detail: [page('reauth_required')] }));
    });
    expect(await screen.findByTestId('social-connection-warning')).toHaveTextContent('needs reconnecting');

    act(() => {
      window.dispatchEvent(new CustomEvent(SOCIAL_ACCOUNTS_LOADED_EVENT, { detail: [page('connected')] }));
    });
    await waitFor(() => expect(screen.queryByTestId('social-connection-warning')).toBeNull());
  });
});
