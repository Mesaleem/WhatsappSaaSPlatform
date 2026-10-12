import { useState, type FormEvent } from 'react';
import { Link } from 'react-router-dom';
import { AxiosError } from 'axios';
import { ArrowLeft, Loader2, Mail, MailCheck } from 'lucide-react';
import publicSiteService from '../../services/publicSiteService';
import { usePageTitle } from '../../utils/usePageTitle';
import { AuthSplit } from '../../website/AuthShowcase';

const POINTS = ['Link valid for 60 minutes', 'Works on any device', 'All old sessions signed out', 'Your data stays safe'];

export default function ForgotPasswordPage() {
  usePageTitle('Forgot password');
  const [email, setEmail] = useState('');
  const [error, setError] = useState<string | null>(null);
  const [sending, setSending] = useState(false);
  const [sent, setSent] = useState<string | null>(null);

  const submit = async (event: FormEvent) => {
    event.preventDefault();
    setError(null);
    if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email.trim())) {
      setError('Enter a valid email address.');
      return;
    }
    setSending(true);
    try {
      const res = await publicSiteService.forgotPassword(email.trim());
      setSent(res.message);
    } catch (err) {
      const e = err as AxiosError<{ message?: string }>;
      setError(e.response?.status === 429 ? 'Too many requests. Please wait a few minutes and try again.' : e.response?.data?.message ?? 'Something went wrong. Please try again.');
    } finally {
      setSending(false);
    }
  };

  return (
    <AuthSplit title="Locked out? It happens." subtitle="We will email you a secure link to choose a new password." points={POINTS}>
      <div className="w-full max-w-md">
        {sent ? (
          <div className="text-center" role="status">
            <span className="mx-auto flex h-16 w-16 items-center justify-center rounded-2xl bg-emerald-100 text-emerald-700">
              <MailCheck className="h-8 w-8" />
            </span>
            <h2 className="mt-6 font-display text-3xl font-bold tracking-tight text-slate-900">Check your email</h2>
            <p className="mt-3 text-sm leading-relaxed text-slate-600">{sent}</p>
            <p className="mt-2 text-sm text-slate-500">Sent to <span className="font-semibold text-slate-800">{email.trim()}</span>. Don't see it? Check spam, or try again in a minute.</p>
            <button type="button" onClick={() => setSent(null)} className="mt-6 text-sm font-semibold text-emerald-700 hover:text-emerald-800">
              Use a different email
            </button>
          </div>
        ) : (
          <>
            <h2 className="font-display text-3xl font-bold tracking-tight text-slate-900">Forgot password?</h2>
            <p className="mt-2 text-sm text-slate-500">Enter the email you log in with and we'll send you a reset link.</p>

            <form onSubmit={submit} noValidate className="mt-8 space-y-5">
              <div>
                <label htmlFor="email" className="block text-sm font-medium text-slate-700">Email address</label>
                <div className="relative mt-1.5">
                  <Mail className="pointer-events-none absolute left-4 top-1/2 h-4 w-4 -translate-y-1/2 text-slate-400" />
                  <input
                    id="email"
                    type="email"
                    autoComplete="email"
                    value={email}
                    onChange={(e) => setEmail(e.target.value)}
                    disabled={sending}
                    placeholder="you@company.com"
                    className={`w-full rounded-xl border bg-white py-3 pl-11 pr-3 text-sm text-slate-900 shadow-sm outline-none transition placeholder:text-slate-400 focus:border-emerald-500 focus:ring-4 focus:ring-emerald-100 ${error ? 'border-red-300' : 'border-slate-300'}`}
                  />
                </div>
                {error && <p className="mt-1 text-xs text-red-600" role="alert">{error}</p>}
              </div>
              <button
                type="submit"
                disabled={sending}
                className="flex w-full items-center justify-center gap-2 rounded-xl bg-emerald-600 px-4 py-3 text-sm font-semibold text-white shadow-lg shadow-emerald-600/25 transition hover:bg-emerald-700 disabled:opacity-70"
              >
                {sending && <Loader2 className="h-4 w-4 animate-spin" />}
                {sending ? 'Sending link…' : 'Send reset link'}
              </button>
            </form>
          </>
        )}

        <Link to="/login" className="mt-8 flex items-center justify-center gap-1.5 text-sm font-medium text-slate-500 hover:text-slate-800">
          <ArrowLeft className="h-4 w-4" /> Back to log in
        </Link>
      </div>
    </AuthSplit>
  );
}
