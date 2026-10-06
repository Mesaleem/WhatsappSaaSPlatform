import { useState, type FormEvent } from 'react';
import { Loader2, XCircle } from 'lucide-react';
import educationService from '../../services/educationService';
import { inputClass } from '../common/Card';
import { describeApiError } from '../../utils/apiError';
import { FEE_FREQUENCIES, FEE_ITEM_STATUSES, type FeeFrequency, type FeeItem, type FeeItemStatus } from '../../types/education';
import { FREQUENCY_LABELS, validateAmount } from './feeUtils';
import DismissibleAlert from '../common/DismissibleAlert';

/**
 * Phase 11 Task 4 — create (POST /fee-items) or edit (PATCH /fee-items/{id}) a fee. There is no delete: a
 * fee is archived, which stops new assignments but leaves existing student charges and payments alone.
 * Changing the amount never rewrites what students already owe.
 */
export default function FeeItemFormModal({
  item,
  onSaved,
  onCancel,
}: {
  item?: FeeItem;
  onSaved: (item: FeeItem, message: string) => void;
  onCancel: () => void;
}) {
  const [name, setName] = useState(item?.name ?? '');
  const [description, setDescription] = useState(item?.description ?? '');
  const [amount, setAmount] = useState(item?.amount ?? '');
  const [frequency, setFrequency] = useState<FeeFrequency>(item?.frequency ?? 'one_time');
  const [status, setStatus] = useState<FeeItemStatus>(item?.status ?? 'active');
  const [saving, setSaving] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [fieldErrors, setFieldErrors] = useState<Record<string, string>>({});

  const submit = (e: FormEvent) => {
    e.preventDefault();
    if (saving) return;
    const amountError = validateAmount(amount);
    if (amountError) {
      setFieldErrors({ amount: amountError });
      return;
    }
    setSaving(true);
    setError(null);
    setFieldErrors({});
    const payload = { name: name.trim(), description: description.trim() === '' ? null : description.trim(), amount: amount.trim(), frequency, status };
    const call = item ? educationService.updateFeeItem(item.id, payload) : educationService.createFeeItem(payload);
    call
      .then((res) => onSaved(res.data, res.message))
      .catch((err: unknown) => {
        const described = describeApiError(err, 'Failed to save.');
        setError(described.message);
        setFieldErrors(described.fieldErrors);
      })
      .finally(() => setSaving(false));
  };

  const field = (key: string) => (fieldErrors[key] ? <p className="mt-1 text-xs text-red-600">{fieldErrors[key]}</p> : null);

  return (
    <div className="fixed inset-0 z-50 flex items-center justify-center bg-slate-900/50 p-4">
      <form onSubmit={submit} className="w-full max-w-md rounded-xl bg-white p-6 shadow-xl" data-testid="fee-item-form">
        <div className="flex items-center justify-between">
          <h3 className="text-base font-semibold text-slate-900">{item ? 'Edit fee' : 'New fee'}</h3>
          <button type="button" onClick={onCancel} disabled={saving} className="text-slate-400 hover:text-slate-600" aria-label="Close">
            <XCircle className="h-5 w-5" />
          </button>
        </div>
        <div className="mt-4 space-y-3">
          <label className="block text-xs font-medium text-slate-600">
            Name *
            <span className="text-red-500" aria-hidden="true"> *</span><input className={inputClass} value={name} onChange={(e) => setName(e.target.value)} required maxLength={120} />
            {field('name')}
          </label>
          <label className="block text-xs font-medium text-slate-600">
            Amount *
            <input className={inputClass} value={amount} onChange={(e) => setAmount(e.target.value)} inputMode="decimal" placeholder="0.00" />
            {field('amount')}
          </label>
          <label className="block text-xs font-medium text-slate-600">
            Frequency
            <select className={inputClass} value={frequency} onChange={(e) => setFrequency(e.target.value as FeeFrequency)}>
              {FEE_FREQUENCIES.map((f) => (
                <option key={f} value={f}>{FREQUENCY_LABELS[f]}</option>
              ))}
            </select>
            <span className="mt-1 block text-[11px] font-normal text-slate-400">A label only — nothing is billed automatically.</span>
          </label>
          <label className="block text-xs font-medium text-slate-600">
            Description
            <textarea className={inputClass} value={description} onChange={(e) => setDescription(e.target.value)} rows={2} maxLength={2000} />
            {field('description')}
          </label>
          {item && (
            <label className="block text-xs font-medium text-slate-600">
              Status
              <select className={inputClass} value={status} onChange={(e) => setStatus(e.target.value as FeeItemStatus)}>
                {FEE_ITEM_STATUSES.map((s) => (
                  <option key={s} value={s}>{s.charAt(0).toUpperCase() + s.slice(1)}</option>
                ))}
              </select>
              <span className="mt-1 block text-[11px] font-normal text-slate-400">Archived fees cannot be assigned; existing charges stay payable.</span>
            </label>
          )}
        </div>
        {error && <DismissibleAlert as="p" className="mt-3 text-sm text-red-600 flex items-start justify-between gap-2" role="alert">{error}</DismissibleAlert>}
        <div className="mt-5 flex justify-end gap-2">
          <button type="button" onClick={onCancel} disabled={saving} className="rounded-lg border border-slate-300 px-4 py-2 text-sm font-medium text-slate-700 hover:bg-slate-50 disabled:opacity-60">
            Cancel
          </button>
          <button type="submit" disabled={saving || name.trim() === ''} className="flex items-center gap-2 rounded-lg bg-indigo-600 px-4 py-2 text-sm font-semibold text-white shadow-sm hover:bg-indigo-700 disabled:opacity-60">
            {saving && <Loader2 className="h-4 w-4 animate-spin" />}
            {item ? 'Save changes' : 'Create'}
          </button>
        </div>
      </form>
    </div>
  );
}
