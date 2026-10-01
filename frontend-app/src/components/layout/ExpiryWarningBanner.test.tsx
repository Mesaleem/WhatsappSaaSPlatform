import { render, screen } from '@testing-library/react';
import { MemoryRouter } from 'react-router-dom';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import ExpiryWarningBanner from './ExpiryWarningBanner';

/**
 * Final hardening §23 — the read-only banner explains every mutation button
 * disabled by an expired/suspended subscription, so it also carries the way
 * out (Billing when the user may renew; "contact your administrator" otherwise).
 */

const auth = { permissions: [] as string[], readOnly: true };
vi.mock('../../core/context/AuthContext', () => ({
  useAuth: () => ({
    user: { id: 1, account: { id: 7, status: 'active', current_subscription: { status: 'expired', expires_at: '2026-01-01T00:00:00Z' } } },
    isSuperAdmin: () => false,
    isReadOnly: () => auth.readOnly,
    hasPermission: (p: string) => auth.permissions.includes(p),
    hasModule: () => true,
  }),
}));

beforeEach(() => {
  auth.permissions = [];
  auth.readOnly = true;
});

describe('ExpiryWarningBanner — read-only mode', () => {
  it('offers renewal to a user who may manage the subscription', () => {
    auth.permissions = ['manage-subscriptions'];
    render(<MemoryRouter><ExpiryWarningBanner /></MemoryRouter>);

    const link = screen.getByRole('link', { name: /renew subscription/i });
    expect(link.getAttribute('href')).toBe('/billing');
  });

  it('tells anyone else whom to ask, without a link they could not open', () => {
    render(<MemoryRouter><ExpiryWarningBanner /></MemoryRouter>);

    expect(screen.getByTestId('read-only-banner')).toHaveTextContent(/contact your account administrator/i);
    expect(screen.queryByRole('link')).toBeNull();
  });
});
