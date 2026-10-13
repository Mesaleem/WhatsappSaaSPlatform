import { useEffect, useState } from 'react';
import { Link, useNavigate, useParams, useSearchParams } from 'react-router-dom';
import { Loader2 } from 'lucide-react';
import crmDocumentsService, { type DocumentPayload } from '../../../services/crmDocumentsService';
import { Card } from '../../../components/common/Card';
import { ErrorBanner, NoClientSelected, Spinner } from '../../../components/crm/CrmUi';
import { useCrmContext } from '../../../components/crm/crmHooks';
import { CustomerPicker, emptyLine, fieldClass, LineItemsEditor } from '../../../components/crm/sales/SalesUi';
import { describeApiError } from '../../../utils/apiError';
import { CRM_DOC_TYPE_LABELS, CRM_DOC_TYPES, type CrmDocItem, type CrmDocType } from '../../../types/crmSales';

/**
 * CRM Sales Phase 2 — create or edit a DRAFT document (or a received customer PO). The
 * server owns totals, GST and numbering; saving returns the stored document. Issuing
 * happens on the detail page.
 */
export default function CrmDocumentEditorPage() {
  const { id } = useParams();
  const editingId = id ? Number(id) : null;
  const [params] = useSearchParams();
  const navigate = useNavigate();
  const { needsClient, readOnly } = useCrmContext();

  const initialType = (CRM_DOC_TYPES as readonly string[]).includes(params.get('type') ?? '') ? (params.get('type') as CrmDocType) : 'invoice';
  const [type, setType] = useState<CrmDocType>(initialType);
  const [form, setForm] = useState<DocumentPayload>({
    contact_id: params.get('contact_id') ? Number(params.get('contact_id')) : null,
    crm_company_id: params.get('crm_company_id') ? Number(params.get('crm_company_id')) : null,
    crm_deal_id: params.get('crm_deal_id') ? Number(params.get('crm_deal_id')) : null,
  });
  const [items, setItems] = useState<CrmDocItem[]>([emptyLine()]);
  const [loading, setLoading] = useState(editingId !== null);
  const [saving, setSaving] = useState(false);
  const [error, setError] = useState<string | null>(null);

  useEffect(() => {
    if (editingId === null || needsClient) return;
    crmDocumentsService
      .getDocument(editingId)
      .then((d) => {
        if (!d.editable) {
          navigate(`/crm/documents/${d.id}`, { replace: true });
          return;
        }
        setType(d.type);
        setForm({
          title: d.title, contact_id: d.contact_id, crm_company_id: d.crm_company_id, crm_deal_id: d.crm_deal_id, issue_date: d.issue_date,
          valid_until: d.valid_until, due_date: d.due_date, place_of_supply: d.place_of_supply, body: d.body, terms: d.terms, notes: d.notes,
          external_reference: d.external_reference,
        });
        setItems(d.items.length > 0 ? d.items : [emptyLine()]);
      })
      .catch((err: unknown) => setError(describeApiError(err, 'Failed to load the document.').message))
      .finally(() => setLoading(false));
  }, [editingId, needsClient, navigate]);

  const set = (patch: Partial<DocumentPayload>) => setForm((f) => ({ ...f, ...patch }));
  const usesItems = type !== 'proposal' || items.some((i) => i.description.trim() !== '');

  const save = () => {
    setSaving(true);
    setError(null);
    const payload: DocumentPayload = {
      ...form,
      items: items
        .filter((i) => i.description.trim() !== '' || i.crm_product_id !== null)
        .map(({ crm_product_id, description, hsn_sac, quantity, unit, unit_price, discount_amount, tax_rate }) => ({ crm_product_id, description, hsn_sac, quantity, unit, unit_price, discount_amount, tax_rate })),
    };
    (editingId ? crmDocumentsService.updateDocument(editingId, payload) : crmDocumentsService.createDocument({ ...payload, type }))
      .then((res) => navigate(`/crm/documents/${res.data.id}`))
      .catch((err: unknown) => {
        setError(describeApiError(err, 'Could not save the document.').message);
        setSaving(false);
      });
  };

  return (
    <div className="p-6">
      <div className="w-full space-y-6">
        <div>
          <Link to={`/crm/documents?type=${type}`} className="text-xs font-medium text-emerald-700 hover:underline">
            All {CRM_DOC_TYPE_LABELS[type].toLowerCase()}s
          </Link>
          <h1 className="mt-1 text-xl font-semibold text-slate-900">{editingId ? `Edit draft ${CRM_DOC_TYPE_LABELS[type].toLowerCase()}` : `New ${CRM_DOC_TYPE_LABELS[type].toLowerCase()}`}</h1>
          <p className="mt-1 text-sm text-slate-500">Saved as a draft. Issue it from the next page to give it a number and send it.</p>
        </div>

        {needsClient ? (
          <NoClientSelected what="sales documents" />
        ) : loading ? (
          <Spinner />
        ) : (
          <Card className="space-y-6">
            {error && <ErrorBanner message={error} />}
            {!editingId && (
              <label className="block max-w-xs text-sm font-medium text-slate-700">
                Document type
                <select value={type} onChange={(e) => setType(e.target.value as CrmDocType)} className={fieldClass}>
                  {CRM_DOC_TYPES.filter((t) => t !== 'credit_note').map((t) => (
                    <option key={t} value={t}>
                      {CRM_DOC_TYPE_LABELS[t]}
                    </option>
                  ))}
                </select>
              </label>
            )}

            <CustomerPicker contactId={form.contact_id ?? null} companyId={form.crm_company_id ?? null} onChange={(c) => set(c)} disabled={readOnly} />

            <div className="grid grid-cols-1 gap-3 sm:grid-cols-4">
              <label className="text-sm font-medium text-slate-700 sm:col-span-2">
                Title (optional)
                <input value={form.title ?? ''} onChange={(e) => set({ title: e.target.value })} maxLength={191} className={fieldClass} />
              </label>
              {type === 'customer_po' ? (
                <label className="text-sm font-medium text-slate-700 sm:col-span-2">
                  Customer's PO number
                  <input value={form.external_reference ?? ''} onChange={(e) => set({ external_reference: e.target.value })} maxLength={100} className={fieldClass} />
                </label>
              ) : (
                <>
                  <label className="text-sm font-medium text-slate-700">
                    Date
                    <input type="date" value={form.issue_date ?? ''} onChange={(e) => set({ issue_date: e.target.value || null })} className={fieldClass} />
                  </label>
                  {type === 'invoice' ? (
                    <label className="text-sm font-medium text-slate-700">
                      Due date
                      <input type="date" value={form.due_date ?? ''} onChange={(e) => set({ due_date: e.target.value || null })} className={fieldClass} />
                    </label>
                  ) : (
                    <label className="text-sm font-medium text-slate-700">
                      Valid until
                      <input type="date" value={form.valid_until ?? ''} onChange={(e) => set({ valid_until: e.target.value || null })} className={fieldClass} />
                    </label>
                  )}
                </>
              )}
              <label className="text-sm font-medium text-slate-700">
                Place of supply (state code)
                <input value={form.place_of_supply ?? ''} onChange={(e) => set({ place_of_supply: e.target.value.replace(/\D/g, '').slice(0, 2) || null })} placeholder="e.g. 27" className={fieldClass} />
              </label>
            </div>

            {type === 'proposal' && (
              <label className="block text-sm font-medium text-slate-700">
                Scope, deliverables and timeline
                <textarea rows={6} value={form.body ?? ''} onChange={(e) => set({ body: e.target.value })} maxLength={10000} className={fieldClass} />
              </label>
            )}

            {(type !== 'proposal' || usesItems) && <LineItemsEditor items={items} onChange={setItems} disabled={readOnly} />}

            <div className="grid grid-cols-1 gap-3 sm:grid-cols-2">
              <label className="text-sm font-medium text-slate-700">
                Terms (optional — defaults to your business terms)
                <textarea rows={3} value={form.terms ?? ''} onChange={(e) => set({ terms: e.target.value })} maxLength={2000} className={fieldClass} />
              </label>
              <label className="text-sm font-medium text-slate-700">
                Internal notes
                <textarea rows={3} value={form.notes ?? ''} onChange={(e) => set({ notes: e.target.value })} maxLength={2000} className={fieldClass} />
              </label>
            </div>

            <div className="flex justify-end gap-2">
              <button type="button" onClick={() => navigate(-1)} className="rounded-lg border border-slate-300 px-4 py-2 text-sm font-medium text-slate-700 hover:bg-slate-50">
                Cancel
              </button>
              <button
                type="button"
                onClick={save}
                disabled={saving || readOnly}
                className="flex items-center gap-2 rounded-lg bg-emerald-600 px-4 py-2 text-sm font-semibold text-white hover:bg-emerald-700 disabled:bg-slate-300"
              >
                {saving && <Loader2 className="h-4 w-4 animate-spin" />}
                Save draft
              </button>
            </div>
          </Card>
        )}
      </div>
    </div>
  );
}
