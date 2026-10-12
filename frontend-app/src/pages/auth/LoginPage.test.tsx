import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { MemoryRouter, Route, Routes } from 'react-router-dom';
import { beforeEach, describe, expect, it, vi } from 'vitest';

import LoginPage from './LoginPage';

const login = vi.fn();
vi.mock('../../core/context/AuthContext', () => ({
  SESSION_ENDED_KEY: 'auth_session_ended',
  useAuth: () => ({ login }),
}));

function renderLogin() {
  return render(
    <MemoryRouter initialEntries={['/login']}>
      <Routes>
        <Route path="/login" element={<LoginPage />} />
        <Route path="/" element={<div>dashboard</div>} />
      </Routes>
    </MemoryRouter>,
  );
}

async function fillAndSubmit(user: ReturnType<typeof userEvent.setup>) {
  await user.type(screen.getByLabelText(/Email address/), 'asha@acme.test');
  await user.type(screen.getByLabelText(/^Password/), 'secret-pass');
  await user.click(screen.getByRole('button', { name: /Sign in/ }));
}

describe('Login — device limit', () => {
  beforeEach(() => {
    login.mockReset();
    sessionStorage.clear();
  });

  it('warns when the account is open on another device and can sign the others out', async () => {
    login.mockRejectedValueOnce(Object.assign(new Error('Already logged in elsewhere.'), { code: 'ACTIVE_SESSION' })).mockResolvedValueOnce({ id: 1 });
    const user = userEvent.setup();
    renderLogin();

    await fillAndSubmit(user);
    expect(await screen.findByText('Device limit reached')).toBeTruthy();
    expect(screen.queryByText('Already logged in elsewhere.')).toBeNull();

    await user.click(screen.getByRole('button', { name: /Log out from all devices & log in here/ }));

    expect(login).toHaveBeenLastCalledWith({ email: 'asha@acme.test', password: 'secret-pass', force: true });
    expect(await screen.findByText('dashboard')).toBeTruthy();
  });

  it('a normal login does not ask to sign other devices out', async () => {
    login.mockResolvedValueOnce({ id: 1 });
    const user = userEvent.setup();
    renderLogin();

    await fillAndSubmit(user);

    expect(login).toHaveBeenCalledWith({ email: 'asha@acme.test', password: 'secret-pass' });
    expect(await screen.findByText('dashboard')).toBeTruthy();
  });

  it('explains once that this browser was signed out', () => {
    sessionStorage.setItem('auth_session_ended', '1');
    renderLogin();

    expect(screen.getByText(/You were signed out/)).toBeTruthy();
    expect(sessionStorage.getItem('auth_session_ended')).toBeNull();
  });

  it('links to forgot password', () => {
    renderLogin();
    expect(screen.getByRole('link', { name: 'Forgot password?' }).getAttribute('href')).toBe('/forgot-password');
  });
});
