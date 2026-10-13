import { useState } from 'react';
import { Loader2, XCircle } from 'lucide-react';
import crmSalesService, { type CompanyPayload } from '../../services/crmSalesService';
import { CRM_COMPANY_TYPES, crmLabel, type CrmCompany, type CrmCompanyType } from '../../types/crm';
import { describeApiError } from '../../utils/apiError';

const fieldClass =
  'mt-1 w-full rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm text-slate-900 outline-none focus:border-emerald-500 focus:ring-2 focus:ring-emerald-100';

const FIELDS: { key: keyof CompanyPayload; label: string; max: number; span?: boolean }[] = [
  { key: 'industry', label: 'Industry', max: 100 },
  { key: 'website', label: 'Website', max: 255 },
  { key: 'phone', label: 'Phone', max: 32 },
  { key: 'email', label: 'Email', max: 191 },
  { key: 'address_line1', label: 'Address', max: 191, span: true },
  { key: 'address_line2', label: 'Address line 2', max: 191, span: true },
  { key: 'city', label: 'City', max: 100 },
  { key: 'state', label: 'State', max: 100 },
  { key: 'pincode', label: 'PIN code', max: 12 },
  { key: 'country', label: 'Country', max: 100 },
];

/** CRM Sales Phase 1 — create or edit a customer company. */
export default function CompanyFormModal({
  company,
  onCancel,
  onSaved,
}: {
  company?: CrmCompany;
  onCancel: () => void;
  onSaved: (company: CrmCompany, message: string) => void;
}) {
  const [form, setForm] = useState<Record<string, string>>(() => {
    const initial: Record<string, string> = { name: company?.name ?? '', gstin: company?.gstin ?? '', type: company?.type ?? 'prospect' };
    FIELDS.forEach((f) => {
      initial[f.key] = (company?.[f.key as keyof CrmCompany] as string | null) ?? '';
    });
    return initial;
  });
  const [saving, setSaving] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const gstinOk = form.gstin === '' || /^[0-9]{2}[A-Za-z0-9]{13}$/.test(form.gstin);

  const save = () => {
    if (!form.name.trim() || !gstinOk || saving) return;
    setSaving(true);
    setError(null);
    const payload: CompanyPayload = { name: form.name.trim(), gstin: form.gstin.trim() || null, type: form.type as CrmCompanyType };
    FIELDS.forEach((f) => {
      (payload as Record<string, unknown>)[f.key] = form[f.key].trim() || null;
    });
    (company ? crmSalesService.updateCompany(company.id, payload) : crmSalesService.createCompany(payload))
      .then((res) => onSaved(res.data, res.message))
      .catch((err: unknown) => {
        setError(describeApiError(err, 'Could not save the company.').message);
        setSaving(false);
      });
  };

  return (
    <div className="fixed inset-0 z-50 flex items-center justify-center bg-slate-900/50 p-4">
      <div role="dialog" aria-labelledby="company-form-title" className="max-h-[90vh] w-full max-w-2xl overflow-y-auto rounded-xl bg-white p-6 shadow-xl">
        <div className="flex items-center justify-between">
          <h3 id="company-form-title" className="text-base font-semibold text-slate-900">
            {company ? 'Edit company' : 'New company'}
          </h3>
          <button type="button" onClick={onCancel} className="text-slate-400 hover:text-slate-600" aria-label="Close">
            <XCircle className="h-5 w-5" />
          </button>
        </div>

        <div className="mt-4 grid grid-cols-1 gap-3 sm:grid-cols-2">
          <label className="text-sm font-medium text-slate-700 sm:col-span-2">
            Company name <span className="text-red-500">*</span>
            <input value={form.name} onChange={(e) => setForm({ ...form, name: e.target.value })} maxLength={191} className={fieldClass} />
          </label>
          <label className="text-sm font-medium text-slate-700">
            GSTIN
            <input value={form.gstin} onChange={(e) => setForm({ ...form, gstin: e.target.value.toUpperCase() })} maxLength={15} className={fieldClass} />
            {!gstinOk && <span className="mt-1 block text-xs text-red-600">A GSTIN has 15 characters, starting with 2 digits.</span>}
          </label>
          <label className="text-sm font-medium text-slate-700">
            Type
            <select value={form.type} onChange={(e) => setForm({ ...form, type: e.target.value })} className={fieldClass}>
              {CRM_COMPANY_TYPES.map((t) => (
                <option key={t} value={t}>
                  {crmLabel(t)}
                </option>
              ))}
            </select>
          </label>
          {FIELDS.map((f) => (
            <label key={f.key} className={`text-sm font-medium text-slate-700 ${f.span ? 'sm:col-span-2' : ''}`}>
              {f.label}
              <input value={form[f.key]} onChange={(e) => setForm({ ...form, [f.key]: e.target.value })} maxLength={f.max} className={fieldClass} />
            </label>
          ))}
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
            disabled={saving || !form.name.trim() || !gstinOk}
            className="flex items-center gap-2 rounded-lg bg-emerald-600 px-4 py-2 text-sm font-semibold text-white hover:bg-emerald-700 disabled:cursor-not-allowed disabled:bg-slate-300"
          >
            {saving && <Loader2 className="h-4 w-4 animate-spin" />}
            {company ? 'Save' : 'Create company'}
          </button>
        </div>
      </div>
    </div>
  );
}
