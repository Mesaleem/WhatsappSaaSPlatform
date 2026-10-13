import { useState } from 'react';
import { Loader2, XCircle } from 'lucide-react';
import crmSalesService from '../../services/crmSalesService';
import type { CrmLead } from '../../types/crm';
import { describeApiError } from '../../utils/apiError';

const fieldClass =
  'mt-1 w-full rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm text-slate-900 outline-none focus:border-emerald-500 focus:ring-2 focus:ring-emerald-100';

/**
 * CRM Sales Phase 1 — Convert Lead. The lead becomes a customer: optionally linked to
 * a company (an existing one with the same GSTIN or name is reused, server-side) and,
 * if chosen, a new deal. Converting is final for the lead (re-engage makes a new one)
 * and safe to repeat: the server never makes a second deal for the same lead.
 */
export default function ConvertLeadModal({
  lead,
  onCancel,
  onDone,
}: {
  lead: CrmLead;
  onCancel: () => void;
  onDone: (lead: CrmLead, message: string) => void;
}) {
  const [companyName, setCompanyName] = useState(lead.company?.name ?? lead.company_name ?? '');
  const [gstin, setGstin] = useState('');
  const [createDeal, setCreateDeal] = useState(true);
  const [dealName, setDealName] = useState('');
  const [dealValue, setDealValue] = useState(lead.budget ?? '');
  const [closeDate, setCloseDate] = useState('');
  const [saving, setSaving] = useState(false);
  const [error, setError] = useState<string | null>(null);

  const valueOk = dealValue === '' || /^\d{1,12}(\.\d{1,2})?$/.test(dealValue);
  const gstinOk = gstin === '' || /^[0-9]{2}[A-Za-z0-9]{13}$/.test(gstin);

  const submit = () => {
    if (saving || !valueOk || !gstinOk) return;
    setSaving(true);
    setError(null);
    crmSalesService
      .convertLead(lead.id, {
        ...(lead.company ? {} : companyName.trim() ? { company: { name: companyName.trim(), gstin: gstin.trim() || null } } : {}),
        create_deal: createDeal,
        ...(createDeal
          ? { deal: { name: dealName.trim() || undefined, value: dealValue || undefined, expected_close_date: closeDate || null } }
          : {}),
      })
      .then((res) => onDone(res.data, res.message))
      .catch((err: unknown) => {
        setError(describeApiError(err, 'Could not convert the lead.').message);
        setSaving(false);
      });
  };

  return (
    <div className="fixed inset-0 z-50 flex items-center justify-center bg-slate-900/50 p-4">
      <div role="dialog" aria-labelledby="convert-title" className="w-full max-w-lg rounded-xl bg-white p-6 shadow-xl" data-testid="crm-convert-modal">
        <div className="flex items-center justify-between">
          <h3 id="convert-title" className="text-base font-semibold text-slate-900">
            Convert lead
          </h3>
          <button type="button" onClick={onCancel} className="text-slate-400 hover:text-slate-600" aria-label="Close">
            <XCircle className="h-5 w-5" />
          </button>
        </div>
        <p className="mt-1 text-xs text-slate-500">
          The lead becomes a customer. Its source, history and tags are kept. A converted lead cannot go back to new; if this
          customer returns later, use Re-engage.
        </p>

        <div className="mt-4 space-y-4">
          {lead.company ? (
            <p className="rounded-lg bg-slate-50 px-3 py-2 text-sm text-slate-700">
              Company: <strong>{lead.company.name}</strong>
            </p>
          ) : (
            <div className="grid grid-cols-1 gap-3 sm:grid-cols-2">
              <label className="text-sm font-medium text-slate-700">
                Company (optional)
                <input value={companyName} onChange={(e) => setCompanyName(e.target.value)} maxLength={191} className={fieldClass} placeholder="Leave empty for an individual" />
              </label>
              <label className="text-sm font-medium text-slate-700">
                GSTIN (optional)
                <input value={gstin} onChange={(e) => setGstin(e.target.value.toUpperCase())} maxLength={15} className={fieldClass} placeholder="27ABCDE1234F1Z5" />
                {!gstinOk && <span className="mt-1 block text-xs text-red-600">A GSTIN has 15 characters, starting with 2 digits.</span>}
              </label>
              <p className="text-xs text-slate-500 sm:col-span-2">A company with the same GSTIN or name is reused, never duplicated.</p>
            </div>
          )}

          {lead.deal ? (
            <p className="rounded-lg bg-slate-50 px-3 py-2 text-sm text-slate-700">
              Deal: <strong>{lead.deal.number}</strong> {lead.deal.name}
            </p>
          ) : (
            <div className="space-y-3">
              <label className="flex items-center gap-2 text-sm font-medium text-slate-700">
                <input type="checkbox" checked={createDeal} onChange={(e) => setCreateDeal(e.target.checked)} className="h-4 w-4 rounded border-slate-300 text-emerald-600" />
                Create a deal for this sale
              </label>
              {createDeal && (
                <div className="grid grid-cols-1 gap-3 sm:grid-cols-3">
                  <label className="text-sm font-medium text-slate-700 sm:col-span-3">
                    Deal name (optional)
                    <input value={dealName} onChange={(e) => setDealName(e.target.value)} maxLength={191} className={fieldClass} placeholder="Defaults to the company or contact name" />
                  </label>
                  <label className="text-sm font-medium text-slate-700">
                    Value (₹)
                    <input inputMode="decimal" value={dealValue} onChange={(e) => setDealValue(e.target.value.trim())} className={fieldClass} placeholder="0.00" />
                    {!valueOk && <span className="mt-1 block text-xs text-red-600">Up to 2 decimals.</span>}
                  </label>
                  <label className="text-sm font-medium text-slate-700 sm:col-span-2">
                    Expected close date
                    <input type="date" value={closeDate} onChange={(e) => setCloseDate(e.target.value)} className={fieldClass} />
                  </label>
                </div>
              )}
            </div>
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
            onClick={submit}
            disabled={saving || !valueOk || !gstinOk}
            className="flex items-center gap-2 rounded-lg bg-emerald-600 px-4 py-2 text-sm font-semibold text-white hover:bg-emerald-700 disabled:cursor-not-allowed disabled:bg-slate-300"
          >
            {saving && <Loader2 className="h-4 w-4 animate-spin" />}
            Convert
          </button>
        </div>
      </div>
    </div>
  );
}
