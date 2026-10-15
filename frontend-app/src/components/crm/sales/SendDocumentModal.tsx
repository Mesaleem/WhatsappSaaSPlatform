import { useState } from 'react';
import { Loader2, XCircle } from 'lucide-react';
import crmDocumentsService from '../../../services/crmDocumentsService';
import { useAuth } from '../../../core/context/AuthContext';
import { describeApiError } from '../../../utils/apiError';
import type { CrmDelivery, CrmDocDetail } from '../../../types/crmSales';
import { fieldClass } from './SalesUi';

type Channel = 'whatsapp' | 'email' | 'manual';

/**
 * CRM Sales Phase 4 — send a document on WhatsApp (the tenant's connected number) or
 * email, or record that it was sent outside the platform. The result shown is what
 * the server recorded: "sent" means the provider accepted it, never "delivered".
 */
export default function SendDocumentModal({ doc, onCancel, onDone }: { doc: CrmDocDetail; onCancel: () => void; onDone: (delivery: CrmDelivery | null, message: string) => void }) {
  const customer = (doc.customer_snapshot ?? {}) as { phone?: string; email?: string };
  // QA fixes (2026-10-14): the CRM-only plan has no WhatsApp sending — email is the default there.
  const { user } = useAuth();
  const hasWhatsApp = user?.capabilities?.whatsapp_send !== false;
  const [channel, setChannel] = useState<Channel>(hasWhatsApp ? 'whatsapp' : 'email');
  const [recipient, setRecipient] = useState((hasWhatsApp ? customer.phone : customer.email) ?? '');
  const [message, setMessage] = useState('');
  const [how, setHow] = useState('My own WhatsApp');
  const [saving, setSaving] = useState(false);
  const [error, setError] = useState<string | null>(null);

  const pick = (c: Channel) => {
    setChannel(c);
    setRecipient(c === 'email' ? (customer.email ?? '') : (customer.phone ?? ''));
  };

  const send = () => {
    setSaving(true);
    setError(null);
    crmDocumentsService
      .send(doc.id, { channel, recipient: recipient.trim() || null, message: message.trim() || null, how: channel === 'manual' ? how : null })
      .then((res) => onDone(res.data, res.message))
      .catch((err: unknown) => {
        setError(describeApiError(err, 'Could not send.').message);
        setSaving(false);
      });
  };

  return (
    <div className="fixed inset-0 z-50 flex items-center justify-center bg-slate-900/50 p-4">
      <div role="dialog" aria-labelledby="send-title" className="w-full max-w-lg rounded-xl bg-white p-6 shadow-xl" data-testid="crm-send-document">
        <div className="flex items-center justify-between">
          <h3 id="send-title" className="text-base font-semibold text-slate-900">
            Send {doc.number}
          </h3>
          <button type="button" onClick={onCancel} className="text-slate-400 hover:text-slate-600" aria-label="Close">
            <XCircle className="h-5 w-5" />
          </button>
        </div>

        <div role="radiogroup" className="mt-4 flex flex-wrap gap-2">
          {(
            [
              ['whatsapp', 'WhatsApp'],
              ['email', 'Email'],
              ['manual', 'I sent it myself'],
            ] as const
          )
            .filter(([c]) => hasWhatsApp || c !== 'whatsapp')
            .map(([c, label]) => (
            <button
              key={c}
              type="button"
              role="radio"
              aria-checked={channel === c}
              onClick={() => pick(c)}
              className={`rounded-lg px-3 py-1.5 text-sm font-medium ${channel === c ? 'bg-emerald-600 text-white' : 'border border-slate-300 text-slate-700 hover:bg-slate-50'}`}
            >
              {label}
            </button>
          ))}
        </div>

        <div className="mt-4 space-y-3">
          <label className="block text-sm font-medium text-slate-700">
            {channel === 'email' ? 'Email address' : channel === 'manual' ? 'Sent to' : 'WhatsApp number'}
            <input value={recipient} onChange={(e) => setRecipient(e.target.value)} maxLength={191} className={fieldClass} />
          </label>
          {channel === 'manual' ? (
            <label className="block text-sm font-medium text-slate-700">
              How
              <input value={how} onChange={(e) => setHow(e.target.value)} maxLength={50} className={fieldClass} />
            </label>
          ) : (
            <label className="block text-sm font-medium text-slate-700">
              Message (optional — a standard message with the link is used when empty)
              <textarea rows={4} value={message} onChange={(e) => setMessage(e.target.value)} maxLength={2000} className={fieldClass} />
            </label>
          )}
          {channel === 'whatsapp' && <p className="text-xs text-slate-500">The PDF is sent from your connected WhatsApp number. On the Meta Cloud API, a message outside the 24-hour window may be refused by WhatsApp.</p>}
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
            onClick={send}
            disabled={saving || (channel === 'manual' && !recipient.trim())}
            className="flex items-center gap-2 rounded-lg bg-emerald-600 px-4 py-2 text-sm font-semibold text-white hover:bg-emerald-700 disabled:bg-slate-300"
          >
            {saving && <Loader2 className="h-4 w-4 animate-spin" />}
            {channel === 'manual' ? 'Record' : 'Send'}
          </button>
        </div>
      </div>
    </div>
  );
}
