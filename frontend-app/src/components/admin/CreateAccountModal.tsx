import { useState, type FormEvent, type ReactNode } from 'react';
import {
  AlertCircle,
  Building2,
  CheckCircle2,
  Globe2,
  IndianRupee,
  Loader2,
  QrCode,
  ShieldCheck,
  UserPlus,
  X,
} from 'lucide-react';
import accountService from '../../services/accountService';
import {
  ACCOUNT_MODULES,
  ACCOUNT_MODULE_LABELS,
  type Account,
  type AccountModule,
  type AccountStatus,
  type CreateAccountPayload,
  type UpdateAccountPayload,
} from '../../types/account';
import type { BillingModel, EngineType, PaymentMode, UpdateSubscriptionPayload } from '../../types/subscription';
import { extractErrorMessage } from '../../utils/apiError';

interface CreateAccountModalProps {
  /** null = create mode. A populated Account = edit mode. */
  account: Account | null;
  onClose: () => void;
  onSaved: () => void;
}

const inputClass =
  'mt-1.5 w-full rounded-lg border border-slate-300 px-3 py-2 text-sm text-slate-900 shadow-sm outline-none transition focus:border-indigo-500 focus:ring-2 focus:ring-indigo-100';

function toDateInputValue(iso: string | null | undefined): string {
  return iso ? iso.slice(0, 10) : '';
}

export default function CreateAccountModal({ account, onClose, onSaved }: CreateAccountModalProps) {
  const isEditMode = account !== null;
  const sub = account?.current_subscription ?? null;

  // Section 1 — Company & Admin
  const [companyName, setCompanyName] = useState(account?.company_name ?? '');
  const [primaryPhone, setPrimaryPhone] = useState(account?.primary_phone ?? '');
  const [status, setStatus] = useState<AccountStatus>(account?.status ?? 'active');
  const [adminName, setAdminName] = useState('');
  const [adminEmail, setAdminEmail] = useState('');
  const [adminPassword, setAdminPassword] = useState('');

  // Section 2 — Subscription & Engine Config
  const [engineType, setEngineType] = useState<EngineType>(sub?.engine_type ?? 'qr');
  const [billingModel, setBillingModel] = useState<BillingModel>(sub?.billing_model ?? 'flat_quota');
  const [ratePerMessage, setRatePerMessage] = useState(sub?.rate_per_message ?? '');
  const [totalAllocated, setTotalAllocated] = useState(
    sub?.total_allocated_messages != null ? String(sub.total_allocated_messages) : '',
  );
  const [pricePaid, setPricePaid] = useState(sub?.price_paid ?? '');
  const [paymentMode, setPaymentMode] = useState<PaymentMode>(sub?.payment_mode ?? 'cash');
  const [startsAt, setStartsAt] = useState(toDateInputValue(sub?.starts_at) || toDateInputValue(new Date().toISOString()));
  const [expiresAt, setExpiresAt] = useState(toDateInputValue(sub?.expires_at));

  const [isSubmitting, setIsSubmitting] = useState(false);
  const [formError, setFormError] = useState<string | null>(null);

  // Absolute Super Admin Control — module toggles. Edit mode only; each
  // checkbox fires its own PATCH immediately ("dynamically start/stop"),
  // independent of this form's own Save Changes submit cycle.
  const [allowedModules, setAllowedModules] = useState<AccountModule[] | null>(account?.allowed_modules ?? null);
  const [modulesBusy, setModulesBusy] = useState<AccountModule | null>(null);
  const [modulesError, setModulesError] = useState<string | null>(null);
  const [modulesSaved, setModulesSaved] = useState(false);

  const validate = (): string | null => {
    if (!companyName.trim()) return 'Company name is required.';
    if (!isEditMode) {
      if (!adminName.trim()) return 'Admin name is required.';
      if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(adminEmail)) return 'Enter a valid admin email.';
      if (adminPassword.length < 8) return 'Admin password must be at least 8 characters.';
    }
    if (billingModel === 'per_message' && (!ratePerMessage || Number(ratePerMessage) <= 0)) {
      return 'Enter a per-message rate greater than 0.';
    }
    if (billingModel !== 'unlimited' && (!totalAllocated || Number(totalAllocated) <= 0)) {
      return 'Enter the total allocated messages.';
    }
    if (pricePaid === '' || Number(pricePaid) < 0) return 'Enter a valid price paid.';
    if (!startsAt || !expiresAt) return 'Starts At and Expires At are both required.';
    if (new Date(expiresAt) <= new Date(startsAt)) return 'Expires At must be after Starts At.';
    return null;
  };

  const handleSubmit = async (event: FormEvent) => {
    event.preventDefault();
    const validationError = validate();
    if (validationError) {
      setFormError(validationError);
      return;
    }
    setFormError(null);
    setIsSubmitting(true);

    try {
      if (isEditMode && account) {
        const accountPayload: UpdateAccountPayload = {
          company_name: companyName.trim(),
          primary_phone: primaryPhone.trim() || null,
          status,
        };
        const subscriptionPayload: UpdateSubscriptionPayload = {
          engine_type: engineType,
          billing_model: billingModel,
          rate_per_message: billingModel === 'per_message' ? Number(ratePerMessage) : null,
          total_allocated_messages: billingModel === 'unlimited' ? null : Number(totalAllocated),
          price_paid: Number(pricePaid),
          payment_mode: paymentMode,
          starts_at: startsAt,
          expires_at: expiresAt,
        };
        await Promise.all([
          accountService.update(account.id, accountPayload),
          accountService.updateSubscription(account.id, subscriptionPayload),
        ]);
      } else {
        const payload: CreateAccountPayload = {
          company_name: companyName.trim(),
          primary_phone: primaryPhone.trim() || undefined,
          status,
          admin_name: adminName.trim(),
          admin_email: adminEmail.trim(),
          admin_password: adminPassword,
          engine_type: engineType,
          billing_model: billingModel,
          rate_per_message: billingModel === 'per_message' ? Number(ratePerMessage) : undefined,
          total_allocated_messages: billingModel === 'unlimited' ? undefined : Number(totalAllocated),
          price_paid: Number(pricePaid),
          payment_mode: paymentMode,
          starts_at: startsAt,
          expires_at: expiresAt,
        };
        await accountService.create(payload);
      }
      onSaved();
    } catch (err) {
      setFormError(extractErrorMessage(err, 'Failed to save the account. Please check the form and try again.'));
    } finally {
      setIsSubmitting(false);
    }
  };

  const isModuleEnabled = (module: AccountModule) => allowedModules === null || allowedModules.includes(module);

  const toggleModule = async (module: AccountModule) => {
    if (!account) return;
    // null ("all enabled") expands to the full list on first toggle, so
    // unchecking one module never silently re-enables the rest.
    const current = allowedModules ?? [...ACCOUNT_MODULES];
    const next = current.includes(module) ? current.filter((m) => m !== module) : [...current, module];

    setModulesBusy(module);
    setModulesError(null);
    setModulesSaved(false);
    try {
      const updated = await accountService.updatePermissions(account.id, { allowed_modules: next });
      setAllowedModules(updated.allowed_modules);
      setModulesSaved(true);
    } catch (err) {
      setModulesError(extractErrorMessage(err, 'Could not update this module. Please try again.'));
    } finally {
      setModulesBusy(null);
    }
  };

  return (
    <div className="fixed inset-0 z-50 flex items-center justify-center bg-slate-900/50 p-4">
      <div className="max-h-[90vh] w-full max-w-2xl overflow-y-auto rounded-xl bg-white shadow-xl">
        <div className="flex items-center justify-between border-b border-slate-200 px-6 py-4">
          <h2 className="text-lg font-semibold text-slate-900">
            {isEditMode ? `Edit ${account?.company_name}` : 'Provision New Account'}
          </h2>
          <button
            onClick={onClose}
            className="rounded-md p-1 text-slate-400 hover:bg-slate-100 hover:text-slate-600"
            aria-label="Close"
          >
            <X className="h-5 w-5" />
          </button>
        </div>

        <form onSubmit={handleSubmit} className="space-y-8 px-6 py-6" noValidate>
          {formError && (
            <div
              role="alert"
              className="flex items-start gap-2 rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700"
            >
              <AlertCircle className="mt-0.5 h-4 w-4 shrink-0" />
              <span>{formError}</span>
            </div>
          )}

          {/* Section 1 — Company & Admin Info */}
          <section>
            <div className="flex items-center gap-2 text-sm font-semibold text-slate-900">
              <Building2 className="h-4 w-4 text-indigo-600" />
              Company &amp; Admin Info
            </div>
            <div className="mt-4 grid grid-cols-1 gap-4 sm:grid-cols-2">
              <Field label="Company Name" required>
                <input
                  value={companyName}
                  onChange={(e) => setCompanyName(e.target.value)}
                  className={inputClass}
                  placeholder="Acme Textiles Pvt. Ltd."
                />
              </Field>
              <Field label="Primary Phone">
                <input
                  value={primaryPhone}
                  onChange={(e) => setPrimaryPhone(e.target.value)}
                  className={inputClass}
                  placeholder="+91 98765 43210"
                />
              </Field>
              <Field label="Status">
                <select value={status} onChange={(e) => setStatus(e.target.value as AccountStatus)} className={inputClass}>
                  <option value="active">Active</option>
                  <option value="suspended">Suspended</option>
                  <option value="expired">Expired</option>
                </select>
              </Field>
            </div>

            {!isEditMode ? (
              <div className="mt-4 grid grid-cols-1 gap-4 sm:grid-cols-2">
                <Field label="Admin Name" required>
                  <input
                    value={adminName}
                    onChange={(e) => setAdminName(e.target.value)}
                    className={inputClass}
                    placeholder="Jane Doe"
                  />
                </Field>
                <Field label="Admin Email" required>
                  <input
                    type="email"
                    value={adminEmail}
                    onChange={(e) => setAdminEmail(e.target.value)}
                    className={inputClass}
                    placeholder="jane@acme.com"
                  />
                </Field>
                <Field label="Admin Password" required hint="At least 8 characters">
                  <input
                    type="password"
                    value={adminPassword}
                    onChange={(e) => setAdminPassword(e.target.value)}
                    className={inputClass}
                    placeholder="••••••••"
                  />
                </Field>
              </div>
            ) : (
              <p className="mt-3 flex items-center gap-1.5 text-xs text-slate-400">
                <UserPlus className="h-3.5 w-3.5" />
                Admin user details are set at creation and aren't edited here.
              </p>
            )}
          </section>

          {/* Section 2 — Subscription & Engine Config */}
          <section>
            <div className="flex items-center gap-2 text-sm font-semibold text-slate-900">
              <IndianRupee className="h-4 w-4 text-indigo-600" />
              Subscription &amp; Engine Config
            </div>

            <div className="mt-4">
              <label className="block text-sm font-medium text-slate-700">Engine</label>
              <div className="mt-1.5 grid grid-cols-2 gap-3">
                <EngineOption
                  active={engineType === 'qr'}
                  onClick={() => setEngineType('qr')}
                  icon={<QrCode className="h-4 w-4" />}
                  label="QR (Baileys)"
                  description="Unofficial, browser-session based"
                />
                <EngineOption
                  active={engineType === 'meta'}
                  onClick={() => setEngineType('meta')}
                  icon={<Globe2 className="h-4 w-4" />}
                  label="Meta Cloud API"
                  description="Official WhatsApp Business Platform"
                />
              </div>
            </div>

            <div className="mt-4 grid grid-cols-1 gap-4 sm:grid-cols-2">
              <Field label="Billing Model" required>
                <select
                  value={billingModel}
                  onChange={(e) => setBillingModel(e.target.value as BillingModel)}
                  className={inputClass}
                >
                  <option value="flat_quota">Flat Quota</option>
                  <option value="per_message">Per Message Rate</option>
                  <option value="unlimited">Unlimited</option>
                </select>
              </Field>

              {billingModel === 'per_message' && (
                <Field label="Custom Rate (INR / message)" required>
                  <input
                    type="number"
                    step="0.0001"
                    min="0"
                    value={ratePerMessage}
                    onChange={(e) => setRatePerMessage(e.target.value)}
                    className={inputClass}
                    placeholder="0.2000"
                  />
                </Field>
              )}

              {billingModel !== 'unlimited' && (
                <Field label="Total Allocated Messages" required>
                  <input
                    type="number"
                    min="1"
                    value={totalAllocated}
                    onChange={(e) => setTotalAllocated(e.target.value)}
                    className={inputClass}
                    placeholder="5000"
                  />
                </Field>
              )}

              <Field label="Price Paid (INR)" required>
                <input
                  type="number"
                  step="0.01"
                  min="0"
                  value={pricePaid}
                  onChange={(e) => setPricePaid(e.target.value)}
                  className={inputClass}
                  placeholder="4999"
                />
              </Field>

              <Field label="Payment Mode" required>
                <select
                  value={paymentMode}
                  onChange={(e) => setPaymentMode(e.target.value as PaymentMode)}
                  className={inputClass}
                >
                  <option value="cash">Cash</option>
                  <option value="razorpay">Razorpay</option>
                </select>
              </Field>

              <Field label="Starts At" required>
                <input type="date" value={startsAt} onChange={(e) => setStartsAt(e.target.value)} className={inputClass} />
              </Field>
              <Field label="Expires At" required>
                <input type="date" value={expiresAt} onChange={(e) => setExpiresAt(e.target.value)} className={inputClass} />
              </Field>
            </div>
          </section>

          {/* Section 3 — Absolute Super Admin Control: per-client module toggles (edit mode only) */}
          {isEditMode && account && (
            <section>
              <div className="flex items-center gap-2 text-sm font-semibold text-slate-900">
                <ShieldCheck className="h-4 w-4 text-indigo-600" />
                Module &amp; Feature Access
              </div>
              <p className="mt-1 text-xs text-slate-500">
                Start or stop what this client can use. Changes apply immediately.
              </p>

              {modulesError && (
                <div
                  role="alert"
                  className="mt-3 flex items-start gap-2 rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700"
                >
                  <AlertCircle className="mt-0.5 h-4 w-4 shrink-0" />
                  <span>{modulesError}</span>
                </div>
              )}

              <div className="mt-3 grid grid-cols-1 gap-2 sm:grid-cols-2">
                {ACCOUNT_MODULES.map((module) => {
                  const enabled = isModuleEnabled(module);
                  const busy = modulesBusy === module;
                  return (
                    <label
                      key={module}
                      className={`flex items-center justify-between gap-3 rounded-lg border px-3 py-2 text-sm transition ${
                        enabled ? 'border-indigo-200 bg-indigo-50/50' : 'border-slate-200'
                      }`}
                    >
                      <span className={enabled ? 'text-slate-900' : 'text-slate-500'}>
                        {ACCOUNT_MODULE_LABELS[module]}
                      </span>
                      <span className="flex items-center gap-2">
                        {busy && <Loader2 className="h-3.5 w-3.5 animate-spin text-slate-400" />}
                        <input
                          type="checkbox"
                          checked={enabled}
                          disabled={busy}
                          onChange={() => void toggleModule(module)}
                          className="h-4 w-4 rounded border-slate-300 text-indigo-600 focus:ring-indigo-500 disabled:opacity-60"
                        />
                      </span>
                    </label>
                  );
                })}
              </div>

              {modulesSaved && !modulesBusy && !modulesError && (
                <p className="mt-2 flex items-center gap-1.5 text-xs text-emerald-600">
                  <CheckCircle2 className="h-3.5 w-3.5" />
                  Saved.
                </p>
              )}
            </section>
          )}

          <div className="flex items-center justify-end gap-3 border-t border-slate-200 pt-4">
            <button
              type="button"
              onClick={onClose}
              className="rounded-lg border border-slate-300 px-4 py-2 text-sm font-medium text-slate-700 hover:bg-slate-50"
            >
              Cancel
            </button>
            <button
              type="submit"
              disabled={isSubmitting}
              className="flex items-center gap-2 rounded-lg bg-indigo-600 px-4 py-2 text-sm font-semibold text-white shadow-sm hover:bg-indigo-700 disabled:cursor-not-allowed disabled:opacity-70"
            >
              {isSubmitting && <Loader2 className="h-4 w-4 animate-spin" />}
              {isEditMode ? 'Save Changes' : 'Provision Account'}
            </button>
          </div>
        </form>
      </div>
    </div>
  );
}

function Field({
  label,
  required,
  hint,
  children,
}: {
  label: string;
  required?: boolean;
  hint?: string;
  children: ReactNode;
}) {
  return (
    <div>
      <label className="block text-sm font-medium text-slate-700">
        {label} {required && <span className="text-red-500">*</span>}
      </label>
      {children}
      {hint && <p className="mt-1 text-xs text-slate-400">{hint}</p>}
    </div>
  );
}

function EngineOption({
  active,
  onClick,
  icon,
  label,
  description,
}: {
  active: boolean;
  onClick: () => void;
  icon: ReactNode;
  label: string;
  description: string;
}) {
  return (
    <button
      type="button"
      onClick={onClick}
      className={`flex items-start gap-3 rounded-lg border px-3 py-2.5 text-left transition ${
        active ? 'border-indigo-500 bg-indigo-50 ring-1 ring-indigo-500' : 'border-slate-300 hover:bg-slate-50'
      }`}
    >
      <span className={`mt-0.5 ${active ? 'text-indigo-600' : 'text-slate-400'}`}>{icon}</span>
      <span>
        <span className={`block text-sm font-medium ${active ? 'text-indigo-900' : 'text-slate-900'}`}>{label}</span>
        <span className="block text-xs text-slate-500">{description}</span>
      </span>
    </button>
  );
}
