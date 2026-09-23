import { useState, type FormEvent } from 'react';
import { Loader2, XCircle } from 'lucide-react';
import crmService from '../../services/crmService';
import { inputClass } from '../common/Card';
import { describeApiError } from '../../utils/apiError';
import type { CrmContact } from '../../types/crm';

/**
 * Phase 6 — CRM Task 8. Create (POST /crm/contacts) or edit
 * (PATCH /crm/contacts/{id}) a contact — the StoreCrmContactRequest /
 * UpdateCrmContactRequest fields only (phone_number, name, email).
 * Normalization, duplicate-number handling ("Contact already exists.")
 * and validation are the backend's; its messages are shown as-is.
 */
export default function ContactFormModal({
  contact,
  onSaved,
  onCancel,
}: {
  contact?: CrmContact;
  onSaved: (contact: CrmContact, message: string) => void;
  onCancel: () => void;
}) {
  const [phone, setPhone] = useState(contact?.phone_number ?? '');
  const [name, setName] = useState(contact?.name ?? '');
  const [email, setEmail] = useState(contact?.email ?? '');
  const [saving, setSaving] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [fieldErrors, setFieldErrors] = useState<Record<string, string>>({});

  const submit = (e: FormEvent) => {
    e.preventDefault();
    if (saving) return;
    setSaving(true);
    setError(null);
    setFieldErrors({});
    const payload = {
      phone_number: phone,
      name: name.trim() === '' ? null : name,
      email: email.trim() === '' ? null : email,
    };
    const call = contact ? crmService.updateContact(contact.id, payload) : crmService.createContact(payload);
    call
      .then((res) => onSaved(res.data, res.message))
      .catch((err: unknown) => {
        const described = describeApiError(err, 'Failed to save the contact.');
        setError(described.message);
        setFieldErrors(described.fieldErrors);
      })
      .finally(() => setSaving(false));
  };

  const field = (key: string) =>
    fieldErrors[key] ? <p className="mt-1 text-xs text-red-600">{fieldErrors[key]}</p> : null;

  return (
    <div className="fixed inset-0 z-50 flex items-center justify-center bg-slate-900/50 p-4">
      <form onSubmit={submit} className="w-full max-w-md rounded-xl bg-white p-6 shadow-xl" data-testid="crm-contact-form">
        <div className="flex items-center justify-between">
          <h3 className="text-base font-semibold text-slate-900">{contact ? 'Edit contact' : 'New contact'}</h3>
          <button type="button" onClick={onCancel} disabled={saving} className="text-slate-400 hover:text-slate-600" aria-label="Close">
            <XCircle className="h-5 w-5" />
          </button>
        </div>
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
          <label className="block text-xs font-medium text-slate-600">
            Email
            <input className={inputClass} type="email" value={email} onChange={(e) => setEmail(e.target.value)} maxLength={255} />
            {field('email')}
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
            {contact ? 'Save changes' : 'Create contact'}
          </button>
        </div>
      </form>
    </div>
  );
}
