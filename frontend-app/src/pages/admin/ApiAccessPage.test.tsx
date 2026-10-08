import { render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import ApiAccessPage from './ApiAccessPage';
import svc from '../../services/apiAccessAdminService';

vi.mock('../../services/apiAccessAdminService', () => ({
  default: {
    list: vi.fn(), changeRequests: vi.fn(), approve: vi.fn(), reject: vi.fn(), revoke: vi.fn(), rebind: vi.fn(),
    disable: vi.fn(), enable: vi.fn(), events: vi.fn(), overrideCooldown: vi.fn(), destroy: vi.fn(),
  },
}));
const s = vi.mocked(svc);

beforeEach(() => {
  vi.clearAllMocks();
  s.list.mockResolvedValue([{
    id: 7, name: 'CRM', key_prefix: 'wasaas_live_ab', account_id: 1, account_name: 'Acme', revoked_at: null, last_used_at: null,
    server_binding: {
      status: 'active', access_disabled: false, credential_pending: false,
      binding: { label: 'Prod', registered_ip: '203.0.113.10', last_success_ip: '203.0.113.10', last_success_at: null, status: 'active' },
      cooldown_until: null, in_cooldown: false, legacy_ip_dependent: false,
    },
    installation_usage: { live: 2, allowance: 5, at_or_over_allowance: false },
  }] as never);
  s.changeRequests.mockResolvedValue([{ id: 3, status: 'pending', api_key_id: 7, account_id: 1, account_name: 'Acme', key_name: 'CRM', key_prefix: 'wasaas_live_ab', current_label: 'Prod', current_ip: '203.0.113.10', requested_label: 'New', requested_ip_policy: 'SINGLE_IP', requested_ips: ['198.51.100.9'], reason: 'Migrating' }] as never);
  s.approve.mockResolvedValue({ message: 'Approved' });
});

describe('ApiAccessPage', () => {
  it('lists bindings and pending requests, never a key value', async () => {
    render(<ApiAccessPage />);
    expect(await screen.findByText(/Migrating/)).toBeInTheDocument();
    expect(screen.getByText('Acme', { selector: 'td' })).toBeInTheDocument();
    expect(document.body.textContent).not.toMatch(/wasaas_live_[A-Za-z0-9]{20}|wasaas_inst_/);
  });

  it('approves a request and reloads', async () => {
    const user = userEvent.setup();
    render(<ApiAccessPage />);
    await user.click(await screen.findByRole('button', { name: 'Approve' }));
    await waitFor(() => expect(s.approve).toHaveBeenCalledWith(3));
    expect(await screen.findByRole('status')).toHaveTextContent('Approved');
    expect(s.list).toHaveBeenCalledTimes(2);
  });

  it('disables API access for a key', async () => {
    const user = userEvent.setup();
    s.disable.mockResolvedValue({ message: 'Disabled' });
    render(<ApiAccessPage />);
    await user.click(await screen.findByRole('button', { name: 'Disable access' }));
    await waitFor(() => expect(s.disable).toHaveBeenCalledWith(7));
  });

  // Phase 4 Task 12 — admin visibility additions.

  it('shows installation usage, credential and cooldown state without ever showing a credential value', async () => {
    render(<ApiAccessPage />);
    await screen.findByText('Acme', { selector: 'td' });
    expect(screen.getByText('2 / 5')).toBeInTheDocument();
    expect(screen.getByText('Configured')).toBeInTheDocument();
    expect(document.body.textContent).not.toMatch(/wasaas_live_[A-Za-z0-9]{20}|wasaas_inst_/);
  });

  it('does not offer an "Override cooldown" action when the key is not in cooldown', async () => {
    render(<ApiAccessPage />);
    await screen.findByText('Acme', { selector: 'td' });
    expect(screen.queryByRole('button', { name: 'Override cooldown' })).not.toBeInTheDocument();
  });

  it('offers and wires "Override cooldown" only while a key is in cooldown, requiring a reason', async () => {
    const user = userEvent.setup();
    s.list.mockResolvedValue([{
      id: 7, name: 'CRM', key_prefix: 'wasaas_live_ab', account_id: 1, account_name: 'Acme', revoked_at: null, last_used_at: null,
      server_binding: {
        status: 'active', access_disabled: false, credential_pending: false,
        binding: { label: 'Prod', registered_ip: '203.0.113.10', last_success_ip: '203.0.113.10', last_success_at: null, status: 'active' },
        cooldown_until: '2026-12-01T00:00:00Z', in_cooldown: true, legacy_ip_dependent: false,
      },
      installation_usage: { live: 2, allowance: 5, at_or_over_allowance: false },
    }] as never);
    s.overrideCooldown.mockResolvedValue({ message: 'Cooldown updated.', data: {} as never });
    vi.spyOn(window, 'prompt').mockReturnValue('customer escalation, approved early reconnect');
    render(<ApiAccessPage />);
    await user.click(await screen.findByRole('button', { name: 'Override cooldown' }));
    await waitFor(() => expect(s.overrideCooldown).toHaveBeenCalledWith(7, 'customer escalation, approved early reconnect'));
  });

  it('shows legacy-IP-dependent state when applicable', async () => {
    s.list.mockResolvedValue([{
      id: 8, name: 'Legacy', key_prefix: 'wasaas_live_cd', account_id: 1, account_name: 'Acme', revoked_at: null, last_used_at: null,
      server_binding: { status: 'unbound', access_disabled: false, credential_pending: false, binding: null, legacy_ip_dependent: true, legacy_authorized_ip: '203.0.113.1', legacy_deadline: null },
      installation_usage: { live: 0, allowance: 5, at_or_over_allowance: false },
    }] as never);
    render(<ApiAccessPage />);
    expect(await screen.findByText(/IP-dependent/)).toBeInTheDocument();
  });

  it('offers "Destroy key" only for a non-revoked key and confirms before calling the service', async () => {
    const user = userEvent.setup();
    s.destroy.mockResolvedValue({ message: 'API key destroyed.', data: {} as never });
    vi.spyOn(window, 'confirm').mockReturnValue(true);
    render(<ApiAccessPage />);
    await user.click(await screen.findByRole('button', { name: 'Destroy key' }));
    await waitFor(() => expect(s.destroy).toHaveBeenCalledWith(7));
  });

  it('does not call destroy when the confirmation is declined', async () => {
    const user = userEvent.setup();
    vi.spyOn(window, 'confirm').mockReturnValue(false);
    render(<ApiAccessPage />);
    await user.click(await screen.findByRole('button', { name: 'Destroy key' }));
    expect(s.destroy).not.toHaveBeenCalled();
  });
});
