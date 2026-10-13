import { useState } from 'react';
import { Loader2, Pencil, Plus, Trash2, XCircle } from 'lucide-react';
import crmDocumentsService from '../../../services/crmDocumentsService';
import { TableCard } from '../../../components/common/Card';
import ConfirmModal from '../../../components/common/ConfirmModal';
import { SearchInput } from '../../../components/common/DataTableControls';
import { TableSkeletonRows } from '../../../components/common/Skeleton';
import { EmptyState, ErrorBanner, NoClientSelected, READ_ONLY_TITLE, Toast } from '../../../components/crm/CrmUi';
import { useCrmContext, useCrmQuery, useCrmToast } from '../../../components/crm/crmHooks';
import { fieldClass } from '../../../components/crm/sales/SalesUi';
import { describeApiError } from '../../../utils/apiError';
import { crmLabel, formatMoney } from '../../../types/crm';
import type { CrmProduct } from '../../../types/crmSales';

/**
 * CRM Sales Phase 2 — products and services. A document copies a product's name, price
 * and tax when it is added, so editing or deleting a product never changes a document.
 */
export default function CrmProductsPage() {
  const { needsClient, readOnly, selectedAccountId } = useCrmContext();
  const { toast, show } = useCrmToast();
  const [search, setSearch] = useState('');
  const [version, setVersion] = useState(0);
  const [editing, setEditing] = useState<CrmProduct | 'new' | null>(null);
  const [deleting, setDeleting] = useState<CrmProduct | null>(null);

  const query = useCrmQuery(needsClient ? null : `products:${selectedAccountId ?? 'own'}:${search}:${version}`, () => crmDocumentsService.listProducts(search), 'Failed to load products.');
  const rows = query.data?.data ?? [];

  return (
    <div className="p-6">
      <div className="w-full space-y-6">
        <div className="flex flex-wrap items-start justify-between gap-3">
          <div>
            <h1 className="text-xl font-semibold text-slate-900">Products & services</h1>
            <p className="mt-1 text-sm text-slate-500">What you sell, with price, GST and HSN/SAC, ready to add to quotations and invoices.</p>
          </div>
          {!needsClient && (
            <button type="button" onClick={() => setEditing('new')} disabled={readOnly} title={readOnly ? READ_ONLY_TITLE : undefined} className="flex items-center gap-2 rounded-lg bg-emerald-600 px-4 py-2 text-sm font-semibold text-white hover:bg-emerald-700 disabled:opacity-60">
              <Plus className="h-4 w-4" /> New product
            </button>
          )}
        </div>
        {needsClient ? (
          <NoClientSelected what="products" />
        ) : (
          <>
            <SearchInput value={search} onChange={setSearch} placeholder="Search name or code…" />
            {query.error && <ErrorBanner message={query.error} />}
            <TableCard>
              <table className="min-w-full divide-y divide-slate-200 text-sm">
                <thead className="bg-slate-50">
                  <tr>
                    <th className="px-4 py-3 text-left font-medium text-slate-600">Product</th>
                    <th className="px-4 py-3 text-left font-medium text-slate-600">Billing</th>
                    <th className="px-4 py-3 text-right font-medium text-slate-600">Price</th>
                    <th className="px-4 py-3 text-right font-medium text-slate-600">GST</th>
                    <th className="px-4 py-3" />
                  </tr>
                </thead>
                <tbody className="divide-y divide-slate-100">
                  {query.isLoading ? (
                    <TableSkeletonRows columns={5} />
                  ) : rows.length === 0 ? (
                    <tr>
                      <td colSpan={5}>
                        <EmptyState title="No products yet." hint="Add what you sell to reuse it on every quotation and invoice." />
                      </td>
                    </tr>
                  ) : (
                    rows.map((p) => (
                      <tr key={p.id} className={p.is_active ? '' : 'text-slate-400'}>
                        <td className="px-4 py-3">
                          <p className="font-medium text-slate-900">{p.name}</p>
                          <p className="text-xs text-slate-500">
                            {[p.code, p.hsn_sac ? `HSN/SAC ${p.hsn_sac}` : null, p.is_active ? null : 'inactive'].filter(Boolean).join(' · ')}
                          </p>
                        </td>
                        <td className="px-4 py-3 text-slate-700">{crmLabel(p.billing_frequency)}</td>
                        <td className="whitespace-nowrap px-4 py-3 text-right">
                          {formatMoney(p.unit_price)} / {p.unit}
                        </td>
                        <td className="px-4 py-3 text-right">{Number(p.tax_rate)}%</td>
                        <td className="whitespace-nowrap px-4 py-3 text-right">
                          <button type="button" onClick={() => setEditing(p)} disabled={readOnly} aria-label={`Edit ${p.name}`} className="rounded p-1.5 text-slate-400 hover:bg-slate-100 hover:text-slate-700 disabled:opacity-40">
                            <Pencil className="h-4 w-4" />
                          </button>
                          <button type="button" onClick={() => setDeleting(p)} disabled={readOnly} aria-label={`Delete ${p.name}`} className="rounded p-1.5 text-slate-400 hover:bg-red-50 hover:text-red-600 disabled:opacity-40">
                            <Trash2 className="h-4 w-4" />
                          </button>
                        </td>
                      </tr>
                    ))
                  )}
                </tbody>
              </table>
            </TableCard>
          </>
        )}
      </div>
      {editing && (
        <ProductModal
          product={editing === 'new' ? null : editing}
          onCancel={() => setEditing(null)}
          onSaved={(m) => {
            setEditing(null);
            show(m);
            setVersion((v) => v + 1);
          }}
        />
      )}
      {deleting && (
        <ConfirmModal
          title="Delete product"
          message={`Delete ${deleting.name}? Quotations and invoices that used it keep their own copy.`}
          confirmLabel="Delete"
          onConfirm={() =>
            crmDocumentsService
              .deleteProduct(deleting.id)
              .then((r) => {
                show(r.message);
                setVersion((v) => v + 1);
              })
              .catch((e: unknown) => show(describeApiError(e, 'Could not delete.').message))
              .finally(() => setDeleting(null))
          }
          onCancel={() => setDeleting(null)}
        />
      )}
      <Toast message={toast} />
    </div>
  );
}

function ProductModal({ product, onCancel, onSaved }: { product: CrmProduct | null; onCancel: () => void; onSaved: (message: string) => void }) {
  const [form, setForm] = useState({
    name: product?.name ?? '',
    code: product?.code ?? '',
    unit_price: product?.unit_price ?? '',
    tax_rate: product?.tax_rate ?? '18',
    hsn_sac: product?.hsn_sac ?? '',
    unit: product?.unit ?? 'nos',
    billing_frequency: product?.billing_frequency ?? 'one_time',
    is_active: product?.is_active ?? true,
  });
  const [saving, setSaving] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const ok = form.name.trim() !== '' && /^\d{1,10}(\.\d{1,2})?$/.test(form.unit_price) && /^\d{1,3}(\.\d{1,2})?$/.test(form.tax_rate);

  const save = () => {
    setSaving(true);
    setError(null);
    crmDocumentsService
      .saveProduct(product?.id ?? null, { ...form, code: form.code.trim() || null, hsn_sac: form.hsn_sac.trim() || null })
      .then((r) => onSaved(r.message))
      .catch((e: unknown) => {
        setError(describeApiError(e, 'Could not save the product.').message);
        setSaving(false);
      });
  };

  return (
    <div className="fixed inset-0 z-50 flex items-center justify-center bg-slate-900/50 p-4">
      <div role="dialog" aria-labelledby="product-title" className="w-full max-w-lg rounded-xl bg-white p-6 shadow-xl">
        <div className="flex items-center justify-between">
          <h3 id="product-title" className="text-base font-semibold text-slate-900">{product ? 'Edit product' : 'New product'}</h3>
          <button type="button" onClick={onCancel} aria-label="Close" className="text-slate-400 hover:text-slate-600">
            <XCircle className="h-5 w-5" />
          </button>
        </div>
        <div className="mt-4 grid grid-cols-1 gap-3 sm:grid-cols-2">
          <label className="text-sm font-medium text-slate-700 sm:col-span-2">
            Name <span className="text-red-500">*</span>
            <input value={form.name} onChange={(e) => setForm({ ...form, name: e.target.value })} maxLength={191} className={fieldClass} />
          </label>
          <label className="text-sm font-medium text-slate-700">
            Price (₹, before GST)
            <input inputMode="decimal" value={form.unit_price} onChange={(e) => setForm({ ...form, unit_price: e.target.value.trim() })} className={fieldClass} />
          </label>
          <label className="text-sm font-medium text-slate-700">
            GST %
            <select value={form.tax_rate} onChange={(e) => setForm({ ...form, tax_rate: e.target.value })} className={fieldClass}>
              {['0', '5', '12', '18', '28'].map((r) => (
                <option key={r} value={r}>
                  {r}%
                </option>
              ))}
              {!['0', '5', '12', '18', '28'].includes(String(Number(form.tax_rate))) && <option value={form.tax_rate}>{form.tax_rate}%</option>}
            </select>
          </label>
          <label className="text-sm font-medium text-slate-700">
            Code
            <input value={form.code} onChange={(e) => setForm({ ...form, code: e.target.value })} maxLength={50} className={fieldClass} />
          </label>
          <label className="text-sm font-medium text-slate-700">
            HSN / SAC
            <input value={form.hsn_sac} onChange={(e) => setForm({ ...form, hsn_sac: e.target.value })} maxLength={20} className={fieldClass} />
          </label>
          <label className="text-sm font-medium text-slate-700">
            Unit
            <input value={form.unit} onChange={(e) => setForm({ ...form, unit: e.target.value })} maxLength={20} className={fieldClass} />
          </label>
          <label className="text-sm font-medium text-slate-700">
            Billing
            <select value={form.billing_frequency} onChange={(e) => setForm({ ...form, billing_frequency: e.target.value as typeof form.billing_frequency })} className={fieldClass}>
              <option value="one_time">One time</option>
              <option value="monthly">Monthly</option>
              <option value="yearly">Yearly</option>
            </select>
          </label>
          <label className="flex items-center gap-2 text-sm font-medium text-slate-700 sm:col-span-2">
            <input type="checkbox" checked={form.is_active} onChange={(e) => setForm({ ...form, is_active: e.target.checked })} className="h-4 w-4 rounded border-slate-300 text-emerald-600" />
            Active (offered on new documents)
          </label>
        </div>
        {error && <p role="alert" className="mt-3 text-sm text-red-600">{error}</p>}
        <div className="mt-6 flex justify-end gap-2">
          <button type="button" onClick={onCancel} className="rounded-lg border border-slate-300 px-4 py-2 text-sm font-medium text-slate-700 hover:bg-slate-50">
            Cancel
          </button>
          <button type="button" onClick={save} disabled={!ok || saving} className="flex items-center gap-2 rounded-lg bg-emerald-600 px-4 py-2 text-sm font-semibold text-white hover:bg-emerald-700 disabled:bg-slate-300">
            {saving && <Loader2 className="h-4 w-4 animate-spin" />}
            Save
          </button>
        </div>
      </div>
    </div>
  );
}
