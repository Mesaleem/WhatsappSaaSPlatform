import { useState, type FormEvent } from 'react';
import { Lock, Loader2, X } from 'lucide-react';
import { useAuth } from '../../core/context/AuthContext';
import profileService from '../../services/profileService';
import DismissibleAlert from '../common/DismissibleAlert';
import { extractErrorMessage } from '../../utils/apiError';

const inputClass =
  'mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 text-sm text-slate-900 shadow-sm outline-none transition focus:border-indigo-500 focus:ring-2 focus:ring-indigo-100';

/** The server's field error for a key (Laravel's `errors` shape), if any. */
function fieldError(err: unknown, field: string): string | null {
  const errors = (err as { response?: { data?: { errors?: Record<string, string[]> } } })?.response?.data?.errors;
  return errors?.[field]?.[0] ?? null;
}

/**
 * Change password: the current password is required and checked on the server. The password is not
 * changed unless the current one is correct.
 */
export default function ChangePasswordModal({ onClose }: { onClose: () => void }) {
  const { user } = useAuth();
  const [current, setCurrent] = useState('');
  const [next, setNext] = useState('');
  const [confirm, setConfirm] = useState('');
  const [busy, setBusy] = useState(false);
  const [localError, setLocalError] = useState<string | null>(null);
  const [currentError, setCurrentError] = useState<string | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [done, setDone] = useState(false);

  const save = async (e: FormEvent) => {
    e.preventDefault();
    setLocalError(null);
    setCurrentError(null);
    setError(null);

    if (next.length < 8) {
      setLocalError('The new password must be at least 8 characters.');
      return;
    }
    if (next !== confirm) {
      setLocalError('The new password and its confirmation do not match.');
      return;
    }
    if (next === current) {
      setLocalError('Choose a password different from the current one.');
      return;
    }

    setBusy(true);
    try {
      await profileService.updateProfile({
        name: user?.name ?? '',
        phone_number: user?.phone_number ?? null,
        current_password: current,
        password: next,
        password_confirmation: confirm,
      });
      setDone(true);
    } catch (err) {
      const wrongCurrent = fieldError(err, 'current_password');
      if (wrongCurrent) {
        setCurrentError(wrongCurrent);
      } else {
        setError(extractErrorMessage(err, 'We could not change your password. Please try again.'));
      }
    } finally {
      setBusy(false);
    }
  };

  return (
    <div className="fixed inset-0 z-50 flex items-center justify-center bg-slate-900/50 p-4" role="dialog" aria-modal="true" aria-labelledby="password-title">
      <form onSubmit={(e) => void save(e)} className="w-full max-w-md rounded-xl bg-white p-6 shadow-xl">
        <div className="flex items-start justify-between gap-4">
          <div>
            <h2 id="password-title" className="text-base font-semibold text-slate-900">Change password</h2>
            <p className="mt-1 text-xs text-slate-500">Enter your current password to set a new one.</p>
          </div>
          <button type="button" onClick={onClose} className="text-slate-400 hover:text-slate-600" aria-label="Close">
            <X className="h-5 w-5" />
          </button>
        </div>

        {done ? (
          <p className="mt-5 rounded-lg border border-emerald-200 bg-emerald-50 px-3 py-2 text-sm text-emerald-800">
            Your password has been changed. Use the new password the next time you sign in.
          </p>
        ) : (
          <div className="mt-5 space-y-4">
            <label className="block text-xs font-medium text-slate-700">
              Current password
              <span className="text-red-500" aria-hidden="true"> *</span><input type="password" className={inputClass} value={current} onChange={(e) => setCurrent(e.target.value)} autoComplete="current-password" required />
              {currentError && <span className="mt-1 block text-red-600">{currentError}</span>}
            </label>
            <label className="block text-xs font-medium text-slate-700">
              New password
              <span className="text-red-500" aria-hidden="true"> *</span><input type="password" className={inputClass} value={next} onChange={(e) => setNext(e.target.value)} autoComplete="new-password" minLength={8} required />
            </label>
            <label className="block text-xs font-medium text-slate-700">
              Confirm new password
              <span className="text-red-500" aria-hidden="true"> *</span><input type="password" className={inputClass} value={confirm} onChange={(e) => setConfirm(e.target.value)} autoComplete="new-password" minLength={8} required />
            </label>

            {localError && <p className="text-sm text-red-600">{localError}</p>}
            {error && <DismissibleAlert className="rounded-lg border border-red-200 bg-red-50 px-3 py-2 text-sm text-red-700">{error}</DismissibleAlert>}
          </div>
        )}

        <div className="mt-6 flex justify-end gap-2">
          <button type="button" onClick={onClose} className="rounded-lg border border-slate-300 px-4 py-2 text-sm font-medium text-slate-700 hover:bg-slate-50">
            {done ? 'Close' : 'Cancel'}
          </button>
          {!done && (
            <button
              type="submit"
              disabled={busy}
              className="flex items-center gap-2 rounded-lg bg-indigo-600 px-4 py-2 text-sm font-semibold text-white hover:bg-indigo-700 disabled:opacity-60"
            >
              {busy ? <Loader2 className="h-4 w-4 animate-spin" /> : <Lock className="h-4 w-4" />}
              Change password
            </button>
          )}
        </div>
      </form>
    </div>
  );
}
