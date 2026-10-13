import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { MemoryRouter } from 'react-router-dom';
import { describe, expect, it, vi } from 'vitest';

import Header from './Header';

vi.mock('../../core/context/TenantContext', () => ({ useTenant: () => ({ canSwitchClients: false }) }));
vi.mock('./NotificationBell', () => ({ default: () => null }));
vi.mock('./AvatarMenu', () => ({ default: () => null }));
vi.mock('./ExpiryWarningBanner', () => ({ default: () => null }));
vi.mock('./ExpiryHeaderChip', () => ({ default: () => null }));
vi.mock('./SocialConnectionWarningBanner', () => ({ default: () => null }));
vi.mock('./AiCreditChip', () => ({ default: () => null }));
vi.mock('../../core/context/AuthContext', () => ({
  useAuth: () => ({ user: { account: null }, isSuperAdmin: () => false, hasPermission: () => false, isReadOnly: () => false }),
}));

describe('Header refresh button', () => {
  it('reloads the page data when clicked', async () => {
    const onRefresh = vi.fn();
    const user = userEvent.setup();
    render(
      <MemoryRouter>
        <Header pageTitle="Dashboard" onRefresh={onRefresh} />
      </MemoryRouter>,
    );

    await user.click(screen.getByRole('button', { name: 'Refresh this page' }));
    expect(onRefresh).toHaveBeenCalledTimes(1);
  });

  it('is not shown when the layout gives no refresh action', () => {
    render(
      <MemoryRouter>
        <Header pageTitle="Dashboard" />
      </MemoryRouter>,
    );
    expect(screen.queryByRole('button', { name: 'Refresh this page' })).toBeNull();
  });
});
