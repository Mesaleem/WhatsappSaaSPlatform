import { render, screen, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { beforeEach, describe, expect, it, vi, type Mock } from 'vitest';

import CreateAccountModal from './CreateAccountModal';
import accountService from '../../services/accountService';
import type { ClientPlanOption } from '../../types/account';

vi.mock('../../services/accountService', () => ({
  default: { planOptions: vi.fn(), create: vi.fn(), listAgents: vi.fn() },
}));

const auth = { superAdmin: true };
vi.mock('../../core/context/AuthContext', () => ({
  useAuth: () => ({
    user: auth.superAdmin ? { id: 1, account: null } : { id: 2, account: { id: 9, account_type: 'agent' } },
    isSuperAdmin: () => auth.superAdmin,
    hasModule: () => true,
  }),
}));

const starter: ClientPlanOption = {
  key: 'starter', label: 'Starter', description: null, engine_type: 'qr', billing_model: 'unlimited',
  total_allocated_messages: null, duration_days: 30, price: 507.63, tax_amount: 91.37, total_amount: 599,
  capabilities: [{ slug: 'whatsapp_send', label: 'WhatsApp Messaging' }],
  modules: ['dashboard', 'analytics', 'billing', 'team_management', 'notifications', 'whatsapp_setup', 'send_alert'],
};
const growth: ClientPlanOption = {
  ...starter, key: 'growth', label: 'Growth', price: 1999, tax_amount: 359.82, total_amount: 2358.82,
  capabilities: [{ slug: 'crm', label: 'CRM' }],
};

async function fillClient(user: ReturnType<typeof userEvent.setup>) {
  await user.type(screen.getByPlaceholderText('Acme Textiles Pvt. Ltd.'), 'Acme');
  await user.type(screen.getByPlaceholderText('Jane Doe'), 'Asha');
  await user.type(screen.getByPlaceholderText('jane@acme.com'), 'asha@acme.test');
  await user.type(screen.getByPlaceholderText('••••••••'), 'secret-pass');
}

describe('Create Client — plan', () => {
  beforeEach(() => {
    vi.clearAllMocks();
    auth.superAdmin = true;
    (accountService.planOptions as Mock).mockResolvedValue({ data: [starter, growth], tax_rate: 0.18 });
    (accountService.create as Mock).mockResolvedValue({ id: 5 });
    (accountService.listAgents as Mock).mockResolvedValue([]);
  });

  it('defaults to the first plan, shows its terms and sends the plan instead of manual terms', async () => {
    const user = userEvent.setup();
    const onSaved = vi.fn();
    render(<CreateAccountModal account={null} onClose={vi.fn()} onSaved={onSaved} />);

    const summary = await screen.findByTestId('plan-summary');
    expect(within(summary).getByText('Starter')).toBeTruthy();
    expect(within(summary).getByText('Unlimited messages')).toBeTruthy();
    expect((screen.getByLabelText('Amount paid (incl. GST)') as HTMLInputElement).value).toBe('599');
    // The manual subscription fields are hidden in plan mode.
    expect(screen.queryByText('Billing Model')).toBeNull();

    await user.selectOptions(screen.getByLabelText('Plan'), 'growth');
    expect((screen.getByLabelText('Amount paid (incl. GST)') as HTMLInputElement).value).toBe('2358.82');
    await user.clear(screen.getByLabelText('Amount paid (incl. GST)'));
    await user.type(screen.getByLabelText('Amount paid (incl. GST)'), '2000');
    await user.selectOptions(screen.getByLabelText('Payment method'), 'upi');
    await user.type(screen.getByLabelText('Receipt / transaction ID'), 'UPI-1');
    await fillClient(user);
    await user.click(screen.getByRole('button', { name: 'Create Client' }));

    const payload = (accountService.create as Mock).mock.calls[0][0];
    expect(payload).toMatchObject({ plan_key: 'growth', plan_total: 2000, payment_method: 'upi', transaction_id: 'UPI-1', allowed_modules: growth.modules });
    expect(payload.engine_type).toBeUndefined();
    expect(payload.expires_at).toBeUndefined();
    expect(onSaved).toHaveBeenCalled();
  });

  it('lets the Super Admin switch to Custom terms', async () => {
    const user = userEvent.setup();
    render(<CreateAccountModal account={null} onClose={vi.fn()} onSaved={vi.fn()} />);

    await screen.findByTestId('plan-summary');
    await user.selectOptions(screen.getByLabelText('Plan'), 'custom');

    expect(screen.queryByTestId('plan-summary')).toBeNull();
    expect(screen.getByText('Billing Model')).toBeTruthy();
  });

  it('does not offer Custom terms to an Agent', async () => {
    auth.superAdmin = false;
    render(<CreateAccountModal account={null} onClose={vi.fn()} onSaved={vi.fn()} />);

    await screen.findByTestId('plan-summary');
    const options = within(screen.getByLabelText('Plan')).getAllByRole('option').map((o) => (o as HTMLOptionElement).value);
    expect(options).toEqual(['starter', 'growth']);
  });
});
