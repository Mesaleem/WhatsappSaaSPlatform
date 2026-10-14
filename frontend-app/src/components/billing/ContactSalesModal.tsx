import { useState } from 'react';
import { Loader2, XCircle } from 'lucide-react';
import billingService from '../../services/billingService';
import { describeApiError } from '../../utils/apiError';

const field =
  'mt-1 w-full rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm text-slate-900 outline-none focus:border-emerald-500 focus:ring-2 focus:ring-emerald-100';

/**
 * "Contact sales" for a custom plan. Plans on sale are monthly; anything else is arranged
 * by the sales team. On submit the customer gets a confirmation email (contact within
 * 24–48 hours) and the Super Admins are emailed and notified.
 */
export default function ContactSalesModal({
  defaults,
  onClose,
  onSent,
}: {
  defaults: { name?: string | null; email?: string | null; company?: string | null };
  onClose: () => void;
  onSent: (message: string) => void;
}) {
  const [form, setForm] = useState({
    name: defaults.name ?? '',
    email: defaults.email ?? '',
    phone: '',
    company: defaults.company ?? '',
    expected_messages: '',
    whatsapp_numbers: '',
    team_users: '',
    requirement: '',
  });
  const [saving, setSaving] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const num = (v: string) => (v.trim() === '' ? null : Number(v));
  const phoneDigits = form.phone.replace(/\D/g, '').length;
  const phoneValid = /^\+?[0-9][0-9\s-]{8,20}$/.test(form.phone.trim()) && phoneDigits >= 10 && phoneDigits <= 15;
  const valid = form.name.trim() !== '' && /\S+@\S+\.\S+/.test(form.email) && phoneValid && form.requirement.trim().length >= 10;

  const submit = () => {
    if (!valid || saving) return;
    setSaving(true);
    setError(null);
    billingService
      .requestCustomPlan({
        name: form.name.trim(),
        email: form.email.trim(),
        phone: form.phone.trim(),
        company: form.company.trim() || null,
        expected_messages: num(form.expected_messages),
        whatsapp_numbers: num(form.whatsapp_numbers),
        team_users: num(form.team_users),
        requirement: form.requirement.trim(),
      })
      .then((res) => onSent(res.message))
      .catch((err: unknown) => {
        setError(describeApiError(err, 'Could not send your request. Please try again.').message);
        setSaving(false);
      });
  };

  return (
    <div className="fixed inset-0 z-50 flex items-center justify-center bg-slate-900/50 p-4">
      <div role="dialog" aria-labelledby="sales-title" className="max-h-[90vh] w-full max-w-lg overflow-y-auto rounded-xl bg-white p-6 shadow-xl" data-testid="contact-sales-modal">
        <div className="flex items-center justify-between">
          <h3 id="sales-title" className="text-base font-semibold text-slate-900">
            Contact our sales team
          </h3>
          <button type="button" onClick={onClose} aria-label="Close" className="text-slate-400 hover:text-slate-600">
            <XCircle className="h-5 w-5" />
          </button>
        </div>
        <p className="mt-1 text-sm text-slate-500">Tell us what you need. Our team will contact you within 24–48 hours with a custom plan.</p>

        <div className="mt-4 grid grid-cols-1 gap-3 sm:grid-cols-2">
          <label className="text-sm font-medium text-slate-700">
            Name <span className="text-red-500">*</span>
            <input value={form.name} onChange={(e) => setForm({ ...form, name: e.target.value })} maxLength={191} className={field} />
          </label>
          <label className="text-sm font-medium text-slate-700">
            Email <span className="text-red-500">*</span>
            <input type="email" value={form.email} onChange={(e) => setForm({ ...form, email: e.target.value })} maxLength={191} className={field} />
          </label>
          <label className="text-sm font-medium text-slate-700">
            Phone <span className="text-red-500">*</span>
            <input
              type="tel"
              inputMode="tel"
              value={form.phone}
              onChange={(e) => setForm({ ...form, phone: e.target.value })}
              maxLength={32}
              placeholder="+91 98765 43210"
              aria-invalid={form.phone.trim() !== '' && !phoneValid}
              className={field}
            />
            {form.phone.trim() !== '' && !phoneValid && <span className="mt-1 block text-xs font-normal text-red-600">Enter a valid phone number (10–15 digits).</span>}
          </label>
          <label className="text-sm font-medium text-slate-700">
            Company
            <input value={form.company} onChange={(e) => setForm({ ...form, company: e.target.value })} maxLength={191} className={field} />
          </label>
          <label className="text-sm font-medium text-slate-700">
            Messages per month
            <input inputMode="numeric" value={form.expected_messages} onChange={(e) => setForm({ ...form, expected_messages: e.target.value.replace(/\D/g, '') })} className={field} />
          </label>
          <label className="text-sm font-medium text-slate-700">
            WhatsApp numbers
            <input inputMode="numeric" value={form.whatsapp_numbers} onChange={(e) => setForm({ ...form, whatsapp_numbers: e.target.value.replace(/\D/g, '') })} className={field} />
          </label>
          <label className="text-sm font-medium text-slate-700">
            Team users
            <input inputMode="numeric" value={form.team_users} onChange={(e) => setForm({ ...form, team_users: e.target.value.replace(/\D/g, '') })} className={field} />
          </label>
          <label className="text-sm font-medium text-slate-700 sm:col-span-2">
            Your requirement <span className="text-red-500">*</span>
            <textarea rows={4} value={form.requirement} onChange={(e) => setForm({ ...form, requirement: e.target.value })} maxLength={5000} placeholder="e.g. 5 WhatsApp numbers, 50,000 messages a month, a yearly plan" className={field} />
          </label>
        </div>

        {error && (
          <p role="alert" className="mt-3 text-sm text-red-600">
            {error}
          </p>
        )}

        <div className="mt-6 flex justify-end gap-2">
          <button type="button" onClick={onClose} className="rounded-lg border border-slate-300 px-4 py-2 text-sm font-medium text-slate-700 hover:bg-slate-50">
            Cancel
          </button>
          <button type="button" onClick={submit} disabled={!valid || saving} className="flex items-center gap-2 rounded-lg bg-emerald-600 px-4 py-2 text-sm font-semibold text-white hover:bg-emerald-700 disabled:bg-slate-300">
            {saving && <Loader2 className="h-4 w-4 animate-spin" />}
            Send request
          </button>
        </div>
      </div>
    </div>
  );
}
