import { useEffect, useState, type FormEvent, type ReactNode } from 'react';
import { Link, Navigate, useNavigate, useSearchParams } from 'react-router-dom';
import { AxiosError } from 'axios';
import { ArrowRight, Building2, Eye, EyeOff, Gift, Loader2, Lock, Mail, MailCheck, Phone, ShieldCheck, User } from 'lucide-react';
import { AUTH_TOKEN_KEY } from '../../core/api/axiosInstance';
import { useAuth } from '../../core/context/AuthContext';
import publicSiteService from '../../services/publicSiteService';
import type { RegisterPayload } from '../../types/site';
import { AuthSplit, BRAND_NAME } from '../AuthShowcase';
import { useSiteContent } from '../SiteContentContext';
import { ButtonLink } from '../ui';
import { usePageTitle } from '../../utils/usePageTitle';
import { trackSignup, useSiteTracking } from '../siteTracking';

/** Seconds before "Resend code" is offered again. */
const RESEND_SECONDS = 60;

const EMPTY: RegisterPayload = {
  company_name: '',
  name: '',
  email: '',
  phone: '',
  password: '',
  password_confirmation: '',
  accept_terms: false,
};

const inputClass =
  'w-full rounded-xl border bg-white py-3 pl-11 pr-3 text-sm text-slate-900 shadow-sm outline-none transition placeholder:text-slate-400 focus:border-emerald-500 focus:ring-4 focus:ring-emerald-100 disabled:bg-slate-50';

export default function SignupPage() {
  const { content, error: contentError, reload } = useSiteContent();
  const { isAuthenticated, refreshUser } = useAuth();
  const navigate = useNavigate();
  const [params] = useSearchParams();
  const plan = params.get('plan');
  usePageTitle('Sign up', content?.brand.name || BRAND_NAME);
  useSiteTracking();

  const [form, setForm] = useState<RegisterPayload>(EMPTY);
  const [showPassword, setShowPassword] = useState(false);
  const [errors, setErrors] = useState<Record<string, string>>({});
  const [formError, setFormError] = useState<string | null>(null);
  const [submitting, setSubmitting] = useState(false);
  const [step, setStep] = useState<'details' | 'verify'>('details');
  const [code, setCode] = useState('');
  const [resendIn, setResendIn] = useState(0);

  useEffect(() => {
    if (resendIn <= 0) return;
    const t = setTimeout(() => setResendIn((s) => s - 1), 1000);
    return () => clearTimeout(t);
  }, [resendIn]);

  if (isAuthenticated && !submitting) return <Navigate to="/" replace />;

  if (!content) {
    return (
      <div className="flex min-h-screen items-center justify-center bg-white">
        {contentError ? (
          <div className="text-center">
            <p className="font-semibold text-slate-900">We couldn't load this page.</p>
            <button type="button" onClick={reload} className="mt-4 rounded-xl border border-slate-300 px-4 py-2 text-sm font-semibold hover:bg-slate-50">
              Try again
            </button>
          </div>
        ) : (
          <Loader2 className="h-8 w-8 animate-spin text-emerald-600" role="status" aria-label="Loading" />
        )}
      </div>
    );
  }

  const { brand } = content;
  const brandName = brand.name || BRAND_NAME;
  const trialDays = Number(brand.trial_days) || 0;
  const trialMessages = Number(brand.trial_messages) || 0;
  const points = [trialDays > 0 ? `${trialDays}-day free trial` : 'Quick setup', 'No credit card needed', 'Connect WhatsApp by QR', 'Upgrade any time'];

  if (brand.signup_enabled === false) {
    return (
      <AuthSplit title={`Start growing with ${brandName}.`} subtitle={brand.description || ''} points={points}>
        <div className="w-full max-w-md text-center">
          <h1 className="font-display text-3xl font-bold">Sign-up is closed right now</h1>
          <p className="mt-3 text-slate-600">Please contact us and we will open an account for you.</p>
          <div className="mt-8 flex justify-center gap-3">
            <ButtonLink to="/contact">Contact us</ButtonLink>
            <ButtonLink to="/login" variant="secondary">Log in</ButtonLink>
          </div>
        </div>
      </AuthSplit>
    );
  }

  const set = (field: keyof RegisterPayload) => (e: { target: { value: string } }) =>
    setForm((f) => ({ ...f, [field]: e.target.value }));

  const validate = () => {
    const next: Record<string, string> = {};
    if (!form.company_name.trim()) next.company_name = 'Please enter your business name.';
    if (!form.name.trim()) next.name = 'Please enter your name.';
    if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(form.email)) next.email = 'Please enter a valid email.';
    if (form.password.length < 8) next.password = 'Use at least 8 characters.';
    if (form.password !== form.password_confirmation) next.password_confirmation = 'Passwords do not match.';
    if (!form.accept_terms) next.accept_terms = 'Please accept the terms to continue.';
    setErrors(next);
    return Object.keys(next).length === 0;
  };

  const fieldErrorsOf = (e: AxiosError<{ errors?: Record<string, string[]> }>) =>
    Object.fromEntries(Object.entries(e.response?.data?.errors ?? {}).map(([k, v]) => [k, v[0]]));

  /** Step 1: check the details, then email a 6-digit code to prove the address is real. */
  const sendCode = async () => {
    setSubmitting(true);
    setFormError(null);
    try {
      await publicSiteService.sendSignupCode(form.email.trim());
      setStep('verify');
      setCode('');
      setResendIn(RESEND_SECONDS);
    } catch (err) {
      const e = err as AxiosError<{ message?: string; errors?: Record<string, string[]> }>;
      if (e.response?.status === 429) setFormError('Too many attempts. Please wait a few minutes and try again.');
      else if (e.response?.data?.errors) setErrors(fieldErrorsOf(e));
      else setFormError(e.response?.data?.message ?? 'We could not send the code. Please try again.');
    } finally {
      setSubmitting(false);
    }
  };

  const submit = async (event: FormEvent) => {
    event.preventDefault();
    setFormError(null);
    if (!validate()) return;
    await sendCode();
  };

  /** Step 2: create the account with the emailed code. */
  const verify = async (event: FormEvent) => {
    event.preventDefault();
    setFormError(null);
    if (!/^\d{6}$/.test(code)) {
      setErrors({ email_code: 'Enter the 6-digit code from the email.' });
      return;
    }
    setErrors({});
    setSubmitting(true);
    try {
      const res = await publicSiteService.register({
        ...form,
        company_name: form.company_name.trim(),
        name: form.name.trim(),
        email: form.email.trim(),
        phone: form.phone?.trim() || undefined,
        email_code: code,
      });
      localStorage.setItem(AUTH_TOKEN_KEY, res.token);
      trackSignup();
      await refreshUser();
      navigate(plan ? '/billing' : '/', { replace: true });
    } catch (err) {
      const e = err as AxiosError<{ message?: string; errors?: Record<string, string[]> }>;
      const fieldErrors = fieldErrorsOf(e);
      if (e.response?.status === 429) {
        setFormError('Too many attempts. Please try again later.');
      } else if (fieldErrors.email_code) {
        setErrors({ email_code: fieldErrors.email_code });
      } else if (Object.keys(fieldErrors).length) {
        // A problem with the details themselves: go back so they can be fixed.
        setErrors(fieldErrors);
        setStep('details');
      } else {
        setFormError(e.response?.data?.message ?? 'Sign-up failed. Please try again.');
      }
      setSubmitting(false);
    }
  };

  const border = (field: string) => (errors[field] ? 'border-red-300' : 'border-slate-300');
  const strength = Math.min(4, [/.{8,}/, /[A-Z]/, /\d/, /[^A-Za-z0-9]/].filter((r) => r.test(form.password)).length);

  return (
    <AuthSplit
      title={`Start growing with ${brandName} today.`}
      subtitle={brand.description || 'WhatsApp, CRM, social media and ads in one workspace.'}
      points={points}
    >
      <div className="w-full max-w-lg">
        <h2 className="font-display text-3xl font-bold tracking-tight text-slate-900">Create your account</h2>
        <p className="mt-2 text-sm text-slate-500">
          Already have an account?{' '}
          <Link to="/login" className="font-semibold text-emerald-700 hover:text-emerald-800">Log in</Link>
        </p>

        {trialDays > 0 && (
          <div className="mt-5 flex items-center gap-3 rounded-2xl border border-emerald-200 bg-gradient-to-r from-emerald-50 to-teal-50 px-4 py-3">
            <span className="flex h-9 w-9 shrink-0 items-center justify-center rounded-xl bg-emerald-600 text-white">
              <Gift className="h-4 w-4" />
            </span>
            <p className="text-sm text-emerald-900">
              <span className="font-semibold">{trialDays}-day free trial</span>
              {trialMessages > 0 ? ` with ${trialMessages.toLocaleString('en-IN')} messages` : ''} — no card needed.
            </p>
          </div>
        )}

        {step === 'details' ? (
        <form onSubmit={submit} noValidate className="mt-6 grid gap-4 sm:grid-cols-2">
          <Field label="Business name" error={errors.company_name} icon={<Building2 />} wide>
            <input className={`${inputClass} ${border('company_name')}`} value={form.company_name} onChange={set('company_name')} autoComplete="organization" disabled={submitting} aria-label="Business name" placeholder="Acme Traders" />
          </Field>
          <Field label="Your name" error={errors.name} icon={<User />}>
            <input className={`${inputClass} ${border('name')}`} value={form.name} onChange={set('name')} autoComplete="name" disabled={submitting} aria-label="Your name" placeholder="Full name" />
          </Field>
          <Field label="Phone (optional)" error={errors.phone} icon={<Phone />}>
            <input className={`${inputClass} ${border('phone')}`} type="tel" value={form.phone} onChange={set('phone')} autoComplete="tel" disabled={submitting} aria-label="Phone" placeholder="+91 98xxx xxxxx" />
          </Field>
          <Field label="Work email" error={errors.email} icon={<Mail />} wide>
            <input className={`${inputClass} ${border('email')}`} type="email" value={form.email} onChange={set('email')} autoComplete="email" disabled={submitting} aria-label="Work email" placeholder="you@company.com" />
          </Field>
          <Field label="Password" error={errors.password} icon={<Lock />}>
            <input
              className={`${inputClass} !pr-10 ${border('password')}`}
              type={showPassword ? 'text' : 'password'}
              value={form.password}
              onChange={set('password')}
              autoComplete="new-password"
              disabled={submitting}
              aria-label="Password"
              placeholder="8+ characters"
            />
            <button
              type="button"
              tabIndex={-1}
              onClick={() => setShowPassword((v) => !v)}
              className="absolute right-3.5 top-1/2 -translate-y-1/2 text-slate-400 hover:text-slate-600"
              aria-label={showPassword ? 'Hide password' : 'Show password'}
            >
              {showPassword ? <EyeOff className="h-4 w-4" /> : <Eye className="h-4 w-4" />}
            </button>
          </Field>
          <Field label="Confirm password" error={errors.password_confirmation} icon={<Lock />}>
            <input
              className={`${inputClass} ${border('password_confirmation')}`}
              type={showPassword ? 'text' : 'password'}
              value={form.password_confirmation}
              onChange={set('password_confirmation')}
              autoComplete="new-password"
              disabled={submitting}
              aria-label="Confirm password"
              placeholder="Repeat password"
            />
          </Field>

          {form.password && (
            <div className="-mt-1 sm:col-span-2" aria-hidden="true">
              <div className="flex gap-1">
                {[0, 1, 2, 3].map((i) => (
                  <span
                    key={i}
                    className={`h-1 flex-1 rounded-full ${i < strength ? ['bg-red-400', 'bg-amber-400', 'bg-lime-500', 'bg-emerald-600'][strength - 1] : 'bg-slate-200'}`}
                  />
                ))}
              </div>
              <p className="mt-1 text-[11px] text-slate-400">{['Too weak', 'Weak', 'Okay', 'Good', 'Strong'][strength]} password</p>
            </div>
          )}

          <div className="sm:col-span-2">
            <label className="flex items-start gap-2.5 text-sm text-slate-600">
              <input
                type="checkbox"
                checked={form.accept_terms}
                onChange={(e) => setForm((f) => ({ ...f, accept_terms: e.target.checked }))}
                disabled={submitting}
                className="mt-0.5 h-4 w-4 rounded border-slate-300 text-emerald-600 focus:ring-emerald-500"
              />
              <span>
                I agree to the{' '}
                <Link to="/terms" target="_blank" className="font-semibold text-emerald-700 hover:underline">Terms of Service</Link> and{' '}
                <Link to="/privacy" target="_blank" className="font-semibold text-emerald-700 hover:underline">Privacy Policy</Link>.
              </span>
            </label>
            {errors.accept_terms && <p className="mt-1 text-xs text-red-600">{errors.accept_terms}</p>}
          </div>

          {formError && <p className="rounded-xl bg-red-50 px-4 py-3 text-sm text-red-700 sm:col-span-2" role="alert">{formError}</p>}

          <button
            type="submit"
            disabled={submitting}
            className="group inline-flex items-center justify-center gap-2 rounded-xl bg-emerald-600 px-6 py-3.5 text-sm font-semibold text-white shadow-lg shadow-emerald-600/25 transition hover:bg-emerald-700 disabled:opacity-70 sm:col-span-2"
          >
            {submitting && <Loader2 className="h-4 w-4 animate-spin" />}
            {submitting ? 'Sending code…' : 'Continue'}
            {!submitting && <ArrowRight className="h-4 w-4 transition group-hover:translate-x-0.5" />}
          </button>
          <p className="flex items-center justify-center gap-1.5 text-xs text-slate-400 sm:col-span-2">
            <ShieldCheck className="h-3.5 w-3.5" /> We'll email you a code to confirm the address is yours.
          </p>
        </form>
        ) : (
          <form onSubmit={verify} noValidate className="mt-6">
            <div className="rounded-2xl border border-slate-200 bg-slate-50 p-5">
              <div className="flex items-start gap-3">
                <span className="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl bg-emerald-100 text-emerald-700">
                  <MailCheck className="h-5 w-5" />
                </span>
                <div className="min-w-0 text-sm">
                  <p className="font-semibold text-slate-900">Check your inbox</p>
                  <p className="mt-0.5 text-slate-600">
                    We sent a 6-digit code to <span className="break-all font-semibold text-slate-900">{form.email.trim()}</span>.
                  </p>
                </div>
              </div>
            </div>

            <label htmlFor="email-code" className="mt-6 block text-sm font-medium text-slate-700">Verification code</label>
            <input
              id="email-code"
              inputMode="numeric"
              autoComplete="one-time-code"
              maxLength={6}
              value={code}
              onChange={(e) => setCode(e.target.value.replace(/\D/g, '').slice(0, 6))}
              disabled={submitting}
              autoFocus
              placeholder="••••••"
              className={`mt-1.5 w-full rounded-xl border bg-white px-4 py-3.5 text-center font-mono text-2xl font-bold tracking-[0.6em] text-slate-900 shadow-sm outline-none transition placeholder:text-slate-300 focus:border-emerald-500 focus:ring-4 focus:ring-emerald-100 ${border('email_code')}`}
            />
            {errors.email_code && <p className="mt-1 text-xs text-red-600" role="alert">{errors.email_code}</p>}
            {formError && <p className="mt-4 rounded-xl bg-red-50 px-4 py-3 text-sm text-red-700" role="alert">{formError}</p>}

            <button
              type="submit"
              disabled={submitting}
              className="group mt-5 inline-flex w-full items-center justify-center gap-2 rounded-xl bg-emerald-600 px-6 py-3.5 text-sm font-semibold text-white shadow-lg shadow-emerald-600/25 transition hover:bg-emerald-700 disabled:opacity-70"
            >
              {submitting && <Loader2 className="h-4 w-4 animate-spin" />}
              {submitting ? 'Creating your account…' : 'Verify & create account'}
              {!submitting && <ArrowRight className="h-4 w-4 transition group-hover:translate-x-0.5" />}
            </button>

            <div className="mt-5 flex items-center justify-between text-sm">
              <button type="button" onClick={() => setStep('details')} disabled={submitting} className="font-medium text-slate-500 hover:text-slate-800">
                ← Change email
              </button>
              <button
                type="button"
                onClick={() => void sendCode()}
                disabled={submitting || resendIn > 0}
                className="font-semibold text-emerald-700 hover:text-emerald-800 disabled:font-medium disabled:text-slate-400"
              >
                {resendIn > 0 ? `Resend code in ${resendIn}s` : 'Resend code'}
              </button>
            </div>
          </form>
        )}
      </div>
    </AuthSplit>
  );
}

function Field({ label, error, wide, icon, children }: { label: string; error?: string; wide?: boolean; icon: ReactNode; children: ReactNode }) {
  return (
    <label className={`block text-sm font-medium text-slate-700 ${wide ? 'sm:col-span-2' : ''}`}>
      {label}
      <span className="relative mt-1.5 block">
        <span className="pointer-events-none absolute left-4 top-1/2 -translate-y-1/2 text-slate-400 [&>svg]:h-4 [&>svg]:w-4">{icon}</span>
        {children}
      </span>
      {error && <span className="mt-1 block text-xs text-red-600">{error}</span>}
    </label>
  );
}
