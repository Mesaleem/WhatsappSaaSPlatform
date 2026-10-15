import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { MemoryRouter } from 'react-router-dom';
import { beforeEach, describe, expect, it, vi, type Mock } from 'vitest';

import PlanManagementPage from './PlanManagementPage';
import planService from '../../services/planService';
import accountService from '../../services/accountService';
import axiosInstance from '../../core/api/axiosInstance';
import type { ManagedPlansResponse } from '../../types/plan';
import { DEFAULT_PRICES } from './planPricing.fixtures';

/**
 * Plan restructure (2026-10-15), step 4 — custom plans in Plan Management:
 * a plan built for one client (only the features it needs, its own limits
 * and price), opened pre-filled from a Contact sales request, and invoiced
 * to that client.
 */

vi.mock('../../services/planService', () => ({ default: { list: vi.fn(), create: vi.fn(), update: vi.fn(), getPricing: vi.fn(), updatePricing: vi.fn() } }));
vi.mock('../../services/accountService', () => ({ default: { list: vi.fn() } }));

const plans = planService as unknown as { list: Mock; create: Mock; update: Mock; getPricing: Mock; updatePricing: Mock };

const response: ManagedPlansResponse = {
  data: [
    {
      slug: 'custom-7-abc',
      label: 'Acme CRM + Ads',
      price: 3500,
      duration_days: 30,
      description: null,
      engine_type: 'meta',
      billing_model: 'flat_quota',
      rate_per_message: null,
      total_allocated_messages: 3000,
      included_credits: 0,
      is_active: true,
      is_public: false,
      capabilities: ['crm', 'ads'],
      capability_limits: {},
      accounts: 0,
      limits: { max_users: 4, max_meta_templates: 5, max_flows: 0, max_broadcasts_per_month: 2, marketing_message_limit: 1000 },
      account: { id: 7, company_name: 'Acme Traders' },
    },
  ],
  available_capabilities: [
    { slug: 'crm', label: 'CRM', category: 'growth', unsupported_providers: [] },
    { slug: 'ads', label: 'Ads', category: 'growth', unsupported_providers: ['qr'] },
  ],
};

beforeEach(() => {
  vi.clearAllMocks();
  plans.list.mockResolvedValue(structuredClone(response));
  plans.getPricing.mockResolvedValue(structuredClone(DEFAULT_PRICES));
  plans.create.mockResolvedValue({ message: 'Plan created.' });
  (accountService.list as Mock).mockResolvedValue({ data: [{ id: 7, company_name: 'Acme Traders' }, { id: 9, company_name: 'Beta Ltd' }] });
  vi.spyOn(axiosInstance, 'post').mockResolvedValue({ data: { message: 'Invoice created.' } });
});

describe('Custom plans', () => {
  it('shows a custom plan with its client, limits and a send-invoice action', async () => {
    const user = userEvent.setup();
    render(<MemoryRouter><PlanManagementPage /></MemoryRouter>);

    const row = await screen.findByTestId('plan-row-custom-7-abc');
    expect(row.textContent).toContain('Custom: Acme Traders');
    expect(row.textContent).toContain('4 team users');
    expect(row.textContent).toContain('No journeys / flows');

    await user.click(screen.getByTestId('invoice-plan-custom-7-abc'));
    expect(axiosInstance.post).toHaveBeenCalledWith('/billing/manual-checkout', { plan_key: 'custom-7-abc' }, { params: { account_id: 7 } });
    expect(await screen.findByText('Invoice created. (Acme Traders)')).toBeTruthy();
  });

  it('builds a custom plan for one client with only some features and its own limits', async () => {
    const user = userEvent.setup();
    render(<MemoryRouter><PlanManagementPage /></MemoryRouter>);
    await screen.findByTestId('plan-row-custom-7-abc');

    await user.click(screen.getByTestId('open-create-plan'));
    await user.type(screen.getByTestId('plan-slug'), 'beta-crm');
    await user.type(screen.getByTestId('plan-label'), 'Beta CRM only');
    await user.type(screen.getByTestId('plan-price'), '1200');
    await user.selectOptions(screen.getByTestId('plan-engine'), 'none');
    await screen.findByRole('option', { name: 'Beta Ltd' });
    await user.selectOptions(screen.getByTestId('plan-account'), '9');
    expect((screen.getByTestId('plan-public') as HTMLInputElement).disabled).toBe(true);
    await user.type(screen.getByTestId('plan-limit-max_users'), '6');
    await user.type(screen.getByTestId('plan-limit-max_broadcasts_per_month'), '0');
    await user.click(screen.getByTestId('plan-save'));

    expect(plans.create).toHaveBeenCalledWith(
      expect.objectContaining({
        slug: 'beta-crm',
        engine_type: 'none',
        account_id: 9,
        is_public: false,
        max_users: 6,
        max_broadcasts_per_month: 0,
        max_flows: null,
      }),
    );
  });

  it('opens a pre-filled custom plan from a Contact sales request link', async () => {
    render(
      <MemoryRouter initialEntries={['/admin/plans?custom_for=7']}>
        <PlanManagementPage />
      </MemoryRouter>,
    );

    await screen.findByTestId('plan-form-modal');
    expect((screen.getByTestId('plan-account') as HTMLSelectElement).value).toBe('7');
    expect((screen.getByTestId('plan-slug') as HTMLInputElement).value).toMatch(/^custom-7-/);
    expect((screen.getByTestId('plan-public') as HTMLInputElement).checked).toBe(false);
  });
});
