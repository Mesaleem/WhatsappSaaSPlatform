import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { MemoryRouter, Route, Routes } from 'react-router-dom';
import { beforeEach, describe, expect, it, vi, type Mock } from 'vitest';

import ForgotPasswordPage from './ForgotPasswordPage';
import ResetPasswordPage from './ResetPasswordPage';
import publicSiteService from '../../services/publicSiteService';

vi.mock('../../services/publicSiteService', () => ({ default: { forgotPassword: vi.fn(), resetPassword: vi.fn() } }));

const renderAt = (path: string) =>
  render(
    <MemoryRouter initialEntries={[path]}>
      <Routes>
        <Route path="/forgot-password" element={<ForgotPasswordPage />} />
        <Route path="/reset-password" element={<ResetPasswordPage />} />
      </Routes>
    </MemoryRouter>,
  );

describe('Forgot / reset password', () => {
  beforeEach(() => vi.clearAllMocks());

  it('requests a reset link and shows the check-your-email screen', async () => {
    (publicSiteService.forgotPassword as Mock).mockResolvedValue({ message: 'If an account exists, we sent a link.' });
    const user = userEvent.setup();
    renderAt('/forgot-password');

    await user.type(screen.getByLabelText('Email address'), 'asha@acme.test');
    await user.click(screen.getByRole('button', { name: 'Send reset link' }));

    expect(publicSiteService.forgotPassword).toHaveBeenCalledWith('asha@acme.test');
    expect(await screen.findByText('Check your email')).toBeTruthy();
    expect(document.title).toBe('WapHub | Forgot password');
  });

  it('sets a new password with the token from the link', async () => {
    (publicSiteService.resetPassword as Mock).mockResolvedValue({ message: 'ok' });
    const user = userEvent.setup();
    renderAt('/reset-password?token=tok-1&email=asha%40acme.test');

    await user.type(screen.getByLabelText('New password'), 'brand-new-pass');
    await user.type(screen.getByLabelText('Confirm new password'), 'brand-new-pass');
    await user.click(screen.getByRole('button', { name: 'Change password' }));

    expect(publicSiteService.resetPassword).toHaveBeenCalledWith({ token: 'tok-1', email: 'asha@acme.test', password: 'brand-new-pass', password_confirmation: 'brand-new-pass' });
    expect(await screen.findByText('Password changed')).toBeTruthy();
  });

  it('shows the server error for an expired link with a way to get a new one', async () => {
    (publicSiteService.resetPassword as Mock).mockRejectedValue({ response: { status: 422, data: { errors: { token: ['This reset link is invalid or has expired. Please request a new one.'] } } } });
    const user = userEvent.setup();
    renderAt('/reset-password?token=old&email=asha%40acme.test');

    await user.type(screen.getByLabelText('New password'), 'brand-new-pass');
    await user.type(screen.getByLabelText('Confirm new password'), 'brand-new-pass');
    await user.click(screen.getByRole('button', { name: 'Change password' }));

    expect(await screen.findByText(/has expired/)).toBeTruthy();
    expect(screen.getByRole('link', { name: 'Request a new link' }).getAttribute('href')).toBe('/forgot-password');
  });

  it('explains a link without token or email', () => {
    renderAt('/reset-password');
    expect(screen.getByText('Link not valid')).toBeTruthy();
  });
});
