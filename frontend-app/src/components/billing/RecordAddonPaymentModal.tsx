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
  /** The date field: when the paid term starts. A plan says "Plan starts on". */
  startLabel?: string;
  /** How long the purchase runs, so the form can show when it ends. */
  term?: { days: number } | { months: number } | null;
}

/** Same rule as the server: an add-on's month ends on the same day next month, or the month's last day. */
function addMonths(iso: string, months: number): string {
  const [y, m, d] = iso.split('-').map(Number);
  const first = new Date(Date.UTC(y, m - 1 + months, 1));
  const lastDay = new Date(Date.UTC(first.getUTCFullYear(), first.getUTCMonth() + 1, 0)).getUTCDate();
  return new Date(Date.UTC(first.getUTCFullYear(), first.getUTCMonth(), Math.min(d, lastDay))).toISOString().slice(0, 10);
}

function addDays(iso: string, days: number): string {
  const date = new Date(`${iso}T00:00:00Z`);
  date.setUTCDate(date.getUTCDate() + days);
  return date.toISOString().slice(0, 10);
}

function formatIsoDate(iso: string): string {
  return new Date(`${iso}T00:00:00Z`).toLocaleDateString('en-IN', { day: '2-digit', month: 'short', year: 'numeric', timeZone: 'UTC' });
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
 * Records a payment made by cash, bank transfer, UPI, or cheque for any
 * invoice: a plan, a WhatsApp number or a module add-on. The server checks every rule (amount, duplicate transaction, who may
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
  startLabel = 'Term starts on',
  term = null,
}: RecordAddonPaymentModalProps) {
  const [amount, setAmount] = useState(totalAmount.toFixed(2));
  const [method, setMethod] = useState<Method>('bank_transfer');
  const [transactionId, setTransactionId] = useState('');
  const [paidOn, setPaidOn] = useState(today());
  // The term starts on the date paid unless it is changed: it opens on today's date.
  const [termStartsOn, setTermStartsOn] = useState(today());
  // The end date the server will set: the chosen start (or the date paid), plus the term.
  const termStart = termStartsOn || paidOn;
  const termEnd = term && /^\d{4}-\d{2}-\d{2}$/.test(termStart)
    ? 'days' in term
      ? addDays(termStart, term.days)
      : addMonths(termStart, term.months)
    : null;
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
            <span className="text-red-500" aria-hidden="true"> *</span><input className={`${inputClass} mt-1`} type="number" step="0.01" min="0" value={amount} onChange={(e) => setAmount(e.target.value)} required />
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
            <span className="text-red-500" aria-hidden="true"> *</span><input className={`${inputClass} mt-1`} value={transactionId} onChange={(e) => setTransactionId(e.target.value)} maxLength={120} required />
          </label>
          <label className="text-xs font-medium text-slate-700">
            Date paid
            <span className="text-red-500" aria-hidden="true"> *</span><input
              className={`${inputClass} mt-1`}
              type="date"
              value={paidOn}
              max={today()}
              onChange={(e) => {
                // Keep the term start on the date paid while the user has not chosen another start.
                if (termStartsOn === paidOn) setTermStartsOn(e.target.value);
                setPaidOn(e.target.value);
              }}
              required
            />
          </label>
          <label className="text-xs font-medium text-slate-700">
            {startLabel} (defaults to the date paid)
            <input className={`${inputClass} mt-1`} type="date" value={termStartsOn} onChange={(e) => setTermStartsOn(e.target.value)} />
            <span className="mt-1 block text-xs font-normal text-slate-600">
              {termEnd ? `Ends on ${formatIsoDate(termEnd)}` : 'No fixed term for this invoice.'}
            </span>
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
