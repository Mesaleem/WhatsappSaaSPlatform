import { useCallback, useEffect, useMemo, useRef, useState } from 'react';
import { Link, useSearchParams } from 'react-router-dom';
import {
  AlertCircle,
  BarChart3,
  Loader2,
  Pause,
  Play,
  Rocket,
  Share2,
  Sparkles,
  Trash2,
  UploadCloud,
  X,
} from 'lucide-react';
import { useAuth } from '../../core/context/AuthContext';
import { useTenant } from '../../core/context/TenantContext';
import adsService, { newLaunchKey } from '../../services/adsService';
import socialService from '../../services/socialService';
import { GateNoticeModal, SelectClientNotice, type GateNoticeAction } from '../../components/common/ActionGate';
import { useClientGate } from '../../components/common/actionGateHooks';
import { connectionStatusOf, type SocialAccount } from '../../types/social';
import aiService from '../../services/aiService';
import mediaService from '../../services/mediaService';
import { TableCard, inputClass } from '../../components/common/Card';
import { ClearFiltersButton, SearchInput, StatusFilterSelect } from '../../components/common/DataTableControls';
import AdPreview from '../../components/social/AdPreview';
import OrganicPostModal from '../../components/social/OrganicPostModal';
import OrganicPostsPanel from '../../components/social/OrganicPostsPanel';
import type { OrganicPost } from '../../types/organic';
import { extractErrorCode, extractErrorMessage, extractFieldErrors } from '../../utils/apiError';
import { socialConnectionError, type SocialConnectionErrorInfo } from '../../utils/socialConnectionError';
import SocialReconnectNotice from '../../components/social/SocialReconnectNotice';
import { indigo, activeGradient } from '../../theme/signalIndigo';
import { AD_CTA_LABELS, AD_CTAS_BY_OBJECTIVE, AD_OBJECTIVE_LABELS, AD_PLACEMENT_LABELS, formatAdMoney } from '../../types/ads';
import type { AdAccountInfo, AdCallToAction, AdCampaign, AdLocation, AdObjective, AdPlacement, LaunchCampaignPayload } from '../../types/ads';
import AdLocationPicker from '../../components/social/AdLocationPicker';
import { AD_COPY_TONES, TARGET_GOAL_LABELS } from '../../types/ai';
import type { AdCopyTone, AdCopyVariant, TargetGoal } from '../../types/ai';
import type { MediaType } from '../../types/media';

const STATUS_BADGE: Record<AdCampaign['status'], string> = {
  LAUNCHING: 'bg-sky-50 text-sky-700 ring-sky-600/20',
  ACTIVE: 'bg-emerald-50 text-emerald-700 ring-emerald-600/20',
  PAUSED: 'bg-slate-100 text-slate-600 ring-slate-500/20',
  FAILED: 'bg-red-50 text-red-700 ring-red-600/20',
  UNCONFIRMED: 'bg-amber-50 text-amber-700 ring-amber-600/20',
  UNAVAILABLE: 'bg-slate-100 text-slate-500 ring-slate-400/20',
};

/** Global Table Filters refactor — Meta Ads had no filter controls at all before this. */
const CAMPAIGN_STATUS_OPTIONS = [
  { value: 'ACTIVE', label: 'Active' },
  { value: 'PAUSED', label: 'Paused' },
  { value: 'LAUNCHING', label: 'Launching' },
  { value: 'FAILED', label: 'Failed' },
  { value: 'UNCONFIRMED', label: 'Unconfirmed' },
  { value: 'UNAVAILABLE', label: 'Unavailable' },
];

/** Phase 10 Task 2 — only these can be paused/resumed (backend MetaAdsService::changeStatus()). */
function canToggle(campaign: AdCampaign): boolean {
  return campaign.status === 'ACTIVE' || campaign.status === 'PAUSED';
}

const OUTCOME_UNKNOWN_LAUNCH =
  'Meta did not confirm the launch. It was not resent automatically — check Meta Ads Manager before launching again.';
const OUTCOME_UNKNOWN_CHANGE =
  'Meta did not confirm the change, so the status was left unchanged. Check Meta Ads Manager; repeating the action is safe.';

function errorStatus(err: unknown): number | undefined {
  return (err as { response?: { status?: number } } | null)?.response?.status;
}

/** The campaign row a 409/502 campaign-state error carries (backend AdCampaignConflict). */
function errorCampaign(err: unknown): AdCampaign | undefined {
  return (err as { response?: { data?: { data?: AdCampaign } } } | null)?.response?.data?.data;
}

/** Owner request (2026-09-30): the ad account's own currency (INR when not known), no longer a hard-coded "$". */
function formatMoney(value: number, currency?: string | null): string {
  return formatAdMoney(value, currency);
}

function formatDate(value: string | null): string {
  if (!value) return '—';
  return new Date(value).toLocaleString();
}

/** Parses a comma-separated free-text field into a trimmed, de-duplicated string list. */
function parseList(value: string): string[] {
  return Array.from(
    new Set(
      value
        .split(',')
        .map((v) => v.trim())
        .filter(Boolean),
    ),
  );
}

interface WizardState {
  objective: AdObjective;
  daily_budget: string;
  cpl_threshold: string;
  /** Countries / states / cities from Meta's location search (owner request 2026-09-30). */
  locations: AdLocation[];
  age_min: string;
  age_max: string;
  interests: string;
  /** Uploaded creative — see mediaService/SocialMediaController. Replaces the prior free-text "Image URL" field. */
  media_url: string | null;
  media_type: MediaType | null;
  campaign_name: string;
  /** Social/Ads Launcher Overhaul — Step 1. Separate from campaign_name on purpose: the AI prompt needs the actual business/product being advertised, not the campaign's internal label (the two were previously conflated — see the module audit). */
  business_name: string;
  target_industry: string;
  /** Free text, e.g. "50% off", "New 2BHK flat batch opening" — optional AI-prompt token. */
  offer_details: string;
  /** Shapes AI copy style only (organic engagement vs paid conversion framing) — launching from THIS wizard always creates a paid Meta campaign regardless of this value; see the Target Goal field's inline note. */
  target_goal: TargetGoal;
  headline: string;
  primary_text: string;
  /** automatic = Advantage+ placements (every Facebook / Instagram placement). */
  placement_mode: 'automatic' | 'manual';
  placements: AdPlacement[];
  /** The ad's button (owner request 2026-09-30). */
  call_to_action: AdCallToAction;
}

const WIZARD_DEFAULTS: WizardState = {
  objective: 'LEAD_GENERATION',
  daily_budget: '',
  cpl_threshold: '',
  locations: [{ key: 'IN', name: 'India', type: 'country', country_code: 'IN', country_name: 'India' }],
  age_min: '18',
  age_max: '65',
  interests: '',
  media_url: null,
  media_type: null,
  campaign_name: '',
  business_name: '',
  target_industry: '',
  offer_details: '',
  target_goal: 'PAID_LEAD_AD',
  headline: '',
  primary_text: '',
  placement_mode: 'automatic',
  placements: [],
  call_to_action: 'SIGN_UP',
};

/** Which wizard step owns a backend validation field (422 `errors` keys). */
function stepOfField(field: string): number {
  if (field.startsWith('targeting_specs') || field.startsWith('placements')) return 1;
  if (field.startsWith('creative')) return 2;
  return 0;
}

const WIZARD_STEPS = ['Objective & Budget', 'Audience Targeting', 'Creative & Hook Copy'] as const;

/**
 * Social Media Marketing & Meta Ads Automation Expansion — Phase 3.
 * 3-step campaign creation wizard (spec item 3). All three steps' fields
 * are kept in ONE piece of state (rather than three separate forms) so
 * Back/Next never loses what the tenant already typed — Next only
 * validates the CURRENT step's fields, not the whole form, so a tenant
 * can move forward without having touched a later step yet.
 */
function LaunchWizardModal({
  onClose,
  onLaunched,
  canManageSocialAccounts = false,
}: {
  onClose: () => void;
  onLaunched: () => void;
  canManageSocialAccounts?: boolean;
}) {
  const [step, setStep] = useState(0);
  const [form, setForm] = useState<WizardState>(WIZARD_DEFAULTS);
  const [stepError, setStepError] = useState<string | null>(null);
  const [isSubmitting, setIsSubmitting] = useState(false);
  const [submitError, setSubmitError] = useState<string | null>(null);
  // Phase 9 Task 2 — the Ad Account connection expired/was revoked: shown
  // with a reconnect link; the wizard (and everything typed) stays open.
  const [connectionError, setConnectionError] = useState<SocialConnectionErrorInfo | null>(null);
  // Phase 10 Task 2 — one Idempotency-Key per launch attempt, kept until a
  // definitive answer: a lost response or double submit replays the stored
  // launch instead of creating a second campaign at Meta.
  const launchKey = useRef<string | null>(null);
  // Owner request (2026-09-30): errors shown where they belong — per field, and
  // at the top of the form with the view scrolled to them.
  const [fieldErrors, setFieldErrors] = useState<Record<string, string>>({});
  const topRef = useRef<HTMLDivElement>(null);
  const showTop = () => topRef.current?.scrollIntoView?.({ behavior: 'smooth', block: 'start' });
  const fieldError = (...prefixes: string[]) => Object.entries(fieldErrors).find(([k]) => prefixes.some((p) => k === p || k.startsWith(`${p}.`)))?.[1] ?? null;
  // The connected ad account: its currency (INR for an Indian ad account), status and spend cap.
  const [accountInfo, setAccountInfo] = useState<AdAccountInfo | null>(null);
  const [accountInfoError, setAccountInfoError] = useState<string | null>(null);
  useEffect(() => {
    let cancelled = false;
    Promise.resolve()
      .then(() => adsService.account())
      .then((info) => {
        if (!cancelled) setAccountInfo(info);
      })
      .catch((err: unknown) => {
        if (!cancelled) setAccountInfoError(extractErrorMessage(err, 'Could not read the Meta ad account.'));
      });
    return () => {
      cancelled = true;
    };
  }, []);
  const currency = accountInfo?.currency ?? 'INR';

  // AI Ad Copywriter — Social/Ads Launcher Overhaul (Step 1: Gemini Pro
  // Engine & Multi-Token Prompt Refactor). Suggestions only: the tenant
  // must explicitly click a variant to apply it to Headline/Primary
  // Text; nothing is auto-filled or auto-submitted.
  const [tone, setTone] = useState<AdCopyTone>('High-Converting');
  const [aiVariants, setAiVariants] = useState<AdCopyVariant[]>([]);
  const [aiProvider, setAiProvider] = useState<string | null>(null);
  const [isGeneratingAi, setIsGeneratingAi] = useState(false);
  const [aiError, setAiError] = useState<string | null>(null);

  // Media Upload API (Step 2). fileInputRef lets the dropzone's whole
  // clickable area open the native file picker via a hidden <input>,
  // rather than relying on the browser's own (visually inconsistent)
  // file-input chrome.
  const fileInputRef = useRef<HTMLInputElement>(null);
  const [isUploadingMedia, setIsUploadingMedia] = useState(false);
  const [uploadProgress, setUploadProgress] = useState(0);
  const [mediaError, setMediaError] = useState<string | null>(null);

  const update = (patch: Partial<WizardState>) => setForm((prev) => {
    const next = { ...prev, ...patch };
    // A button the new objective does not offer falls back to that objective's default.
    if (!AD_CTAS_BY_OBJECTIVE[next.objective].includes(next.call_to_action)) next.call_to_action = AD_CTAS_BY_OBJECTIVE[next.objective][0];
    return next;
  });

  const handleGenerateAi = () => {
    if (!form.business_name.trim() || !form.target_industry.trim()) {
      setAiError('Enter a business name and a target industry above before generating.');
      return;
    }
    setAiError(null);
    setIsGeneratingAi(true);
    aiService
      .generate({
        business_name: form.business_name.trim(),
        target_industry: form.target_industry.trim(),
        offer_details: form.offer_details.trim(),
        target_goal: form.target_goal,
        tone,
      })
      .then((result) => {
        setAiVariants(result.variants);
        setAiProvider(result.provider);
      })
      .catch((err: unknown) => setAiError(extractErrorMessage(err, 'Failed to generate ad copy.')))
      .finally(() => setIsGeneratingAi(false));
  };

  const applyAiVariant = (variant: AdCopyVariant) => {
    update({ headline: variant.hook, primary_text: `${variant.caption}\n\n${variant.cta}` });
  };

  const handleFileSelected = (file: File | undefined | null) => {
    if (!file) return;

    setMediaError(null);
    setIsUploadingMedia(true);
    setUploadProgress(0);

    mediaService
      .upload(file, setUploadProgress)
      .then((uploaded) => {
        update({ media_url: uploaded.url, media_type: uploaded.type });
      })
      .catch((err: unknown) => setMediaError(extractErrorMessage(err, 'Failed to upload media.')))
      .finally(() => setIsUploadingMedia(false));
  };

  const handleRemoveMedia = () => {
    update({ media_url: null, media_type: null });
    setMediaError(null);
    if (fileInputRef.current) fileInputRef.current.value = '';
  };

  const validateStep = (): string | null => {
    if (step === 0) {
      if (!form.campaign_name.trim()) return 'Campaign name is required.';
      const budget = Number(form.daily_budget);
      if (!form.daily_budget || Number.isNaN(budget) || budget <= 0) return 'Enter a daily budget greater than 0.';
      if (form.cpl_threshold && Number.isNaN(Number(form.cpl_threshold))) return 'CPL threshold must be a number.';
      if (accountInfo?.runnable === false) return `The connected Meta ad account is ${accountInfo.account_status_label ?? 'not active'}, so campaigns cannot run. Resolve it in Meta Ads Manager first.`;
      if (accountInfo?.remaining_spend_cap != null && budget > accountInfo.remaining_spend_cap) {
        return `The daily budget is more than the ${formatMoney(accountInfo.remaining_spend_cap, currency)} left before the ad account's spend cap.`;
      }
    }
    if (step === 1) {
      if (form.locations.length === 0) return 'Choose at least one location (country, state or city).';
      if (form.placement_mode === 'manual' && form.placements.length === 0) return 'Choose at least one placement, or use automatic placements.';
      const ageMin = Number(form.age_min);
      const ageMax = Number(form.age_max);
      if (!Number.isInteger(ageMin) || ageMin < 13 || ageMin > 65) return 'Minimum age must be between 13 and 65.';
      if (!Number.isInteger(ageMax) || ageMax < 13 || ageMax > 65) return 'Maximum age must be between 13 and 65.';
      if (ageMax < ageMin) return 'Maximum age cannot be less than minimum age.';
    }
    if (step === 2) {
      if (!form.headline.trim()) return 'Headline is required.';
      if (!form.primary_text.trim()) return 'Primary text is required.';
    }
    return null;
  };

  const goNext = () => {
    const error = validateStep();
    if (error) {
      setStepError(error);
      showTop();
      return;
    }
    setStepError(null);
    setStep((s) => Math.min(s + 1, WIZARD_STEPS.length - 1));
  };

  const goBack = () => {
    setStepError(null);
    setStep((s) => Math.max(s - 1, 0));
  };

  const handleLaunch = () => {
    const error = validateStep();
    if (error) {
      setStepError(error);
      showTop();
      return;
    }

    const payload: LaunchCampaignPayload = {
      campaign_name: form.campaign_name.trim(),
      objective: form.objective,
      daily_budget: Number(form.daily_budget),
      cpl_threshold: form.cpl_threshold ? Number(form.cpl_threshold) : null,
      targeting_specs: {
        locations: form.locations.map(({ key, type, name }) => ({ key, type, name })),
        age_min: Number(form.age_min),
        age_max: Number(form.age_max),
        interests: parseList(form.interests),
      },
      creative: {
        // Populated by the upload dropzone (mediaService/SocialMediaController)
        // rather than typed by hand — see the module audit's gap analysis,
        // item [C]-1. Field name kept as image_url on the wire: the
        // backend (AdCampaignController/MetaAdsService) is unchanged by
        // this refactor and still expects that key.
        image_url: form.media_url,
        headline: form.headline.trim(),
        primary_text: form.primary_text.trim(),
        call_to_action: form.call_to_action,
      },
      placements: form.placement_mode === 'manual' ? form.placements : [],
    };

    setSubmitError(null);
    setConnectionError(null);
    setFieldErrors({});
    setIsSubmitting(true);

    launchKey.current ??= newLaunchKey();

    adsService
      .launch(payload, launchKey.current)
      .then(() => {
        launchKey.current = null;
        onLaunched();
      })
      .catch((err: unknown) => {
        const code = extractErrorCode(err);
        const status = errorStatus(err);
        // Keep the key when the outcome is not definitive (no response, the
        // launch still running, Meta never confirmed it) so a repeat cannot
        // launch twice; a definitive refusal starts a fresh attempt next time.
        const definitive = status !== undefined && status < 500 && code !== 'AD_LAUNCH_IN_PROGRESS';
        if (definitive) launchKey.current = null;

        const connection = socialConnectionError(err);
        const fields = extractFieldErrors(err);
        if (connection) {
          setConnectionError(connection);
        } else if (code === 'AD_PROVIDER_OUTCOME_UNKNOWN') {
          setSubmitError(OUTCOME_UNKNOWN_LAUNCH);
        } else if (Object.keys(fields).length > 0) {
          // Take the user to the step that holds the first invalid field.
          setFieldErrors(fields);
          setStep(Math.min(...Object.keys(fields).map(stepOfField)));
          setSubmitError('Some details need attention — see the highlighted fields.');
        } else {
          setSubmitError(extractErrorMessage(err, 'Failed to launch the campaign.'));
        }
        showTop();
      })
      .finally(() => setIsSubmitting(false));
  };

  // Owner request (2026-09-30): an in-page form instead of a modal, so the whole
  // form and its errors are visible and the page can scroll normally.
  return (
    <section className="w-full" data-testid="launch-wizard" ref={topRef}>
      <div className="w-full rounded-2xl border bg-white p-6 shadow-sm" style={{ borderColor: indigo.border }}>
        <div className="flex items-center justify-between">
          <h2 className="font-display text-base font-bold" style={{ color: indigo.ink }}>
            Launch Meta Ads Campaign
          </h2>
          <button onClick={onClose} className="flex items-center gap-1 rounded-md px-2 py-1 text-sm text-slate-500 hover:bg-slate-100 hover:text-slate-700" aria-label="Close">
            <X className="h-4 w-4" /> Cancel
          </button>
        </div>

        <div className="mt-4 flex items-center gap-2">
          {WIZARD_STEPS.map((label, i) => (
            <div key={label} className="flex flex-1 items-center gap-2">
              <div
                className="flex h-6 w-6 flex-shrink-0 items-center justify-center rounded-full text-xs font-semibold"
                style={
                  i <= step
                    ? { background: activeGradient, color: '#fff' }
                    : { background: indigo.track, color: indigo.muted }
                }
              >
                {i + 1}
              </div>
              <span className="hidden text-xs font-medium sm:inline" style={{ color: i <= step ? indigo.ink : indigo.muted }}>
                {label}
              </span>
              {i < WIZARD_STEPS.length - 1 && <div className="h-px flex-1" style={{ background: indigo.border }} />}
            </div>
          ))}
        </div>

        <div className="mt-6 space-y-4">
          {stepError && (
            <div className="flex items-start gap-2 rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700" role="alert">
              <AlertCircle className="mt-0.5 h-4 w-4 flex-shrink-0" />
              {stepError}
            </div>
          )}

          {connectionError && <SocialReconnectNotice info={connectionError} canManageSocialAccounts={canManageSocialAccounts} />}

          {submitError && (
            <div className="flex items-start gap-2 rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700" role="alert" data-testid="launch-submit-error">
              <AlertCircle className="mt-0.5 h-4 w-4 flex-shrink-0" />
              <div>
                <p>{submitError}</p>
                {Object.keys(fieldErrors).length > 0 && (
                  <ul className="mt-1 list-disc pl-4 text-xs">
                    {Object.entries(fieldErrors).map(([k, m]) => (
                      <li key={k}>{m}</li>
                    ))}
                  </ul>
                )}
              </div>
            </div>
          )}

          {step === 0 && (
            <>
              <label className="block text-sm font-medium text-slate-700">
                Campaign Name <span className="text-red-500">*</span>
                <input
                  type="text"
                  className={inputClass}
                  value={form.campaign_name}
                  onChange={(e) => update({ campaign_name: e.target.value })}
                  placeholder="Spring Lead Gen Push"
                />
                {fieldError('campaign_name') && <p className="mt-1 text-xs font-medium text-red-600">{fieldError('campaign_name')}</p>}
              </label>
              <label className="block text-sm font-medium text-slate-700">
                Objective
                <select
                  className={inputClass}
                  value={form.objective}
                  onChange={(e) => update({ objective: e.target.value as AdObjective })}
                >
                  {(Object.keys(AD_OBJECTIVE_LABELS) as AdObjective[]).map((obj) => (
                    <option key={obj} value={obj}>
                      {AD_OBJECTIVE_LABELS[obj]}
                    </option>
                  ))}
                </select>
              </label>
              <div className="rounded-xl border border-slate-200 bg-slate-50 px-4 py-3 text-xs text-slate-600" data-testid="ad-account-info">
                {accountInfo ? (
                  <div className="grid grid-cols-2 gap-2 sm:grid-cols-4">
                    <div>
                      <p className="font-medium text-slate-500">Ad account</p>
                      <p className="text-sm font-semibold text-slate-800">{accountInfo.name ?? '—'}</p>
                      <p className={accountInfo.runnable === false ? 'text-red-600' : 'text-emerald-700'}>{accountInfo.account_status_label ?? 'status unknown'}</p>
                    </div>
                    <div>
                      <p className="font-medium text-slate-500">Amount spent</p>
                      <p className="text-sm font-semibold text-slate-800">{accountInfo.amount_spent !== null ? formatMoney(accountInfo.amount_spent, currency) : '—'}</p>
                    </div>
                    <div>
                      <p className="font-medium text-slate-500">Spend cap left</p>
                      <p className="text-sm font-semibold text-slate-800">{accountInfo.remaining_spend_cap !== null ? formatMoney(accountInfo.remaining_spend_cap, currency) : 'No cap'}</p>
                    </div>
                    <div>
                      <p className="font-medium text-slate-500">Balance due</p>
                      <p className="text-sm font-semibold text-slate-800">{accountInfo.balance !== null ? formatMoney(accountInfo.balance, currency) : '—'}</p>
                    </div>
                  </div>
                ) : (
                  <p>{accountInfoError ? `${accountInfoError} Amounts are assumed to be in INR.` : 'Reading the Meta ad account…'}</p>
                )}
                <p className="mt-2 text-[11px] text-slate-500">Meta bills this ad account directly in its own currency; payment is managed in Meta Ads Manager.</p>
              </div>
              <label className="block text-sm font-medium text-slate-700">
                Daily Budget ({currency}) <span className="text-red-500">*</span>
                <input
                  type="number"
                  min="1"
                  step="0.01"
                  className={inputClass}
                  value={form.daily_budget}
                  onChange={(e) => update({ daily_budget: e.target.value })}
                  placeholder={currency === 'INR' ? '500.00' : '25.00'}
                />
                {fieldError('daily_budget') && <p className="mt-1 text-xs font-medium text-red-600">{fieldError('daily_budget')}</p>}
              </label>
              <label className="block text-sm font-medium text-slate-700">
                CPL Auto-Pause Threshold ({currency}, optional)
                <input
                  type="number"
                  min="0"
                  step="0.01"
                  className={inputClass}
                  value={form.cpl_threshold}
                  onChange={(e) => update({ cpl_threshold: e.target.value })}
                  placeholder="e.g. 5.00 — leave blank to set later"
                />
              </label>
            </>
          )}

          {step === 1 && (
            <>
              <div className="block text-sm font-medium text-slate-700">
                Locations <span className="text-red-500">*</span>
                <AdLocationPicker value={form.locations} onChange={(locations) => update({ locations })} invalid={Boolean(fieldError('targeting_specs.locations', 'targeting_specs.countries'))} />
                {fieldError('targeting_specs.locations', 'targeting_specs.countries') && <p className="mt-1 text-xs font-medium text-red-600">{fieldError('targeting_specs.locations', 'targeting_specs.countries')}</p>}
              </div>
              <div className="grid grid-cols-2 gap-3">
                <label className="block text-sm font-medium text-slate-700">
                  Min Age <span className="text-red-500">*</span>
                  <input
                    type="number"
                    min="13"
                    max="65"
                    className={inputClass}
                    value={form.age_min}
                    onChange={(e) => update({ age_min: e.target.value })}
                  />
                </label>
                <label className="block text-sm font-medium text-slate-700">
                  Max Age <span className="text-red-500">*</span>
                  <input
                    type="number"
                    min="13"
                    max="65"
                    className={inputClass}
                    value={form.age_max}
                    onChange={(e) => update({ age_max: e.target.value })}
                  />
                </label>
              </div>
              <label className="block text-sm font-medium text-slate-700">
                Interests (comma-separated, optional)
                <input
                  type="text"
                  className={inputClass}
                  value={form.interests}
                  onChange={(e) => update({ interests: e.target.value })}
                  placeholder="Fitness, Home Loans"
                />
              </label>
              <fieldset className="text-sm text-slate-700" data-testid="ad-placements">
                <legend className="font-medium">Placements</legend>
                <div className="mt-1 flex flex-wrap gap-4">
                  <label className="flex items-center gap-2">
                    <input type="radio" name="placement_mode" checked={form.placement_mode === 'automatic'} onChange={() => update({ placement_mode: 'automatic' })} />
                    Automatic (recommended — Meta shows the ad wherever it performs best)
                  </label>
                  <label className="flex items-center gap-2">
                    <input type="radio" name="placement_mode" checked={form.placement_mode === 'manual'} onChange={() => update({ placement_mode: 'manual' })} />
                    Choose placements
                  </label>
                </div>
                {form.placement_mode === 'manual' && (
                  <div className="mt-2 grid grid-cols-1 gap-2 sm:grid-cols-3">
                    {(Object.keys(AD_PLACEMENT_LABELS) as AdPlacement[]).map((placement) => (
                      <label key={placement} className="flex items-center gap-2 rounded-lg border border-slate-200 px-3 py-2">
                        <input
                          type="checkbox"
                          checked={form.placements.includes(placement)}
                          onChange={(e) => update({ placements: e.target.checked ? [...form.placements, placement] : form.placements.filter((p) => p !== placement) })}
                        />
                        {AD_PLACEMENT_LABELS[placement]}
                      </label>
                    ))}
                  </div>
                )}
                <p className="mt-1 text-xs text-slate-500">Stories and Reels show vertical (9:16) media best.</p>
                {fieldError('placements') && <p className="mt-1 text-xs font-medium text-red-600">{fieldError('placements')}</p>}
              </fieldset>
            </>
          )}

          {step === 2 && (
            <div className="grid grid-cols-1 gap-6 lg:grid-cols-2">
              <div className="space-y-4">
                <div className="rounded-xl border border-indigo-100 bg-indigo-50/50 p-4">
                  <div className="flex items-center gap-2 text-sm font-semibold" style={{ color: indigo.ink }}>
                    <Sparkles className="h-4 w-4" style={{ color: indigo.accentSolid }} />
                    Generate with AI
                  </div>
                  <p className="mt-1 text-xs" style={{ color: indigo.muted }}>
                    Suggestions only — pick a variant below to fill Headline &amp; Primary Text, or write your own.
                  </p>
                  <div className="mt-3 grid grid-cols-1 gap-3 sm:grid-cols-2">
                    <label className="block text-xs font-medium text-slate-700">
                      Business / Product Name
                      <input
                        type="text"
                        className={inputClass}
                        value={form.business_name}
                        onChange={(e) => update({ business_name: e.target.value })}
                        placeholder="e.g. Sunrise Homes"
                      />
                    </label>
                    <label className="block text-xs font-medium text-slate-700">
                      Target Industry
                      <input
                        type="text"
                        className={inputClass}
                        value={form.target_industry}
                        onChange={(e) => update({ target_industry: e.target.value })}
                        placeholder="e.g. Real Estate, Gym, Coaching…"
                      />
                    </label>
                    <label className="block text-xs font-medium text-slate-700 sm:col-span-2">
                      Offer Details (optional)
                      <input
                        type="text"
                        className={inputClass}
                        value={form.offer_details}
                        onChange={(e) => update({ offer_details: e.target.value })}
                        placeholder="e.g. 50% off, New 2BHK flat batch opening"
                      />
                    </label>
                    <label className="block text-xs font-medium text-slate-700">
                      Target Goal
                      <select
                        className={inputClass}
                        value={form.target_goal}
                        onChange={(e) => update({ target_goal: e.target.value as TargetGoal })}
                      >
                        {(Object.keys(TARGET_GOAL_LABELS) as TargetGoal[]).map((g) => (
                          <option key={g} value={g}>
                            {TARGET_GOAL_LABELS[g]}
                          </option>
                        ))}
                      </select>
                    </label>
                    <label className="block text-xs font-medium text-slate-700">
                      Tone
                      <select className={inputClass} value={tone} onChange={(e) => setTone(e.target.value as AdCopyTone)}>
                        {AD_COPY_TONES.map((t) => (
                          <option key={t} value={t}>
                            {t}
                          </option>
                        ))}
                      </select>
                    </label>
                  </div>
                  {form.target_goal === 'ORGANIC_POST' && (
                    <p className="mt-2 text-[11px] italic" style={{ color: indigo.muted }}>
                      Note: launching from this wizard always creates a paid Meta campaign — "Organic Post" here only
                      shapes the AI copy's tone for a more engagement-first style.
                    </p>
                  )}
                  <button
                    type="button"
                    onClick={handleGenerateAi}
                    disabled={isGeneratingAi}
                    className="mt-3 flex items-center gap-2 rounded-lg px-3 py-1.5 text-xs font-semibold text-white disabled:opacity-60"
                    style={{ background: activeGradient }}
                  >
                    {isGeneratingAi ? <Loader2 className="h-3.5 w-3.5 animate-spin" /> : <Sparkles className="h-3.5 w-3.5" />}
                    {isGeneratingAi ? 'Generating…' : aiVariants.length > 0 ? '✨ Regenerate with AI' : '✨ Generate with AI'}
                  </button>

                  {aiError && (
                    <div className="mt-3 flex items-start gap-2 rounded-lg border border-red-200 bg-red-50 px-3 py-2 text-xs text-red-700">
                      <AlertCircle className="mt-0.5 h-3.5 w-3.5 flex-shrink-0" />
                      {aiError}
                    </div>
                  )}

                  {aiVariants.length > 0 && (
                    <div className="mt-3 space-y-2">
                      {aiProvider && (
                        <p className="text-[11px] uppercase tracking-wide" style={{ color: indigo.muted }}>
                          Source: {aiProvider}
                        </p>
                      )}
                      {aiVariants.map((variant, i) => (
                        <button
                          key={i}
                          type="button"
                          onClick={() => applyAiVariant(variant)}
                          className="block w-full rounded-lg border border-slate-200 bg-white p-3 text-left text-xs hover:border-indigo-300 hover:bg-indigo-50/40"
                        >
                          <p className="font-semibold text-slate-900">{variant.hook}</p>
                          <p className="mt-1 text-slate-600">{variant.caption}</p>
                          <p className="mt-1 font-medium" style={{ color: indigo.accentSolid }}>
                            {variant.cta}
                          </p>
                        </button>
                      ))}
                    </div>
                  )}
                </div>

                <div>
                  <label className="block text-sm font-medium text-slate-700">Creative Media (optional)</label>
                  <input
                    ref={fileInputRef}
                    type="file"
                    accept="image/jpeg,image/png,image/webp,image/gif,video/mp4,video/quicktime,video/webm"
                    className="hidden"
                    onChange={(e) => handleFileSelected(e.target.files?.[0])}
                  />
                  {form.media_url ? (
                    <div className="mt-1 flex items-center gap-3 rounded-xl border border-slate-200 p-2">
                      {form.media_type === 'video' ? (
                        // eslint-disable-next-line jsx-a11y/media-has-caption
                        <video src={form.media_url} className="h-16 w-16 flex-shrink-0 rounded-lg bg-black object-cover" muted />
                      ) : (
                        <img src={form.media_url} alt="Uploaded creative" className="h-16 w-16 flex-shrink-0 rounded-lg object-cover" />
                      )}
                      <div className="min-w-0 flex-1">
                        <p className="truncate text-xs font-medium text-slate-700">Media uploaded</p>
                        <button
                          type="button"
                          onClick={() => fileInputRef.current?.click()}
                          className="text-xs font-semibold"
                          style={{ color: indigo.accentSolid }}
                        >
                          Replace
                        </button>
                      </div>
                      <button
                        type="button"
                        onClick={handleRemoveMedia}
                        className="flex-shrink-0 rounded-lg p-1.5 text-slate-400 hover:bg-red-50 hover:text-red-600"
                        aria-label="Remove media"
                      >
                        <Trash2 className="h-4 w-4" />
                      </button>
                    </div>
                  ) : (
                    <button
                      type="button"
                      onClick={() => fileInputRef.current?.click()}
                      disabled={isUploadingMedia}
                      className="mt-1 flex w-full flex-col items-center justify-center gap-1.5 rounded-xl border-2 border-dashed border-slate-200 px-4 py-6 text-center hover:border-indigo-300 hover:bg-indigo-50/30 disabled:opacity-60"
                    >
                      {isUploadingMedia ? (
                        <>
                          <Loader2 className="h-5 w-5 animate-spin" style={{ color: indigo.accentSolid }} />
                          <span className="text-xs font-medium text-slate-600">Uploading… {uploadProgress}%</span>
                        </>
                      ) : (
                        <>
                          <UploadCloud className="h-5 w-5" style={{ color: indigo.muted }} />
                          <span className="text-xs font-medium text-slate-600">Click to upload an image or video</span>
                          <span className="text-[11px] text-slate-400">JPG, PNG, WEBP, GIF, MP4, MOV, WEBM — up to 50MB</span>
                        </>
                      )}
                    </button>
                  )}
                  {mediaError && <p className="mt-1.5 text-xs font-medium text-red-600">{mediaError}</p>}
                </div>

                <label className="block text-sm font-medium text-slate-700">
                  Headline <span className="text-red-500">*</span>
                  <input
                    type="text"
                    className={inputClass}
                    value={form.headline}
                    onChange={(e) => update({ headline: e.target.value })}
                    placeholder="Get a Free Quote Today"
                  />
                  {fieldError('creative.headline') && <p className="mt-1 text-xs font-medium text-red-600">{fieldError('creative.headline')}</p>}
                </label>
                <label className="block text-sm font-medium text-slate-700">
                  Button
                  <select
                    aria-label="Button"
                    className={inputClass}
                    value={form.call_to_action}
                    onChange={(e) => update({ call_to_action: e.target.value as AdCallToAction })}
                    disabled={AD_CTAS_BY_OBJECTIVE[form.objective].length === 1}
                  >
                    {AD_CTAS_BY_OBJECTIVE[form.objective].map((cta) => (
                      <option key={cta} value={cta}>
                        {AD_CTA_LABELS[cta]}
                      </option>
                    ))}
                  </select>
                  {AD_CTAS_BY_OBJECTIVE[form.objective].length === 1 && <span className="mt-1 block text-xs font-normal text-slate-500">Click-to-WhatsApp ads always use this button.</span>}
                  {fieldError('creative.call_to_action') && <p className="mt-1 text-xs font-medium text-red-600">{fieldError('creative.call_to_action')}</p>}
                </label>
                <label className="block text-sm font-medium text-slate-700">
                  Primary Text <span className="text-red-500">*</span>
                  <textarea
                    className={inputClass}
                    rows={3}
                    value={form.primary_text}
                    onChange={(e) => update({ primary_text: e.target.value })}
                    placeholder="Tell people why they should tap your ad…"
                  />
                  {fieldError('creative.primary_text') && <p className="mt-1 text-xs font-medium text-red-600">{fieldError('creative.primary_text')}</p>}
                </label>
              </div>

              <div className="lg:sticky lg:top-0">
                <p className="mb-2 text-xs font-semibold uppercase tracking-wide" style={{ color: indigo.muted }}>
                  Live Preview
                </p>
                <AdPreview
                  businessName={form.business_name}
                  headline={form.headline}
                  primaryText={form.primary_text}
                  ctaLabel={AD_CTA_LABELS[form.call_to_action]}
                  mediaUrl={form.media_url}
                  mediaType={form.media_type}
                  isSponsored={form.target_goal === 'PAID_LEAD_AD'}
                />
              </div>
            </div>
          )}

        </div>

        <div className="mt-6 flex items-center justify-between">
          <button
            type="button"
            onClick={goBack}
            disabled={step === 0 || isSubmitting}
            className="rounded-lg border border-slate-300 px-4 py-2 text-sm font-medium text-slate-700 hover:bg-slate-50 disabled:opacity-40"
          >
            Back
          </button>
          {step < WIZARD_STEPS.length - 1 ? (
            <button
              type="button"
              onClick={goNext}
              className="rounded-lg px-4 py-2 text-sm font-semibold text-white"
              style={{ background: activeGradient }}
            >
              Next
            </button>
          ) : (
            <button
              type="button"
              onClick={handleLaunch}
              disabled={isSubmitting}
              className="flex items-center gap-2 rounded-lg px-4 py-2 text-sm font-semibold text-white disabled:opacity-60"
              style={{ background: activeGradient }}
            >
              {isSubmitting ? <Loader2 className="h-4 w-4 animate-spin" /> : <Rocket className="h-4 w-4" />}
              Launch Campaign
            </button>
          )}
        </div>
      </div>
    </section>
  );
}

/** Auto-Pause Rule Settings (spec item 3) — inline per-campaign CPL threshold editor in the performance table. */
function CplThresholdEditor({ campaign, onSaved }: { campaign: AdCampaign; onSaved: (c: AdCampaign) => void }) {
  const [value, setValue] = useState(campaign.cpl_threshold !== null ? String(campaign.cpl_threshold) : '');
  const [isSaving, setIsSaving] = useState(false);
  const [error, setError] = useState<string | null>(null);

  const dirty = value !== (campaign.cpl_threshold !== null ? String(campaign.cpl_threshold) : '');

  const save = () => {
    const parsed = value.trim() === '' ? null : Number(value);
    if (parsed !== null && (Number.isNaN(parsed) || parsed < 0)) {
      setError('Invalid value');
      return;
    }
    setError(null);
    setIsSaving(true);
    adsService
      .updateCplThreshold(campaign.id, parsed)
      .then((res) => onSaved(res.data))
      .catch(() => setError('Save failed'))
      .finally(() => setIsSaving(false));
  };

  return (
    <div className="flex items-center gap-1.5">
      <span className="text-xs" style={{ color: indigo.muted }}>
        {campaign.currency ?? 'INR'}
      </span>
      <input
        type="number"
        min="0"
        step="0.01"
        value={value}
        onChange={(e) => setValue(e.target.value)}
        className="w-20 rounded-lg border border-slate-300 px-2 py-1 text-xs text-slate-900 outline-none focus:border-indigo-500 focus:ring-2 focus:ring-indigo-100"
        placeholder="none"
      />
      {dirty && (
        <button
          type="button"
          onClick={save}
          disabled={isSaving}
          className="rounded-md px-2 py-1 text-xs font-semibold text-white disabled:opacity-60"
          style={{ background: activeGradient }}
        >
          {isSaving ? '…' : 'Save'}
        </button>
      )}
      {error && <span className="text-xs text-red-600">{error}</span>}
    </div>
  );
}

interface LaunchNotice {
  title: string;
  message: string;
  action: GateNoticeAction | null;
  /** Offered when the missing asset is only recommended, not required by the backend. */
  continueAnyway: (() => void) | null;
}

/**
 * Final hardening §23 — the two launch actions are always clickable. A click
 * walks the prerequisites the backend itself enforces (MetaAdsService:
 * a connected `meta_ad_account` is required; a `facebook_page` is used when
 * present) and, when one is missing, explains it and links to the Social
 * Accounts connect flow with `returnTo` so the launcher reopens afterwards.
 * This is guidance only — the backend re-checks everything on launch/publish
 * (module, permission, tenant, provider state) and its error is still shown.
 */
export default function MetaAdsPage() {
  const { user, isSuperAdmin, hasPermission, hasModule } = useAuth();
  const { selectedAccountId } = useTenant();
  const superAdmin = isSuperAdmin();
  // Owner request (2026-09-30): with its own Platform account a Super Admin needs no client here.
  const noTenantSelected = superAdmin && selectedAccountId === null && !user?.platform_crm_account;
  const { guard, openPicker, picker } = useClientGate({ platformFallback: true });
  const [searchParams, setSearchParams] = useSearchParams();
  const [checking, setChecking] = useState<'paid' | 'organic' | null>(null);
  const [notice, setNotice] = useState<LaunchNotice | null>(null);
  // Phase 9 Task 6 — the Social Accounts routes also require the `social` capability (capability.guard:social);
  // absent capability map = no invented denial, same as ProtectedRoute.
  const hasSocialCapability = user?.capabilities ? Boolean(user.capabilities.social) : true;
  const canManageSocialAccounts = superAdmin || (hasPermission('manage-social-accounts') && hasModule('social_accounts') && hasSocialCapability);
  // Phase 10 Task 3 — the page is open to social_ads.view (read-only) as well; each action
  // follows its own backend permission (routes/api.php, social/ads group).
  const canLaunchAds = superAdmin || hasPermission('launch-meta-ads') || hasPermission('social_ads.launch');
  const canToggleAds = superAdmin || hasPermission('launch-meta-ads');
  const canEditBudget = superAdmin || hasPermission('launch-meta-ads') || hasPermission('social_ads.edit_budget');
  const canPostOrganic = superAdmin || hasPermission('launch-meta-ads') || canManageSocialAccounts;
  // Phase 9 Task 4 — insights: view-social-analytics + social_accounts module + social capability
  // (absent capability map = no invented denial, same as ProtectedRoute). The backend re-checks all of it.
  const canViewSocialInsights =
    superAdmin || (hasPermission('view-social-analytics') && hasModule('social_accounts') && hasSocialCapability);

  const [campaigns, setCampaigns] = useState<AdCampaign[]>([]);
  const [isLoading, setIsLoading] = useState(true);
  const [pageError, setPageError] = useState<string | null>(null);
  const [isWizardOpen, setIsWizardOpen] = useState(false);
  // Social/Ads Launcher Overhaul — Step 3. Mode Toggle: two independent
  // modals (organic has no budget/targeting/objective steps, so it is
  // NOT folded into LaunchWizardModal's 3-step paid flow — see
  // OrganicPostModal's docblock) rather than one shared wizard with a
  // branching first step.
  const [isOrganicModalOpen, setIsOrganicModalOpen] = useState(false);
  // Phase 9 Task 3 — bumped after a post is published/scheduled so the Organic Posts panel reloads.
  const [organicRefreshKey, setOrganicRefreshKey] = useState(0);
  const [busyId, setBusyId] = useState<number | null>(null);
  const [toast, setToast] = useState<string | null>(null);

  const [search, setSearch] = useState('');
  const [statusFilter, setStatusFilter] = useState('');

  const showToast = useCallback((message: string) => {
    setToast(message);
    setTimeout(() => setToast(null), 4000);
  }, []);

  // Phase 10 Task 2 — a response for a previously selected client never lands on the current one.
  const loadSeq = useRef(0);

  const loadCampaigns = useCallback(() => {
    const seq = ++loadSeq.current;

    if (noTenantSelected) {
      setCampaigns([]);
      setIsLoading(false);
      return;
    }

    setIsLoading(true);
    setPageError(null);
    adsService
      .list()
      .then((rows) => {
        if (seq === loadSeq.current) setCampaigns(rows);
      })
      .catch((err: unknown) => {
        if (seq === loadSeq.current) setPageError(extractErrorMessage(err, 'Failed to load campaigns.'));
      })
      .finally(() => {
        if (seq === loadSeq.current) setIsLoading(false);
      });
  }, [noTenantSelected]);

  // Phase 10 Task 2 — switching client clears everything that belonged to the previous one.
  useEffect(() => {
    setCampaigns([]);
    setIsWizardOpen(false);
    setIsOrganicModalOpen(false);
    setBusyId(null);
  }, [selectedAccountId]);

  useEffect(() => {
    loadCampaigns();
  }, [loadCampaigns, selectedAccountId]);

  const connectAction = (mode: 'paid' | 'organic'): GateNoticeAction | null =>
    canManageSocialAccounts ? { label: 'Connect Meta Account', to: `/social/accounts?returnTo=${encodeURIComponent(`/social/ads?open=${mode}`)}` } : null;
  const askAdmin = canManageSocialAccounts ? '' : ' Ask a team member with access to Social Accounts to connect it.';

  const openPaidWithPrerequisites = useCallback(() => {
    setChecking('paid');
    socialService
      .listAccounts()
      .then((accounts: SocialAccount[]) => {
        const adAccount = accounts.find((a) => a.asset_type === 'meta_ad_account');
        const page = accounts.find((a) => a.asset_type === 'facebook_page');

        if (!adAccount) {
          setNotice({
            title: 'Connect a Meta Ad Account first',
            message: `A paid campaign is created in a Meta Ad Account, and none is connected for this account yet. Connect your Meta account and select the Ad Account (and the Facebook Page) to use; the launcher reopens afterwards.${askAdmin}`,
            action: connectAction('paid'),
            continueAnyway: null,
          });
        } else if (connectionStatusOf(adAccount) !== 'connected') {
          // Phase 9 Task 2 — connection_status also covers a token past its expiry.
          const expired = connectionStatusOf(adAccount) === 'expired';
          setNotice({
            title: 'Reconnect your Meta Ad Account',
            message: `${expired ? 'The Meta Ad Account connection has expired' : 'Access to the Meta Ad Account was revoked'} (${adAccount.status_reason ?? (expired ? 'its access token expired' : 'Meta no longer accepts the connection')}). Reconnect it before launching a campaign.${askAdmin}`,
            action: connectAction('paid'),
            continueAnyway: null,
          });
        } else if (!page) {
          setNotice({
            title: 'No Facebook Page connected',
            message: `Ads normally run on behalf of a Facebook Page, and none is connected. You can connect one first (recommended), or continue — Meta may reject some objectives without a Page.${askAdmin}`,
            action: connectAction('paid'),
            continueAnyway: () => {
              setNotice(null);
              setIsWizardOpen(true);
            },
          });
        } else {
          setIsWizardOpen(true);
        }
      })
      // The check is a convenience: if it cannot run (e.g. no access to the
      // Social Accounts list), the wizard opens and the backend decides on launch.
      .catch(() => setIsWizardOpen(true))
      .finally(() => setChecking(null));
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [canManageSocialAccounts]);

  const openOrganicWithPrerequisites = useCallback(() => {
    setChecking('organic');
    socialService
      .listAccounts()
      .then((accounts: SocialAccount[]) => {
        const publishable = accounts.filter((a) => a.asset_type === 'facebook_page' || a.asset_type === 'instagram' || a.asset_type === 'linkedin_page');
        if (publishable.length === 0) {
          setNotice({
            title: 'Connect a Page or Instagram account first',
            message: `Organic posts are published to a connected Facebook Page, Instagram account or LinkedIn page, and none is connected yet.${askAdmin}`,
            action: connectAction('organic'),
            continueAnyway: null,
          });
        } else if (publishable.every((a) => connectionStatusOf(a) !== 'connected')) {
          // Phase 9 Task 2 — every publishing connection is expired/revoked.
          setNotice({
            title: 'Reconnect your Page or Instagram account',
            message: `Every connected Page / Instagram account needs to be reconnected (expired or access revoked) before a post can be published.${askAdmin}`,
            action: connectAction('organic'),
            continueAnyway: null,
          });
        } else {
          setIsOrganicModalOpen(true);
        }
      })
      .catch(() => setIsOrganicModalOpen(true))
      .finally(() => setChecking(null));
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [canManageSocialAccounts]);

  const startPaid = () => guard(openPaidWithPrerequisites, 'launch a paid Meta ad campaign');
  const startOrganic = () => guard(openOrganicWithPrerequisites, 'publish an organic post');

  // Returning from the Social Accounts connect flow (?open=paid|organic):
  // re-run the same prerequisite check once, then drop the parameter.
  const requestedOpen = searchParams.get('open');
  useEffect(() => {
    if (noTenantSelected || (requestedOpen !== 'paid' && requestedOpen !== 'organic')) return;
    const next = new URLSearchParams(searchParams);
    next.delete('open');
    setSearchParams(next, { replace: true });
    // Phase 10 Task 3 — a read-only user is never dropped into a flow they cannot complete.
    if (requestedOpen === 'paid') {
      if (canLaunchAds) openPaidWithPrerequisites();
    } else if (canPostOrganic) openOrganicWithPrerequisites();
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [requestedOpen, noTenantSelected]);

  const handleLaunched = () => {
    setIsWizardOpen(false);
    showToast('Campaign launched.');
    loadCampaigns();
  };

  const handlePublished = (posts: OrganicPost[]) => {
    const allScheduled = posts.length > 0 && posts.every((p) => p.status === 'scheduled');
    showToast(allScheduled ? 'Post scheduled.' : 'Post published.');
    setOrganicRefreshKey((k) => k + 1);
  };

  const toggleStatus = (campaign: AdCampaign) => {
    if (!canToggle(campaign)) return;
    setBusyId(campaign.id);
    const action = campaign.status === 'ACTIVE' ? adsService.pause(campaign.id) : adsService.resume(campaign.id);

    action
      .then((res) => {
        setCampaigns((prev) => prev.map((c) => (c.id === campaign.id ? res.data : c)));
        showToast(res.message || (campaign.status === 'ACTIVE' ? 'Campaign paused.' : 'Campaign resumed.'));
      })
      .catch((err: unknown) => {
        // Phase 10 Task 2 — a campaign-state error carries the current row (e.g. now UNAVAILABLE).
        const current = errorCampaign(err);
        if (current) setCampaigns((prev) => prev.map((c) => (c.id === current.id ? current : c)));
        showToast(extractErrorCode(err) === 'AD_PROVIDER_OUTCOME_UNKNOWN' ? OUTCOME_UNKNOWN_CHANGE : extractErrorMessage(err, 'Action failed.'));
      })
      .finally(() => setBusyId(null));
  };

  const updateCampaignInPlace = (updated: AdCampaign) => {
    setCampaigns((prev) => prev.map((c) => (c.id === updated.id ? updated : c)));
  };

  const hasCampaigns = useMemo(() => campaigns.length > 0, [campaigns]);

  const filteredCampaigns = useMemo(() => {
    const term = search.trim().toLowerCase();
    return campaigns.filter((c) => {
      if (statusFilter && c.status !== statusFilter) return false;
      if (term && !c.name.toLowerCase().includes(term)) return false;
      return true;
    });
  }, [campaigns, search, statusFilter]);

  const hasActiveFilters = search !== '' || statusFilter !== '';
  const clearFilters = () => {
    setSearch('');
    setStatusFilter('');
  };

  // Metrics are only as fresh as the last CheckAdPerformanceRules cron
  // tick (every 15 min) — see AdCampaignController's docblock on why the
  // list endpoint serves cached figures instead of a live Meta call per
  // page load. Surfaced here so the tenant isn't misled into thinking
  // spend/leads are real-time.
  const lastRefreshedAt = useMemo(() => {
    const timestamps = campaigns.map((c) => c.last_checked_at).filter((t): t is string => t !== null);
    if (timestamps.length === 0) return null;
    return timestamps.reduce((latest, t) => (t > latest ? t : latest));
  }, [campaigns]);

  return (
    <div className="p-6">
      <div className="w-full">
        <div className="mb-6 flex items-center justify-between">
          <div>
            <h1 className="font-display text-lg font-bold" style={{ color: indigo.ink }}>
              Meta Ads Launcher
            </h1>
            <p className="mt-1 text-sm" style={{ color: indigo.muted }}>
              Launch and monitor Meta ad campaigns, with an automatic budget guard against zero-conversion spend and high CPL.
            </p>
          </div>
          {/* Social/Ads Launcher Overhaul — Step 3. Mode Toggle between the
              free Organic Post flow and the paid Meta Ad Campaign wizard,
              per this step's explicit spec item. */}
          <div className="flex items-center gap-2">
            <Link
              to="/social/ads/dashboard"
              data-testid="open-ads-dashboard"
              className="flex items-center gap-2 rounded-lg border px-4 py-2 text-sm font-semibold"
              style={{ borderColor: indigo.border, color: indigo.ink }}
            >
              <BarChart3 className="h-4 w-4" />
              Dashboard
            </Link>
            {canPostOrganic && (
            <button
              onClick={startOrganic}
              aria-busy={checking === 'organic'}
              title={noTenantSelected ? 'You will be asked which client to post for.' : undefined}
              data-testid="open-organic-post"
              className="flex items-center gap-2 rounded-lg border px-4 py-2 text-sm font-semibold"
              style={{ borderColor: indigo.border, color: indigo.ink }}
            >
              {checking === 'organic' ? <Loader2 className="h-4 w-4 animate-spin" /> : <Share2 className="h-4 w-4" />}
              Organic Post
            </button>
            )}
            {canLaunchAds && (
            <button
              onClick={startPaid}
              aria-busy={checking === 'paid'}
              title={noTenantSelected ? 'You will be asked which client to launch it for.' : undefined}
              data-testid="open-paid-campaign"
              className="flex items-center gap-2 rounded-lg px-4 py-2 text-sm font-semibold text-white"
              style={{ background: activeGradient }}
            >
              {checking === 'paid' ? <Loader2 className="h-4 w-4 animate-spin" /> : <Rocket className="h-4 w-4" />}
              Paid Meta Ad Campaign
            </button>
            )}
          </div>
        </div>

        {isWizardOpen ? (
          <LaunchWizardModal
            onClose={() => {
              setIsWizardOpen(false);
              // Phase 10 Task 2 — a failed or unconfirmed launch is a stored row too.
              loadCampaigns();
            }}
            onLaunched={handleLaunched}
            canManageSocialAccounts={canManageSocialAccounts}
          />
        ) : noTenantSelected ? (
          <SelectClientNotice message="Ad campaigns belong to a client. Select one to view or launch their campaigns." onSelect={() => openPicker('view or launch ad campaigns')} />
        ) : (
          <>
            {pageError && (
              <div className="mb-4 flex items-start gap-2 rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700">
                <AlertCircle className="mt-0.5 h-4 w-4 flex-shrink-0" />
                {pageError}
              </div>
            )}

            {hasCampaigns && (
              <p className="mb-2 text-xs" style={{ color: indigo.muted }}>
                Metrics last refreshed by the Auto-Budget Guard: {formatDate(lastRefreshedAt)}
              </p>
            )}

            <div className="mb-4 flex flex-wrap items-center gap-3">
              <SearchInput value={search} onChange={setSearch} placeholder="Search campaigns…" />
              <StatusFilterSelect value={statusFilter} onChange={setStatusFilter} options={CAMPAIGN_STATUS_OPTIONS} allLabel="All statuses" />
              <ClearFiltersButton active={hasActiveFilters} onClear={clearFilters} />
            </div>

            <TableCard>
              <table className="min-w-full divide-y divide-slate-200 text-sm">
                <thead className="bg-slate-50">
                  <tr>
                    <th className="px-4 py-3 text-left font-medium text-slate-600">Campaign</th>
                    <th className="px-4 py-3 text-left font-medium text-slate-600">Status</th>
                    <th className="px-4 py-3 text-left font-medium text-slate-600">Spend</th>
                    <th className="px-4 py-3 text-left font-medium text-slate-600">Leads</th>
                    <th className="px-4 py-3 text-left font-medium text-slate-600">CPL</th>
                    <th className="px-4 py-3 text-left font-medium text-slate-600">Auto-Pause CPL Limit</th>
                    <th className="px-4 py-3 text-right font-medium text-slate-600">Actions</th>
                  </tr>
                </thead>
                <tbody className="divide-y divide-slate-100">
                  {isLoading ? (
                    <tr>
                      <td colSpan={7} className="px-4 py-10 text-center">
                        <Loader2 className="mx-auto h-5 w-5 animate-spin" style={{ color: indigo.muted }} />
                      </td>
                    </tr>
                  ) : !hasCampaigns ? (
                    <tr>
                      <td colSpan={7} className="px-4 py-10 text-center text-sm text-slate-500" data-testid="ads-empty">
                        <p>No campaigns launched yet.</p>
                        {canLaunchAds && (
                        <button
                          type="button"
                          onClick={startPaid}
                          className="mt-3 inline-flex items-center gap-2 rounded-lg px-4 py-2 text-sm font-semibold text-white"
                          style={{ background: activeGradient }}
                        >
                          <Rocket className="h-4 w-4" />
                          Launch your first campaign
                        </button>
                        )}
                      </td>
                    </tr>
                  ) : filteredCampaigns.length === 0 ? (
                    <tr>
                      <td colSpan={7} className="px-4 py-10 text-center text-sm text-slate-500">
                        No campaigns match the current filters.
                      </td>
                    </tr>
                  ) : (
                    filteredCampaigns.map((campaign) => (
                      <tr key={campaign.id}>
                        <td className="px-4 py-3">
                          <p className="font-medium text-slate-900">{campaign.name}</p>
                          <p className="text-xs" style={{ color: indigo.muted }}>
                            {AD_OBJECTIVE_LABELS[campaign.objective]}
                          </p>
                        </td>
                        <td className="px-4 py-3">
                          <span
                            className={`inline-flex items-center rounded-full px-2.5 py-0.5 text-xs font-medium ring-1 ring-inset ${STATUS_BADGE[campaign.status]}`}
                          >
                            {campaign.status}
                          </span>
                          {campaign.auto_paused_at && (
                            <p className="mt-1 max-w-[180px] text-xs text-amber-600" title={campaign.auto_pause_reason ?? undefined}>
                              Auto-paused: {campaign.auto_pause_reason}
                            </p>
                          )}
                          {campaign.last_provider_error && (
                            <p className="mt-1 max-w-[220px] text-xs text-red-600" title={campaign.last_provider_error}>
                              {campaign.last_provider_error}
                            </p>
                          )}
                        </td>
                        <td className="px-4 py-3 text-slate-700">{formatMoney(campaign.spend, campaign.currency)}</td>
                        <td className="px-4 py-3 text-slate-700">{campaign.leads}</td>
                        <td className="px-4 py-3 text-slate-700">{campaign.cpl !== null ? formatMoney(campaign.cpl, campaign.currency) : '—'}</td>
                        <td className="px-4 py-3">
                          {canEditBudget ? (
                            <CplThresholdEditor campaign={campaign} onSaved={updateCampaignInPlace} />
                          ) : (
                            <span className="text-xs text-slate-700">{campaign.cpl_threshold !== null ? formatMoney(campaign.cpl_threshold, campaign.currency) : '—'}</span>
                          )}
                        </td>
                        <td className="px-4 py-3 text-right">
                          {canToggleAds && canToggle(campaign) ? (
                            <button
                              type="button"
                              onClick={() => toggleStatus(campaign)}
                              disabled={busyId === campaign.id}
                              className={`inline-flex items-center gap-1.5 rounded-lg px-3 py-1.5 text-xs font-semibold shadow-sm disabled:opacity-60 ${
                                campaign.status === 'ACTIVE'
                                  ? 'border border-red-200 text-red-600 hover:bg-red-50'
                                  : 'text-white'
                              }`}
                              style={campaign.status === 'ACTIVE' ? undefined : { background: activeGradient }}
                            >
                              {campaign.status === 'ACTIVE' ? <Pause className="h-3.5 w-3.5" /> : <Play className="h-3.5 w-3.5" />}
                              {campaign.status === 'ACTIVE' ? 'Pause' : 'Resume'}
                            </button>
                          ) : (
                            <span className="text-xs" style={{ color: indigo.muted }}>
                              —
                            </span>
                          )}
                        </td>
                      </tr>
                    ))
                  )}
                </tbody>
              </table>
            </TableCard>

            {/* Phase 9 Task 6 — keyed by the selected client: switching client remounts the panel,
                so no post, insight or error of the previous client stays on screen. */}
            {canManageSocialAccounts && <OrganicPostsPanel key={selectedAccountId ?? 'own'} refreshKey={organicRefreshKey} canManageSocialAccounts={canManageSocialAccounts} canViewInsights={canViewSocialInsights} />}
          </>
        )}
      </div>

      {picker}
      {notice && (
        <GateNoticeModal
          testId="ads-prerequisite"
          title={notice.title}
          message={notice.message}
          action={notice.action}
          secondary={notice.continueAnyway ? { label: 'Continue without a Page', onClick: notice.continueAnyway } : null}
          onClose={() => setNotice(null)}
        />
      )}
      {isOrganicModalOpen && <OrganicPostModal onClose={() => setIsOrganicModalOpen(false)} onPublished={handlePublished} canManageSocialAccounts={canManageSocialAccounts} />}

      {toast && (
        <div className="fixed bottom-6 right-6 z-50 rounded-lg bg-slate-900 px-4 py-3 text-sm font-medium text-white shadow-lg">
          {toast}
        </div>
      )}
    </div>
  );
}
