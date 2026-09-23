import { useCallback, useEffect, useMemo, useState } from 'react';
import { AlertTriangle, Check, Loader2, Pencil, Plus, Power, PowerOff, RefreshCw, X } from 'lucide-react';

import planService from '../../services/planService';
import { TableCard, inputClass } from '../../components/common/Card';
import ConfirmModal from '../../components/common/ConfirmModal';
import { extractErrorMessage } from '../../utils/apiError';
import { indigo, activeGradient } from '../../theme/signalIndigo';
import type {
  CreatePlanPayload,
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
];

const BILLING_OPTIONS: { value: PlanBillingModel; label: string }[] = [
  { value: 'flat_quota', label: 'Flat quota' },
  { value: 'per_message', label: 'Per message' },
  { value: 'unlimited', label: 'Unlimited' },
];

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
  is_active: boolean;
  capabilities: string[];
}

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
    is_active: true,
    capabilities: [],
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
    is_active: plan.is_active,
    capabilities: [...plan.capabilities],
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
              className="mt-0.5 h-4 w-4 rounded border-slate-300 text-indigo-600"
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
}: {
  /** null = create. */
  plan: ManagedPlan | null;
  capabilityOptions: PlanCapabilityOption[];
  onClose: () => void;
  onSaved: (message: string) => void;
}) {
  const isEdit = plan !== null;
  const [form, setForm] = useState<PlanFormState>(plan ? formFromPlan(plan) : emptyForm());
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

    if (form.is_active !== plan.is_active) patch.is_active = form.is_active;

    // Absent unless genuinely changed — see this function's docblock.
    if (bundleChanged) patch.capabilities = form.capabilities;

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
          is_active: form.is_active,
          capabilities: form.capabilities,
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
              <input
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
              <input
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
              <select
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
              <select
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
              <input
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
              <input
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
                className="h-4 w-4 rounded border-slate-300 text-indigo-600"
              />
              Available for purchase
            </label>

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
  const [editing, setEditing] = useState<ManagedPlan | 'new' | null>(null);
  const [pendingToggle, setPendingToggle] = useState<ManagedPlan | null>(null);
  const [busySlug, setBusySlug] = useState<string | null>(null);

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
                  </td>
                  <td className="px-4 py-2.5 text-slate-600">{plan.engine_type}</td>
                  <td className="px-4 py-2.5 text-slate-600">{plan.billing_model}</td>
                  <td className="px-4 py-2.5 text-slate-600" data-testid={`plan-price-${plan.slug}`}>
                    {plan.price}
                  </td>
                  <td className="px-4 py-2.5 text-slate-600">{plan.total_allocated_messages ?? '—'}</td>
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

      {editing && (
        <PlanFormModal
          plan={editing === 'new' ? null : editing}
          capabilityOptions={capabilityOptions}
          onClose={() => setEditing(null)}
          onSaved={handleSaved}
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
