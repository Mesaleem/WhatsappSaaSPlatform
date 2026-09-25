import { render, screen, waitFor } from '@testing-library/react';
import { beforeEach, describe, expect, it, vi, type Mock } from 'vitest';

import ActivityLogsPage from './ActivityLogsPage';
import activityLogService from '../../services/activityLogService';
import accountService from '../../services/accountService';
import type { ActivityLog } from '../../types/activityLog';

vi.mock('../../services/activityLogService', () => ({
  default: { list: vi.fn(), listModules: vi.fn() },
}));
vi.mock('../../services/accountService', () => ({
  default: { listAgents: vi.fn(), list: vi.fn() },
}));

const logs = activityLogService as unknown as { list: Mock; listModules: Mock };
const accounts = accountService as unknown as { listAgents: Mock; list: Mock };

const row = (id: number, action: ActivityLog['action_type']): ActivityLog => ({
  id,
  user: null,
  account: { id: 3, company_name: 'Acme' },
  agent: null,
  module_name: 'Entitlement Authorization',
  action_type: action,
  route_path: 'api/whatsapp/flows',
  ip_address: '127.0.0.1',
  old_values: null,
  new_values: { decision: action, category: action === 'denied' ? 'capability_not_entitled' : 'entitled' },
  created_at: '2026-09-25T10:00:00Z',
});

describe('ActivityLogsPage — P5-8 entitlement decisions', () => {
  beforeEach(() => {
    accounts.listAgents.mockResolvedValue([]);
    accounts.list.mockResolvedValue({ data: [] });
    logs.list.mockResolvedValue({ data: [row(1, 'denied'), row(2, 'allowed'), row(3, 'replay')], last_page: 1, total: 3 });
    logs.listModules.mockResolvedValue(['Entitlement Authorization', 'WhatsApp Flows']);
  });

  it('renders allowed/denied decisions with their own badges', async () => {
    render(<ActivityLogsPage />);

    const denied = await screen.findByText('denied', { selector: 'span' });
    const allowed = screen.getByText('allowed', { selector: 'span' });

    expect(denied.className).toContain('text-rose-700');
    expect(allowed.className).toContain('text-teal-700');
    expect(denied.className).not.toContain('undefined');
    // Phase 8 Task 1 — a retried credit operation.
    expect(screen.getByText('replay', { selector: 'span' }).className).toContain('text-slate-600');
  });

  it('offers Allowed and Denied as action filters', async () => {
    render(<ActivityLogsPage />);
    await waitFor(() => expect(logs.list).toHaveBeenCalled());

    expect(screen.getByRole('option', { name: 'Allowed' })).toBeTruthy();
    expect(screen.getByRole('option', { name: 'Denied' })).toBeTruthy();
    expect(screen.getByRole('option', { name: 'Replay' })).toBeTruthy();
  });
});
