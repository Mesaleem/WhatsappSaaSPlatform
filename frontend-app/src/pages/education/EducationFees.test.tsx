import { act, fireEvent, render, screen, waitFor, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { MemoryRouter, Route, Routes } from 'react-router-dom';
import { beforeEach, describe, expect, it, vi, type Mock } from 'vitest';

import EducationPage from './EducationPage';
import educationService from '../../services/educationService';
import { apiError, makeFeeItem, makeFeePayment, makeGroup, makeLedger, makeStudent, makeStudentFee, paginated } from '../../test/educationFixtures';

/**
 * Phase 11 Task 4 — the Fees tab: fee catalogue, a student's charges with server-computed balances,
 * assigning, recording full / partial payments, history, read-only, client switching, stale responses.
 */

vi.mock('../../services/educationService', () => ({
  default: {
    listStudents: vi.fn(), createStudent: vi.fn(), updateStudent: vi.fn(),
    listGroups: vi.fn(), createGroup: vi.fn(), updateGroup: vi.fn(),
    attendanceSheet: vi.fn(), saveAttendance: vi.fn(), studentAttendance: vi.fn(),
    listFeeItems: vi.fn(), createFeeItem: vi.fn(), updateFeeItem: vi.fn(),
    studentFees: vi.fn(), assignFee: vi.fn(), recordFeePayment: vi.fn(), studentFeePayments: vi.fn(),
  },
}));
const industry = { context: vi.fn() };
vi.mock('../../services/industryService', () => ({ default: { context: (...a: unknown[]) => industry.context(...a) } }));

const BASE_KEYS = ['education.students', 'education.batches', 'education.attendance'];
const auth = { superAdmin: false, readOnly: false, permissions: ['view-education', 'manage-education'], keys: [...BASE_KEYS, 'education.fees'] };
vi.mock('../../core/context/AuthContext', () => ({
  useAuth: () => ({
    isSuperAdmin: () => auth.superAdmin,
    isReadOnly: () => auth.readOnly,
    hasPermission: (p: string) => auth.permissions.includes(p),
    user: { platform_crm_account: null, industry_modules: auth.keys },
  }),
}));
const tenant: { selectedAccountId: number | null } = { selectedAccountId: null };
vi.mock('../../core/context/TenantContext', () => ({
  useTenant: () => ({ selectedAccountId: tenant.selectedAccountId, accounts: [], selectAccount: vi.fn() }),
}));

const edu = educationService as unknown as Record<string, Mock>;

const tuition = makeFeeItem({ id: 1, name: 'Tuition', amount: '5000.00', frequency: 'quarterly', description: 'Term fee' });
const bus = makeFeeItem({ id: 2, name: 'Bus', amount: '300.00' });
const oldFee = makeFeeItem({ id: 3, name: 'Old fee', status: 'archived' });
const asha = makeStudent({ id: 1, contact: { id: 101, name: 'Asha', phone_number: '919800000001', email: null } });
const bala = makeStudent({ id: 2, contact: { id: 102, name: 'Bala', phone_number: '919800000002', email: null } });

const openFee = makeStudentFee({ id: 11, charge_name: 'Tuition', amount_due: '5000.00', outstanding: '5000.00' });
const partialFee = makeStudentFee({ id: 12, charge_name: 'Bus', amount_due: '300.00', amount_paid: '100.00', outstanding: '200.00', status: 'partially_paid', due_date: null });
const paidFee = makeStudentFee({ id: 13, charge_name: 'Lab', amount_due: '100.00', amount_paid: '100.00', outstanding: '0.00', status: 'paid' });
const overdueFee = makeStudentFee({ id: 14, charge_name: 'Exam', amount_due: '50.00', outstanding: '50.00', status: 'overdue', due_date: '2026-01-01' });

function tree(tab = 'fees') {
  return (
    <MemoryRouter initialEntries={[`/education?tab=${tab}`]}>
      <Routes>
        <Route path="/education" element={<EducationPage />} />
      </Routes>
    </MemoryRouter>
  );
}

async function openStudent(name = 'Asha') {
  const view = render(tree());
  await screen.findByRole('option', { name });
  fireEvent.change(screen.getByLabelText('Student'), { target: { value: String(name === 'Asha' ? 1 : 2) } });
  return view;
}

beforeEach(() => {
  auth.superAdmin = false;
  auth.readOnly = false;
  auth.permissions = ['view-education', 'manage-education'];
  auth.keys = [...BASE_KEYS, 'education.fees'];
  tenant.selectedAccountId = null;
  Object.values(edu).forEach((m) => m.mockReset());
  industry.context.mockReset();
  edu.listStudents.mockResolvedValue(paginated([asha, bala]));
  edu.listGroups.mockResolvedValue(paginated([makeGroup({ id: 5 })]));
  edu.listFeeItems.mockResolvedValue(paginated([tuition, bus, oldFee]));
  edu.studentFees.mockResolvedValue(makeLedger([openFee, partialFee, paidFee, overdueFee], { total_due: '5450.00', total_paid: '200.00', outstanding: '5250.00', overdue: '50.00' }));
  edu.studentFeePayments.mockResolvedValue({ ...paginated([makeFeePayment({ id: 1, amount: '100.00', charge_name: 'Bus', payment_method: 'upi', reference: 'UPI-9' })]), per_page: 20 });
  edu.assignFee.mockResolvedValue({ message: 'Charge assigned.', data: openFee });
  edu.recordFeePayment.mockResolvedValue({ message: 'Payment recorded.', idempotent: false, payment: makeFeePayment(), data: openFee });
  edu.createFeeItem.mockResolvedValue({ message: 'Created.', data: tuition });
  edu.updateFeeItem.mockResolvedValue({ message: 'Updated.', data: tuition });
});

describe('fees tab visibility', () => {
  it('shows the Fees tab only when the module is usable (same keys as the backend)', async () => {
    auth.keys = BASE_KEYS;
    render(tree());
    await screen.findByTestId('students-panel'); // ?tab=fees falls back to Students

    expect(screen.queryByRole('tab', { name: 'Fees' })).toBeNull();
    expect(screen.queryByTestId('fees-tab')).toBeNull();
    expect(edu.listFeeItems).not.toHaveBeenCalled();
  });

  it('shows it, and opens on the requested tab', async () => {
    render(tree());

    expect(await screen.findByTestId('fees-tab')).toBeTruthy();
    expect(screen.getByRole('tab', { name: 'Fees' }).getAttribute('aria-selected')).toBe('true');
  });
});

describe('fee catalogue', () => {
  it('lists the fees with amount, frequency and status', async () => {
    render(tree());

    const row = await screen.findByTestId('fee-item-row-1');
    expect(within(row).getByText('Tuition')).toBeTruthy();
    expect(within(row).getByText('5000.00')).toBeTruthy();
    expect(within(row).getByText('Quarterly')).toBeTruthy();
    expect(within(screen.getByTestId('fee-item-row-3')).getByText('archived')).toBeTruthy();
  });

  it('shows loading, empty and error states', async () => {
    let resolve!: (v: unknown) => void;
    edu.listFeeItems.mockReturnValueOnce(new Promise((r) => { resolve = r; }));
    const view = render(tree());
    await waitFor(() => expect(document.querySelectorAll('tr.animate-pulse').length).toBeGreaterThan(0));
    await act(async () => resolve(paginated([])));
    expect(await screen.findByText('No fees yet.')).toBeTruthy();
    view.unmount();

    edu.listFeeItems.mockRejectedValue(apiError(500));
    render(tree());
    expect(await screen.findByRole('alert')).toBeTruthy();
  });

  it('creates a fee and re-reads the list', async () => {
    const user = userEvent.setup();
    render(tree());
    await screen.findByTestId('fee-item-row-1');

    await user.click(screen.getByRole('button', { name: /new fee/i }));
    const form = screen.getByTestId('fee-item-form');
    await user.type(within(form).getByLabelText(/name/i), 'Uniform');
    await user.type(within(form).getByLabelText(/amount/i), '450.50');
    await user.click(within(form).getByRole('button', { name: 'Create' }));

    await waitFor(() => expect(edu.createFeeItem).toHaveBeenCalledWith({ name: 'Uniform', description: null, amount: '450.50', frequency: 'one_time', status: 'active' }));
    await waitFor(() => expect(edu.listFeeItems).toHaveBeenCalledTimes(2));
    expect(await screen.findByText('Created.')).toBeTruthy();
    expect(screen.queryByTestId('fee-item-form')).toBeNull();
  });

  it('validates the amount locally and makes no request', async () => {
    const user = userEvent.setup();
    render(tree());
    await screen.findByTestId('fee-item-row-1');
    await user.click(screen.getByRole('button', { name: /new fee/i }));
    const form = screen.getByTestId('fee-item-form');
    await user.type(within(form).getByLabelText(/name/i), 'Uniform');

    for (const bad of ['', '0', '12.345', 'abc', '-5']) {
      fireEvent.change(within(form).getByLabelText(/amount/i), { target: { value: bad } });
      await user.click(within(form).getByRole('button', { name: 'Create' }));
      expect(within(form).getByText(/enter an amount|greater than zero|decimal places/i)).toBeTruthy();
    }
    expect(edu.createFeeItem).not.toHaveBeenCalled();
  });

  it('shows the server field error (duplicate name) and keeps the form open', async () => {
    const user = userEvent.setup();
    edu.createFeeItem.mockRejectedValue(apiError(422, { message: 'x', errors: { name: ['A charge with this name already exists.'] } }));
    render(tree());
    await screen.findByTestId('fee-item-row-1');
    await user.click(screen.getByRole('button', { name: /new fee/i }));
    const form = screen.getByTestId('fee-item-form');
    await user.type(within(form).getByLabelText(/name/i), 'Tuition');
    await user.type(within(form).getByLabelText(/amount/i), '10');
    await user.click(within(form).getByRole('button', { name: 'Create' }));

    expect(await within(form).findByText('A charge with this name already exists.')).toBeTruthy();
    expect(screen.getByTestId('fee-item-form')).toBeTruthy();
  });

  it('edits a fee, including archiving it', async () => {
    const user = userEvent.setup();
    render(tree());
    const row = await screen.findByTestId('fee-item-row-2');

    await user.click(within(row).getByRole('button', { name: 'Edit' }));
    const form = screen.getByTestId('fee-item-form');
    fireEvent.change(within(form).getByLabelText('Amount *'), { target: { value: '350' } });
    fireEvent.change(within(form).getByLabelText(/status/i), { target: { value: 'archived' } });
    await user.click(within(form).getByRole('button', { name: 'Save changes' }));

    await waitFor(() => expect(edu.updateFeeItem).toHaveBeenCalledWith(2, { name: 'Bus', description: null, amount: '350', frequency: 'one_time', status: 'archived' }));
    expect(await screen.findByText('Updated.')).toBeTruthy();
  });
});

describe('a student\'s fees', () => {
  it('asks for a student first, then shows server-computed totals, rows and statuses', async () => {
    render(tree());
    expect(await screen.findByText('Select a student to see their fees.')).toBeTruthy();
    expect(edu.studentFees).not.toHaveBeenCalled();

    await screen.findByRole('option', { name: 'Asha' });
    fireEvent.change(screen.getByLabelText('Student'), { target: { value: '1' } });
    const totals = within(await screen.findByTestId('fee-totals'));
    expect(edu.studentFees).toHaveBeenCalledWith(1);
    expect(totals.getByText('Total due').nextSibling?.textContent).toBe('5450.00');
    expect(totals.getByText('Paid').nextSibling?.textContent).toBe('200.00');
    expect(totals.getByText('Outstanding').nextSibling?.textContent).toBe('5250.00');
    expect(totals.getByText('Overdue').nextSibling?.textContent).toBe('50.00');
    expect(within(screen.getByTestId('student-fee-row-12')).getByText('Partially paid')).toBeTruthy();
    expect(within(screen.getByTestId('student-fee-row-13')).getByText('Paid')).toBeTruthy();
    expect(within(screen.getByTestId('student-fee-row-14')).getByText('Overdue')).toBeTruthy();
    expect(within(screen.getByTestId('student-fee-row-13')).queryByRole('button', { name: 'Record payment' })).toBeNull(); // fully paid
  });

  it('shows a cancelled charge as Cancelled with no outstanding amount and no payment action', async () => {
    const cancelledFee = makeStudentFee({ id: 15, charge_name: 'Trip', amount_due: '700.00', outstanding: '0.00', status: 'cancelled', cancelled_at: '2026-10-01T09:00:00+00:00', cancellation_reason: 'duplicate' });
    edu.studentFees.mockResolvedValue(makeLedger([openFee, cancelledFee], { total_due: '5000.00', total_paid: '0.00', outstanding: '5000.00', overdue: '0.00', count: 1 }));
    render(tree());
    await screen.findByRole('option', { name: 'Asha' });
    fireEvent.change(screen.getByLabelText('Student'), { target: { value: '1' } });
    const row = within(await screen.findByTestId('student-fee-row-15'));
    expect(row.getByText('Cancelled')).toBeTruthy();
    expect(row.queryByRole('button', { name: 'Record payment' })).toBeNull();
    expect(within(screen.getByTestId('student-fee-row-11')).getByRole('button', { name: 'Record payment' })).toBeTruthy();
    expect(within(screen.getByTestId('fee-totals')).getByText('Outstanding').nextSibling?.textContent).toBe('5000.00'); // server totals, cancelled excluded
  });

  it('shows empty, loading and error states', async () => {
    edu.studentFees.mockResolvedValue(makeLedger([]));
    const view = await openStudent();
    expect(await screen.findByText('No fees assigned to this student.')).toBeTruthy();
    view.unmount();

    let resolve!: (v: unknown) => void;
    edu.studentFees.mockReturnValue(new Promise((r) => { resolve = r; }));
    const second = await openStudent();
    await waitFor(() => expect(document.querySelectorAll('tr.animate-pulse').length).toBeGreaterThan(0));
    await act(async () => resolve(makeLedger([openFee])));
    expect(await screen.findByTestId('student-fee-row-11')).toBeTruthy();
    second.unmount();

    edu.studentFees.mockRejectedValue(apiError(500));
    await openStudent();
    expect(await screen.findByRole('alert')).toBeTruthy();
  });

  it('shows the payment history (and an empty state)', async () => {
    const view = await openStudent();
    const row = await screen.findByTestId('fee-payment-row-1');
    expect(within(row).getByText('100.00')).toBeTruthy();
    expect(within(row).getByText('UPI')).toBeTruthy();
    expect(within(row).getByText('UPI-9')).toBeTruthy();
    view.unmount();

    edu.studentFeePayments.mockResolvedValue({ ...paginated([]), per_page: 20 });
    await openStudent();
    expect(await screen.findByText('No payments recorded yet.')).toBeTruthy();
  });

  it('assigns an ACTIVE fee to the student and re-reads the ledger', async () => {
    const user = userEvent.setup();
    await openStudent();
    await screen.findByTestId('student-fee-row-11');

    await user.click(screen.getByRole('button', { name: /assign fee/i }));
    const form = screen.getByTestId('assign-fee-form');
    expect(within(form).queryByRole('option', { name: /Old fee/ })).toBeNull(); // archived fees are not offered
    fireEvent.change(within(form).getByLabelText(/^Fee/), { target: { value: '2' } });
    fireEvent.change(within(form).getByLabelText('Due date'), { target: { value: '2027-01-10' } });
    await user.click(within(form).getByRole('button', { name: 'Assign' }));

    await waitFor(() => expect(edu.assignFee).toHaveBeenCalledWith(1, { charge_item_id: 2, due_date: '2027-01-10' }));
    expect(await screen.findByText('Charge assigned.')).toBeTruthy();
    await waitFor(() => expect(edu.studentFees).toHaveBeenCalledTimes(2));
  });

  it('sends a custom amount, and shows a server rejection (already assigned)', async () => {
    const user = userEvent.setup();
    edu.assignFee.mockRejectedValue(apiError(422, { message: 'x', errors: { charge_item_id: ['This charge is already assigned to the customer for that due date.'] } }));
    await openStudent();
    await screen.findByTestId('student-fee-row-11');
    await user.click(screen.getByRole('button', { name: /assign fee/i }));
    const form = screen.getByTestId('assign-fee-form');
    fireEvent.change(within(form).getByLabelText(/^Fee/), { target: { value: '1' } });
    await user.type(within(form).getByLabelText('Amount due'), '4500');
    await user.click(within(form).getByRole('button', { name: 'Assign' }));

    expect(edu.assignFee).toHaveBeenCalledWith(1, { charge_item_id: 1, amount_due: '4500', due_date: null });
    expect(await within(form).findByText(/already assigned/)).toBeTruthy();
    expect(screen.getByTestId('assign-fee-form')).toBeTruthy();
  });
});

describe('recording payments', () => {
  async function openPayment(feeId: number) {
    await openStudent();
    const row = await screen.findByTestId(`student-fee-row-${feeId}`);
    fireEvent.click(within(row).getByRole('button', { name: 'Record payment' }));
    return screen.getByTestId('payment-form');
  }

  it('defaults to the full outstanding amount and records it once, then re-reads', async () => {
    const user = userEvent.setup();
    const form = await openPayment(12);
    expect((within(form).getByLabelText('Amount *') as HTMLInputElement).value).toBe('200.00');

    await user.click(within(form).getByRole('button', { name: 'Record payment' }));

    await waitFor(() => expect(edu.recordFeePayment).toHaveBeenCalledTimes(1));
    const [studentId, feeId, payload] = edu.recordFeePayment.mock.calls[0];
    expect([studentId, feeId]).toEqual([1, 12]);
    expect(payload).toMatchObject({ amount: '200.00', payment_method: null, reference: null });
    expect(payload.idempotency_key).toMatch(/^pay-.{8,}/);
    expect(payload.payment_date).toMatch(/^\d{4}-\d{2}-\d{2}$/);
    expect(await screen.findByText('Payment recorded.')).toBeTruthy();
    await waitFor(() => expect(edu.studentFees).toHaveBeenCalledTimes(2));
    await waitFor(() => expect(edu.studentFeePayments).toHaveBeenCalledTimes(2));
    expect(screen.queryByTestId('payment-form')).toBeNull();
  });

  it('records a partial payment with method and reference', async () => {
    const user = userEvent.setup();
    const form = await openPayment(11);

    fireEvent.change(within(form).getByLabelText('Amount *'), { target: { value: '1250.50' } });
    fireEvent.change(within(form).getByLabelText('Method'), { target: { value: 'upi' } });
    await user.type(within(form).getByLabelText('Reference'), 'UPI-123');
    await user.click(within(form).getByRole('button', { name: 'Record payment' }));

    await waitFor(() => expect(edu.recordFeePayment).toHaveBeenCalledWith(1, 11, expect.objectContaining({ amount: '1250.50', payment_method: 'upi', reference: 'UPI-123' })));
  });

  it('rejects zero, negative and malformed amounts locally without a request', async () => {
    const user = userEvent.setup();
    const form = await openPayment(11);

    for (const bad of ['', '0', '0.00', '-1', '1.234', 'abc']) {
      fireEvent.change(within(form).getByLabelText(/^Amount/), { target: { value: bad } });
      await user.click(within(form).getByRole('button', { name: 'Record payment' }));
      expect(within(form).getByText(/enter an amount|greater than zero|decimal places/i)).toBeTruthy();
    }
    expect(edu.recordFeePayment).not.toHaveBeenCalled();
  });

  it('cannot be submitted twice while the request is in flight', async () => {
    const user = userEvent.setup();
    let resolve!: (v: unknown) => void;
    edu.recordFeePayment.mockReturnValue(new Promise((r) => { resolve = r; }));
    const form = await openPayment(11);
    const button = within(form).getByRole('button', { name: 'Record payment' }) as HTMLButtonElement;

    await user.click(button);
    await user.click(button);
    fireEvent.submit(form);

    expect(edu.recordFeePayment).toHaveBeenCalledTimes(1);
    expect(button.disabled).toBe(true);
    await act(async () => resolve({ message: 'Payment recorded.', idempotent: false, payment: makeFeePayment(), data: openFee }));
    expect(await screen.findByText('Payment recorded.')).toBeTruthy();
  });

  it('shows the server\'s overpayment refusal, keeps the form, and a retry re-uses the SAME idempotency key', async () => {
    const user = userEvent.setup();
    edu.recordFeePayment.mockRejectedValueOnce(apiError(422, { message: 'x', errors: { amount: ['The payment exceeds the outstanding amount of 200.00.'] } }));
    const form = await openPayment(12);
    fireEvent.change(within(form).getByLabelText(/^Amount/), { target: { value: '999' } });
    await user.click(within(form).getByRole('button', { name: 'Record payment' }));

    expect(await within(form).findByText(/exceeds the outstanding amount/)).toBeTruthy();
    expect(screen.getByTestId('payment-form')).toBeTruthy();

    fireEvent.change(within(form).getByLabelText(/^Amount/), { target: { value: '200' } });
    await user.click(within(form).getByRole('button', { name: 'Record payment' }));
    await waitFor(() => expect(edu.recordFeePayment).toHaveBeenCalledTimes(2));
    expect(edu.recordFeePayment.mock.calls[1][2].idempotency_key).toBe(edu.recordFeePayment.mock.calls[0][2].idempotency_key);
  });

  it('a different payment form gets a different idempotency key', async () => {
    const user = userEvent.setup();
    let form = await openPayment(11);
    await user.click(within(form).getByRole('button', { name: 'Record payment' }));
    await screen.findByText('Payment recorded.');
    await waitFor(() => expect(screen.queryByTestId('payment-form')).toBeNull());

    fireEvent.click(within(screen.getByTestId('student-fee-row-11')).getByRole('button', { name: 'Record payment' }));
    form = screen.getByTestId('payment-form');
    await user.click(within(form).getByRole('button', { name: 'Record payment' }));

    await waitFor(() => expect(edu.recordFeePayment).toHaveBeenCalledTimes(2));
    expect(edu.recordFeePayment.mock.calls[1][2].idempotency_key).not.toBe(edu.recordFeePayment.mock.calls[0][2].idempotency_key);
  });
});

describe('read-only and permissions', () => {
  it('an expired subscription lets you look but not create, edit, assign or pay', async () => {
    auth.readOnly = true;
    await openStudent();
    await screen.findByTestId('student-fee-row-11');

    expect((screen.getByRole('button', { name: /new fee/i }) as HTMLButtonElement).disabled).toBe(true);
    expect((within(screen.getByTestId('fee-item-row-1')).getByRole('button', { name: 'Edit' }) as HTMLButtonElement).disabled).toBe(true);
    expect((screen.getByRole('button', { name: /assign fee/i }) as HTMLButtonElement).disabled).toBe(true);
    expect((within(screen.getByTestId('student-fee-row-11')).getByRole('button', { name: 'Record payment' }) as HTMLButtonElement).disabled).toBe(true);
    fireEvent.click(within(screen.getByTestId('student-fee-row-11')).getByRole('button', { name: 'Record payment' }));
    expect(screen.queryByTestId('payment-form')).toBeNull();
    expect(screen.getByTestId('fee-totals')).toBeTruthy(); // reads still work
  });

  it('without manage-education every write control is hidden', async () => {
    auth.permissions = ['view-education'];
    await openStudent();
    await screen.findByTestId('student-fee-row-11');

    expect(screen.queryByRole('button', { name: /new fee/i })).toBeNull();
    expect(screen.queryByRole('button', { name: 'Edit' })).toBeNull();
    expect(screen.queryByRole('button', { name: /assign fee/i })).toBeNull();
    expect(screen.queryByRole('button', { name: 'Record payment' })).toBeNull();
  });
});

describe('client switching and stale responses', () => {
  it('a late ledger for the previous student never replaces the one now selected', async () => {
    let resolveFirst!: (v: unknown) => void;
    edu.studentFees
      .mockReturnValueOnce(new Promise((r) => { resolveFirst = r; }))
      .mockResolvedValue(makeLedger([makeStudentFee({ id: 21, charge_name: 'Bala fee' })]));
    render(tree());
    await screen.findByRole('option', { name: 'Asha' });
    fireEvent.change(screen.getByLabelText('Student'), { target: { value: '1' } });
    await waitFor(() => expect(edu.studentFees).toHaveBeenCalledTimes(1));

    fireEvent.change(screen.getByLabelText('Student'), { target: { value: '2' } });
    await screen.findByTestId('student-fee-row-21');

    await act(async () => resolveFirst(makeLedger([makeStudentFee({ id: 11, charge_name: 'Asha fee' })])));
    expect(screen.queryByTestId('student-fee-row-11')).toBeNull();
    expect(screen.getByTestId('student-fee-row-21')).toBeTruthy();
  });

  it('switching client clears the student, closes dialogs and re-reads the catalogue', async () => {
    auth.superAdmin = true;
    tenant.selectedAccountId = 1;
    industry.context.mockResolvedValue([{ industry: 'education', label: 'Education', subtype: null, subtype_label: null, allowed: true, modules: [{ key: 'students', label: 'S', allowed: true }, { key: 'fees', label: 'F', allowed: true }] }]);
    const view = await openStudent();
    await screen.findByTestId('student-fee-row-11');
    fireEvent.click(within(screen.getByTestId('student-fee-row-11')).getByRole('button', { name: 'Record payment' }));
    expect(screen.getByTestId('payment-form')).toBeTruthy();
    expect(edu.listFeeItems).toHaveBeenCalledTimes(1);

    tenant.selectedAccountId = 2;
    view.rerender(tree());

    await waitFor(() => expect(edu.listFeeItems).toHaveBeenCalledTimes(2));
    await waitFor(() => expect(screen.queryByTestId('payment-form')).toBeNull());
    await waitFor(() => expect((screen.getByLabelText('Student') as HTMLSelectElement).value).toBe(''));
    expect(screen.queryByTestId('student-fee-row-11')).toBeNull();
    expect(edu.recordFeePayment).not.toHaveBeenCalled();
  });
});
