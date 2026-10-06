import { useEffect, useState } from 'react';
import billingService from '../../services/billingService';
import type { Invoice } from '../../types/billing';

/** What the client pays for an invoice, by the kind its plan key names. */
function kindLabel(invoice: Invoice): string {
  if (invoice.plan_key.startsWith('module_addon:')) return 'Group and add-on';
  if (invoice.plan_key === 'whatsapp_addon') return 'Extra WhatsApp numbers';
  return 'Plan';
}

function formatMoney(value: string | number): string {
  return `₹${Number(value).toLocaleString('en-IN', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`;
}

/**
 * Everything the client still owes, in one place: the plan, the extra numbers and the group add-ons.
 * Only the amounts it lists are due. Each part can be paid or recorded from its own row.
 */
export default function AmountsDueCard() {
  const [due, setDue] = useState<Invoice[] | null>(null);
  const [failed, setFailed] = useState(false);

  useEffect(() => {
    billingService
      .getInvoices(1, 100)
      .then((page) => setDue(page.data.filter((invoice) => invoice.status === 'pending')))
      .catch(() => setFailed(true));
  }, []);

  if (failed) return null;

  const total = (due ?? []).reduce((sum, invoice) => sum + Number(invoice.total_amount), 0);

  return (
    <section className="rounded-xl border border-slate-200 bg-white shadow-sm">
      <div className="flex flex-wrap items-center justify-between gap-3 border-b border-slate-200 px-6 py-4">
        <div>
          <h2 className="text-sm font-semibold text-slate-900">Amounts due</h2>
          <p className="mt-0.5 text-xs text-slate-500">
            Only the amounts listed here are due. Extra numbers and groups are optional, and you can add them while your plan is active.
          </p>
        </div>
        {due !== null && due.length > 0 && (
          <p className="text-sm font-semibold text-slate-900" data-testid="amounts-due-total">
            Total due: {formatMoney(total)}
          </p>
        )}
      </div>

      {due === null && <p className="px-6 py-6 text-sm text-slate-500">Loading…</p>}

      {due !== null && due.length === 0 && (
        <p className="px-6 py-6 text-sm text-slate-500">Nothing is due right now.</p>
      )}

      {due !== null && due.length > 0 && (
        <ul className="divide-y divide-slate-100">
          {due.map((invoice) => (
            <li key={invoice.id} className="flex flex-wrap items-center justify-between gap-3 px-6 py-3 text-sm">
              <div>
                <p className="font-medium text-slate-900">{invoice.plan_label}</p>
                <p className="text-xs text-slate-500">
                  {kindLabel(invoice)} · {invoice.invoice_number}
                </p>
              </div>
              <p className="font-semibold text-slate-900">{formatMoney(invoice.total_amount)}</p>
            </li>
          ))}
        </ul>
      )}
    </section>
  );
}
