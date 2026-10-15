import { render, screen } from '@testing-library/react';
import { MemoryRouter } from 'react-router-dom';
import { beforeEach, describe, expect, it, vi, type Mock } from 'vitest';

import TeamPermissionsPage from './TeamPermissionsPage';
import teamService from '../../services/teamService';

/**
 * QA fixes (2026-10-14): a Client Admin can give a team member CRM, setup /
 * logs and social permissions too — and only for what the plan has.
 */

vi.mock('../../services/teamService', () => ({ default: { listUsers: vi.fn(), getPermissions: vi.fn(), updatePermissions: vi.fn() } }));
const modules = { list: [] as string[] };
vi.mock('../../core/context/AuthContext', () => ({
  useAuth: () => ({ hasModule: (m: string) => modules.list.includes(m) }),
}));

const member = { id: 5, name: 'Ravi', email: 'ravi@example.com', roles: [{ id: 3, name: 'user' }], is_active: true };

function renderAt() {
  return render(
    <MemoryRouter initialEntries={['/team/permissions?user=5']}>
      <TeamPermissionsPage />
    </MemoryRouter>,
  );
}

beforeEach(() => {
  vi.clearAllMocks();
  (teamService.listUsers as Mock).mockResolvedValue({ data: [member], scope: 'account' });
  (teamService.getPermissions as Mock).mockResolvedValue({ user_id: 5, direct: [], effective: ['send-messages'] });
});

describe('Team permission matrix groups', () => {
  it('a CRM-plan client sees the CRM group with "own leads only" explained, and no WhatsApp groups', async () => {
    modules.list = ['lead_crm', 'team_management'];
    renderAt();

    expect(await screen.findByText('CRM')).toBeTruthy();
    expect(screen.getByText("See everyone's leads")).toBeTruthy();
    expect(screen.getByText('Quotes & invoices')).toBeTruthy();
    expect(screen.queryByText('WhatsApp setup & logs')).toBeNull();
    expect(screen.queryByText('Social Ads')).toBeNull();
  });

  it('a QR client can grant WhatsApp setup, chatbot and message logs', async () => {
    modules.list = ['whatsapp_setup', 'send_alert', 'chatbot', 'message_logs'];
    renderAt();

    expect(await screen.findByText('WhatsApp setup & logs')).toBeTruthy();
    expect(screen.getByText('Connect / disconnect WhatsApp')).toBeTruthy();
    expect(screen.getByText('Message logs')).toBeTruthy();
    expect(screen.queryByText('CRM')).toBeNull();
  });
});
