import { render, screen, waitFor, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { MemoryRouter } from 'react-router-dom';
import { beforeEach, describe, expect, it, vi, type Mock } from 'vitest';

import BillingPage from './BillingPage';
import billingService from '../../services/billingService';
import type { Plan, PlansResponse } from '../../types/billing';

/**
 * Plan purchase rules (owner, 2026-10-14) on the Billing page: the running plan is not
 * offered again, a bigger plan is an "Upgrade" that warns first, a pending request shows
 * "Pay invoice" + "Cancel request", and "Contact sales" sends the custom-plan form.
 */

vi.mock('../../services/billingService', () => ({
  default: {
    getPlans: vi.fn(),
    getInvoices: vi.fn(),
    cancelInvoice: vi.fn(),
    manualCheckout: vi.fn(),
    createOrder: vi.fn(),
    requestCustomPlan: vi.fn(),
    customPlanRequests: vi.fn(),
  },
}));
vi.mock('../../core/context/AuthContext', () => ({
  useAuth: () => ({
    user: { id: 1, name: 'Asha', email: 'asha@example.com', account: { id: 7, company_name: 'Acme', account_type: 'client', current_subscription: null } },
    isSuperAdmin: () => false,
    hasRole: () => false,
  }),
}));
vi.mock('../../components/billing/AmountsDueCard', () => ({ default: () => null }));
vi.mock('../../components/billing/PendingInvoicesCard', () => ({ default: () => null }));
vi.mock('../../components/billing/useOnlineInvoicePayment', () => ({ useOnlineInvoicePayment: () => ({ payOnline: vi.fn(), payingId: null, stripeCard: null }) }));

const svc = billingService as unknown as Record<string, Mock>;

const plan = (key: string, label: string, price: string, purchase: Plan['purchase']): Plan => ({
  key, label, description: '', engine_type: 'qr', billing_model: 'unlimited', total_allocated_messages: null, duration_days: 30,
  price, tax_amount: '0.00', total_amount: price, purchase,
});

const response = (o: Partial<PlansResponse> = {}): PlansResponse => ({
  plans: [
    plan('starter', 'Starter', '499.00', { action: 'renew_after_expiry', allowed: false, message: 'This plan is already running until 30 Oct 2026. You can renew it after it ends.' }),
    plan('growth', 'Growth', '1999.00', { action: 'upgrade', allowed: true, message: 'Upgrading ends your current plan as soon as this payment is made.' }),
  ],
  plan_status: { running: true, expires_at: '2026-10-30T00:00:00Z', plan_key: 'starter', plan_label: 'Starter', pending_invoice: null },
  available_gateways: [],
  tax_rate: 0,
  ...o,
});

function renderPage() {
  return render(
    <MemoryRouter>
      <BillingPage />
    </MemoryRouter>,
  );
}

beforeEach(() => {
  vi.clearAllMocks();
  svc.getInvoices.mockResolvedValue({ data: [], current_page: 1, last_page: 1, per_page: 10, total: 0 });
  svc.getPlans.mockResolvedValue(response());
});

describe('billing plan rules', () => {
  it('marks the running plan as current, not purchasable, and warns before an upgrade', async () => {
    svc.manualCheckout.mockResolvedValue({ message: 'Invoice created.', invoice: {} });
    const user = userEvent.setup();
    renderPage();

    expect(await screen.findByText('Current plan', { selector: 'span' })).toBeInTheDocument();
    expect(screen.getByRole('button', { name: 'Current plan' })).toBeDisabled();
    expect(screen.getByText(/renew it after it ends/i)).toBeInTheDocument();
    expect(screen.getAllByText('/ month').length).toBeGreaterThan(0);

    await user.click(screen.getByRole('button', { name: 'Upgrade' }));
    expect(svc.manualCheckout).not.toHaveBeenCalled();
    expect(screen.getByText(/ends your current plan as soon as this payment is made/i)).toBeInTheDocument();
    await user.click(screen.getByRole('button', { name: 'Upgrade now' }));
    await waitFor(() => expect(svc.manualCheckout).toHaveBeenCalledWith('growth'));
  });

  it('shows a pending request with Pay invoice and Cancel request', async () => {
    svc.getPlans.mockResolvedValue(
      response({
        plans: [plan('growth', 'Growth', '1999.00', { action: 'pending', allowed: false, message: 'You already requested the Growth plan.' })],
        plan_status: { running: false, expires_at: null, plan_key: null, plan_label: null, pending_invoice: { id: 44, invoice_number: 'INV-7-1', plan_key: 'growth', plan_label: 'Growth', total_amount: '1999.00', plan_change: 'new' } },
      }),
    );
    svc.cancelInvoice.mockResolvedValue({ message: 'Plan request cancelled.' });
    const user = userEvent.setup();
    renderPage();

    const banner = await screen.findByTestId('pending-plan-request');
    expect(within(banner).getByRole('button', { name: 'Pay invoice' })).toBeInTheDocument();
    expect(screen.getByRole('button', { name: 'Request pending' })).toBeDisabled();
    await user.click(within(banner).getByRole('button', { name: 'Cancel request' }));
    await waitFor(() => expect(svc.cancelInvoice).toHaveBeenCalledWith(44));
    expect(await screen.findByText('Plan request cancelled.')).toBeInTheDocument();
  });

  it('sends the custom plan request from Contact sales', async () => {
    svc.requestCustomPlan.mockResolvedValue({ message: 'Thank you! Our team will contact you within 24–48 hours.' });
    const user = userEvent.setup();
    renderPage();

    await user.click(await screen.findByRole('button', { name: 'Contact sales' }));
    const modal = screen.getByTestId('contact-sales-modal');
    expect(within(modal).getByDisplayValue('asha@example.com')).toBeInTheDocument();
    await user.type(within(modal).getByLabelText(/your requirement/i), 'Yearly plan with 5 numbers');
    // The phone number is required before the request can be sent.
    expect(within(modal).getByRole('button', { name: 'Send request' })).toBeDisabled();
    await user.type(within(modal).getByLabelText(/phone/i), '12345');
    expect(within(modal).getByText(/valid phone number/i)).toBeInTheDocument();
    await user.clear(within(modal).getByLabelText(/phone/i));
    await user.type(within(modal).getByLabelText(/phone/i), '+91 98765 43210');
    await user.click(within(modal).getByRole('button', { name: 'Send request' }));

    await waitFor(() => expect(svc.requestCustomPlan).toHaveBeenCalledWith(expect.objectContaining({ name: 'Asha', email: 'asha@example.com', phone: '+91 98765 43210', company: 'Acme', requirement: 'Yearly plan with 5 numbers' })));
    expect(await screen.findByText(/24–48 hours/)).toBeInTheDocument();
  });
});
