import { useState, type FormEvent } from 'react';
import { Lock, Loader2, Save, X } from 'lucide-react';
import { useAuth } from '../../core/context/AuthContext';
import profileService from '../../services/profileService';
import DismissibleAlert from '../common/DismissibleAlert';
import { extractErrorMessage } from '../../utils/apiError';

const inputClass =
  'mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 text-sm text-slate-900 shadow-sm outline-none transition focus:border-indigo-500 focus:ring-2 focus:ring-indigo-100 disabled:bg-slate-50 disabled:text-slate-500';

/**
 * Profile: name and phone can be changed. The email cannot: it is the login ID.
 */
export default function ProfileDetailsModal({ onClose }: { onClose: () => void }) {
  const { user, refreshUser } = useAuth();
  const [name, setName] = useState(user?.name ?? '');
  const [phone, setPhone] = useState(user?.phone_number ?? '');
  const [busy, setBusy] = useState(false);
  const [message, setMessage] = useState<string | null>(null);
  const [error, setError] = useState<string | null>(null);

  const save = async (e: FormEvent) => {
    e.preventDefault();
    setBusy(true);
    setMessage(null);
    setError(null);
    try {
      await profileService.updateProfile({ name: name.trim(), phone_number: phone.trim() || null });
      await refreshUser();
      setMessage('Your details are saved.');
    } catch (err) {
      setError(extractErrorMessage(err, 'We could not save your details. Please try again.'));
    } finally {
      setBusy(false);
    }
  };

  return (
    <div className="fixed inset-0 z-50 flex items-center justify-center bg-slate-900/50 p-4" role="dialog" aria-modal="true" aria-labelledby="profile-title">
      <form onSubmit={(e) => void save(e)} className="w-full max-w-md rounded-xl bg-white p-6 shadow-xl">
        <div className="flex items-start justify-between gap-4">
          <div>
            <h2 id="profile-title" className="text-base font-semibold text-slate-900">Profile</h2>
            <p className="mt-1 text-xs text-slate-500">Your name and phone number.</p>
          </div>
          <button type="button" onClick={onClose} className="text-slate-400 hover:text-slate-600" aria-label="Close">
            <X className="h-5 w-5" />
          </button>
        </div>

        <div className="mt-5 space-y-4">
          <label className="block text-xs font-medium text-slate-700">
            Full name
            <span className="text-red-500" aria-hidden="true"> *</span><input className={inputClass} value={name} onChange={(e) => setName(e.target.value)} required maxLength={255} />
          </label>
          <label className="block text-xs font-medium text-slate-700">
            Phone number
            <input className={inputClass} value={phone} onChange={(e) => setPhone(e.target.value)} maxLength={25} placeholder="919876543210" />
          </label>
          <label className="block text-xs font-medium text-slate-700">
            <span className="flex items-center gap-1">
              Email <Lock className="h-3 w-3 text-slate-400" />
            </span>
            <input className={inputClass} value={user?.email ?? ''} disabled readOnly />
            <span className="mt-1 block font-normal text-slate-500">
              Your email is your login ID, so it cannot be changed here. Contact support if you need a different login.
            </span>
          </label>

          {message && <p className="text-sm text-emerald-700">{message}</p>}
          {error && <DismissibleAlert className="rounded-lg border border-red-200 bg-red-50 px-3 py-2 text-sm text-red-700">{error}</DismissibleAlert>}
        </div>

        <div className="mt-6 flex justify-end gap-2">
          <button type="button" onClick={onClose} className="rounded-lg border border-slate-300 px-4 py-2 text-sm font-medium text-slate-700 hover:bg-slate-50">
            Close
          </button>
          <button
            type="submit"
            disabled={busy}
            className="flex items-center gap-2 rounded-lg bg-indigo-600 px-4 py-2 text-sm font-semibold text-white hover:bg-indigo-700 disabled:opacity-60"
          >
            {busy ? <Loader2 className="h-4 w-4 animate-spin" /> : <Save className="h-4 w-4" />}
            Save details
          </button>
        </div>
      </form>
    </div>
  );
}
