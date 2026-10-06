import { useState, type FormEvent } from 'react';
import { X } from 'lucide-react';
import whatsappService from '../../services/whatsappService';
import { extractErrorMessage } from '../../utils/apiError';

interface NumberChangeRequestModalProps {
  numberId: number;
  /** The number the slot holds now, digits with country code. */
  currentPhone: string;
  accountId?: number;
  onClose: () => void;
  onRequested: () => void;
}

/**
 * A client asks for its slot to hold a different number, because the number was entered
 * wrongly. Nothing changes until a Super Admin or the agent approves it. The server checks
 * the number, its uniqueness and who may ask; this form only collects what it needs.
 */
export default function NumberChangeRequestModal({ numberId, currentPhone, accountId, onClose, onRequested }: NumberChangeRequestModalProps) {
  const [newPhone, setNewPhone] = useState('');
  const [reason, setReason] = useState('');
  const [checked, setChecked] = useState(false);
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);

  const digits = newPhone.replace(/\D/g, '');
  const valid =
    digits.length >= 11 && digits.length <= 15 && digits !== currentPhone && reason.trim().length >= 5 && checked;

  const submit = async (e: FormEvent<HTMLFormElement>) => {
    e.preventDefault();
    if (!valid) return;
    setBusy(true);
    setError(null);
    try {
      await whatsappService.requestNumberChange(numberId, digits, reason.trim(), accountId);
      onRequested();
    } catch (err) {
      setError(extractErrorMessage(err, 'Could not send the request. Please try again.'));
    } finally {
      setBusy(false);
    }
  };

  return (
    <div className="fixed inset-0 z-50 flex items-center justify-center bg-slate-900/50 p-4" role="dialog" aria-modal="true">
      <form onSubmit={(e) => void submit(e)} className="w-full max-w-md rounded-xl bg-white p-6 shadow-xl">
        <div className="flex items-start justify-between gap-4">
          <div>
            <h2 className="text-base font-semibold text-slate-900">Request a number change</h2>
            <p className="mt-1 text-xs text-slate-500">
              Use this only if the number on this slot was entered wrongly. Your Super Admin or agent checks the new number before
              anything changes.
            </p>
          </div>
          <button type="button" onClick={onClose} className="text-slate-400 hover:text-slate-600" aria-label="Close">
            <X className="h-5 w-5" />
          </button>
        </div>

        <div className="mt-5 space-y-4">
          <div>
            <p className="text-xs font-medium text-slate-700">Current number on this slot</p>
            <p className="mt-1 rounded-lg bg-slate-50 px-3 py-2 text-sm text-slate-600">+{currentPhone}</p>
          </div>

          <label className="block text-xs font-medium text-slate-700">
            Correct WhatsApp number
            <span className="text-red-500" aria-hidden="true"> *</span><input
              type="tel"
              inputMode="numeric"
              value={newPhone}
              onChange={(e) => setNewPhone(e.target.value)}
              placeholder="919876543210"
              className="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 text-sm text-slate-900 focus:border-indigo-500 focus:outline-none"
            />
            <span className="mt-1 block font-normal text-slate-400">Country code first, no + or spaces. Example: 91 for India.</span>
            {digits.length > 0 && digits === currentPhone && (
              <span className="mt-1 block font-normal text-red-600">This is the number already on this slot.</span>
            )}
          </label>

          <label className="block text-xs font-medium text-slate-700">
            Reason
            <span className="text-red-500" aria-hidden="true"> *</span><textarea
              value={reason}
              onChange={(e) => setReason(e.target.value)}
              rows={2}
              maxLength={500}
              placeholder="For example: I typed one digit wrong."
              className="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 text-sm text-slate-900 focus:border-indigo-500 focus:outline-none"
            />
          </label>

          <label className="flex items-start gap-2 rounded-lg border border-amber-200 bg-amber-50 px-3 py-2 text-xs text-amber-900">
            <span className="text-red-500" aria-hidden="true"> *</span><input type="checkbox" checked={checked} onChange={(e) => setChecked(e.target.checked)} className="mt-0.5" />
            <span>
              Please verify the number once again. I have checked that +{digits || '…'} is the WhatsApp account I will connect.
            </span>
          </label>

          {error && <p className="text-xs text-red-600">{error}</p>}
        </div>

        <div className="mt-6 flex justify-end gap-2">
          <button type="button" onClick={onClose} className="rounded-lg border border-slate-300 px-4 py-2 text-sm font-medium text-slate-700 hover:bg-slate-50">
            Cancel
          </button>
          <button
            type="submit"
            disabled={!valid || busy}
            className="rounded-lg bg-indigo-600 px-4 py-2 text-sm font-semibold text-white hover:bg-indigo-700 disabled:cursor-not-allowed disabled:opacity-50"
          >
            {busy ? 'Sending…' : 'Send request'}
          </button>
        </div>
      </form>
    </div>
  );
}
