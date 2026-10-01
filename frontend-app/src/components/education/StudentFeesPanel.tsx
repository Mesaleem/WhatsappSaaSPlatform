import { useState } from 'react';
import { Plus } from 'lucide-react';
import educationService from '../../services/educationService';
import { TableCard, inputClass } from '../common/Card';
import { Pagination } from '../common/DataTableControls';
import { TableSkeletonRows } from '../common/Skeleton';
import { EmptyState, ErrorBanner, READ_ONLY_TITLE } from '../crm/CrmUi';
import { useCrmQuery } from '../crm/crmHooks';
import AssignFeeModal from './AssignFeeModal';
import RecordPaymentModal from './RecordPaymentModal';
import { FEE_STATUS_LABELS, FEE_STATUS_STYLES, METHOD_LABELS } from './feeUtils';
import type { FeeItem, StudentFee } from '../../types/education';

type Modal = { kind: 'assign' } | { kind: 'pay'; fee: StudentFee } | null;

/**
 * Phase 11 Task 4 — one student's charges with total due / paid / outstanding (all computed by the server
 * from the payment records), assigning a fee, recording a full or partial payment, and payment history.
 *
 * The selected student and any open dialog belong to the client they were chosen for, and every query is
 * keyed by client + student, so switching client (or student) can never show — or pay into — the previous one.
 */
export default function StudentFeesPanel({
  accountKey,
  items,
  canManage,
  readOnly,
  onToast,
}: {
  accountKey: number | null;
  items: FeeItem[];
  canManage: boolean;
  readOnly: boolean;
  onToast: (message: string) => void;
}) {
  const [sel, setSel] = useState<{ account: number | null; studentId: number | null; modal: Modal }>({ account: accountKey, studentId: null, modal: null });
  const current = sel.account === accountKey ? sel : { account: accountKey, studentId: null, modal: null as Modal };
  const [page, setPage] = useState(1);
  const studentId = current.studentId;
  const writable = canManage && !readOnly;

  const students = useCrmQuery(`fee-students:${accountKey ?? 'own'}`, () => educationService.listStudents({}, 1, 100), 'Failed to load students.');
  const ledger = useCrmQuery(
    studentId !== null ? JSON.stringify({ ledger: accountKey, student: studentId }) : null,
    () => educationService.studentFees(studentId as number),
    'Failed to load the student’s fees.',
  );
  const payments = useCrmQuery(
    studentId !== null ? JSON.stringify({ payments: accountKey, student: studentId, page }) : null,
    () => educationService.studentFeePayments(studentId as number, page),
    'Failed to load payment history.',
  );

  const update = (patch: Partial<typeof current>) => setSel({ ...current, ...patch });
  const refresh = () => {
    ledger.reload();
    payments.reload();
  };
  const summary = ledger.data?.summary;

  return (
    <section className="space-y-4" data-testid="student-fees-panel">
      <div className="flex flex-wrap items-end justify-between gap-3">
        <label className="text-xs font-medium text-slate-600">
          Student
          <select
            className={`${inputClass} min-w-[16rem]`}
            value={studentId ?? ''}
            onChange={(e) => {
              setPage(1);
              update({ studentId: e.target.value === '' ? null : Number(e.target.value), modal: null });
            }}
          >
            <option value="">Select a student…</option>
            {(students.data?.data ?? []).map((s) => (
              <option key={s.id} value={s.id}>{s.contact?.name || s.contact?.phone_number || `Student ${s.id}`}</option>
            ))}
          </select>
        </label>
        {canManage && studentId !== null && (
          <button
            type="button"
            onClick={() => update({ modal: { kind: 'assign' } })}
            disabled={readOnly}
            title={readOnly ? READ_ONLY_TITLE : undefined}
            className="flex items-center gap-2 rounded-lg bg-indigo-600 px-4 py-2 text-sm font-semibold text-white shadow-sm hover:bg-indigo-700 disabled:cursor-not-allowed disabled:opacity-60"
          >
            <Plus className="h-4 w-4" />
            Assign fee
          </button>
        )}
      </div>

      {students.error && <ErrorBanner message={students.error} />}

      {studentId === null ? (
        <TableCard>
          <EmptyState title="Select a student to see their fees." />
        </TableCard>
      ) : (
        <>
          {ledger.error && <ErrorBanner message={ledger.error} />}
          {summary && (
            <div className="flex flex-wrap gap-3 text-sm" data-testid="fee-totals">
              <Total label="Total due" value={summary.total_due} />
              <Total label="Paid" value={summary.total_paid} />
              <Total label="Outstanding" value={summary.outstanding} emphasis />
              {summary.overdue !== '0.00' && <Total label="Overdue" value={summary.overdue} />}
            </div>
          )}

          <TableCard>
            <table className="min-w-full divide-y divide-slate-200 text-sm" data-testid="student-fees-table">
              <thead className="bg-slate-50">
                <tr>
                  {['Fee', 'Due date', 'Amount due', 'Paid', 'Outstanding', 'Status', ''].map((h) => (
                    <th key={h || 'actions'} className="px-4 py-3 text-left font-medium text-slate-600">{h}</th>
                  ))}
                </tr>
              </thead>
              <tbody className="divide-y divide-slate-100">
                {ledger.isLoading ? (
                  <TableSkeletonRows columns={7} />
                ) : !ledger.data ? null : ledger.data.data.length === 0 ? (
                  <tr>
                    <td colSpan={7}>
                      <EmptyState title="No fees assigned to this student." hint={canManage ? 'Use “Assign fee” to add one.' : undefined} />
                    </td>
                  </tr>
                ) : (
                  ledger.data.data.map((f) => (
                    <tr key={f.id} data-testid={`student-fee-row-${f.id}`}>
                      <td className="px-4 py-3 font-medium text-slate-900">{f.charge_name}</td>
                      <td className="whitespace-nowrap px-4 py-3 text-slate-700">{f.due_date ?? '—'}</td>
                      <td className="px-4 py-3 text-slate-700">{f.amount_due}</td>
                      <td className="px-4 py-3 text-slate-700">{f.amount_paid}</td>
                      <td className="px-4 py-3 font-medium text-slate-900">{f.outstanding}</td>
                      <td className="px-4 py-3">
                        <span className={`rounded-full border px-2.5 py-0.5 text-xs font-medium ${FEE_STATUS_STYLES[f.status] ?? ''}`}>{FEE_STATUS_LABELS[f.status] ?? f.status}</span>
                      </td>
                      <td className="px-4 py-3 text-right">
                        {canManage && f.status !== 'paid' && f.status !== 'cancelled' && (
                          <button
                            type="button"
                            onClick={() => update({ modal: { kind: 'pay', fee: f } })}
                            disabled={readOnly}
                            title={readOnly ? READ_ONLY_TITLE : undefined}
                            className="text-xs font-semibold text-indigo-600 hover:text-indigo-800 disabled:cursor-not-allowed disabled:opacity-50"
                          >
                            Record payment
                          </button>
                        )}
                      </td>
                    </tr>
                  ))
                )}
              </tbody>
            </table>
          </TableCard>

          <h2 className="text-sm font-semibold text-slate-900">Payment history</h2>
          {payments.error && <ErrorBanner message={payments.error} />}
          <TableCard>
            <table className="min-w-full divide-y divide-slate-200 text-sm" data-testid="fee-payments-table">
              <thead className="bg-slate-50">
                <tr>
                  {['Date', 'Fee', 'Amount', 'Method', 'Reference'].map((h) => (
                    <th key={h} className="px-4 py-3 text-left font-medium text-slate-600">{h}</th>
                  ))}
                </tr>
              </thead>
              <tbody className="divide-y divide-slate-100">
                {payments.isLoading ? (
                  <TableSkeletonRows columns={5} />
                ) : !payments.data ? null : payments.data.data.length === 0 ? (
                  <tr>
                    <td colSpan={5}>
                      <EmptyState title="No payments recorded yet." />
                    </td>
                  </tr>
                ) : (
                  payments.data.data.map((p) => (
                    <tr key={p.id} data-testid={`fee-payment-row-${p.id}`}>
                      <td className="whitespace-nowrap px-4 py-3 text-slate-700">{p.payment_date}</td>
                      <td className="px-4 py-3 text-slate-700">{p.charge_name ?? '—'}</td>
                      <td className="px-4 py-3 font-medium text-slate-900">{p.amount}</td>
                      <td className="px-4 py-3 text-slate-700">{p.payment_method ? METHOD_LABELS[p.payment_method] ?? p.payment_method : '—'}</td>
                      <td className="px-4 py-3 text-slate-700">{p.reference ?? '—'}</td>
                    </tr>
                  ))
                )}
              </tbody>
            </table>
            <Pagination page={page} lastPage={payments.data?.last_page ?? 1} total={payments.data?.total ?? 0} perPage={payments.data?.per_page ?? 20} onPageChange={setPage} />
          </TableCard>
        </>
      )}

      {writable && studentId !== null && current.modal?.kind === 'assign' && (
        <AssignFeeModal
          studentId={studentId}
          items={items}
          onCancel={() => update({ modal: null })}
          onAssigned={(message) => {
            update({ modal: null });
            onToast(message);
            refresh();
          }}
        />
      )}
      {writable && studentId !== null && current.modal?.kind === 'pay' && (
        <RecordPaymentModal
          key={current.modal.fee.id}
          studentId={studentId}
          fee={current.modal.fee}
          onCancel={() => update({ modal: null })}
          onRecorded={(message) => {
            update({ modal: null });
            onToast(message);
            setPage(1);
            refresh();
          }}
        />
      )}
    </section>
  );
}

function Total({ label, value, emphasis = false }: { label: string; value: string; emphasis?: boolean }) {
  return (
    <div className={`rounded-lg border bg-white px-3 py-2 ${emphasis ? 'border-indigo-200' : 'border-slate-200'}`}>
      <div className="text-xs text-slate-500">{label}</div>
      <div className="font-semibold text-slate-900">{value}</div>
    </div>
  );
}
