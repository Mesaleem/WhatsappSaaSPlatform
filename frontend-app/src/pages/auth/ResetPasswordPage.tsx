import { useState, type FormEvent } from 'react';
import { Link, useSearchParams } from 'react-router-dom';
import { AxiosError } from 'axios';
import { CheckCircle2, Eye, EyeOff, KeyRound, Loader2, Lock } from 'lucide-react';
import publicSiteService from '../../services/publicSiteService';
import { usePageTitle } from '../../utils/usePageTitle';
import { AuthSplit } from '../../website/AuthShowcase';

const POINTS = ['Use 8+ characters', 'Mix letters and numbers', 'All old sessions signed out', 'Log in right after'];

/** Opened from the emailed link: /reset-password?token=…&email=… */
export default function ResetPasswordPage() {
  usePageTitle('Reset password');
  const [params] = useSearchParams();
  const token = params.get('token') ?? '';
  const email = params.get('email') ?? '';

  const [password, setPassword] = useState('');
  const [confirm, setConfirm] = useState('');
  const [show, setShow] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [saving, setSaving] = useState(false);
  const [done, setDone] = useState(false);

  const submit = async (event: FormEvent) => {
    event.preventDefault();
    setError(null);
    if (password.length < 8) return setError('Use at least 8 characters.');
    if (password !== confirm) return setError('Passwords do not match.');

    setSaving(true);
    try {
      await publicSiteService.resetPassword({ token, email, password, password_confirmation: confirm });
      setDone(true);
    } catch (err) {
      const e = err as AxiosError<{ message?: string; errors?: Record<string, string[]> }>;
      const first = e.response?.data?.errors && Object.values(e.response.data.errors)[0]?.[0];
      setError(e.response?.status === 429 ? 'Too many attempts. Please wait a few minutes.' : first ?? e.response?.data?.message ?? 'Could not change the password. Please try again.');
    } finally {
      setSaving(false);
    }
  };

  const input = 'w-full rounded-xl border border-slate-300 bg-white py-3 pl-11 pr-11 text-sm text-slate-900 shadow-sm outline-none transition placeholder:text-slate-400 focus:border-emerald-500 focus:ring-4 focus:ring-emerald-100';

  return (
    <AuthSplit title="Choose a new password." subtitle="Pick something strong that you don't use anywhere else." points={POINTS}>
      <div className="w-full max-w-md">
        {done ? (
          <div className="text-center" role="status">
            <span className="mx-auto flex h-16 w-16 items-center justify-center rounded-2xl bg-emerald-100 text-emerald-700">
              <CheckCircle2 className="h-8 w-8" />
            </span>
            <h2 className="mt-6 font-display text-3xl font-bold tracking-tight text-slate-900">Password changed</h2>
            <p className="mt-3 text-sm text-slate-600">You can now log in with your new password.</p>
            <Link to="/login" className="mt-8 inline-flex w-full items-center justify-center rounded-xl bg-emerald-600 px-4 py-3 text-sm font-semibold text-white shadow-lg shadow-emerald-600/25 hover:bg-emerald-700">
              Go to log in
            </Link>
          </div>
        ) : !token || !email ? (
          <div className="text-center">
            <h2 className="font-display text-3xl font-bold tracking-tight text-slate-900">Link not valid</h2>
            <p className="mt-3 text-sm text-slate-600">This reset link is incomplete. Please request a new one.</p>
            <Link to="/forgot-password" className="mt-8 inline-flex w-full items-center justify-center rounded-xl bg-emerald-600 px-4 py-3 text-sm font-semibold text-white hover:bg-emerald-700">
              Request a new link
            </Link>
          </div>
        ) : (
          <>
            <span className="flex h-12 w-12 items-center justify-center rounded-2xl bg-emerald-100 text-emerald-700">
              <KeyRound className="h-6 w-6" />
            </span>
            <h2 className="mt-5 font-display text-3xl font-bold tracking-tight text-slate-900">Set a new password</h2>
            <p className="mt-2 text-sm text-slate-500">For <span className="font-semibold text-slate-800">{email}</span></p>

            <form onSubmit={submit} noValidate className="mt-8 space-y-5">
              {[
                ['New password', password, setPassword, 'new-password'] as const,
                ['Confirm new password', confirm, setConfirm, 'confirm-password'] as const,
              ].map(([label, value, setter, id]) => (
                <div key={id}>
                  <label htmlFor={id} className="block text-sm font-medium text-slate-700">{label}</label>
                  <div className="relative mt-1.5">
                    <Lock className="pointer-events-none absolute left-4 top-1/2 h-4 w-4 -translate-y-1/2 text-slate-400" />
                    <input id={id} type={show ? 'text' : 'password'} autoComplete="new-password" value={value} onChange={(e) => setter(e.target.value)} disabled={saving} className={input} />
                    {id === 'new-password' && (
                      <button type="button" tabIndex={-1} onClick={() => setShow((v) => !v)} className="absolute right-4 top-1/2 -translate-y-1/2 text-slate-400 hover:text-slate-600" aria-label={show ? 'Hide password' : 'Show password'}>
                        {show ? <EyeOff className="h-4 w-4" /> : <Eye className="h-4 w-4" />}
                      </button>
                    )}
                  </div>
                </div>
              ))}
              {error && (
                <p className="rounded-xl bg-red-50 px-4 py-3 text-sm text-red-700" role="alert">
                  {error}{' '}
                  {/expired|invalid/i.test(error) && <Link to="/forgot-password" className="font-semibold underline">Request a new link</Link>}
                </p>
              )}
              <button
                type="submit"
                disabled={saving}
                className="flex w-full items-center justify-center gap-2 rounded-xl bg-emerald-600 px-4 py-3 text-sm font-semibold text-white shadow-lg shadow-emerald-600/25 transition hover:bg-emerald-700 disabled:opacity-70"
              >
                {saving && <Loader2 className="h-4 w-4 animate-spin" />}
                {saving ? 'Saving…' : 'Change password'}
              </button>
            </form>
          </>
        )}
      </div>
    </AuthSplit>
  );
}
