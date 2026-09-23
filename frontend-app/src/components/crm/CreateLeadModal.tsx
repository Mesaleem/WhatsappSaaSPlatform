import { useState, type FormEvent } from 'react';
import { Loader2, XCircle } from 'lucide-react';
import crmService from '../../services/crmService';
import { inputClass } from '../common/Card';
import { describeApiError } from '../../utils/apiError';
import {
  CRM_LEAD_STATUSES,
  crmLabel,
  crmSourceLabel,
  type CrmAssignee,
  type CrmLead,
  type CrmLeadStatus,
} from '../../types/crm';

/**
 * Phase 6 — CRM Task 8. POST /api/crm/leads with exactly the fields
 * StoreCrmLeadRequest accepts (phone_number, name, status,
 * assigned_user_id). Source is not sent: a manually added lead is always
 * `manual`, enforced by the backend. The backend resolves or creates the Contact for the
 * phone number and applies every rule; this form only collects input and
 * shows the server's field errors. No account_id is ever sent.
 */
export default function CreateLeadModal({
  assignees,
  onCreated,
  onCancel,
  targetLabel,
}: {
  assignees: CrmAssignee[];
  onCreated: (lead: CrmLead, message: string) => void;
  onCancel: () => void;
  /** The client the lead will be created for, when the caller acts on a selected client (Super Admin / Agent). Display only — the account is sent by the axios interceptor. */
  targetLabel?: string | null;
}) {
  const [phone, setPhone] = useState('');
  const [name, setName] = useState('');
  const [status, setStatus] = useState<CrmLeadStatus>('new');
  const [assignee, setAssignee] = useState('');
  const [saving, setSaving] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [fieldErrors, setFieldErrors] = useState<Record<string, string>>({});

  const submit = (e: FormEvent) => {
    e.preventDefault();
    if (saving) return;
    setSaving(true);
    setError(null);
    setFieldErrors({});
    crmService
      .createLead({
        phone_number: phone,
        name: name.trim() === '' ? null : name,
        status,
        assigned_user_id: assignee === '' ? null : Number(assignee),
      })
      .then((res) => onCreated(res.data, res.message))
      .catch((err: unknown) => {
        const described = describeApiError(err, 'Failed to create the lead.');
        setError(described.message);
        setFieldErrors(described.fieldErrors);
      })
      .finally(() => setSaving(false));
  };

  const field = (key: string) =>
    fieldErrors[key] ? <p className="mt-1 text-xs text-red-600">{fieldErrors[key]}</p> : null;

  return (
    <div className="fixed inset-0 z-50 flex items-center justify-center bg-slate-900/50 p-4">
      <form onSubmit={submit} className="w-full max-w-md rounded-xl bg-white p-6 shadow-xl" data-testid="crm-create-lead">
        <div className="flex items-center justify-between">
          <h3 className="text-base font-semibold text-slate-900">Add lead</h3>
          <button type="button" onClick={onCancel} disabled={saving} className="text-slate-400 hover:text-slate-600" aria-label="Close">
            <XCircle className="h-5 w-5" />
          </button>
        </div>
        {targetLabel && (
          <p className="mt-1 text-xs font-medium text-indigo-700" data-testid="crm-create-lead-target">
            For client: {targetLabel}
          </p>
        )}
        <p className="mt-1 text-xs text-slate-500">An existing contact with this phone number is reused automatically.</p>

        <div className="mt-4 space-y-3">
          <label className="block text-xs font-medium text-slate-600">
            Phone number *
            <input className={inputClass} value={phone} onChange={(e) => setPhone(e.target.value)} required maxLength={32} />
            {field('phone_number')}
          </label>
          <label className="block text-xs font-medium text-slate-600">
            Name
            <input className={inputClass} value={name} onChange={(e) => setName(e.target.value)} maxLength={255} />
            {field('name')}
          </label>
          <div className="grid grid-cols-1 gap-3 sm:grid-cols-2">
            <label className="block text-xs font-medium text-slate-600">
              Source
              <input className={inputClass} value={crmSourceLabel('manual')} readOnly disabled data-testid="crm-create-lead-source" />
            </label>
            <label className="block text-xs font-medium text-slate-600">
              Status
              <select className={inputClass} value={status} onChange={(e) => setStatus(e.target.value as CrmLeadStatus)}>
                {CRM_LEAD_STATUSES.map((s) => (
                  <option key={s} value={s}>
                    {crmLabel(s)}
                  </option>
                ))}
              </select>
              {field('status')}
            </label>
          </div>
          <label className="block text-xs font-medium text-slate-600">
            Assignee
            <select className={inputClass} value={assignee} onChange={(e) => setAssignee(e.target.value)}>
              <option value="">Unassigned</option>
              {assignees.map((a) => (
                <option key={a.id} value={String(a.id)}>
                  {a.name}
                </option>
              ))}
            </select>
            {field('assigned_user_id')}
          </label>
        </div>

        {error && <p className="mt-3 text-sm text-red-600" role="alert">{error}</p>}

        <div className="mt-5 flex justify-end gap-2">
          <button
            type="button"
            onClick={onCancel}
            disabled={saving}
            className="rounded-lg border border-slate-300 px-4 py-2 text-sm font-medium text-slate-700 hover:bg-slate-50 disabled:opacity-60"
          >
            Cancel
          </button>
          <button
            type="submit"
            disabled={saving || phone.trim() === ''}
            className="flex items-center gap-2 rounded-lg bg-indigo-600 px-4 py-2 text-sm font-semibold text-white shadow-sm hover:bg-indigo-700 disabled:opacity-60"
          >
            {saving && <Loader2 className="h-4 w-4 animate-spin" />}
            Create lead
          </button>
        </div>
      </form>
    </div>
  );
}
