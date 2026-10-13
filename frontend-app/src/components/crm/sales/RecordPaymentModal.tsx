import { useMemo, useState } from 'react';
import { Loader2, XCircle } from 'lucide-react';
import crmDocumentsService from '../../../services/crmDocumentsService';
import { describeApiError } from '../../../utils/apiError';
import { formatMoney } from '../../../types/crm';
import { CRM_PAYMENT_METHODS, type CrmPayment, type CrmDocSummary } from '../../../types/crmSales';
import { useCrmQuery } from '../crmHooks';
import { fieldClass } from './SalesUi';

const AMOUNT = /^\d{1,10}(\.\d{1,2})?$/;

/**
 * CRM Sales Phase 3 — record a payment received (cash, UPI, bank, cheque...) and apply it
 * to one or more open invoices of the same customer. The server re-checks every balance
 * under a lock; whatever is not applied stays on the payment as a credit. A single
 * idempotency key per opened form makes a double click record one payment.
 */
export default function RecordPaymentModal({
  invoice,
  customer,
  onCancel,
  onSaved,
}: {
  invoice?: CrmDocSummary;
  customer?: { contact_id: number | null; crm_company_id: number | null };
  onCancel: () => void;
  onSaved: (payment: CrmPayment, message: string) => void;
}) {
  const who = customer ?? { contact_id: invoice?.contact_id ?? null, crm_company_id: invoice?.crm_company_id ?? null };
  const [amount, setAmount] = useState(invoice?.balance_due ?? '');
  const [method, setMethod] = useState<(typeof CRM_PAYMENT_METHODS)[number]>('upi');
  const [paidOn, setPaidOn] = useState(() => new Date().toISOString().slice(0, 10));
  const [reference, setReference] = useState('');
  const [apply, setApply] = useState<Record<number, string>>(() => (invoice ? { [invoice.id]: invoice.balance_due } : {}));
  const [saving, setSaving] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const idempotencyKey = useMemo(() => `pay-${Date.now()}-${Math.random().toString(36).slice(2)}`, []);

  const open = useCrmQuery(
    `open-invoices:${who.contact_id}:${who.crm_company_id}`,
    () => crmDocumentsService.listDocuments({ type: 'invoice', contact_id: who.contact_id ?? undefined, crm_company_id: who.contact_id ? undefined : (who.crm_company_id ?? undefined), per_page: 50 }),
    'Failed to load open invoices.',
  );
  const invoices = (open.data?.data ?? []).filter((d) => ['issued', 'partially_paid'].includes(d.status));
  const allocations = Object.entries(apply).filter(([, v]) => v !== '' && v !== '0').map(([id, v]) => ({ crm_document_id: Number(id), amount: v }));
  const valid = AMOUNT.test(amount) && Number(amount) > 0 && allocations.every((a) => AMOUNT.test(a.amount));

  const save = () => {
    if (!valid || saving) return;
    setSaving(true);
    setError(null);
    crmDocumentsService
      .recordPayment({ amount, method, paid_on: paidOn, reference: reference.trim() || null, contact_id: who.contact_id, crm_company_id: who.crm_company_id, idempotency_key: idempotencyKey, allocations })
      .then((res) => onSaved(res.data, res.message))
      .catch((err: unknown) => {
        setError(describeApiError(err, 'Could not record the payment.').message);
        setSaving(false);
      });
  };

  return (
    <div className="fixed inset-0 z-50 flex items-center justify-center bg-slate-900/50 p-4">
      <div role="dialog" aria-labelledby="pay-title" className="max-h-[90vh] w-full max-w-lg overflow-y-auto rounded-xl bg-white p-6 shadow-xl" data-testid="crm-record-payment">
        <div className="flex items-center justify-between">
          <h3 id="pay-title" className="text-base font-semibold text-slate-900">
            Record a payment
          </h3>
          <button type="button" onClick={onCancel} className="text-slate-400 hover:text-slate-600" aria-label="Close">
            <XCircle className="h-5 w-5" />
          </button>
        </div>

        <div className="mt-4 grid grid-cols-1 gap-3 sm:grid-cols-2">
          <label className="text-sm font-medium text-slate-700">
            Amount received (₹)
            <input inputMode="decimal" value={amount} onChange={(e) => setAmount(e.target.value.trim())} className={fieldClass} aria-label="Amount received" />
          </label>
          <label className="text-sm font-medium text-slate-700">
            Method
            <select value={method} onChange={(e) => setMethod(e.target.value as typeof method)} className={fieldClass}>
              {CRM_PAYMENT_METHODS.map((m) => (
                <option key={m} value={m}>
                  {m === 'upi' ? 'UPI' : m.replace('_', ' ').replace(/^\w/, (c) => c.toUpperCase())}
                </option>
              ))}
            </select>
          </label>
          <label className="text-sm font-medium text-slate-700">
            Date received
            <input type="date" value={paidOn} max={new Date().toISOString().slice(0, 10)} onChange={(e) => setPaidOn(e.target.value)} className={fieldClass} />
          </label>
          <label className="text-sm font-medium text-slate-700">
            Reference (UTR, cheque no.)
            <input value={reference} onChange={(e) => setReference(e.target.value)} maxLength={120} className={fieldClass} />
          </label>
        </div>

        <div className="mt-5">
          <p className="text-sm font-semibold text-slate-900">Apply to invoices</p>
          {invoices.length === 0 ? (
            <p className="mt-1 text-sm text-slate-500">No open invoices for this customer. The payment is kept as a credit.</p>
          ) : (
            <ul className="mt-2 space-y-2">
              {invoices.map((d) => (
                <li key={d.id} className="flex items-center justify-between gap-3 text-sm">
                  <span>
                    <span className="font-medium text-slate-900">{d.number}</span>
                    <span className="ml-2 text-slate-500">balance {formatMoney(d.balance_due)}</span>
                  </span>
                  <input
                    aria-label={`Apply to ${d.number}`}
                    inputMode="decimal"
                    value={apply[d.id] ?? ''}
                    onChange={(e) => setApply((a) => ({ ...a, [d.id]: e.target.value.trim() }))}
                    placeholder="0.00"
                    className="w-28 rounded-md border border-slate-300 px-2 py-1 text-right text-sm"
                  />
                </li>
              ))}
            </ul>
          )}
        </div>

        {error && (
          <p role="alert" className="mt-3 text-sm text-red-600">
            {error}
          </p>
        )}

        <div className="mt-6 flex justify-end gap-2">
          <button type="button" onClick={onCancel} className="rounded-lg border border-slate-300 px-4 py-2 text-sm font-medium text-slate-700 hover:bg-slate-50">
            Cancel
          </button>
          <button
            type="button"
            onClick={save}
            disabled={!valid || saving}
            className="flex items-center gap-2 rounded-lg bg-emerald-600 px-4 py-2 text-sm font-semibold text-white hover:bg-emerald-700 disabled:bg-slate-300"
          >
            {saving && <Loader2 className="h-4 w-4 animate-spin" />}
            Record payment
          </button>
        </div>
      </div>
    </div>
  );
}
