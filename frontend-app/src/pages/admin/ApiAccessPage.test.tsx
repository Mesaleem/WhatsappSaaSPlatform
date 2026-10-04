import { render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import ApiAccessPage from './ApiAccessPage';
import svc from '../../services/apiAccessAdminService';

vi.mock('../../services/apiAccessAdminService', () => ({
  default: { list: vi.fn(), changeRequests: vi.fn(), approve: vi.fn(), reject: vi.fn(), revoke: vi.fn(), rebind: vi.fn(), disable: vi.fn(), enable: vi.fn(), events: vi.fn() },
}));
const s = vi.mocked(svc);

beforeEach(() => {
  vi.clearAllMocks();
  s.list.mockResolvedValue([{ id: 7, name: 'CRM', key_prefix: 'wasaas_live_ab', account_id: 1, account_name: 'Acme', revoked_at: null, last_used_at: null, server_binding: { status: 'active', access_disabled: false, binding: { label: 'Prod', registered_ip: '203.0.113.10', last_success_ip: '203.0.113.10', last_success_at: null, status: 'active' } } }] as never);
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
});
