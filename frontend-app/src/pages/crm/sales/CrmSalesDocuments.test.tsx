import { render, screen, waitFor, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { MemoryRouter, Route, Routes, useLocation } from 'react-router-dom';
import { beforeEach, describe, expect, it, vi, type Mock } from 'vitest';

import CrmDocumentDetailPage from './CrmDocumentDetailPage';
import CrmSalesSettingsPage from './CrmSalesSettingsPage';
import PublicDocumentPage from '../../public/PublicDocumentPage';
import crmDocumentsService from '../../../services/crmDocumentsService';
import type { CrmDocDetail } from '../../../types/crmSales';
import { loadRazorpayScript } from '../../../utils/loadRazorpayScript';
import { paginated } from '../../../test/crmFixtures';

/**
 * CRM Sales Phases 2–6 — the document page's actions, recording a payment, the gateway
 * settings' write-only secrets and the customer's public pay page. Rules are the
 * server's; these check the UI calls the right endpoint with the right payload.
 */

vi.mock('../../../services/crmDocumentsService', () => ({
  default: {
    getDocument: vi.fn(),
    issue: vi.fn(),
    changeStatus: vi.fn(),
    convert: vi.fn(),
    creditNote: vi.fn(),
    send: vi.fn(),
    remind: vi.fn(),
    downloadPdf: vi.fn(),
    listDocuments: vi.fn(),
    recordPayment: vi.fn(),
    getProfile: vi.fn(),
    saveProfile: vi.fn(),
    getGateway: vi.fn(),
    saveGateway: vi.fn(),
    getReminderSettings: vi.fn(),
    saveReminderSettings: vi.fn(),
    listReminders: vi.fn(),
    publicDocument: vi.fn(),
    publicPay: vi.fn(),
    publicConfirm: vi.fn(),
    publicPdfUrl: vi.fn(() => 'http://api/pdf'),
    documentReminders: vi.fn(),
    uploadAttachment: vi.fn(),
    downloadAttachment: vi.fn(),
  },
}));
const perms = { payments: true };
vi.mock('../../../core/context/AuthContext', () => ({
  useAuth: () => ({ isSuperAdmin: () => false, isReadOnly: () => false, user: { id: 1 }, hasPermission: (p: string) => p !== 'manage-crm-payments' || perms.payments }),
}));
vi.mock('../../../core/context/TenantContext', () => ({ useTenant: () => ({ selectedAccountId: null }) }));
vi.mock('../../../utils/loadRazorpayScript', () => ({ loadRazorpayScript: vi.fn() }));

const svc = crmDocumentsService as unknown as Record<string, Mock>;

function Probe() {
  return <div data-testid="location">{useLocation().pathname}</div>;
}

const doc = (o: Partial<CrmDocDetail> = {}): CrmDocDetail => ({
  id: 5, type: 'invoice', status: 'issued', number: 'INV/2026-27/0001', title: null, issue_date: '2026-10-14', valid_until: null, due_date: '2026-10-29',
  is_overdue: false, currency: 'INR', tax_mode: 'intra', grand_total: '1180.00', amount_paid: '0.00', amount_credited: '0.00', balance_due: '1180.00',
  parent_id: null, crm_deal_id: null, contact_id: 3, crm_company_id: null, customer_name: 'Rahul', external_reference: null, editable: false, created_at: null,
  place_of_supply: null, subtotal: '1000.00', discount_total: '0.00', taxable_total: '1000.00', cgst_total: '90.00', sgst_total: '90.00', igst_total: '0.00',
  tax_total: '180.00', round_off: '0.00', body: null, terms: null, notes: null, customer_snapshot: { phone: '919800000001', email: 'r@example.com' }, seller_snapshot: {},
  issued_at: null, cancel_reason: null, items: [{ id: 1, crm_product_id: null, description: 'Plan', hsn_sac: null, quantity: '1.000', unit: 'nos', unit_price: '1000.00', discount_amount: '0.00', tax_rate: '18.00', line_total: '1180.00' }],
  children: [], allocations: [], deliveries: [], share: { view_url: 'http://app/d/tok', pdf_url: 'http://api/pdf' },
  ...o,
});

function renderDoc() {
  return render(
    <MemoryRouter initialEntries={['/crm/documents/5']}>
      <Routes>
        <Route path="/crm/documents/:id" element={<CrmDocumentDetailPage />} />
      </Routes>
      <Probe />
    </MemoryRouter>,
  );
}

beforeEach(() => {
  vi.clearAllMocks();
  perms.payments = true;
  svc.listDocuments.mockResolvedValue(paginated([doc()]));
  svc.listReminders.mockResolvedValue(paginated([]));
  svc.publicPdfUrl.mockReturnValue('http://api/pdf');
  svc.documentReminders.mockResolvedValue([]);
});

describe('document page', () => {
  it('shows the server totals and GST split, and issues a draft only after confirmation', async () => {
    svc.getDocument.mockResolvedValue(doc({ status: 'draft', number: null, editable: true }));
    svc.issue.mockResolvedValue({ message: 'Issued as INV/2026-27/0001.', data: doc() });
    const user = userEvent.setup();
    renderDoc();

    const totals = await screen.findByTestId('crm-doc-totals');
    expect(within(totals).getByText('CGST')).toBeInTheDocument();
    expect(within(totals).getByText('₹1,180.00')).toBeInTheDocument();
    await user.click(screen.getByRole('button', { name: /issue/i }));
    expect(svc.issue).not.toHaveBeenCalled();
    await user.click(within(screen.getByText(/becomes final/i).closest('div')!.parentElement!).getByRole('button', { name: 'Issue' }));
    await waitFor(() => expect(svc.issue).toHaveBeenCalledWith(5));
  });

  it('records a payment applied to the invoice with one idempotency key', async () => {
    svc.getDocument.mockResolvedValue(doc());
    svc.recordPayment.mockResolvedValue({ message: 'Payment RCPT/2026-27/0001 recorded.', data: {} });
    const user = userEvent.setup();
    renderDoc();

    await user.click(await screen.findByRole('button', { name: /record payment/i }));
    const modal = screen.getByTestId('crm-record-payment');
    await user.clear(within(modal).getByLabelText('Amount received'));
    await user.type(within(modal).getByLabelText('Amount received'), '500');
    const apply = await within(modal).findByLabelText('Apply to INV/2026-27/0001');
    await user.clear(apply);
    await user.type(apply, '500');
    await user.click(within(modal).getByRole('button', { name: 'Record payment' }));

    await waitFor(() => expect(svc.recordPayment).toHaveBeenCalledTimes(1));
    const payload = svc.recordPayment.mock.calls[0][0];
    expect(payload).toMatchObject({ amount: '500', method: 'upi', contact_id: 3, allocations: [{ crm_document_id: 5, amount: '500' }] });
    expect(payload.idempotency_key).toMatch(/^pay-/);
  });

  it('hides payment actions without the payments permission and voids with a reason', async () => {
    perms.payments = false;
    svc.getDocument.mockResolvedValue(doc());
    svc.changeStatus.mockResolvedValue({ message: 'Status updated.', data: doc({ status: 'void' }) });
    const user = userEvent.setup();
    renderDoc();

    await screen.findByTestId('crm-doc-totals');
    expect(screen.queryByRole('button', { name: /record payment/i })).not.toBeInTheDocument();
    await user.click(screen.getByRole('button', { name: 'Void' }));
    await user.keyboard('Wrong customer');
    await user.click(screen.getByRole('button', { name: 'Confirm' }));
    await waitFor(() => expect(svc.changeStatus).toHaveBeenCalledWith(5, 'void', 'Wrong customer'));
  });

  it('sends on WhatsApp to the customer number by default', async () => {
    svc.getDocument.mockResolvedValue(doc());
    svc.send.mockResolvedValue({ message: 'Sent.', data: { id: 1, status: 'sent' } });
    const user = userEvent.setup();
    renderDoc();

    await user.click(await screen.findByRole('button', { name: /^send$/i }));
    const modal = screen.getByTestId('crm-send-document');
    expect(within(modal).getByDisplayValue('919800000001')).toBeInTheDocument();
    await user.click(within(modal).getByRole('button', { name: 'Send' }));
    await waitFor(() => expect(svc.send).toHaveBeenCalledWith(5, { channel: 'whatsapp', recipient: '919800000001', message: null, how: null }));
  });
});

describe('sales settings', () => {
  it('never shows a saved secret and sends a blank secret as "keep"', async () => {
    svc.getProfile.mockResolvedValue({ legal_name: null, default_legal_name: 'Acme', gstin: null, state: null, state_code: null, address_line1: null, address_line2: null, city: null, pincode: null, phone: null, email: null, bank_details: null, upi_id: null, default_terms: null, round_off: true, default_due_days: 15 });
    svc.getGateway.mockResolvedValue({ gateway: 'razorpay', mode: 'test', key_id: 'rzp_test_1', is_enabled: true, has_key_secret: true, has_webhook_secret: true, webhook_url: 'http://api/webhooks/crm-payments/tok' });
    svc.getReminderSettings.mockResolvedValue({ enabled: false, days_before: [3], on_due_date: true, days_after: [3, 7], channel: 'whatsapp', auto_send: true, email_fallback: false, quiet_start_hour: null, quiet_end_hour: null, max_attempts: 3 });
    svc.saveGateway.mockResolvedValue({ message: 'Payment gateway saved.', data: { gateway: 'razorpay', mode: 'test', key_id: 'rzp_test_1', is_enabled: true, has_key_secret: true, has_webhook_secret: true, webhook_url: 'x' } });
    const user = userEvent.setup();
    render(
      <MemoryRouter>
        <CrmSalesSettingsPage />
      </MemoryRouter>,
    );

    await screen.findByText(/webhook url/i);
    expect(screen.getByText('http://api/webhooks/crm-payments/tok')).toBeInTheDocument();
    const saves = screen.getAllByRole('button', { name: 'Save' });
    await user.click(saves[1]);
    await waitFor(() => expect(svc.saveGateway).toHaveBeenCalledWith({ gateway: 'razorpay', mode: 'test', key_id: 'rzp_test_1', key_secret: null, webhook_secret: null, is_enabled: true }));
  });
});

describe('public document page', () => {
  it('pays the balance through Razorpay and confirms the signed result with the server', async () => {
    svc.publicDocument.mockResolvedValue({
      type: 'invoice', number: 'INV/2026-27/0001', status: 'issued', issue_date: '2026-10-14', valid_until: null, due_date: '2026-10-29', currency: 'INR',
      seller: { name: 'Acme', address: [] }, customer: { name: 'Rahul' }, items: [], taxable_total: '1000.00', tax_total: '180.00', grand_total: '1180.00',
      amount_paid: '0.00', balance_due: '1180.00', payable_online: true, body: null, terms: null,
    });
    svc.publicPay.mockResolvedValue({ gateway: 'razorpay', key_id: 'rzp_test_1', order_id: 'order_1', client_secret: null, amount_minor: 118000, amount: '1180.00', currency: 'INR', invoice_number: 'INV/2026-27/0001', seller_name: 'Acme' });
    svc.publicConfirm.mockResolvedValue({ message: 'Payment received. Thank you!', data: { receipt: 'RCPT/2026-27/0001' } });
    (loadRazorpayScript as unknown as Mock).mockResolvedValue(true);
    let options: { handler: (r: Record<string, string>) => void; amount: number; order_id: string } | null = null;
    window.Razorpay = vi.fn().mockImplementation((o) => {
      options = o;
      return { open: () => undefined };
    }) as unknown as typeof window.Razorpay;
    const user = userEvent.setup();
    render(
      <MemoryRouter initialEntries={['/d/tok-123']}>
        <Routes>
          <Route path="/d/:token" element={<PublicDocumentPage />} />
        </Routes>
      </MemoryRouter>,
    );

    await user.click(await screen.findByRole('button', { name: /pay ₹1,180.00 now/i }));
    await waitFor(() => expect(options).not.toBeNull());
    expect(options!.amount).toBe(118000);
    expect(options!.order_id).toBe('order_1');
    options!.handler({ razorpay_order_id: 'order_1', razorpay_payment_id: 'pay_1', razorpay_signature: 'sig' });
    await waitFor(() => expect(svc.publicConfirm).toHaveBeenCalledWith('tok-123', { razorpay_order_id: 'order_1', razorpay_payment_id: 'pay_1', razorpay_signature: 'sig' }));
    expect(await screen.findByRole('status')).toHaveTextContent('RCPT/2026-27/0001');
  });
});
