import { useEffect, useRef, useState, type FormEvent, type ReactNode } from 'react';
import {
  AlertCircle,
  Building2,
  CheckCircle2,
  Eye,
  EyeOff,
  Globe2,
  ImageIcon,
  IndianRupee,
  Loader2,
  MessageSquare,
  QrCode,
  RefreshCw,
  Share2,
  ShieldCheck,
  UserPlus,
  X,
} from 'lucide-react';
import accountService from '../../services/accountService';
import {
  ACCOUNT_MODULES,
  ACCOUNT_MODULE_LABELS,
  CORE_COMMON_MODULES,
  MODULE_ASSIGNMENTS,
  MODULE_ASSIGNMENT_LABELS,
  SOCIAL_SUITE_MODULES,
  WHATSAPP_SUITE_MODULES,
  type Account,
  type AccountModule,
  type AccountStatus,
  type CreateAccountPayload,
  type ModuleAssignment,
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

/**
 * Corrected Unified Client & Admin User Creation — Section 2's
 * Auto-Generate option. 12 chars, guarantees at least one of each
 * character class so it always clears AccountController::store()'s
 * 'min:8' rule with room to spare.
 */
function generatePassword(): string {
  const upper = 'ABCDEFGHJKLMNPQRSTUVWXYZ';
  const lower = 'abcdefghijkmnopqrstuvwxyz';
  const digits = '23456789';
  const symbols = '!@#$%^&*';
  const all = upper + lower + digits + symbols;
  const pick = (set: string) => set[Math.floor(Math.random() * set.length)];
  const required = [pick(upper), pick(lower), pick(digits), pick(symbols)];
  const rest = Array.from({ length: 8 }, () => pick(all));
  return [...required, ...rest].sort(() => Math.random() - 0.5).join('');
}

export default function CreateAccountModal({ account, onClose, onSaved }: CreateAccountModalProps) {
  const isEditMode = account !== null;
  const sub = account?.current_subscription ?? null;

  // Section 1 — Company & Admin
  const [companyName, setCompanyName] = useState(account?.company_name ?? '');
  const [primaryPhone, setPrimaryPhone] = useState(account?.primary_phone ?? '');
  // White-Label Automated PDF Reporting — Final Phase (edit mode only; see Section 2.5 below).
  const [logoUrl, setLogoUrl] = useState(account?.logo_url ?? '');
  const [brandAccentColor, setBrandAccentColor] = useState(account?.brand_accent_color ?? '');
  const [status, setStatus] = useState<AccountStatus>(account?.status ?? 'active');
  // Super Admin Client Provisioning refactor — Section 1 additions.
  const [moduleAssignment, setModuleAssignment] = useState<ModuleAssignment>(account?.module_assignment ?? 'both');
  const [maxUsersLimit, setMaxUsersLimit] = useState(
    account?.max_users_limit != null ? String(account.max_users_limit) : '',
  );
  // Section 2 — Primary Client Admin Credentials (create mode only).
  const [adminName, setAdminName] = useState('');
  const [adminEmail, setAdminEmail] = useState('');
  const [adminPhone, setAdminPhone] = useState('');
  const [adminPassword, setAdminPassword] = useState('');
  const [showAdminPassword, setShowAdminPassword] = useState(false);

  // Section 3 — Subscription & Engine Config
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

  // "Module & Feature Access" — reorganized into Core Common + WhatsApp
  // Suite + Social Suite (see types/account.ts). Runs in BOTH modes now:
  //  - Edit mode: unchanged pre-existing behavior — every change fires
  //    an immediate PATCH via accountService.updatePermissions()
  //    ("dynamically start/stop"), independent of this form's own Save
  //    Changes submit cycle.
  //  - Create mode (NEW): purely local state, no PATCH calls — the
  //    final array rides on CreateAccountPayload.allowed_modules at
  //    submit time, seeded to CORE_COMMON_MODULES so exactly the 4 core
  //    items are pre-checked and nothing else, per spec.
  const [allowedModules, setAllowedModules] = useState<AccountModule[] | null>(
    isEditMode ? (account?.allowed_modules ?? null) : [...CORE_COMMON_MODULES],
  );
  const [modulesBusy, setModulesBusy] = useState<AccountModule | null>(null);
  const [modulesBulkBusy, setModulesBulkBusy] = useState(false);
  const [modulesError, setModulesError] = useState<string | null>(null);
  const [modulesSaved, setModulesSaved] = useState(false);

  const validate = (): string | null => {
    if (!companyName.trim()) return 'Company name is required.';
    if (isEditMode && brandAccentColor.trim() && !/^[0-9a-fA-F]{6}$/.test(brandAccentColor.trim())) {
      return 'Brand Accent Color must be a 6-digit hex code (e.g. 4F46E5), without the #.';
    }
    if (maxUsersLimit.trim() && (!/^\d+$/.test(maxUsersLimit.trim()) || Number(maxUsersLimit) < 1)) {
      return 'Max Users Limit must be a whole number of at least 1 (leave blank for unlimited).';
    }
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
          logo_url: logoUrl.trim() || null,
          brand_accent_color: brandAccentColor.trim() || null,
          max_users_limit: maxUsersLimit.trim() ? Number(maxUsersLimit) : null,
          module_assignment: moduleAssignment,
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
          max_users_limit: maxUsersLimit.trim() ? Number(maxUsersLimit) : undefined,
          module_assignment: moduleAssignment,
          // "Module & Feature Access" now renders during Create too —
          // whatever Core Common / suite selection the Super Admin left
          // it in (default: the 4 Core Common items) ships with the
          // account atomically instead of requiring a follow-up PATCH.
          allowed_modules: allowedModules ?? undefined,
          admin_name: adminName.trim(),
          admin_email: adminEmail.trim(),
          admin_phone: adminPhone.trim() || undefined,
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

  /**
   * Commits a computed next `allowed_modules` array. Edit mode fires the
   * existing immediate PATCH ("dynamically start/stop"); create mode is
   * purely local (no account id exists yet to PATCH against) — the
   * array rides on CreateAccountPayload.allowed_modules at submit.
   */
  const commitModules = async (next: AccountModule[]) => {
    if (isEditMode && account) {
      setModulesError(null);
      setModulesSaved(false);
      try {
        const updated = await accountService.updatePermissions(account.id, { allowed_modules: next });
        setAllowedModules(updated.allowed_modules);
        setModulesSaved(true);
      } catch (err) {
        setModulesError(extractErrorMessage(err, 'Could not update this module. Please try again.'));
      }
      return;
    }
    setAllowedModules(next);
  };

  const toggleModule = async (module: AccountModule) => {
    // null ("all enabled") expands to the full list on first toggle, so
    // unchecking one module never silently re-enables the rest.
    const current = allowedModules ?? [...ACCOUNT_MODULES];
    const next = current.includes(module) ? current.filter((m) => m !== module) : [...current, module];
    setModulesBusy(module);
    await commitModules(next);
    setModulesBusy(null);
  };

  /**
   * Master Category checkbox for a suite (WhatsApp / Social). The
   * master's own checked/indeterminate state is DERIVED in SuiteSection
   * below (checked = every child enabled, indeterminate = some-but-not-
   * all) rather than stored separately, so it can never drift out of
   * sync with the individual rows — per spec: "Unchecking any child
   * route sets the Master Header to unchecked/indeterminate state."
   * This handler only ever adds/removes the specific slugs in
   * `suiteModules`, exactly like toggleModule does for one slug — every
   * other slug (the other suite, Core Common, or an unmanaged slug like
   * 'notifications'/'developer_api') is left untouched.
   */
  const toggleSuite = async (suiteModules: AccountModule[], enableAll: boolean) => {
    const current = allowedModules ?? [...ACCOUNT_MODULES];
    const next = enableAll
      ? [...new Set([...current, ...suiteModules])]
      : current.filter((m) => !suiteModules.includes(m));
    setModulesBulkBusy(true);
    await commitModules(next);
    setModulesBulkBusy(false);
  };

  const MANAGED_CHECKLIST_MODULES: AccountModule[] = [...CORE_COMMON_MODULES, ...WHATSAPP_SUITE_MODULES, ...SOCIAL_SUITE_MODULES];

  /**
   * Quick Plan preset buttons (top of modal). Each preset is an
   * absolute assignment of the MANAGED slugs (Core Common + both
   * suites) — not an incremental toggle — so it always includes Core
   * Common (Core Common can never be fully cleared by a preset) and
   * explicitly clears whichever suite it doesn't name, rather than
   * leaving previously-checked rows in an inconsistent state. Any slug
   * this checklist doesn't manage ('notifications', 'developer_api') is
   * preserved exactly as-is, same as toggleModule/toggleSuite.
   */
  const applyPreset = async (modules: AccountModule[]) => {
    const current = allowedModules ?? [...ACCOUNT_MODULES];
    const unmanaged = current.filter((m) => !MANAGED_CHECKLIST_MODULES.includes(m));
    const next = [...new Set([...unmanaged, ...modules])];
    setModulesBulkBusy(true);
    await commitModules(next);
    setModulesBulkBusy(false);
  };

  const applyWhatsappPlan = () => applyPreset([...CORE_COMMON_MODULES, ...WHATSAPP_SUITE_MODULES]);
  const applySocialPlan = () => applyPreset([...CORE_COMMON_MODULES, ...SOCIAL_SUITE_MODULES]);
  const applyFullEnterprisePlan = () => applyPreset(MANAGED_CHECKLIST_MODULES);

  /**
   * [Bug fix, disclosed]: the Quick Plan preset buttons' "selected" blue
   * highlight was previously a class hardcoded onto the Full Enterprise
   * Plan button in JSX, unconditionally, regardless of which preset (if
   * any) actually matched the current module selection — clicking
   * WhatsApp Plan or Social Media Plan correctly called applyPreset()
   * and correctly updated allowedModules/the checkboxes below (verified
   * by reading commitModules()/isModuleEnabled()), but the highlight
   * itself was never wired to any state, so it visually never moved off
   * Full Enterprise Plan. isPresetActive() below does an EXACT match — a
   * preset's own managed modules must all be enabled AND every other
   * managed module (i.e. the suite it doesn't include) must be disabled
   * — so a custom/mixed selection that happens to be a superset of one
   * preset is correctly shown as unhighlighted (none of the three) here,
   * not misattributed to that preset.
   */
  const isPresetActive = (presetModules: AccountModule[]) =>
    MANAGED_CHECKLIST_MODULES.every((module) =>
      presetModules.includes(module) ? isModuleEnabled(module) : !isModuleEnabled(module),
    );

  const isWhatsappPlanActive = isPresetActive([...CORE_COMMON_MODULES, ...WHATSAPP_SUITE_MODULES]);
  const isSocialPlanActive = isPresetActive([...CORE_COMMON_MODULES, ...SOCIAL_SUITE_MODULES]);
  const isFullEnterprisePlanActive = isPresetActive(MANAGED_CHECKLIST_MODULES);

  const PRESET_BUTTON_ACTIVE_CLASS =
    'rounded-lg border border-indigo-300 bg-indigo-50 px-3 py-1.5 text-xs font-semibold text-indigo-700 transition hover:bg-indigo-100 disabled:opacity-60';
  const PRESET_BUTTON_INACTIVE_CLASS =
    'rounded-lg border border-slate-300 px-3 py-1.5 text-xs font-semibold text-slate-700 transition hover:bg-slate-50 disabled:opacity-60';

  return (
    <div className="fixed inset-0 z-50 flex items-center justify-center bg-slate-900/50 p-4">
      <div className="max-h-[90vh] w-full max-w-2xl overflow-y-auto rounded-xl bg-white shadow-xl">
        <div className="flex items-center justify-between border-b border-slate-200 px-6 py-4">
          <h2 className="text-lg font-semibold text-slate-900">
            {isEditMode ? `Edit ${account?.company_name}` : 'Create New Client & Admin'}
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

          {/* Section 1 — Client Organization Details (Account model; no password field lives here — see AccountController::store()'s docblock and this refactor's audit report). */}
          <section>
            <div className="flex items-center gap-2 text-sm font-semibold text-slate-900">
              <Building2 className="h-4 w-4 text-indigo-600" />
              Client Organization Details
            </div>
            <div className="mt-4 grid grid-cols-1 gap-4 sm:grid-cols-2">
              <Field label="Organization Name" required>
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
              <Field label="Module Assignment" required hint="Which widget set this client's Dashboard shows.">
                <select
                  value={moduleAssignment}
                  onChange={(e) => setModuleAssignment(e.target.value as ModuleAssignment)}
                  className={inputClass}
                >
                  {MODULE_ASSIGNMENTS.map((m) => (
                    <option key={m} value={m}>
                      {MODULE_ASSIGNMENT_LABELS[m]}
                    </option>
                  ))}
                </select>
              </Field>
              <Field label="Max Users Limit" hint="Total users allowed on this account, owner included. Leave blank for unlimited.">
                <input
                  type="number"
                  min="1"
                  value={maxUsersLimit}
                  onChange={(e) => setMaxUsersLimit(e.target.value)}
                  className={inputClass}
                  placeholder="Unlimited"
                />
              </Field>
            </div>
          </section>

          {/* Section 2 — Primary Client Admin Credentials (User model; create mode only — see the !isEditMode note below). */}
          {!isEditMode ? (
            <section>
              <div className="flex items-center gap-2 text-sm font-semibold text-slate-900">
                <UserPlus className="h-4 w-4 text-indigo-600" />
                Primary Client Admin Credentials
              </div>
              <div className="mt-4 grid grid-cols-1 gap-4 sm:grid-cols-2">
                <Field label="Full Name" required>
                  <input
                    value={adminName}
                    onChange={(e) => setAdminName(e.target.value)}
                    className={inputClass}
                    placeholder="Jane Doe"
                  />
                </Field>
                <Field label="Official Email" required>
                  <input
                    type="email"
                    value={adminEmail}
                    onChange={(e) => setAdminEmail(e.target.value)}
                    className={inputClass}
                    placeholder="jane@acme.com"
                  />
                </Field>
                <Field label="Phone Number">
                  <input
                    value={adminPhone}
                    onChange={(e) => setAdminPhone(e.target.value)}
                    className={inputClass}
                    placeholder="+91 98765 43210"
                  />
                </Field>
                <Field label="Password" required hint="At least 8 characters">
                  <div className="mt-1.5 flex gap-2">
                    <div className="relative flex-1">
                      <input
                        type={showAdminPassword ? 'text' : 'password'}
                        value={adminPassword}
                        onChange={(e) => setAdminPassword(e.target.value)}
                        className={`${inputClass} mt-0 pr-9`}
                        placeholder="••••••••"
                      />
                      <button
                        type="button"
                        onClick={() => setShowAdminPassword((v) => !v)}
                        className="absolute inset-y-0 right-0 flex items-center px-2.5 text-slate-400 hover:text-slate-600"
                        aria-label={showAdminPassword ? 'Hide password' : 'Show password'}
                      >
                        {showAdminPassword ? <EyeOff className="h-4 w-4" /> : <Eye className="h-4 w-4" />}
                      </button>
                    </div>
                    <button
                      type="button"
                      onClick={() => {
                        setAdminPassword(generatePassword());
                        setShowAdminPassword(true);
                      }}
                      className="flex items-center gap-1.5 whitespace-nowrap rounded-lg border border-slate-300 px-3 py-2 text-xs font-semibold text-slate-700 hover:bg-slate-50"
                    >
                      <RefreshCw className="h-3.5 w-3.5" />
                      Auto-Generate
                    </button>
                  </div>
                </Field>
              </div>
            </section>
          ) : (
            <p className="flex items-center gap-1.5 text-xs text-slate-400">
              <UserPlus className="h-3.5 w-3.5" />
              Admin user details are set at creation and aren't edited here.
            </p>
          )}

          {/* Section 2.5 — White-Label Branding (Final Phase; edit mode only — SocialReportController attaches these to the PDF report). */}
          {isEditMode && account && (
            <section>
              <div className="flex items-center gap-2 text-sm font-semibold text-slate-900">
                <ImageIcon className="h-4 w-4 text-indigo-600" />
                White-Label Branding
              </div>
              <p className="mt-1 text-xs text-slate-500">
                Applied to this client's monthly Social Report PDF (Company Name is already used from above).
              </p>
              <div className="mt-4 grid grid-cols-1 gap-4 sm:grid-cols-2">
                <Field label="Logo URL" hint="Publicly reachable image URL — shown as a text line in the PDF for now.">
                  <input
                    value={logoUrl}
                    onChange={(e) => setLogoUrl(e.target.value)}
                    className={inputClass}
                    placeholder="https://cdn.example.com/logo.png"
                  />
                </Field>
                <Field label="Brand Accent Color" hint="6-digit hex, no # (e.g. 4F46E5). Defaults to the platform indigo if left blank.">
                  <input
                    value={brandAccentColor}
                    onChange={(e) => setBrandAccentColor(e.target.value.replace(/^#/, ''))}
                    className={inputClass}
                    placeholder="4F46E5"
                    maxLength={6}
                  />
                </Field>
              </div>
            </section>
          )}

          {/* Section 3 — Subscription & Engine Config */}
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

          {/* Section 4 — "Module & Feature Access": Core Common baseline +
              WhatsApp Suite + Social Suite, each with a Master Category
              checkbox, plus Quick Plan presets. Renders in BOTH modes now
              (previously edit-mode only) — see commitModules' docblock
              above for how the two modes differ. */}
          <section>
            <div className="flex items-center gap-2 text-sm font-semibold text-slate-900">
              <ShieldCheck className="h-4 w-4 text-indigo-600" />
              Module &amp; Feature Access
            </div>
            <p className="mt-1 text-xs text-slate-500">
              {isEditMode
                ? 'Start or stop what this client can use. Changes apply immediately.'
                : 'Core features are included for every client. Pick a Quick Plan preset or build a custom mix below.'}
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

            {/* Quick Plan Preset Buttons */}
            <div className="mt-3 flex flex-wrap items-center gap-2">
              <button
                type="button"
                onClick={() => void applyWhatsappPlan()}
                disabled={modulesBulkBusy}
                className={isWhatsappPlanActive ? PRESET_BUTTON_ACTIVE_CLASS : PRESET_BUTTON_INACTIVE_CLASS}
              >
                WhatsApp Plan
              </button>
              <button
                type="button"
                onClick={() => void applySocialPlan()}
                disabled={modulesBulkBusy}
                className={isSocialPlanActive ? PRESET_BUTTON_ACTIVE_CLASS : PRESET_BUTTON_INACTIVE_CLASS}
              >
                Social Media Plan
              </button>
              <button
                type="button"
                onClick={() => void applyFullEnterprisePlan()}
                disabled={modulesBulkBusy}
                className={isFullEnterprisePlanActive ? PRESET_BUTTON_ACTIVE_CLASS : PRESET_BUTTON_INACTIVE_CLASS}
              >
                Full Enterprise Plan
              </button>
              {modulesBulkBusy && <Loader2 className="h-4 w-4 animate-spin text-slate-400" />}
            </div>

            {/* Core Common Features (Shared) — pre-checked by default in
                create mode; no Master checkbox (it's the shared baseline
                every preset includes, not a suite to enable/disable). */}
            <div className="mt-4">
              <div className="text-xs font-semibold uppercase tracking-wide text-slate-500">
                Core Common Features (Shared)
              </div>
              <div className="mt-2 grid grid-cols-1 gap-2 sm:grid-cols-2">
                {CORE_COMMON_MODULES.map((module) => {
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
            </div>

            <SuiteSection
              title="WhatsApp Messaging Suite"
              icon={MessageSquare}
              modules={WHATSAPP_SUITE_MODULES}
              isModuleEnabled={isModuleEnabled}
              modulesBusy={modulesBusy}
              modulesBulkBusy={modulesBulkBusy}
              onToggleModule={(module) => void toggleModule(module)}
              onToggleSuite={(enableAll) => void toggleSuite(WHATSAPP_SUITE_MODULES, enableAll)}
            />

            <SuiteSection
              title="Social Media Suite"
              icon={Share2}
              modules={SOCIAL_SUITE_MODULES}
              isModuleEnabled={isModuleEnabled}
              modulesBusy={modulesBusy}
              modulesBulkBusy={modulesBulkBusy}
              onToggleModule={(module) => void toggleModule(module)}
              onToggleSuite={(enableAll) => void toggleSuite(SOCIAL_SUITE_MODULES, enableAll)}
            />

            {modulesSaved && !modulesBusy && !modulesBulkBusy && !modulesError && (
              <p className="mt-2 flex items-center gap-1.5 text-xs text-emerald-600">
                <CheckCircle2 className="h-3.5 w-3.5" />
                Saved.
              </p>
            )}
          </section>

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
              {isEditMode ? 'Save Changes' : 'Create Client'}
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

/**
 * A checkbox that can render a true tri-state "indeterminate" visual.
 * React has no `indeterminate` prop on <input type="checkbox">, so it's
 * set imperatively via a ref (the only way the DOM exposes it). Used by
 * SuiteSection for the two Master Category checkboxes.
 */
function IndeterminateCheckbox({
  checked,
  indeterminate,
  disabled,
  onChange,
  className,
}: {
  checked: boolean;
  indeterminate: boolean;
  disabled?: boolean;
  onChange: () => void;
  className?: string;
}) {
  const ref = useRef<HTMLInputElement>(null);
  useEffect(() => {
    if (ref.current) ref.current.indeterminate = indeterminate;
  }, [indeterminate]);
  return (
    <input ref={ref} type="checkbox" checked={checked} disabled={disabled} onChange={onChange} className={className} />
  );
}

/**
 * One suite block (WhatsApp Messaging / Social Media) inside "Module &
 * Feature Access": a Master Category checkbox in the header plus every
 * child route as its own row. The master's checked/indeterminate state
 * is DERIVED from the children on every render (never stored on its
 * own), so it can never drift out of sync with them — per spec:
 * "Unchecking any child route sets the Master Header to
 * unchecked/indeterminate state."
 */
function SuiteSection({
  title,
  icon: Icon,
  modules,
  isModuleEnabled,
  modulesBusy,
  modulesBulkBusy,
  onToggleModule,
  onToggleSuite,
}: {
  title: string;
  icon: typeof ShieldCheck;
  modules: AccountModule[];
  isModuleEnabled: (module: AccountModule) => boolean;
  modulesBusy: AccountModule | null;
  modulesBulkBusy: boolean;
  onToggleModule: (module: AccountModule) => void;
  onToggleSuite: (enableAll: boolean) => void;
}) {
  const enabledCount = modules.filter((module) => isModuleEnabled(module)).length;
  const allEnabled = enabledCount === modules.length;
  const someEnabled = enabledCount > 0 && !allEnabled;

  return (
    <div className="mt-4 rounded-lg border border-slate-200 p-3">
      <div className="flex items-center justify-between gap-3">
        <span className="flex items-center gap-1.5 text-xs font-semibold uppercase tracking-wide text-slate-700">
          <Icon className="h-3.5 w-3.5 text-indigo-600" />
          {title}
        </span>
        <label className="flex items-center gap-1.5 text-[11px] font-medium normal-case tracking-normal text-slate-500">
          Select all
          <IndeterminateCheckbox
            checked={allEnabled}
            indeterminate={someEnabled}
            disabled={modulesBulkBusy}
            onChange={() => onToggleSuite(!allEnabled)}
            className="h-4 w-4 rounded border-slate-300 text-indigo-600 focus:ring-indigo-500 disabled:opacity-60"
          />
        </label>
      </div>
      <div className="mt-2 grid grid-cols-1 gap-2 sm:grid-cols-2">
        {modules.map((module) => {
          const enabled = isModuleEnabled(module);
          const busy = modulesBusy === module;
          return (
            <label
              key={module}
              className={`flex items-center justify-between gap-3 rounded-lg border px-3 py-2 text-sm transition ${
                enabled ? 'border-indigo-200 bg-indigo-50/50' : 'border-slate-200'
              }`}
            >
              <span className={enabled ? 'text-slate-900' : 'text-slate-500'}>{ACCOUNT_MODULE_LABELS[module]}</span>
              <span className="flex items-center gap-2">
                {busy && <Loader2 className="h-3.5 w-3.5 animate-spin text-slate-400" />}
                <input
                  type="checkbox"
                  checked={enabled}
                  disabled={busy}
                  onChange={() => onToggleModule(module)}
                  className="h-4 w-4 rounded border-slate-300 text-indigo-600 focus:ring-indigo-500 disabled:opacity-60"
                />
              </span>
            </label>
          );
        })}
      </div>
    </div>
  );
}
