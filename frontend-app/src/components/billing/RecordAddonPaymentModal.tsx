import { useState, type FormEvent } from 'react';
import { X } from 'lucide-react';
import whatsappService from '../../services/whatsappService';

type Method = 'cash' | 'bank_transfer' | 'upi' | 'cheque' | 'other';

const METHOD_LABEL: Record<Method, string> = {
  cash: 'Cash',
  bank_transfer: 'Bank transfer',
  upi: 'UPI',
  cheque: 'Cheque',
  other: 'Other',
};

interface RecordAddonPaymentModalProps {
  invoiceId: number;
  accountId: number;
  invoiceNumber: string;
  /** GST-inclusive total the payment must equal. */
  totalAmount: number;
  onClose: () => void;
  onRecorded: () => void;
  /** Overrides how the payment is sent (for example a module add-on request). */
  submit?: (body: Record<string, unknown>) => Promise<unknown>;
}

function today(): string {
  return new Date().toISOString().slice(0, 10);
}

function errorMessage(err: unknown): string {
  const data = (err as { response?: { data?: { message?: string; errors?: Record<string, string[]> } } })?.response?.data;
  const first = data?.errors ? Object.values(data.errors)[0]?.[0] : undefined;
  return first ?? data?.message ?? 'Could not record this payment. Please check the details and try again.';
}

/**
 * Records a payment made by cash, bank transfer, UPI, or cheque for an add-on
 * invoice. The server checks every rule (amount, duplicate transaction, who may
 * record for this account, date limits); this form only collects the details.
 */
export default function RecordAddonPaymentModal({
  invoiceId,
  accountId,
  invoiceNumber,
  totalAmount,
  onClose,
  onRecorded,
  submit: submitOverride,
}: RecordAddonPaymentModalProps) {
  const [amount, setAmount] = useState(totalAmount.toFixed(2));
  const [method, setMethod] = useState<Method>('bank_transfer');
  const [transactionId, setTransactionId] = useState('');
  const [paidOn, setPaidOn] = useState(today());
  const [termStartsOn, setTermStartsOn] = useState('');
  const [note, setNote] = useState('');
  const [submitting, setSubmitting] = useState(false);
  const [error, setError] = useState<string | null>(null);

  const submit = async (e: FormEvent<HTMLFormElement>) => {
    e.preventDefault();
    setError(null);

    if (!transactionId.trim()) {
      setError('Enter the transaction ID or receipt number.');
      return;
    }

    setSubmitting(true);
    try {
      const body = {
        amount: Number(amount),
        method,
        transaction_id: transactionId.trim(),
        paid_on: paidOn,
        ...(termStartsOn ? { term_starts_on: termStartsOn } : {}),
        ...(note.trim() ? { note: note.trim() } : {}),
      };
      // Default: a WhatsApp add-on invoice. Module add-ons pass their own submit.
      if (submitOverride) {
        await submitOverride(body);
      } else {
        await whatsappService.recordAddonPayment(invoiceId, accountId, body);
      }
      onRecorded();
    } catch (err) {
      setError(errorMessage(err));
    } finally {
      setSubmitting(false);
    }
  };

  const inputClass = 'w-full rounded-lg border border-slate-300 px-3 py-2 text-sm text-slate-900 focus:border-indigo-500 focus:outline-none';

  return (
    <div className="fixed inset-0 z-50 flex items-center justify-center bg-slate-900/50 p-4">
      <form onSubmit={submit} className="w-full max-w-md rounded-xl bg-white p-6 shadow-xl">
        <div className="flex items-center justify-between">
          <h2 className="text-base font-semibold text-slate-900">Record payment</h2>
          <button type="button" onClick={onClose} className="rounded-md p-1 text-slate-400 hover:bg-slate-100" aria-label="Close">
            <X className="h-5 w-5" />
          </button>
        </div>
        <p className="mt-1 text-sm text-slate-500">Invoice {invoiceNumber} · Total ₹{totalAmount.toFixed(2)} (GST included)</p>

        <div className="mt-4 grid grid-cols-2 gap-3">
          <label className="text-xs font-medium text-slate-700">
            Amount received (₹)
            <input className={`${inputClass} mt-1`} type="number" step="0.01" min="0" value={amount} onChange={(e) => setAmount(e.target.value)} required />
          </label>
          <label className="text-xs font-medium text-slate-700">
            How it was paid
            <select className={`${inputClass} mt-1`} value={method} onChange={(e) => setMethod(e.target.value as Method)}>
              {(Object.keys(METHOD_LABEL) as Method[]).map((m) => (
                <option key={m} value={m}>
                  {METHOD_LABEL[m]}
                </option>
              ))}
            </select>
          </label>
          <label className="col-span-2 text-xs font-medium text-slate-700">
            Transaction ID / receipt number
            <input className={`${inputClass} mt-1`} value={transactionId} onChange={(e) => setTransactionId(e.target.value)} maxLength={120} required />
          </label>
          <label className="text-xs font-medium text-slate-700">
            Date paid
            <input className={`${inputClass} mt-1`} type="date" value={paidOn} max={today()} onChange={(e) => setPaidOn(e.target.value)} required />
          </label>
          <label className="text-xs font-medium text-slate-700">
            Term starts on (optional)
            <input className={`${inputClass} mt-1`} type="date" value={termStartsOn} onChange={(e) => setTermStartsOn(e.target.value)} />
          </label>
          <label className="col-span-2 text-xs font-medium text-slate-700">
            Note (optional)
            <input className={`${inputClass} mt-1`} value={note} onChange={(e) => setNote(e.target.value)} maxLength={255} />
          </label>
        </div>

        <p className="mt-3 text-xs text-slate-400">
          Leave the term start empty to start it on the date paid. It can be moved by up to 30 days.
        </p>

        {error && <p className="mt-3 text-sm text-red-600">{error}</p>}

        <div className="mt-5 flex justify-end gap-2">
          <button type="button" onClick={onClose} className="rounded-lg border border-slate-300 px-3 py-2 text-sm font-medium text-slate-700 hover:bg-slate-50">
            Cancel
          </button>
          <button type="submit" disabled={submitting} className="rounded-lg bg-indigo-600 px-3 py-2 text-sm font-semibold text-white hover:bg-indigo-700 disabled:opacity-60">
            {submitting ? 'Recording…' : 'Record payment'}
          </button>
        </div>
      </form>
    </div>
  );
}
