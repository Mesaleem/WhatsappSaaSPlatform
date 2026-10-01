import { useState, type FormEvent } from 'react';
import { Loader2, XCircle } from 'lucide-react';
import educationService from '../../services/educationService';
import { inputClass } from '../common/Card';
import { describeApiError } from '../../utils/apiError';
import type { FeeItem } from '../../types/education';
import { validateAmount } from './feeUtils';
import DismissibleAlert from '../common/DismissibleAlert';

/** Phase 11 Task 4 — assign an ACTIVE fee to one student (POST /students/{id}/fees). Amount defaults to the fee's. */
export default function AssignFeeModal({
  studentId,
  items,
  onAssigned,
  onCancel,
}: {
  studentId: number;
  items: FeeItem[];
  onAssigned: (message: string) => void;
  onCancel: () => void;
}) {
  const active = items.filter((i) => i.status === 'active');
  const [itemId, setItemId] = useState<number | ''>('');
  const [amount, setAmount] = useState('');
  const [dueDate, setDueDate] = useState('');
  const [saving, setSaving] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [fieldErrors, setFieldErrors] = useState<Record<string, string>>({});
  const chosen = active.find((i) => i.id === itemId);

  const submit = (e: FormEvent) => {
    e.preventDefault();
    if (saving || itemId === '') return;
    if (amount.trim() !== '') {
      const amountError = validateAmount(amount);
      if (amountError) {
        setFieldErrors({ amount_due: amountError });
        return;
      }
    }
    setSaving(true);
    setError(null);
    setFieldErrors({});
    educationService
      .assignFee(studentId, { charge_item_id: itemId, ...(amount.trim() !== '' ? { amount_due: amount.trim() } : {}), due_date: dueDate === '' ? null : dueDate })
      .then((res) => onAssigned(res.message))
      .catch((err: unknown) => {
        const described = describeApiError(err, 'Failed to assign the fee.');
        setError(described.message);
        setFieldErrors({ ...described.fieldErrors, ...(described.fieldErrors.charge_item_id ? { charge_item_id: described.fieldErrors.charge_item_id } : {}) });
      })
      .finally(() => setSaving(false));
  };

  const field = (key: string) => (fieldErrors[key] ? <p className="mt-1 text-xs text-red-600">{fieldErrors[key]}</p> : null);

  return (
    <div className="fixed inset-0 z-50 flex items-center justify-center bg-slate-900/50 p-4">
      <form onSubmit={submit} className="w-full max-w-md rounded-xl bg-white p-6 shadow-xl" data-testid="assign-fee-form">
        <div className="flex items-center justify-between">
          <h3 className="text-base font-semibold text-slate-900">Assign a fee</h3>
          <button type="button" onClick={onCancel} disabled={saving} className="text-slate-400 hover:text-slate-600" aria-label="Close">
            <XCircle className="h-5 w-5" />
          </button>
        </div>
        <div className="mt-4 space-y-3">
          <label className="block text-xs font-medium text-slate-600">
            Fee *
            <select className={inputClass} value={itemId} onChange={(e) => setItemId(e.target.value === '' ? '' : Number(e.target.value))}>
              <option value="">Select a fee…</option>
              {active.map((i) => (
                <option key={i.id} value={i.id}>{i.name} — {i.amount}</option>
              ))}
            </select>
            {field('charge_item_id')}
          </label>
          {active.length === 0 && <p className="text-xs text-slate-500">There are no active fees. Create one first.</p>}
          <label className="block text-xs font-medium text-slate-600">
            Amount due
            <input className={inputClass} value={amount} onChange={(e) => setAmount(e.target.value)} inputMode="decimal" placeholder={chosen ? chosen.amount : 'Defaults to the fee amount'} />
            {field('amount_due')}
          </label>
          <label className="block text-xs font-medium text-slate-600">
            Due date
            <input className={inputClass} type="date" value={dueDate} onChange={(e) => setDueDate(e.target.value)} />
            {field('due_date')}
          </label>
        </div>
        {error && <DismissibleAlert as="p" className="mt-3 text-sm text-red-600 flex items-start justify-between gap-2" role="alert">{error}</DismissibleAlert>}
        <div className="mt-5 flex justify-end gap-2">
          <button type="button" onClick={onCancel} disabled={saving} className="rounded-lg border border-slate-300 px-4 py-2 text-sm font-medium text-slate-700 hover:bg-slate-50 disabled:opacity-60">
            Cancel
          </button>
          <button type="submit" disabled={saving || itemId === ''} className="flex items-center gap-2 rounded-lg bg-indigo-600 px-4 py-2 text-sm font-semibold text-white shadow-sm hover:bg-indigo-700 disabled:opacity-60">
            {saving && <Loader2 className="h-4 w-4 animate-spin" />}
            Assign
          </button>
        </div>
      </form>
    </div>
  );
}
