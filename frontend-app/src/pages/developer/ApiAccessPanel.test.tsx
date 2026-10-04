import { render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import ApiAccessPanel from './ApiAccessPanel';
import developerService from '../../services/developerService';
import type { ApiKey } from '../../types/developer';

vi.mock('../../services/developerService', () => ({
  default: { requestServerChange: vi.fn(), registerServer: vi.fn(), issueInstallationCredential: vi.fn() },
}));
const service = vi.mocked(developerService);

function key(over: Record<string, unknown> = {}): ApiKey {
  return {
    id: 7, name: 'CRM', key_prefix: 'wasaas_live_ab', revoked_at: null, last_used_at: null,
    server_binding: {
      status: 'active', enforced: true, access_disabled: false, access_disabled_reason: null, credential_pending: false,
      pending_change_request: null, last_change_request: null,
      binding: { id: 1, status: 'active', label: 'Production Server', ip_policy: 'SINGLE_IP', authorized_ips: ['203.0.113.10'], registered_ip: '203.0.113.10', registered_at: null, last_success_ip: '203.0.113.10', last_success_at: null, credential_issued: true, revoked_at: null, created_at: null },
      ...over,
    },
  } as unknown as ApiKey;
}

describe('ApiAccessPanel', () => {
  beforeEach(() => vi.clearAllMocks());

  it('shows status, server, IP and the licence warning', () => {
    render(<ApiAccessPanel apiKey={key()} onChanged={() => undefined} />);
    expect(screen.getByText(/Status: Active/)).toBeInTheDocument();
    expect(screen.getByText('Production Server')).toBeInTheDocument();
    expect(screen.getAllByText('203.0.113.10').length).toBeGreaterThan(0);
    expect(screen.getByText(/not permitted/i)).toBeInTheDocument();
    expect(screen.getByRole('button', { name: /request server change/i })).toBeInTheDocument();
  });

  it('tells the owner of a legacy key that server authorization is required and offers enrollment', async () => {
    const user = userEvent.setup();
    service.registerServer.mockResolvedValue({ installation_credential: 'wasaas_inst_LEGACY', installation_header: 'X-Client-Installation' } as never);
    const onChanged = vi.fn();
    render(<ApiAccessPanel apiKey={key({ status: 'unbound', binding: null, binding_required: true })} onChanged={onChanged} />);

    expect(screen.getByText(/Status: Server authorization required/)).toBeInTheDocument();
    expect(screen.getByRole('note')).toHaveTextContent(/requires server authorization before it can be used/i);
    expect(screen.queryByRole('button', { name: /request server change/i })).not.toBeInTheDocument();

    await user.click(screen.getByRole('button', { name: /register authorized server/i }));
    await user.click(screen.getByRole('checkbox', { name: /authorized server/i }));
    await user.type(screen.getByLabelText(/server ip/i), '203.0.113.50');
    await user.click(screen.getByRole('button', { name: /^register server$/i }));

    expect(await screen.findByTestId('installation-credential')).toHaveTextContent('wasaas_inst_LEGACY');
    expect(service.registerServer).toHaveBeenCalledWith(7, expect.objectContaining({ acknowledge_server_binding: true, authorized_ips: ['203.0.113.50'] }));
    expect(onChanged).toHaveBeenCalled();
  });

  it('shows the pending notice and hides the request button while a request is pending', () => {
    render(<ApiAccessPanel apiKey={key({ pending_change_request: { id: 1, status: 'pending' } })} onChanged={() => undefined} />);
    expect(screen.getByText('Server Change Request: Pending Super Admin Approval')).toBeInTheDocument();
    expect(screen.queryByRole('button', { name: /request server change/i })).not.toBeInTheDocument();
  });

  it('submits a server change request with reason and new IP', async () => {
    const user = userEvent.setup();
    const onChanged = vi.fn();
    service.requestServerChange.mockResolvedValue({} as never);
    render(<ApiAccessPanel apiKey={key()} onChanged={onChanged} />);
    await user.click(screen.getByRole('button', { name: /request server change/i }));
    await user.type(screen.getByLabelText(/new server ip/i), '198.51.100.9');
    await user.click(screen.getByRole('button', { name: /submit request/i }));
    expect(screen.getByRole('alert')).toHaveTextContent(/why the server is changing/i);
    await user.type(screen.getByLabelText(/reason/i), 'Migrating hosts');
    await user.click(screen.getByRole('button', { name: /submit request/i }));
    await waitFor(() => expect(service.requestServerChange).toHaveBeenCalledWith(7, expect.objectContaining({ reason: 'Migrating hosts', requested_ips: ['198.51.100.9'] })));
    await waitFor(() => expect(onChanged).toHaveBeenCalled());
  });

  it('reveals an issued installation credential once', async () => {
    const user = userEvent.setup();
    service.issueInstallationCredential.mockResolvedValue({ installation_credential: 'wasaas_inst_SECRET', installation_header: 'X-Client-Installation' } as never);
    render(<ApiAccessPanel apiKey={key({ credential_pending: true })} onChanged={() => undefined} />);
    await user.click(screen.getByRole('button', { name: /issue installation credential/i }));
    expect(await screen.findByTestId('installation-credential')).toHaveTextContent('wasaas_inst_SECRET');
    await user.click(screen.getByRole('button', { name: /done/i }));
    expect(screen.queryByTestId('installation-credential')).not.toBeInTheDocument();
  });
});
