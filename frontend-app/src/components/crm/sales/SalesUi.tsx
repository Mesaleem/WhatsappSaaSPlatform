import { useState } from 'react';
import { Plus, Trash2 } from 'lucide-react';
import crmService from '../../../services/crmService';
import crmSalesService from '../../../services/crmSalesService';
import crmDocumentsService from '../../../services/crmDocumentsService';
import { useCrmQuery } from '../crmHooks';
import { crmDocStatusLabel, type CrmDocItem } from '../../../types/crmSales';

export const fieldClass =
  'mt-1 w-full rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm text-slate-900 outline-none focus:border-emerald-500 focus:ring-2 focus:ring-emerald-100 disabled:bg-slate-50';

const STATUS_STYLES: Record<string, string> = {
  draft: 'bg-slate-100 text-slate-600 ring-slate-200',
  sent: 'bg-sky-50 text-sky-700 ring-sky-200',
  issued: 'bg-sky-50 text-sky-700 ring-sky-200',
  received: 'bg-sky-50 text-sky-700 ring-sky-200',
  confirmed: 'bg-sky-50 text-sky-700 ring-sky-200',
  approved: 'bg-teal-50 text-teal-700 ring-teal-200',
  accepted: 'bg-emerald-50 text-emerald-700 ring-emerald-200',
  fulfilled: 'bg-emerald-50 text-emerald-700 ring-emerald-200',
  paid: 'bg-emerald-50 text-emerald-700 ring-emerald-200',
  successful: 'bg-emerald-50 text-emerald-700 ring-emerald-200',
  partially_paid: 'bg-amber-50 text-amber-700 ring-amber-200',
  awaiting_approval: 'bg-amber-50 text-amber-700 ring-amber-200',
  pending: 'bg-amber-50 text-amber-700 ring-amber-200',
  rejected: 'bg-red-50 text-red-700 ring-red-200',
  expired: 'bg-red-50 text-red-700 ring-red-200',
  failed: 'bg-red-50 text-red-700 ring-red-200',
  void: 'bg-slate-100 text-slate-500 ring-slate-200',
  cancelled: 'bg-slate-100 text-slate-500 ring-slate-200',
  reversed: 'bg-slate-100 text-slate-500 ring-slate-200',
  skipped: 'bg-slate-100 text-slate-500 ring-slate-200',
};

export function DocStatusBadge({ status, overdue = false }: { status: string; overdue?: boolean }) {
  return (
    <span className="inline-flex items-center gap-1">
      <span className={`inline-flex rounded-full px-2 py-0.5 text-xs font-medium ring-1 ring-inset ${STATUS_STYLES[status] ?? STATUS_STYLES.draft}`}>
        {crmDocStatusLabel(status)}
      </span>
      {overdue && <span className="inline-flex rounded-full bg-red-50 px-2 py-0.5 text-xs font-semibold text-red-700 ring-1 ring-inset ring-red-200">Overdue</span>}
    </span>
  );
}

/**
 * Pick the customer: a CRM contact (search by name / phone) and/or a company. The
 * server re-checks both belong to this account.
 */
export function CustomerPicker({
  contactId,
  companyId,
  onChange,
  disabled,
}: {
  contactId: number | null;
  companyId: number | null;
  onChange: (next: { contact_id: number | null; crm_company_id: number | null }) => void;
  disabled?: boolean;
}) {
  const [search, setSearch] = useState('');
  const contacts = useCrmQuery(`picker-contacts:${search}`, () => crmService.listContacts(search, 1, 20), 'Failed to load contacts.');
  const companies = useCrmQuery('picker-companies', () => crmSalesService.listCompanies('', 1, 100), 'Failed to load companies.');
  const contactOptions = contacts.data?.data ?? [];

  return (
    <div className="grid grid-cols-1 gap-3 sm:grid-cols-2">
      <label className="text-sm font-medium text-slate-700">
        Contact
        <input
          value={search}
          onChange={(e) => setSearch(e.target.value)}
          placeholder="Search name or phone…"
          disabled={disabled}
          className={fieldClass}
          aria-label="Search contacts"
        />
        <select
          aria-label="Contact"
          value={contactId ?? ''}
          disabled={disabled}
          onChange={(e) => onChange({ contact_id: e.target.value ? Number(e.target.value) : null, crm_company_id: companyId })}
          className={fieldClass}
        >
          <option value="">No contact</option>
          {contactId !== null && !contactOptions.some((c) => c.id === contactId) && <option value={contactId}>Contact #{contactId}</option>}
          {contactOptions.map((c) => (
            <option key={c.id} value={c.id}>
              {(c.name || 'Unnamed') + ' · ' + c.phone_number}
            </option>
          ))}
        </select>
      </label>
      <label className="text-sm font-medium text-slate-700">
        Company
        <select
          aria-label="Company"
          value={companyId ?? ''}
          disabled={disabled}
          onChange={(e) => onChange({ contact_id: contactId, crm_company_id: e.target.value ? Number(e.target.value) : null })}
          className={`${fieldClass} mt-[3.25rem]`}
        >
          <option value="">No company</option>
          {(companies.data?.data ?? []).map((c) => (
            <option key={c.id} value={c.id}>
              {c.name}
              {c.gstin ? ` · ${c.gstin}` : ''}
            </option>
          ))}
        </select>
      </label>
    </div>
  );
}

export const emptyLine = (): CrmDocItem => ({
  crm_product_id: null,
  description: '',
  hsn_sac: null,
  quantity: '1',
  unit: 'nos',
  unit_price: '0',
  discount_amount: '0',
  tax_rate: '0',
});

/**
 * Edit document lines. A product fills the line from the catalogue (the server copies
 * it as a snapshot). Totals and GST are calculated by the server on save — not here —
 * so what is shown after saving is exactly what the document holds.
 */
export function LineItemsEditor({ items, onChange, disabled }: { items: CrmDocItem[]; onChange: (items: CrmDocItem[]) => void; disabled?: boolean }) {
  const products = useCrmQuery('editor-products', () => crmDocumentsService.listProducts('', 1, true), 'Failed to load products.');
  const catalogue = products.data?.data ?? [];
  const set = (i: number, patch: Partial<CrmDocItem>) => onChange(items.map((it, j) => (j === i ? { ...it, ...patch } : it)));
  const cell = 'w-full rounded-md border border-slate-300 px-2 py-1.5 text-sm outline-none focus:border-emerald-500 disabled:bg-slate-50';

  return (
    <div className="space-y-2" data-testid="crm-line-items">
      <div className="overflow-x-auto">
        <table className="min-w-full text-sm">
          <thead>
            <tr className="text-left text-xs font-medium uppercase tracking-wide text-slate-500">
              <th className="py-2 pr-2">Product / description</th>
              <th className="w-20 py-2 pr-2">Qty</th>
              <th className="w-28 py-2 pr-2">Rate (₹)</th>
              <th className="w-24 py-2 pr-2">Discount (₹)</th>
              <th className="w-20 py-2 pr-2">GST %</th>
              <th className="w-10" />
            </tr>
          </thead>
          <tbody>
            {items.map((it, i) => (
              <tr key={i} className="align-top">
                <td className="py-1 pr-2">
                  <select
                    aria-label={`Line ${i + 1} product`}
                    value={it.crm_product_id ?? ''}
                    disabled={disabled}
                    onChange={(e) => {
                      const p = catalogue.find((x) => x.id === Number(e.target.value));
                      set(i, p ? { crm_product_id: p.id, description: p.name, unit_price: p.unit_price, tax_rate: p.tax_rate, hsn_sac: p.hsn_sac, unit: p.unit } : { crm_product_id: null });
                    }}
                    className={cell}
                  >
                    <option value="">Custom line</option>
                    {catalogue.map((p) => (
                      <option key={p.id} value={p.id}>
                        {p.name}
                      </option>
                    ))}
                  </select>
                  <input aria-label={`Line ${i + 1} description`} value={it.description} disabled={disabled} maxLength={500} onChange={(e) => set(i, { description: e.target.value })} placeholder="Description" className={`${cell} mt-1`} />
                </td>
                <td className="py-1 pr-2">
                  <input aria-label={`Line ${i + 1} quantity`} inputMode="decimal" value={it.quantity} disabled={disabled} onChange={(e) => set(i, { quantity: e.target.value.trim() })} className={cell} />
                </td>
                <td className="py-1 pr-2">
                  <input aria-label={`Line ${i + 1} rate`} inputMode="decimal" value={it.unit_price} disabled={disabled} onChange={(e) => set(i, { unit_price: e.target.value.trim() })} className={cell} />
                </td>
                <td className="py-1 pr-2">
                  <input aria-label={`Line ${i + 1} discount`} inputMode="decimal" value={it.discount_amount} disabled={disabled} onChange={(e) => set(i, { discount_amount: e.target.value.trim() })} className={cell} />
                </td>
                <td className="py-1 pr-2">
                  <input aria-label={`Line ${i + 1} GST`} inputMode="decimal" value={it.tax_rate} disabled={disabled} onChange={(e) => set(i, { tax_rate: e.target.value.trim() })} className={cell} />
                </td>
                <td className="py-1">
                  <button
                    type="button"
                    onClick={() => onChange(items.filter((_, j) => j !== i))}
                    disabled={disabled}
                    aria-label={`Remove line ${i + 1}`}
                    className="rounded p-1.5 text-slate-400 hover:bg-red-50 hover:text-red-600 disabled:opacity-40"
                  >
                    <Trash2 className="h-4 w-4" />
                  </button>
                </td>
              </tr>
            ))}
          </tbody>
        </table>
      </div>
      <button
        type="button"
        onClick={() => onChange([...items, emptyLine()])}
        disabled={disabled || items.length >= 100}
        className="flex items-center gap-1 rounded-lg border border-dashed border-slate-300 px-3 py-1.5 text-sm text-slate-700 hover:bg-slate-50 disabled:opacity-50"
      >
        <Plus className="h-4 w-4" /> Add line
      </button>
      <p className="text-xs text-slate-500">GST (CGST + SGST, or IGST for another state) and totals are calculated when you save.</p>
    </div>
  );
}
