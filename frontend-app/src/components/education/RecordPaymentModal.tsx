import { useRef, useState, type FormEvent } from 'react';
import { Loader2, XCircle } from 'lucide-react';
import educationService from '../../services/educationService';
import { inputClass } from '../common/Card';
import { describeApiError } from '../../utils/apiError';
import { PAYMENT_METHODS, type PaymentMethod, type StudentFee } from '../../types/education';
import { todayLocal } from './attendanceUtils';
import { METHOD_LABELS, newIdempotencyKey, validateAmount } from './feeUtils';
import DismissibleAlert from '../common/DismissibleAlert';

/**
 * Phase 11 Task 4 — record a payment against one charge (POST …/fees/{fee}/payments). The amount defaults to
 * the outstanding balance (a full payment); a smaller amount is a partial payment. The balance shown is only
 * a hint — the server recomputes it from the payment records and refuses an overpayment.
 *
 * The idempotency key lives as long as THIS form: a double click or a retry after a network error re-sends
 * the same key, so the server records one payment.
 */
export default function RecordPaymentModal({
  studentId,
  fee,
  onRecorded,
  onCancel,
}: {
  studentId: number;
  fee: StudentFee;
  onRecorded: (message: string) => void;
  onCancel: () => void;
}) {
  const [amount, setAmount] = useState(fee.outstanding);
  const [date, setDate] = useState(todayLocal());
  const [method, setMethod] = useState<PaymentMethod | ''>('');
  const [reference, setReference] = useState('');
  const [saving, setSaving] = useState(false);
  const inFlight = useRef(false);
  const [key] = useState(newIdempotencyKey);
  const [error, setError] = useState<string | null>(null);
  const [fieldErrors, setFieldErrors] = useState<Record<string, string>>({});

  const submit = (e: FormEvent) => {
    e.preventDefault();
    if (inFlight.current) return;
    const amountError = validateAmount(amount);
    if (amountError) {
      setFieldErrors({ amount: amountError });
      return;
    }
    inFlight.current = true;
    setSaving(true);
    setError(null);
    setFieldErrors({});
    educationService
      .recordFeePayment(studentId, fee.id, {
        amount: amount.trim(),
        payment_date: date,
        payment_method: method === '' ? null : method,
        reference: reference.trim() === '' ? null : reference.trim(),
        idempotency_key: key,
      })
      .then((res) => onRecorded(res.message))
      .catch((err: unknown) => {
        const described = describeApiError(err, 'Failed to record the payment.');
        setError(described.message);
        setFieldErrors(described.fieldErrors);
      })
      .finally(() => {
        inFlight.current = false;
        setSaving(false);
      });
  };

  const field = (k: string) => (fieldErrors[k] ? <p className="mt-1 text-xs text-red-600">{fieldErrors[k]}</p> : null);

  return (
    <div className="fixed inset-0 z-50 flex items-center justify-center bg-slate-900/50 p-4">
      <form onSubmit={submit} className="w-full max-w-md rounded-xl bg-white p-6 shadow-xl" data-testid="payment-form">
        <div className="flex items-center justify-between">
          <h3 className="text-base font-semibold text-slate-900">Record payment — {fee.charge_name}</h3>
          <button type="button" onClick={onCancel} disabled={saving} className="text-slate-400 hover:text-slate-600" aria-label="Close">
            <XCircle className="h-5 w-5" />
          </button>
        </div>
        <p className="mt-2 text-xs text-slate-500">
          Due {fee.amount_due} · paid {fee.amount_paid} · outstanding <span className="font-semibold text-slate-700">{fee.outstanding}</span>
        </p>
        <div className="mt-4 space-y-3">
          <label className="block text-xs font-medium text-slate-600">
            Amount *
            <input className={inputClass} value={amount} onChange={(e) => setAmount(e.target.value)} inputMode="decimal" />
            {field('amount')}
          </label>
          <label className="block text-xs font-medium text-slate-600">
            Payment date
            <input className={inputClass} type="date" value={date} onChange={(e) => setDate(e.target.value)} />
            {field('payment_date')}
          </label>
          <label className="block text-xs font-medium text-slate-600">
            Method
            <select className={inputClass} value={method} onChange={(e) => setMethod(e.target.value as PaymentMethod | '')}>
              <option value="">Not specified</option>
              {PAYMENT_METHODS.map((m) => (
                <option key={m} value={m}>{METHOD_LABELS[m]}</option>
              ))}
            </select>
            {field('payment_method')}
          </label>
          <label className="block text-xs font-medium text-slate-600">
            Reference
            <input className={inputClass} value={reference} onChange={(e) => setReference(e.target.value)} maxLength={120} />
            {field('reference')}
          </label>
        </div>
        {error && <DismissibleAlert as="p" className="mt-3 text-sm text-red-600 flex items-start justify-between gap-2" role="alert">{error}</DismissibleAlert>}
        <div className="mt-5 flex justify-end gap-2">
          <button type="button" onClick={onCancel} disabled={saving} className="rounded-lg border border-slate-300 px-4 py-2 text-sm font-medium text-slate-700 hover:bg-slate-50 disabled:opacity-60">
            Cancel
          </button>
          <button type="submit" disabled={saving} className="flex items-center gap-2 rounded-lg bg-indigo-600 px-4 py-2 text-sm font-semibold text-white shadow-sm hover:bg-indigo-700 disabled:opacity-60">
            {saving && <Loader2 className="h-4 w-4 animate-spin" />}
            Record payment
          </button>
        </div>
      </form>
    </div>
  );
}
