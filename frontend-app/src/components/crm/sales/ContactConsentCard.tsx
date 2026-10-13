import { useState } from 'react';
import { Loader2 } from 'lucide-react';
import crmService from '../../../services/crmService';
import crmSalesService from '../../../services/crmSalesService';
import { Card } from '../../common/Card';
import { useCrmQuery } from '../crmHooks';
import { READ_ONLY_TITLE } from '../CrmUi';
import { describeApiError } from '../../../utils/apiError';
import type { CrmContact } from '../../../types/crm';
import { fieldClass } from './SalesUi';

/**
 * CRM Sales — a contact's company, role and consent. "Opted out" stops every message the
 * CRM would send them (documents, reminders, receipts); the server stamps when.
 */
export default function ContactConsentCard({ contact, readOnly, onSaved }: { contact: CrmContact; readOnly: boolean; onSaved: (c: CrmContact, message: string) => void }) {
  const [companyId, setCompanyId] = useState<string>(contact.crm_company_id ? String(contact.crm_company_id) : '');
  const [designation, setDesignation] = useState(contact.designation ?? '');
  const [channel, setChannel] = useState(contact.preferred_channel ?? '');
  const [optIn, setOptIn] = useState<boolean>(contact.marketing_opt_in ?? false);
  const [optedOut, setOptedOut] = useState<boolean>(!!contact.opted_out_at);
  const [saving, setSaving] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const companies = useCrmQuery('consent-companies', () => crmSalesService.listCompanies('', 1, 100), 'Failed to load companies.');

  const save = () => {
    setSaving(true);
    setError(null);
    crmService
      .updateContact(contact.id, {
        crm_company_id: companyId ? Number(companyId) : null,
        designation: designation.trim() || null,
        preferred_channel: (channel || null) as 'whatsapp' | 'email' | 'phone' | null,
        marketing_opt_in: optIn,
        opted_out: optedOut,
      })
      .then((res) => onSaved(res.data, 'Contact preferences saved.'))
      .catch((e: unknown) => setError(describeApiError(e, 'Could not save.').message))
      .finally(() => setSaving(false));
  };

  return (
    <Card className="space-y-4">
      <p className="text-sm font-semibold text-slate-900">Company & communication</p>
      <div className="grid grid-cols-1 gap-3 sm:grid-cols-3">
        <label className="text-sm font-medium text-slate-700">
          Company
          <select value={companyId} onChange={(e) => setCompanyId(e.target.value)} disabled={readOnly} className={fieldClass}>
            <option value="">None</option>
            {(companies.data?.data ?? []).map((c) => (
              <option key={c.id} value={c.id}>
                {c.name}
              </option>
            ))}
          </select>
        </label>
        <label className="text-sm font-medium text-slate-700">
          Designation
          <input value={designation} onChange={(e) => setDesignation(e.target.value)} maxLength={100} disabled={readOnly} className={fieldClass} />
        </label>
        <label className="text-sm font-medium text-slate-700">
          Preferred channel
          <select value={channel} onChange={(e) => setChannel(e.target.value)} disabled={readOnly} className={fieldClass}>
            <option value="">No preference</option>
            <option value="whatsapp">WhatsApp</option>
            <option value="email">Email</option>
            <option value="phone">Phone call</option>
          </select>
        </label>
      </div>
      <div className="flex flex-wrap gap-6">
        <label className="flex items-center gap-2 text-sm text-slate-700">
          <input type="checkbox" checked={optIn} onChange={(e) => setOptIn(e.target.checked)} disabled={readOnly} className="h-4 w-4 rounded border-slate-300 text-emerald-600" />
          Agreed to receive marketing messages
        </label>
        <label className="flex items-center gap-2 text-sm font-medium text-red-700">
          <input type="checkbox" checked={optedOut} onChange={(e) => setOptedOut(e.target.checked)} disabled={readOnly} aria-label="Opted out of all messages" className="h-4 w-4 rounded border-slate-300 text-red-600" />
          Opted out — do not message this person
        </label>
      </div>
      {contact.opted_out_at && <p className="text-xs text-slate-500">Opted out on {new Date(contact.opted_out_at).toLocaleDateString()}.</p>}
      {error && <p role="alert" className="text-sm text-red-600">{error}</p>}
      <div className="flex justify-end">
        <button type="button" onClick={save} disabled={saving || readOnly} title={readOnly ? READ_ONLY_TITLE : undefined} className="flex items-center gap-2 rounded-lg bg-emerald-600 px-4 py-2 text-sm font-semibold text-white hover:bg-emerald-700 disabled:bg-slate-300">
          {saving && <Loader2 className="h-4 w-4 animate-spin" />} Save
        </button>
      </div>
    </Card>
  );
}
