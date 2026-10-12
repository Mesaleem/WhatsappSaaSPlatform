import { useState, type FormEvent } from 'react';
import { Link, useLocation, useNavigate, type Location } from 'react-router-dom';
import { AlertCircle, ArrowRight, Eye, EyeOff, Info, Lock, LogOut, Mail, Loader2, MonitorSmartphone } from 'lucide-react';
import { SESSION_ENDED_KEY, useAuth } from '../../core/context/AuthContext';
import type { LoginError } from '../../types/auth';
import DismissibleAlert from '../../components/common/DismissibleAlert';
import { AuthSplit } from '../../website/AuthShowcase';
import { usePageTitle } from '../../utils/usePageTitle';

interface LocationState {
  from?: Location;
}

export default function LoginPage() {
  const { login } = useAuth();
  const navigate = useNavigate();
  const location = useLocation();
  usePageTitle('Log in');

  const [email, setEmail] = useState('');
  const [password, setPassword] = useState('');
  const [showPassword, setShowPassword] = useState(false);
  const [isSubmitting, setIsSubmitting] = useState(false);
  const [formError, setFormError] = useState<string | null>(null);
  const [fieldErrors, setFieldErrors] = useState<{ email?: string; password?: string }>({});
  const [activeSession, setActiveSession] = useState(false);
  // Shown once when the server ended this browser's session (another device logged in, or the password changed).
  const [sessionEnded] = useState(() => {
    try {
      const flag = sessionStorage.getItem(SESSION_ENDED_KEY) === '1';
      sessionStorage.removeItem(SESSION_ENDED_KEY);
      return flag;
    } catch {
      return false;
    }
  });

  const validate = (): boolean => {
    const errors: { email?: string; password?: string } = {};
    if (!email.trim()) {
      errors.email = 'Email is required.';
    } else if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email)) {
      errors.email = 'Enter a valid email address.';
    }
    if (!password) {
      errors.password = 'Password is required.';
    }
    setFieldErrors(errors);
    return Object.keys(errors).length === 0;
  };

  const handleSubmit = async (event: FormEvent) => {
    event.preventDefault();
    setFormError(null);
    if (!validate()) return;

    await signIn(false);
  };

  /** force = "log out from all devices and log in here", after a 409 ACTIVE_SESSION. */
  const signIn = async (force: boolean) => {
    setIsSubmitting(true);
    setFormError(null);
    try {
      await login({ email: email.trim(), password, ...(force ? { force: true } : {}) });
      const state = location.state as LocationState | null;
      const destination = state?.from?.pathname ?? '/';
      navigate(destination, { replace: true });
    } catch (err) {
      const e = err as LoginError;
      if (e.code === 'ACTIVE_SESSION') {
        setActiveSession(true);
      } else {
        setFormError(err instanceof Error ? err.message : 'Unable to sign in. Please try again.');
      }
    } finally {
      setIsSubmitting(false);
    }
  };

  const inputClass = (hasError: boolean) =>
    `w-full rounded-xl border bg-white py-3 pl-11 text-sm text-slate-900 shadow-sm outline-none transition placeholder:text-slate-400 focus:border-emerald-500 focus:ring-4 focus:ring-emerald-100 disabled:bg-slate-50 ${
      hasError ? 'border-red-300' : 'border-slate-300'
    }`;

  return (
    <AuthSplit
      title="Welcome back to your customer conversations."
      subtitle="Campaigns, chatbots, leads and social — everything is right where you left it."
      points={['WhatsApp QR & Cloud API', 'No-code Journey Builder', 'Built-in CRM', 'Facebook & Instagram']}
    >
      <div className="w-full max-w-md">
        <h2 className="font-display text-3xl font-bold tracking-tight text-slate-900">Log in</h2>
        <p className="mt-2 text-sm text-slate-500">Enter your email and password to open your workspace.</p>

        {sessionEnded && !activeSession && (
          <div role="status" className="mt-6 flex items-start gap-3 rounded-xl border border-sky-200 bg-sky-50 px-4 py-3 text-sm text-sky-900">
            <Info className="mt-0.5 h-4 w-4 shrink-0" />
            <span>
              You were signed out. This happens when your account logs in on another device or its password is changed.
              Not you? <Link to="/forgot-password" className="font-semibold underline">Reset your password</Link>.
            </span>
          </div>
        )}

        {activeSession && (
          <div role="alertdialog" aria-labelledby="active-session-title" className="mt-6 rounded-2xl border border-amber-300 bg-amber-50 p-5">
            <div className="flex items-start gap-3">
              <span className="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl bg-amber-100 text-amber-700">
                <MonitorSmartphone className="h-5 w-5" />
              </span>
              <div className="min-w-0">
                <p id="active-session-title" className="font-semibold text-amber-900">Device limit reached</p>
                <p className="mt-1 text-sm text-amber-800">
                  Your account is already logged in on the maximum number of devices. Log out from all devices to continue here — every other device will be signed out and we will email you about it.
                </p>
              </div>
            </div>
            <div className="mt-4 flex flex-col gap-2 sm:flex-row">
              <button
                type="button"
                onClick={() => void signIn(true)}
                disabled={isSubmitting}
                className="inline-flex flex-1 items-center justify-center gap-2 rounded-xl bg-amber-600 px-4 py-2.5 text-sm font-semibold text-white shadow-sm transition hover:bg-amber-700 disabled:opacity-70"
              >
                {isSubmitting ? <Loader2 className="h-4 w-4 animate-spin" /> : <LogOut className="h-4 w-4" />}
                Log out from all devices &amp; log in here
              </button>
              <button
                type="button"
                onClick={() => setActiveSession(false)}
                disabled={isSubmitting}
                className="rounded-xl border border-amber-300 bg-white px-4 py-2.5 text-sm font-semibold text-amber-900 hover:bg-amber-100"
              >
                Cancel
              </button>
            </div>
            <p className="mt-3 text-xs text-amber-700">
              Didn't log in anywhere else? <Link to="/forgot-password" className="font-semibold underline">Change your password</Link> after logging in.
            </p>
          </div>
        )}

        {formError && (
          <DismissibleAlert
            role="alert"
            className="mt-6 flex items-start gap-2 rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700"
          >
            <AlertCircle className="mt-0.5 h-4 w-4 shrink-0" />
            <span>{formError}</span>
          </DismissibleAlert>
        )}

        <form className="mt-8 space-y-5" onSubmit={handleSubmit} noValidate>
          <div>
            <label htmlFor="email" className="block text-sm font-medium text-slate-700">
              Email address <span className="text-red-500">*</span>
            </label>
            <div className="relative mt-1.5">
              <Mail className="pointer-events-none absolute left-4 top-1/2 h-4 w-4 -translate-y-1/2 text-slate-400" />
              <input
                id="email"
                name="email"
                type="email"
                autoComplete="email"
                value={email}
                onChange={(e) => setEmail(e.target.value)}
                disabled={isSubmitting}
                className={`${inputClass(!!fieldErrors.email)} pr-3`}
                placeholder="you@company.com"
              />
            </div>
            {fieldErrors.email && <p className="mt-1 text-xs text-red-600">{fieldErrors.email}</p>}
          </div>

          <div>
            <div className="flex items-center justify-between">
              <label htmlFor="password" className="block text-sm font-medium text-slate-700">
                Password <span className="text-red-500">*</span>
              </label>
              <Link to="/forgot-password" className="text-sm font-semibold text-emerald-700 hover:text-emerald-800">
                Forgot password?
              </Link>
            </div>
            <div className="relative mt-1.5">
              <Lock className="pointer-events-none absolute left-4 top-1/2 h-4 w-4 -translate-y-1/2 text-slate-400" />
              <input
                id="password"
                name="password"
                type={showPassword ? 'text' : 'password'}
                autoComplete="current-password"
                value={password}
                onChange={(e) => setPassword(e.target.value)}
                disabled={isSubmitting}
                className={`${inputClass(!!fieldErrors.password)} pr-11`}
                placeholder="••••••••"
              />
              <button
                type="button"
                tabIndex={-1}
                onClick={() => setShowPassword((v) => !v)}
                className="absolute right-4 top-1/2 -translate-y-1/2 text-slate-400 hover:text-slate-600"
                aria-label={showPassword ? 'Hide password' : 'Show password'}
              >
                {showPassword ? <EyeOff className="h-4 w-4" /> : <Eye className="h-4 w-4" />}
              </button>
            </div>
            {fieldErrors.password && <p className="mt-1 text-xs text-red-600">{fieldErrors.password}</p>}
          </div>

          <button
            type="submit"
            disabled={isSubmitting}
            className="group flex w-full items-center justify-center gap-2 rounded-xl bg-emerald-600 px-4 py-3 text-sm font-semibold text-white shadow-lg shadow-emerald-600/25 transition hover:bg-emerald-700 disabled:cursor-not-allowed disabled:opacity-70"
          >
            {isSubmitting ? <Loader2 className="h-4 w-4 animate-spin" /> : null}
            {isSubmitting ? 'Signing in…' : 'Sign in'}
            {!isSubmitting && <ArrowRight className="h-4 w-4 transition group-hover:translate-x-0.5" />}
          </button>
        </form>

        <div className="mt-8 flex items-center gap-3 text-xs text-slate-400">
          <span className="h-px flex-1 bg-slate-200" /> New to WapHub? <span className="h-px flex-1 bg-slate-200" />
        </div>
        <Link
          to="/signup"
          className="mt-4 flex w-full items-center justify-center rounded-xl border border-slate-300 bg-white px-4 py-3 text-sm font-semibold text-slate-800 transition hover:border-emerald-300 hover:bg-emerald-50/50"
        >
          Create an account
        </Link>
      </div>
    </AuthSplit>
  );
}
