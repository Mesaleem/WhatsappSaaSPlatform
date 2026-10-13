import { useState } from 'react';
import { Loader2, Pencil } from 'lucide-react';
import crmSalesService from '../../services/crmSalesService';
import { CRM_TEMPERATURES, crmLabel, formatMoney, type CrmLead, type CrmTemperature } from '../../types/crm';
import { describeApiError } from '../../utils/apiError';
import { formatDate } from './crmFilters';
import { READ_ONLY_TITLE } from './CrmUi';

const fieldClass =
  'mt-1 w-full rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm text-slate-900 outline-none focus:border-emerald-500 focus:ring-2 focus:ring-emerald-100';

const TEMPERATURE_STYLES: Record<CrmTemperature, string> = {
  hot: 'bg-red-50 text-red-700 ring-red-200',
  warm: 'bg-amber-50 text-amber-700 ring-amber-200',
  cold: 'bg-sky-50 text-sky-700 ring-sky-200',
};

/** datetime from the API -> the value a datetime-local input expects (local time). */
function toLocalInput(iso: string | null | undefined): string {
  if (!iso) return '';
  const d = new Date(iso);
  const pad = (n: number) => String(n).padStart(2, '0');
  return `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}T${pad(d.getHours())}:${pad(d.getMinutes())}`;
}

/**
 * CRM Sales Phase 1 — the lead's qualification: company, requirement, budget, timeline,
 * temperature and next follow-up. Shown read-only with an Edit button; saved through
 * PATCH /crm/leads/{id}. Attribution (campaign, ad...) is shown but never editable.
 */
export default function QualificationCard({
  lead,
  readOnly,
  onUpdated,
}: {
  lead: CrmLead;
  readOnly: boolean;
  onUpdated: (lead: CrmLead, message: string) => void;
}) {
  const [editing, setEditing] = useState(false);
  const [form, setForm] = useState({
    company_name: lead.company_name ?? '',
    requirement: lead.requirement ?? '',
    budget: lead.budget ?? '',
    timeline: lead.timeline ?? '',
    temperature: (lead.temperature ?? '') as CrmTemperature | '',
    next_follow_up_at: toLocalInput(lead.next_follow_up_at),
  });
  const [saving, setSaving] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const budgetOk = form.budget === '' || /^\d{1,12}(\.\d{1,2})?$/.test(form.budget);

  const save = () => {
    if (!budgetOk || saving) return;
    setSaving(true);
    setError(null);
    crmSalesService
      .updateQualification(lead.id, {
        company_name: form.company_name.trim() || null,
        requirement: form.requirement.trim() || null,
        budget: form.budget || null,
        timeline: form.timeline.trim() || null,
        temperature: form.temperature || null,
        next_follow_up_at: form.next_follow_up_at ? new Date(form.next_follow_up_at).toISOString() : null,
      })
      .then((res) => {
        setEditing(false);
        onUpdated(res.data, 'Qualification saved.');
      })
      .catch((err: unknown) => setError(describeApiError(err, 'Could not save.').message))
      .finally(() => setSaving(false));
  };

  const attribution = lead.attribution && Object.keys(lead.attribution).length > 0 ? lead.attribution : null;

  return (
    <div className="space-y-3" data-testid="crm-qualification">
      <div className="flex items-center justify-between">
        <p className="text-sm font-semibold text-slate-900">Qualification</p>
        {!editing && (
          <button
            type="button"
            onClick={() => setEditing(true)}
            disabled={readOnly}
            title={readOnly ? READ_ONLY_TITLE : undefined}
            className="flex items-center gap-1 rounded-lg border border-slate-300 px-2.5 py-1 text-xs font-medium text-slate-700 hover:bg-slate-50 disabled:cursor-not-allowed disabled:opacity-50"
          >
            <Pencil className="h-3 w-3" /> Edit
          </button>
        )}
      </div>

      {editing ? (
        <div className="grid grid-cols-1 gap-3 sm:grid-cols-2">
          <label className="text-sm font-medium text-slate-700">
            Company
            <input value={form.company_name} onChange={(e) => setForm({ ...form, company_name: e.target.value })} maxLength={191} className={fieldClass} />
          </label>
          <label className="text-sm font-medium text-slate-700">
            Budget (₹)
            <input inputMode="decimal" value={form.budget} onChange={(e) => setForm({ ...form, budget: e.target.value.trim() })} className={fieldClass} />
            {!budgetOk && <span className="mt-1 block text-xs text-red-600">Up to 2 decimals.</span>}
          </label>
          <label className="text-sm font-medium text-slate-700">
            Timeline
            <input value={form.timeline} onChange={(e) => setForm({ ...form, timeline: e.target.value })} maxLength={50} className={fieldClass} placeholder="e.g. Within 30 days" />
          </label>
          <label className="text-sm font-medium text-slate-700">
            Temperature
            <select value={form.temperature} onChange={(e) => setForm({ ...form, temperature: e.target.value as CrmTemperature | '' })} className={fieldClass}>
              <option value="">Not set</option>
              {CRM_TEMPERATURES.map((t) => (
                <option key={t} value={t}>
                  {crmLabel(t)}
                </option>
              ))}
            </select>
          </label>
          <label className="text-sm font-medium text-slate-700 sm:col-span-2">
            Requirement
            <textarea rows={3} value={form.requirement} onChange={(e) => setForm({ ...form, requirement: e.target.value })} maxLength={5000} className={fieldClass} />
          </label>
          <label className="text-sm font-medium text-slate-700">
            Next follow-up
            <input type="datetime-local" value={form.next_follow_up_at} onChange={(e) => setForm({ ...form, next_follow_up_at: e.target.value })} className={fieldClass} />
          </label>
          {error && <p role="alert" className="text-sm text-red-600 sm:col-span-2">{error}</p>}
          <div className="flex justify-end gap-2 sm:col-span-2">
            <button type="button" onClick={() => setEditing(false)} className="rounded-lg border border-slate-300 px-3 py-1.5 text-sm text-slate-700 hover:bg-slate-50">
              Cancel
            </button>
            <button
              type="button"
              onClick={save}
              disabled={saving || !budgetOk}
              className="flex items-center gap-1 rounded-lg bg-emerald-600 px-3 py-1.5 text-sm font-semibold text-white hover:bg-emerald-700 disabled:bg-slate-300"
            >
              {saving && <Loader2 className="h-3.5 w-3.5 animate-spin" />} Save
            </button>
          </div>
        </div>
      ) : (
        <dl className="grid grid-cols-1 gap-3 text-sm sm:grid-cols-2">
          <Field label="Company" value={lead.company?.name ?? lead.company_name} />
          <Field label="Budget" value={lead.budget ? formatMoney(lead.budget) : null} />
          <Field label="Timeline" value={lead.timeline} />
          <div>
            <dt className="text-xs font-medium uppercase tracking-wide text-slate-500">Temperature</dt>
            <dd className="mt-1">
              {lead.temperature ? (
                <span className={`inline-flex rounded-full px-2 py-0.5 text-xs font-medium ring-1 ring-inset ${TEMPERATURE_STYLES[lead.temperature]}`}>{crmLabel(lead.temperature)}</span>
              ) : (
                <span className="text-slate-400">—</span>
              )}
            </dd>
          </div>
          <div className="sm:col-span-2">
            <Field label="Requirement" value={lead.requirement} />
          </div>
          <Field label="Next follow-up" value={lead.next_follow_up_at ? formatDate(lead.next_follow_up_at) : null} />
          {attribution && (
            <div className="sm:col-span-2">
              <dt className="text-xs font-medium uppercase tracking-wide text-slate-500">Attribution</dt>
              <dd className="mt-1 flex flex-wrap gap-1">
                {Object.entries(attribution).map(([k, v]) => (
                  <span key={k} className="rounded-md bg-slate-100 px-2 py-0.5 text-xs text-slate-700">
                    {crmLabel(k)}: {String(v)}
                  </span>
                ))}
              </dd>
            </div>
          )}
        </dl>
      )}
    </div>
  );
}

function Field({ label, value }: { label: string; value: string | null | undefined }) {
  return (
    <div>
      <dt className="text-xs font-medium uppercase tracking-wide text-slate-500">{label}</dt>
      <dd className="mt-1 whitespace-pre-line text-slate-800">{value || <span className="text-slate-400">—</span>}</dd>
    </div>
  );
}
