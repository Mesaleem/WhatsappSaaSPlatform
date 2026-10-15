import { useCallback, useEffect, useMemo, useState } from 'react';
import { AlertTriangle, Check, Loader2, Pencil, Plus, Power, PowerOff, RefreshCw, Send, X } from 'lucide-react';
import { useSearchParams } from 'react-router-dom';

import planService from '../../services/planService';
import accountService from '../../services/accountService';
import axiosInstance from '../../core/api/axiosInstance';
import { PLAN_LIMIT_LABELS, describePlanLimit, type PlanLimits } from '../../types/account';
import PlanPriceCalculator from './PlanPriceCalculator';
import PriceListModal from './PriceListModal';
import { TableCard, inputClass } from '../../components/common/Card';
import ConfirmModal from '../../components/common/ConfirmModal';
import ModuleOfferAdminCard from '../../components/billing/ModuleOfferAdminCard';
import WhatsAppNumberPriceCard from '../../components/billing/WhatsAppNumberPriceCard';
import { extractErrorMessage } from '../../utils/apiError';
import { indigo, activeGradient } from '../../theme/signalIndigo';
import type {
  CreatePlanPayload,
  CustomPlanPriceList,
  ManagedPlan,
  PlanBillingModel,
  PlanCapabilityOption,
  PlanEngineType,
  UpdatePlanPayload,
} from '../../types/plan';

/**
 * Phase 5 Task 12 — Super Admin Plan Management.
 *
 * Tasks 10 and 11 built a complete, tested plan lifecycle that only a
 * `curl` could reach. This is the screen for it, and nothing more: every
 * plan, price, quota, engine and capability shown here comes from the
 * API response, and every write goes back through the same endpoints.
 *
 * WHAT THIS PAGE DOES NOT DO, deliberately:
 *   - it does not validate business rules as authorization. The backend
 *     refuses a duplicate slug, an unknown capability, a bad engine and
 *     an unauthorized caller on its own; this UI just renders what it
 *     says. Client-side checks here exist to save a round trip, never
 *     to decide anything.
 *   - it does not encode provider compatibility. The warning below is
 *     rendered from `unsupported_providers`, which the server computes
 *     from provider_capabilities — React never holds the matrix.
 *   - it does not reconcile entitlements. Changing a bundle dispatches
 *     the server's own reconciliation job; this page only reports what
 *     the server said changed.
 *
 * THE ONE SEMANTIC THAT MUST NOT BE BROKEN: on update, an ABSENT key is
 * left unchanged by the server, and `capabilities: []` clears the bundle
 * while omitting `capabilities` leaves it alone. buildPatch() below is
 * where that is honoured — read its comment before changing it.
 */

const ENGINE_OPTIONS: { value: PlanEngineType; label: string }[] = [
  { value: 'qr', label: 'QR (Baileys)' },
  { value: 'meta', label: 'Meta Cloud API' },
  { value: 'none', label: 'No WhatsApp (e.g. CRM only)' },
];

/** Plan restructure (2026-10-15) — the per-plan limits, in form order. */
const LIMIT_KEYS: (keyof PlanLimits)[] = ['max_users', 'max_meta_templates', 'max_flows', 'max_broadcasts_per_month', 'marketing_message_limit'];

const BILLING_OPTIONS: { value: PlanBillingModel; label: string }[] = [
  { value: 'flat_quota', label: 'Flat quota' },
  { value: 'per_message', label: 'Per message' },
  { value: 'unlimited', label: 'Unlimited' },
];

/**
 * Phase 4 Task 2 / Task 10 FIX — mirrors
 * InstallationAllowanceResolver::CAPABILITY server-side. The one
 * capability this screen gives a dedicated numeric input for, because
 * the backend requires a concrete limit for it whenever `external_api`
 * is also bundled (PlanManagementController::
 * assertApiInstallationsConcreteWhenExternalApi()).
 */
const API_INSTALLATIONS_CAPABILITY = 'api_installations';

interface PlanFormState {
  slug: string;
  label: string;
  description: string;
  price: string;
  duration_days: string;
  engine_type: PlanEngineType;
  billing_model: PlanBillingModel;
  rate_per_message: string;
  total_allocated_messages: string;
  /** Phase 8 Task 2 — AI credits per purchased period. */
  included_credits: string;
  is_active: boolean;
  capabilities: string[];
  /**
   * Phase 4 Task 2 / Task 10 FIX — the backend has required a concrete,
   * non-negative api_installations usage_limit on any plan that also
   * includes external_api since Task 2, but this screen never exposed
   * a field for it until now: a Super Admin trying to set this up
   * through the UI alone had no way to satisfy that requirement and no
   * visible reason why the save was refused. Raw string input, same
   * convention as rate_per_message/total_allocated_messages — empty
   * string means "no limit" (null), not zero.
   */
  api_installations_limit: string;
  /** Plan restructure (2026-10-15): '' = unlimited, '0' = not included. */
  limits: Record<keyof PlanLimits, string>;
  /** Listed on the website and every client's Billing page. */
  is_public: boolean;
  /** Create only: '' = a normal plan, else the client this custom plan is for. */
  account_id: string;
}

const limitStrings = (limits?: PlanLimits): Record<keyof PlanLimits, string> =>
  Object.fromEntries(LIMIT_KEYS.map((k) => [k, limits?.[k] == null ? '' : String(limits[k])])) as Record<keyof PlanLimits, string>;

function emptyForm(): PlanFormState {
  return {
    slug: '',
    label: '',
    description: '',
    price: '',
    duration_days: '30',
    engine_type: 'qr',
    billing_model: 'flat_quota',
    rate_per_message: '',
    total_allocated_messages: '',
    included_credits: '0',
    is_active: true,
    capabilities: [],
    api_installations_limit: '',
    limits: limitStrings(),
    is_public: true,
    account_id: '',
  };
}

function formFromPlan(plan: ManagedPlan): PlanFormState {
  return {
    slug: plan.slug,
    label: plan.label,
    description: plan.description ?? '',
    price: String(plan.price),
    duration_days: String(plan.duration_days),
    engine_type: plan.engine_type,
    billing_model: plan.billing_model,
    rate_per_message: plan.rate_per_message == null ? '' : String(plan.rate_per_message),
    total_allocated_messages: plan.total_allocated_messages == null ? '' : String(plan.total_allocated_messages),
    included_credits: String(plan.included_credits ?? 0),
    is_active: plan.is_active,
    capabilities: [...plan.capabilities],
    api_installations_limit:
      plan.capability_limits?.api_installations == null ? '' : String(plan.capability_limits.api_installations),
    limits: limitStrings(plan.limits),
    is_public: plan.is_public ?? true,
    account_id: plan.account ? String(plan.account.id) : '',
  };
}

const numberOrNull = (value: string): number | null => (value.trim() === '' ? null : Number(value));

/** Field-level errors, keyed exactly as the backend's 422 `errors` object is. */
type FieldErrors = Record<string, string>;

/** Laravel's 422 body -> { field: firstMessage }. */
function fieldErrorsFrom(err: unknown): FieldErrors {
  const response = (err as { response?: { status?: number; data?: { errors?: Record<string, string[]> } } })?.response;

  if (response?.status !== 422 || !response.data?.errors) return {};

  return Object.fromEntries(
    Object.entries(response.data.errors).map(([field, messages]) => [field, messages[0] ?? 'Invalid value.']),
  );
}

function FieldError({ errors, name }: { errors: FieldErrors; name: string }) {
  if (!errors[name]) return null;

  return (
    <p data-testid={`field-error-${name}`} className="mt-1 text-[11px] font-medium text-red-600">
      {errors[name]}
    </p>
  );
}

// ====================================================================
// Capability bundle editor
// ====================================================================

function CapabilityPicker({
  options,
  selected,
  engineType,
  onToggle,
}: {
  options: PlanCapabilityOption[];
  selected: string[];
  engineType: PlanEngineType;
  onToggle: (slug: string) => void;
}) {
  return (
    <div className="space-y-1.5" data-testid="capability-picker">
      {options.map((option) => {
        const checked = selected.includes(option.slug);
        /*
          Informational only. The server computed this list from
          provider_capabilities and refuses the pairing itself at grant
          time — a plan may still legitimately bundle a capability its
          engine cannot run (the confirmed matrix does exactly that for
          growth + journey_automation), so this warns rather than blocks.
        */
        const incompatible = option.unsupported_providers.includes(engineType);

        return (
          <label
            key={option.slug}
            data-testid={`capability-option-${option.slug}`}
            className="flex items-start gap-2 rounded-lg px-2 py-1.5 hover:bg-slate-50"
          >
            <input
              type="checkbox"
              checked={checked}
              onChange={() => onToggle(option.slug)}
              data-testid={`capability-checkbox-${option.slug}`}
              className="mt-0.5 h-4 w-4 rounded border-slate-300 text-emerald-700"
            />
            <span className="min-w-0 flex-1">
              <span className="text-xs font-medium text-slate-800">{option.label}</span>
              <span className="ml-1.5 text-[11px] text-slate-400">{option.slug}</span>
              {checked && incompatible && (
                <span
                  data-testid={`capability-warning-${option.slug}`}
                  className="mt-0.5 flex items-center gap-1 text-[11px] font-medium text-amber-700"
                >
                  <AlertTriangle className="h-3 w-3" />
                  Not supported on {engineType} — accounts on this plan will not receive it.
                </span>
              )}
            </span>
          </label>
        );
      })}
    </div>
  );
}

// ====================================================================
// Create / edit modal
// ====================================================================

function PlanFormModal({
  plan,
  capabilityOptions,
  onClose,
  onSaved,
  customForAccountId = null,
  prices = null,
  onEditRates = () => undefined,
}: {
  /** null = create. */
  plan: ManagedPlan | null;
  capabilityOptions: PlanCapabilityOption[];
  onClose: () => void;
  onSaved: (message: string) => void;
  /** Create a custom plan for this client (e.g. from a Contact sales request). */
  customForAccountId?: number | null;
  /** The custom-plan price list for the calculator (null while loading). */
  prices?: CustomPlanPriceList | null;
  onEditRates?: () => void;
}) {
  const isEdit = plan !== null;
  const [form, setForm] = useState<PlanFormState>(() => {
    if (plan) return formFromPlan(plan);
    const blank = emptyForm();
    return customForAccountId
      ? { ...blank, account_id: String(customForAccountId), is_public: false, slug: 'custom-' + customForAccountId + '-' + Date.now().toString(36) }
      : blank;
  });
  const [clients, setClients] = useState<{ id: number; company_name: string }[]>([]);

  // Create only: the clients a custom plan can be built for.
  useEffect(() => {
    if (isEdit) return;
    void accountService
      .list({ per_page: 100, account_type: 'client' })
      .then((res) => setClients(res.data.map((a) => ({ id: a.id, company_name: a.company_name }))))
      .catch(() => undefined);
  }, [isEdit]);
  const [errors, setErrors] = useState<FieldErrors>({});
  const [formError, setFormError] = useState<string | null>(null);
  const [isSaving, setIsSaving] = useState(false);
  const [pendingConfirm, setPendingConfirm] = useState<string | null>(null);

  const set = <K extends keyof PlanFormState>(key: K, value: PlanFormState[K]) =>
    setForm((f) => ({ ...f, [key]: value }));

  const toggleCapability = (slug: string) =>
    setForm((f) => ({
      ...f,
      capabilities: f.capabilities.includes(slug)
        ? f.capabilities.filter((s) => s !== slug)
        : [...f.capabilities, slug],
    }));

  const bundleChanged = useMemo(
    () =>
      !plan ||
      [...form.capabilities].sort().join(',') !== [...plan.capabilities].sort().join(','),
    [form.capabilities, plan],
  );

  const priceChanged = plan ? Number(form.price) !== plan.price : false;
  const bigPriceChange =
    plan && priceChanged && plan.price > 0 && Math.abs(Number(form.price) - plan.price) / plan.price >= 0.25;

  /**
   * ONLY the dimensions that actually changed.
   *
   * This is the load-bearing part of the edit flow. The backend leaves
   * an omitted key untouched, so sending the whole form back would
   * re-assert values the administrator never looked at — and, worse,
   * would send `capabilities` on every save, which is a bundle
   * REPLACEMENT that triggers fleet reconciliation. An unchanged bundle
   * must not be sent at all; a deliberately emptied one must be sent as
   * [].
   */
  const buildPatch = (): UpdatePlanPayload => {
    if (!plan) return {};

    const patch: UpdatePlanPayload = {};

    if (form.label !== plan.label) patch.label = form.label;
    if ((form.description || null) !== plan.description) patch.description = form.description || null;
    if (Number(form.price) !== plan.price) patch.price = Number(form.price);
    if (Number(form.duration_days) !== plan.duration_days) patch.duration_days = Number(form.duration_days);
    if (form.engine_type !== plan.engine_type) patch.engine_type = form.engine_type;
    if (form.billing_model !== plan.billing_model) patch.billing_model = form.billing_model;

    const rate = numberOrNull(form.rate_per_message);
    if (rate !== (plan.rate_per_message == null ? null : Number(plan.rate_per_message))) {
      patch.rate_per_message = rate;
    }

    const quota = numberOrNull(form.total_allocated_messages);
    if (quota !== plan.total_allocated_messages) patch.total_allocated_messages = quota;

    const credits = Number(form.included_credits || 0);
    if (credits !== (plan.included_credits ?? 0)) patch.included_credits = credits;

    if (form.is_active !== plan.is_active) patch.is_active = form.is_active;
    if (form.is_public !== (plan.is_public ?? true)) patch.is_public = form.is_public;
    for (const key of LIMIT_KEYS) {
      const value = numberOrNull(form.limits[key]);
      if (value !== (plan.limits?.[key] ?? null)) patch[key] = value;
    }

    // Absent unless genuinely changed — see this function's docblock.
    if (bundleChanged) patch.capabilities = form.capabilities;

    // Phase 4 Task 2 / Task 10 FIX — same "absent unless changed"
    // discipline as every other field above, but for the
    // api_installations usage_limit pivot specifically. Only sent when
    // the capability is actually part of the RESULTING bundle (sending
    // it otherwise is refused by assertCapabilityLimitKeysAreBundled())
    // and only when its value actually changed, so an untouched limit
    // on an untouched bundle never causes a needless pivot write.
    const resultingCapabilities = bundleChanged ? form.capabilities : plan.capabilities;
    if (resultingCapabilities.includes(API_INSTALLATIONS_CAPABILITY)) {
      const newLimit = numberOrNull(form.api_installations_limit);
      const previousLimit = plan.capability_limits?.[API_INSTALLATIONS_CAPABILITY] ?? null;
      if (bundleChanged || newLimit !== previousLimit) {
        patch.capability_limits = { [API_INSTALLATIONS_CAPABILITY]: newLimit };
      }
    }

    return patch;
  };

  const submit = async () => {
    setIsSaving(true);
    setErrors({});
    setFormError(null);

    try {
      if (isEdit) {
        const result = await planService.update(plan.slug, buildPatch());
        onSaved(result.message);
      } else {
        const payload: CreatePlanPayload = {
          slug: form.slug.trim(),
          label: form.label.trim(),
          price: Number(form.price),
          duration_days: Number(form.duration_days),
          engine_type: form.engine_type,
          billing_model: form.billing_model,
          description: form.description || null,
          rate_per_message: numberOrNull(form.rate_per_message),
          total_allocated_messages: numberOrNull(form.total_allocated_messages),
          included_credits: Number(form.included_credits || 0),
          is_active: form.is_active,
          is_public: form.account_id ? false : form.is_public,
          account_id: form.account_id ? Number(form.account_id) : null,
          ...Object.fromEntries(LIMIT_KEYS.map((k) => [k, numberOrNull(form.limits[k])])),
          capabilities: form.capabilities,
          // Phase 4 Task 2 / Task 10 FIX — only meaningful (and only
          // accepted by the backend) when the capability is part of
          // this create's own bundle.
          ...(form.capabilities.includes(API_INSTALLATIONS_CAPABILITY)
            ? { capability_limits: { [API_INSTALLATIONS_CAPABILITY]: numberOrNull(form.api_installations_limit) } }
            : {}),
        };
        const result = await planService.create(payload);
        onSaved(result.message);
      }
    } catch (err) {
      // Field-level where the backend gave it (duplicate slug, unknown
      // capability, bad engine); a single message otherwise.
      setErrors(fieldErrorsFrom(err));
      setFormError(extractErrorMessage(err, 'That change was refused.'));
    } finally {
      setIsSaving(false);
      setPendingConfirm(null);
    }
  };

  /** High-impact edits get a confirmation naming the plan and its reach. */
  const handleSave = () => {
    if (!plan) {
      void submit();

      return;
    }

    const reach = `${plan.accounts} account${plan.accounts === 1 ? '' : 's'} have purchased “${plan.label}”.`;

    if (bundleChanged) {
      setPendingConfirm(
        `Replacing the capability bundle on “${plan.label}” reconciles every account on it. ${reach} Capabilities the plan no longer includes are revoked; manual and agent-delegated grants are left alone.`,
      );

      return;
    }

    if (bigPriceChange) {
      setPendingConfirm(
        `Changing the price of “${plan.label}” from ${plan.price} to ${form.price} affects new purchases only — existing invoices and subscriptions are untouched. ${reach}`,
      );

      return;
    }

    void submit();
  };

  return (
    <div className="fixed inset-0 z-50 flex items-center justify-center bg-slate-900/40 p-4">
      <div className="flex max-h-[88vh] w-full max-w-3xl flex-col rounded-2xl bg-white shadow-xl" data-testid="plan-form-modal">
        <div className="flex items-start justify-between border-b border-slate-200 px-5 py-4">
          <h2 className="font-display text-base font-bold text-slate-900">
            {isEdit ? `Edit plan — ${plan.label}` : 'Create plan'}
          </h2>
          <button onClick={onClose} className="rounded-lg p-1.5 text-slate-400 hover:bg-slate-100" aria-label="Close">
            <X className="h-4 w-4" />
          </button>
        </div>

        <div className="min-h-0 flex-1 overflow-y-auto px-5 py-4">
          {formError && (
            <p data-testid="plan-form-error" className="mb-3 rounded-lg bg-red-50 px-3 py-2 text-sm text-red-700">
              {formError}
            </p>
          )}

          <div className="grid gap-4 sm:grid-cols-2">
            {/* ---- Billing dimensions ---- */}
            <div className="sm:col-span-2">
              <h3 className="text-xs font-bold uppercase tracking-wide" style={{ color: indigo.muted }}>
                Billing
              </h3>
              <p className="text-[11px]" style={{ color: indigo.muted }}>
                Applies to future purchases only. Existing invoices and subscriptions are never rewritten.
              </p>
            </div>

            <label className="block text-xs font-medium text-slate-700">
              Name
              <span className="text-red-500" aria-hidden="true"> *</span><input
                type="text"
                data-testid="plan-label"
                className={inputClass}
                value={form.label}
                onChange={(e) => set('label', e.target.value)}
              />
              <FieldError errors={errors} name="label" />
            </label>

            <label className="block text-xs font-medium text-slate-700">
              Slug
              <span className="text-red-500" aria-hidden="true"> *</span><input
                type="text"
                data-testid="plan-slug"
                className={inputClass}
                value={form.slug}
                disabled={isEdit}
                onChange={(e) => set('slug', e.target.value)}
                placeholder="e.g. scale"
              />
              <FieldError errors={errors} name="slug" />
            </label>

            <label className="block text-xs font-medium text-slate-700">
              Engine
              <span className="text-red-500" aria-hidden="true"> *</span><select
                data-testid="plan-engine"
                className={inputClass}
                value={form.engine_type}
                onChange={(e) => set('engine_type', e.target.value as PlanEngineType)}
              >
                {ENGINE_OPTIONS.map((o) => (
                  <option key={o.value} value={o.value}>
                    {o.label}
                  </option>
                ))}
              </select>
              <FieldError errors={errors} name="engine_type" />
            </label>

            <label className="block text-xs font-medium text-slate-700">
              Billing model
              <span className="text-red-500" aria-hidden="true"> *</span><select
                data-testid="plan-billing-model"
                className={inputClass}
                value={form.billing_model}
                onChange={(e) => set('billing_model', e.target.value as PlanBillingModel)}
              >
                {BILLING_OPTIONS.map((o) => (
                  <option key={o.value} value={o.value}>
                    {o.label}
                  </option>
                ))}
              </select>
              <FieldError errors={errors} name="billing_model" />
            </label>

            <label className="block text-xs font-medium text-slate-700">
              Price
              <span className="text-red-500" aria-hidden="true"> *</span><input
                type="number"
                min={0}
                step="0.01"
                data-testid="plan-price"
                className={inputClass}
                value={form.price}
                onChange={(e) => set('price', e.target.value)}
              />
              <FieldError errors={errors} name="price" />
            </label>

            <label className="block text-xs font-medium text-slate-700">
              Duration (days)
              <span className="text-red-500" aria-hidden="true"> *</span><input
                type="number"
                min={1}
                data-testid="plan-duration"
                className={inputClass}
                value={form.duration_days}
                onChange={(e) => set('duration_days', e.target.value)}
              />
              <FieldError errors={errors} name="duration_days" />
            </label>

            <label className="block text-xs font-medium text-slate-700">
              Allocated messages
              <input
                type="number"
                min={1}
                data-testid="plan-quota"
                className={inputClass}
                value={form.total_allocated_messages}
                onChange={(e) => set('total_allocated_messages', e.target.value)}
                placeholder="Leave empty for unlimited"
              />
              <FieldError errors={errors} name="total_allocated_messages" />
            </label>

            {/* Phase 8 Task 2 — per purchased period; the backend refuses credits on a plan without `ai`. */}
            <label className="block text-xs font-medium text-slate-700">
              Included AI credits / period
              <input
                type="number"
                min={0}
                step={1}
                data-testid="plan-included-credits"
                className={inputClass}
                value={form.included_credits}
                onChange={(e) => set('included_credits', e.target.value)}
              />
              <span className="mt-1 block text-[11px] font-normal text-slate-400">0 = none. Requires the “ai” capability; applies to new orders.</span>
              <FieldError errors={errors} name="included_credits" />
            </label>

            <label className="block text-xs font-medium text-slate-700">
              Rate per message
              <input
                type="number"
                min={0}
                step="0.0001"
                data-testid="plan-rate"
                className={inputClass}
                value={form.rate_per_message}
                onChange={(e) => set('rate_per_message', e.target.value)}
                placeholder="Only for per-message billing"
              />
              <FieldError errors={errors} name="rate_per_message" />
            </label>

            <label className="block text-xs font-medium text-slate-700 sm:col-span-2">
              Description
              <textarea
                rows={2}
                data-testid="plan-description"
                className={inputClass}
                value={form.description}
                onChange={(e) => set('description', e.target.value)}
              />
              <FieldError errors={errors} name="description" />
            </label>

            <label className="flex items-center gap-2 text-xs font-medium text-slate-700 sm:col-span-2">
              <input
                type="checkbox"
                data-testid="plan-active"
                checked={form.is_active}
                onChange={(e) => set('is_active', e.target.checked)}
                className="h-4 w-4 rounded border-slate-300 text-emerald-700"
              />
              Available for purchase
            </label>

            {/* ---- Who sees it (plan restructure, 2026-10-15) ---- */}
            {!isEdit && (
              <label className="block text-xs font-medium text-slate-700 sm:col-span-2">
                Custom plan for a client
                <select
                  data-testid="plan-account"
                  className={inputClass}
                  value={form.account_id}
                  onChange={(e) => setForm((f) => ({ ...f, account_id: e.target.value, is_public: e.target.value ? false : f.is_public }))}
                >
                  <option value="">No — a plan for everyone</option>
                  {form.account_id && !clients.some((c) => String(c.id) === form.account_id) && (
                    <option value={form.account_id}>Client #{form.account_id}</option>
                  )}
                  {clients.map((c) => (
                    <option key={c.id} value={c.id}>
                      {c.company_name}
                    </option>
                  ))}
                </select>
                <span className="mt-1 block text-[11px] font-normal text-slate-400">
                  A custom plan is seen and bought only by that client (on their Billing page). Pick only the features they
                  need below — e.g. only CRM, only journeys, or CRM + ads — and set its own price and limits.
                </span>
                <FieldError errors={errors} name="account_id" />
              </label>
            )}
            {isEdit && plan.account && (
              <p className="text-xs text-slate-600 sm:col-span-2">
                Custom plan for <strong>{plan.account.company_name ?? 'client #' + plan.account.id}</strong> — only they see it.
              </p>
            )}
            <label className="flex items-center gap-2 text-xs font-medium text-slate-700 sm:col-span-2">
              <input
                type="checkbox"
                data-testid="plan-public"
                checked={form.is_public && !form.account_id}
                disabled={!!form.account_id || (isEdit && !!plan.account)}
                onChange={(e) => set('is_public', e.target.checked)}
                className="h-4 w-4 rounded border-slate-300 text-emerald-700"
              />
              Show on the website and the Billing page
            </label>

            {/* ---- Limits (plan restructure, 2026-10-15) ---- */}
            <div className="sm:col-span-2 border-t border-slate-200 pt-4">
              <h3 className="text-xs font-bold uppercase tracking-wide" style={{ color: indigo.muted }}>
                Limits
              </h3>
              <p className="text-[11px]" style={{ color: indigo.muted }}>
                Empty = unlimited, 0 = not included. Applies to purchases from now on.
              </p>
            </div>
            {LIMIT_KEYS.map((key) => (
              <label key={key} className="block text-xs font-medium text-slate-700">
                {PLAN_LIMIT_LABELS[key]}
                <input
                  type="number"
                  min={key === 'max_users' ? 1 : 0}
                  step={1}
                  data-testid={'plan-limit-' + key}
                  className={inputClass}
                  value={form.limits[key]}
                  onChange={(e) => setForm((f) => ({ ...f, limits: { ...f.limits, [key]: e.target.value } }))}
                  placeholder="Unlimited"
                />
                <FieldError errors={errors} name={key} />
              </label>
            ))}

            {/* ---- Price calculator (owner, 2026-10-15): what these features and limits add up to ---- */}
            <div className="sm:col-span-2">
              <PlanPriceCalculator
                prices={prices}
                currentPrice={form.price.trim() === '' ? null : Number(form.price)}
                onUsePrice={(price) => set('price', String(price))}
                onEditRates={onEditRates}
                input={{
                  engine_type: form.engine_type,
                  billing_model: form.billing_model,
                  total_allocated_messages: numberOrNull(form.total_allocated_messages),
                  duration_days: Number(form.duration_days) || 30,
                  capabilities: form.capabilities,
                  limits: Object.fromEntries(LIMIT_KEYS.map((k) => [k, numberOrNull(form.limits[k])])) as unknown as PlanLimits,
                  capabilityLabels: Object.fromEntries(capabilityOptions.map((c) => [c.slug, c.label])),
                }}
              />
            </div>

            {/* ---- Capability bundle ---- */}
            <div className="sm:col-span-2 border-t border-slate-200 pt-4">
              <h3 className="text-xs font-bold uppercase tracking-wide" style={{ color: indigo.muted }}>
                Capability bundle
              </h3>
              <p className="mb-2 text-[11px]" style={{ color: indigo.muted }}>
                What this plan grants. Changing it reconciles every account on the plan — manual and agent-delegated
                grants are never touched.
              </p>
              <CapabilityPicker
                options={capabilityOptions}
                selected={form.capabilities}
                engineType={form.engine_type}
                onToggle={toggleCapability}
              />
              <FieldError errors={errors} name="capabilities" />

              {/*
                Phase 4 Task 2 / Task 10 FIX — always rendered, same
                convention as "Included AI credits / period" above
                (also conditionally required by another capability):
                the field exists regardless of the current selection,
                and the hint explains the condition under which the
                backend requires a concrete value, rather than the
                field appearing and disappearing as capabilities are
                toggled.
              */}
              <label className="mt-3 block text-xs font-medium text-slate-700">
                API installations limit
                <input
                  type="number"
                  min={0}
                  step={1}
                  data-testid="plan-api-installations-limit"
                  className={inputClass}
                  value={form.api_installations_limit}
                  onChange={(e) => set('api_installations_limit', e.target.value)}
                  placeholder="Leave empty unless this plan includes api_installations"
                />
                <span className="mt-1 block text-[11px] font-normal text-slate-400">
                  How many authorized servers an account on this plan may bind at once. Required (0 or greater) when
                  this plan includes both “api_installations” and “external_api”; ignored unless “api_installations”
                  is in the bundle above.
                </span>
                <FieldError errors={errors} name="capability_limits.api_installations" />
                <FieldError errors={errors} name="capability_limits" />
              </label>
            </div>
          </div>
        </div>

        <div className="flex items-center justify-end gap-2 border-t border-slate-200 px-5 py-3">
          <button onClick={onClose} className="rounded-lg px-3 py-2 text-sm font-medium text-slate-600 hover:bg-slate-100">
            Cancel
          </button>
          <button
            onClick={handleSave}
            disabled={isSaving}
            data-testid="plan-save"
            className="flex items-center gap-2 rounded-lg px-4 py-2 text-sm font-semibold text-white disabled:opacity-60"
            style={{ background: activeGradient }}
          >
            {isSaving && <Loader2 className="h-4 w-4 animate-spin" />}
            {isEdit ? 'Save changes' : 'Create plan'}
          </button>
        </div>
      </div>

      {pendingConfirm && (
        <ConfirmModal
          title="Confirm plan change"
          message={pendingConfirm}
          confirmLabel="Apply"
          onConfirm={() => void submit()}
          onCancel={() => setPendingConfirm(null)}
        />
      )}
    </div>
  );
}

// ====================================================================
// Page
// ====================================================================

export default function PlanManagementPage() {
  const [plans, setPlans] = useState<ManagedPlan[]>([]);
  const [capabilityOptions, setCapabilityOptions] = useState<PlanCapabilityOption[]>([]);
  const [isLoading, setIsLoading] = useState(true);
  const [pageError, setPageError] = useState<string | null>(null);
  const [notice, setNotice] = useState<string | null>(null);
  const [searchParams, setSearchParams] = useSearchParams();
  const customFor = Number(searchParams.get('custom_for')) || null;
  // "Build a custom plan" from a Contact sales request (/admin/plans?custom_for=<client id>) opens the form at once.
  const [editing, setEditing] = useState<ManagedPlan | 'new' | null>(() => (customFor ? 'new' : null));
  const [pendingToggle, setPendingToggle] = useState<ManagedPlan | null>(null);
  const [busySlug, setBusySlug] = useState<string | null>(null);
  const [prices, setPrices] = useState<CustomPlanPriceList | null>(null);
  const [editingRates, setEditingRates] = useState(false);

  useEffect(() => {
    void planService.getPricing().then(setPrices).catch(() => undefined);
  }, []);

  /** Custom plan: create the client's invoice for it (they pay it, or you record the payment). */
  const sendInvoice = async (plan: ManagedPlan) => {
    if (!plan.account) return;
    setBusySlug(plan.slug);
    setPageError(null);
    try {
      const res = await axiosInstance.post<{ message: string }>('/billing/manual-checkout', { plan_key: plan.slug }, { params: { account_id: plan.account.id } });
      setNotice(res.data.message + ' (' + (plan.account.company_name ?? 'client') + ')');
    } catch (err) {
      setPageError(extractErrorMessage(err, 'Could not create the invoice.'));
    } finally {
      setBusySlug(null);
    }
  };

  const load = useCallback(async () => {
    setIsLoading(true);
    setPageError(null);
    try {
      const response = await planService.list();
      setPlans(response.data);
      setCapabilityOptions(response.available_capabilities);
    } catch (err) {
      setPageError(extractErrorMessage(err, 'Failed to load plans.'));
    } finally {
      setIsLoading(false);
    }
  }, []);

  useEffect(() => {
    void load();
  }, [load]);

  const handleSaved = (message: string) => {
    setEditing(null);
    setNotice(message);
    void load();
  };

  /** Activation is an ordinary partial update — one field, nothing else. */
  const confirmToggle = async () => {
    if (!pendingToggle) return;

    setBusySlug(pendingToggle.slug);
    try {
      const result = await planService.update(pendingToggle.slug, { is_active: !pendingToggle.is_active });
      setNotice(result.message);
      await load();
    } catch (err) {
      setPageError(extractErrorMessage(err, 'That change was refused.'));
    } finally {
      setBusySlug(null);
      setPendingToggle(null);
    }
  };

  return (
    <div className="p-6" data-testid="plan-management-page">
      <div className="mb-4 flex flex-wrap items-center justify-between gap-3">
        <div>
          <h1 className="font-display text-xl font-bold" style={{ color: indigo.ink }}>
            Plans
          </h1>
          <p className="text-xs" style={{ color: indigo.muted }}>
            What each plan costs and grants. Changes apply to future purchases; existing invoices are never rewritten.
          </p>
        </div>
        <div className="flex items-center gap-2">
          <button
            onClick={() => void load()}
            className="flex items-center gap-1.5 rounded-lg border px-3 py-2 text-sm font-medium text-slate-600 hover:bg-slate-50"
            style={{ borderColor: indigo.border }}
          >
            <RefreshCw className="h-4 w-4" />
            Refresh
          </button>
          <button
            onClick={() => setEditing('new')}
            data-testid="open-create-plan"
            className="flex items-center gap-2 rounded-lg px-4 py-2 text-sm font-semibold text-white"
            style={{ background: activeGradient }}
          >
            <Plus className="h-4 w-4" />
            Create plan
          </button>
        </div>
      </div>

      {notice && (
        <p data-testid="plan-notice" className="mb-3 rounded-lg bg-emerald-50 px-3 py-2 text-sm text-emerald-800">
          {notice}
        </p>
      )}
      {pageError && (
        <p data-testid="plan-page-error" className="mb-3 rounded-lg bg-red-50 px-3 py-2 text-sm text-red-700">
          {pageError}
        </p>
      )}

      <TableCard>
        {isLoading ? (
          <p className="flex items-center gap-2 p-4 text-sm text-slate-500">
            <Loader2 className="h-4 w-4 animate-spin" />
            Loading plans…
          </p>
        ) : (
          <table className="w-full text-left text-sm">
            <thead>
              <tr className="text-[11px] uppercase tracking-wide text-slate-400">
                <th className="px-4 py-2 font-semibold">Plan</th>
                <th className="px-4 py-2 font-semibold">Engine</th>
                <th className="px-4 py-2 font-semibold">Billing</th>
                <th className="px-4 py-2 font-semibold">Price</th>
                <th className="px-4 py-2 font-semibold">Quota</th>
                <th className="px-4 py-2 font-semibold">AI credits</th>
                <th className="px-4 py-2 font-semibold">Duration</th>
                <th className="px-4 py-2 font-semibold">Capabilities</th>
                <th className="px-4 py-2 font-semibold">Accounts</th>
                <th className="px-4 py-2 font-semibold">Status</th>
                <th className="px-4 py-2 text-right font-semibold">Actions</th>
              </tr>
            </thead>
            <tbody className="divide-y divide-slate-100">
              {plans.map((plan) => (
                <tr key={plan.slug} data-testid={`plan-row-${plan.slug}`}>
                  <td className="px-4 py-2.5">
                    <span className="font-medium text-slate-800">{plan.label}</span>
                    <span className="ml-1.5 text-[11px] text-slate-400">{plan.slug}</span>
                    {plan.account && (
                      <span className="ml-1.5 rounded-full bg-teal-50 px-2 py-0.5 text-[11px] font-semibold text-teal-700 ring-1 ring-teal-200">
                        Custom: {plan.account.company_name ?? '#' + plan.account.id}
                      </span>
                    )}
                    {!plan.account && plan.is_public === false && (
                      <span className="ml-1.5 rounded-full bg-slate-100 px-2 py-0.5 text-[11px] font-semibold text-slate-500 ring-1 ring-slate-200">Hidden</span>
                    )}
                    {plan.limits && (
                      <span className="mt-0.5 block text-[11px] text-slate-400">
                        {LIMIT_KEYS.map((k) => describePlanLimit(k, plan.limits![k])).filter(Boolean).join(' · ')}
                      </span>
                    )}
                  </td>
                  <td className="px-4 py-2.5 text-slate-600">{plan.engine_type}</td>
                  <td className="px-4 py-2.5 text-slate-600">{plan.billing_model}</td>
                  <td className="px-4 py-2.5 text-slate-600" data-testid={`plan-price-${plan.slug}`}>
                    {plan.price}
                  </td>
                  <td className="px-4 py-2.5 text-slate-600">{plan.total_allocated_messages ?? '—'}</td>
                  <td className="px-4 py-2.5 text-slate-600" data-testid={`plan-credits-${plan.slug}`}>
                    {plan.included_credits ?? 0}
                  </td>
                  <td className="px-4 py-2.5 text-slate-600">{plan.duration_days}d</td>
                  <td className="px-4 py-2.5 text-slate-600" data-testid={`plan-capabilities-${plan.slug}`}>
                    {plan.capabilities.length}
                  </td>
                  <td className="px-4 py-2.5 text-slate-600" data-testid={`plan-accounts-${plan.slug}`}>
                    {plan.accounts}
                  </td>
                  <td className="px-4 py-2.5">
                    {plan.is_active ? (
                      <span
                        data-testid={`plan-status-${plan.slug}`}
                        className="inline-flex items-center gap-1 rounded-full bg-emerald-50 px-2 py-0.5 text-[11px] font-semibold text-emerald-700 ring-1 ring-emerald-200"
                      >
                        <Check className="h-3 w-3" />
                        Active
                      </span>
                    ) : (
                      <span
                        data-testid={`plan-status-${plan.slug}`}
                        className="inline-flex items-center gap-1 rounded-full bg-slate-100 px-2 py-0.5 text-[11px] font-semibold text-slate-500 ring-1 ring-slate-200"
                      >
                        <PowerOff className="h-3 w-3" />
                        Inactive
                      </span>
                    )}
                  </td>
                  <td className="px-4 py-2.5 text-right">
                    <div className="flex items-center justify-end gap-1">
                      {plan.account && plan.is_active && (
                        <button
                          onClick={() => void sendInvoice(plan)}
                          disabled={busySlug === plan.slug}
                          data-testid={'invoice-plan-' + plan.slug}
                          className="rounded-md p-1.5 text-teal-700 hover:bg-slate-100 disabled:opacity-50"
                          aria-label={'Send invoice for ' + plan.label}
                          title="Create the client's invoice for this plan"
                        >
                          <Send className="h-4 w-4" />
                        </button>
                      )}
                      <button
                        onClick={() => setEditing(plan)}
                        data-testid={`edit-plan-${plan.slug}`}
                        className="rounded-md p-1.5 text-slate-500 hover:bg-slate-100"
                        aria-label={`Edit ${plan.label}`}
                        title="Edit"
                      >
                        <Pencil className="h-4 w-4" />
                      </button>
                      <button
                        onClick={() => setPendingToggle(plan)}
                        disabled={busySlug === plan.slug}
                        data-testid={`toggle-plan-${plan.slug}`}
                        className={`rounded-md p-1.5 hover:bg-slate-100 disabled:opacity-50 ${
                          plan.is_active ? 'text-red-500' : 'text-emerald-600'
                        }`}
                        aria-label={plan.is_active ? `Deactivate ${plan.label}` : `Activate ${plan.label}`}
                        title={plan.is_active ? 'Deactivate' : 'Activate'}
                      >
                        {busySlug === plan.slug ? (
                          <Loader2 className="h-4 w-4 animate-spin" />
                        ) : plan.is_active ? (
                          <PowerOff className="h-4 w-4" />
                        ) : (
                          <Power className="h-4 w-4" />
                        )}
                      </button>
                    </div>
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        )}
      </TableCard>

      <ModuleOfferAdminCard />

      <WhatsAppNumberPriceCard />

      {editing && (
        <PlanFormModal
          plan={editing === 'new' ? null : editing}
          capabilityOptions={capabilityOptions}
          customForAccountId={editing === 'new' ? customFor : null}
          prices={prices}
          onEditRates={() => setEditingRates(true)}
          onClose={() => {
            setEditing(null);
            if (customFor) setSearchParams({});
          }}
          onSaved={(message) => {
            handleSaved(message);
            if (customFor) setSearchParams({});
          }}
        />
      )}

      {editingRates && prices && (
        <PriceListModal
          prices={prices}
          capabilityOptions={capabilityOptions}
          onClose={() => setEditingRates(false)}
          onSaved={(saved) => {
            setPrices(saved);
            setEditingRates(false);
          }}
        />
      )}

      {pendingToggle && (
        <ConfirmModal
          title={pendingToggle.is_active ? 'Deactivate plan' : 'Activate plan'}
          message={
            pendingToggle.is_active
              ? `“${pendingToggle.label}” will no longer be purchasable. The ${pendingToggle.accounts} account${
                  pendingToggle.accounts === 1 ? '' : 's'
                } already on it keep their subscription and their capabilities — nothing is cancelled or revoked.`
              : `“${pendingToggle.label}” will become purchasable again. Existing customers are unaffected.`
          }
          confirmLabel={pendingToggle.is_active ? 'Deactivate' : 'Activate'}
          onConfirm={() => void confirmToggle()}
          onCancel={() => setPendingToggle(null)}
        />
      )}
    </div>
  );
}
