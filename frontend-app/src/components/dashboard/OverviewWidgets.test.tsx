import { render, screen, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { MemoryRouter } from 'react-router-dom';
import { describe, expect, it, vi } from 'vitest';

import { AgentBusinessOverview, PlanFeatureWidgets, PlatformAttention, PlatformPlansAndTopClients, UpgradeHint } from './OverviewWidgets';
import type { TenantOverview } from '../../services/dashboardService';

vi.mock('../../core/context/TenantContext', () => ({ useTenant: () => ({ selectedAccountId: null }) }));

const wrap = (ui: React.ReactNode) => render(<MemoryRouter>{ui}</MemoryRouter>);

const starter: TenantOverview = {
  account_id: 1,
  plan: { key: 'starter', label: 'Starter' },
  subscription: null,
  capabilities: { whatsapp_send: true, crm: false, journey_automation: false, ai: false, ads: false },
};

describe('Dashboard overview widgets', () => {
  it('a plan without CRM/Journey/AI/Ads shows no plan widgets, only the upgrade hint', () => {
    const { container } = wrap(<PlanFeatureWidgets tenant={starter} />);
    expect(container.innerHTML).toBe('');

    wrap(<UpgradeHint tenant={starter} />);
    expect(screen.getByTestId('upgrade-hint').textContent).toContain('Unlock CRM, Journey Builder, AI replies, Meta Ads');
  });

  it('shows exactly the widgets the plan includes', () => {
    wrap(
      <PlanFeatureWidgets
        tenant={{
          ...starter,
          plan: { key: 'growth', label: 'Growth' },
          capabilities: { crm: true, journey_automation: true, ai: true, ads: false },
          crm: { total: 12, new: 4, contacted: 3, converted: 5, new_7d: 2 },
          journeys: { active: 2, total: 3, running: 7, completed_30d: 40 },
          ai: { available: 300, balance: 320 },
        }}
      />,
    );
    const widgets = screen.getByTestId('plan-widgets');
    expect(within(widgets).getByText('Growth')).toBeTruthy();
    expect(within(widgets).getByText('CRM leads')).toBeTruthy();
    expect(within(widgets).getByText('4 new · 5 converted')).toBeTruthy();
    expect(within(widgets).getByText('2 live')).toBeTruthy();
    expect(within(widgets).getByText('AI credits')).toBeTruthy();
    expect(within(widgets).queryByText('Ad spend (30d)')).toBeNull();
  });

  it('Super Admin attention items link to where they are handled', async () => {
    const onOpenExpiring = vi.fn();
    const user = userEvent.setup();
    wrap(
      <PlatformAttention
        platform={{ attention: { pending_invoices: 2, quota_requests: 0, contact_messages: 1, expiring_7d: 3 }, plan_split: [], top_clients: [] }}
        onOpenExpiring={onOpenExpiring}
      />,
    );
    expect(screen.getByText('6 open')).toBeTruthy();
    expect(screen.getByRole('link', { name: /Pending invoices/ }).getAttribute('href')).toBe('/billing');
    expect(screen.getByRole('link', { name: /Website messages/ }).getAttribute('href')).toBe('/admin/website');
    await user.click(screen.getByRole('button', { name: /Expiring in 7 days/ }));
    expect(onOpenExpiring).toHaveBeenCalled();
  });

  it('lists clients per plan and the busiest clients', () => {
    wrap(
      <PlatformPlansAndTopClients
        platform={{
          attention: { pending_invoices: 0, quota_requests: 0, contact_messages: 0, expiring_7d: 0 },
          plan_split: [{ plan_key: 'starter', label: 'Starter', clients: 5 }, { plan_key: null, label: 'No plan (custom / trial)', clients: 2 }],
          top_clients: [{ account_id: 9, company_name: 'Acme', messages_30d: 1200 }],
        }}
      />,
    );
    expect(within(screen.getByTestId('plan-split')).getByText('Starter')).toBeTruthy();
    expect(screen.getByText('7 clients')).toBeTruthy();
    expect(screen.getByText('1,200 msgs')).toBeTruthy();
  });

  it('agent sees commission and clients about to expire', () => {
    wrap(
      <AgentBusinessOverview
        agent={{ clients_total: 4, clients_active: 3, commission_month: 1500, commission_total: 9000, pending_invoices: 1, messages_30d: 800, expiring: [{ account_id: 2, company_name: 'Bright Tutors', expires_at: '2026-10-15T00:00:00Z' }] }}
      />,
    );
    expect(screen.getByText('₹1,500')).toBeTruthy();
    expect(screen.getByText('3 active')).toBeTruthy();
    expect(screen.getByText('Bright Tutors')).toBeTruthy();
  });
});
