import { useState } from 'react';
import { Loader2, XCircle } from 'lucide-react';
import crmSalesService from '../../services/crmSalesService';
import type { CrmDeal, CrmStage } from '../../types/crm';
import { describeApiError } from '../../utils/apiError';
import { useCrmQuery } from './crmHooks';

const fieldClass =
  'mt-1 w-full rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm text-slate-900 outline-none focus:border-emerald-500 focus:ring-2 focus:ring-emerald-100';

/**
 * CRM Sales Phase 1 — create or edit a deal. A new deal may pick its starting stage;
 * an existing deal's stage is changed on the board (PATCH {id}/stage), never here.
 */
export default function DealFormModal({
  deal,
  stages,
  onCancel,
  onSaved,
}: {
  deal?: CrmDeal;
  stages: CrmStage[];
  onCancel: () => void;
  onSaved: (deal: CrmDeal, message: string) => void;
}) {
  const [name, setName] = useState(deal?.name ?? '');
  const [value, setValue] = useState(deal?.value ?? '');
  const [closeDate, setCloseDate] = useState(deal?.expected_close_date ?? '');
  const [companyId, setCompanyId] = useState<string>(deal?.company ? String(deal.company.id) : '');
  const [stageId, setStageId] = useState<string>(() => String(stages.find((s) => s.kind === 'open')?.id ?? ''));
  const [saving, setSaving] = useState(false);
  const [error, setError] = useState<string | null>(null);

  const companies = useCrmQuery('deal-form-companies', () => crmSalesService.listCompanies('', 1, 100), 'Failed to load companies.');
  const valueOk = value === '' || /^\d{1,12}(\.\d{1,2})?$/.test(value);

  const save = () => {
    if (!name.trim() || !valueOk || saving) return;
    setSaving(true);
    setError(null);
    const payload = {
      name: name.trim(),
      value: value || '0',
      expected_close_date: closeDate || null,
      crm_company_id: companyId ? Number(companyId) : null,
    };
    (deal ? crmSalesService.updateDeal(deal.id, payload) : crmSalesService.createDeal({ ...payload, stage_id: stageId ? Number(stageId) : null }))
      .then((res) => onSaved(res.data, res.message))
      .catch((err: unknown) => {
        setError(describeApiError(err, 'Could not save the deal.').message);
        setSaving(false);
      });
  };

  return (
    <div className="fixed inset-0 z-50 flex items-center justify-center bg-slate-900/50 p-4">
      <div role="dialog" aria-labelledby="deal-form-title" className="w-full max-w-lg rounded-xl bg-white p-6 shadow-xl">
        <div className="flex items-center justify-between">
          <h3 id="deal-form-title" className="text-base font-semibold text-slate-900">
            {deal ? `Edit ${deal.number}` : 'New deal'}
          </h3>
          <button type="button" onClick={onCancel} className="text-slate-400 hover:text-slate-600" aria-label="Close">
            <XCircle className="h-5 w-5" />
          </button>
        </div>

        <div className="mt-4 grid grid-cols-1 gap-3 sm:grid-cols-2">
          <label className="text-sm font-medium text-slate-700 sm:col-span-2">
            Deal name <span className="text-red-500">*</span>
            <input value={name} onChange={(e) => setName(e.target.value)} maxLength={191} className={fieldClass} placeholder="e.g. Sharma Travels — annual plan" />
          </label>
          <label className="text-sm font-medium text-slate-700">
            Value (₹)
            <input inputMode="decimal" value={value} onChange={(e) => setValue(e.target.value.trim())} className={fieldClass} placeholder="0.00" />
            {!valueOk && <span className="mt-1 block text-xs text-red-600">Up to 2 decimals.</span>}
          </label>
          <label className="text-sm font-medium text-slate-700">
            Expected close date
            <input type="date" value={closeDate} onChange={(e) => setCloseDate(e.target.value)} className={fieldClass} />
          </label>
          <label className="text-sm font-medium text-slate-700">
            Company
            <select value={companyId} onChange={(e) => setCompanyId(e.target.value)} className={fieldClass}>
              <option value="">No company</option>
              {(companies.data?.data ?? []).map((c) => (
                <option key={c.id} value={c.id}>
                  {c.name}
                </option>
              ))}
            </select>
          </label>
          {!deal && (
            <label className="text-sm font-medium text-slate-700">
              Starting stage
              <select value={stageId} onChange={(e) => setStageId(e.target.value)} className={fieldClass}>
                {stages
                  .filter((s) => s.kind === 'open')
                  .map((s) => (
                    <option key={s.id} value={s.id}>
                      {s.name}
                    </option>
                  ))}
              </select>
            </label>
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
            disabled={saving || !name.trim() || !valueOk}
            className="flex items-center gap-2 rounded-lg bg-emerald-600 px-4 py-2 text-sm font-semibold text-white hover:bg-emerald-700 disabled:cursor-not-allowed disabled:bg-slate-300"
          >
            {saving && <Loader2 className="h-4 w-4 animate-spin" />}
            {deal ? 'Save' : 'Create deal'}
          </button>
        </div>
      </div>
    </div>
  );
}
