import { useCallback, useEffect, useMemo, useRef, useState } from 'react';
import {
  AlertCircle,
  Building2,
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
import adsService from '../../services/adsService';
import aiService from '../../services/aiService';
import mediaService from '../../services/mediaService';
import { TableCard, inputClass } from '../../components/common/Card';
import { ClearFiltersButton, SearchInput, StatusFilterSelect } from '../../components/common/DataTableControls';
import AdPreview from '../../components/social/AdPreview';
import OrganicPostModal from '../../components/social/OrganicPostModal';
import { extractErrorMessage } from '../../utils/apiError';
import { indigo, activeGradient } from '../../theme/signalIndigo';
import { AD_OBJECTIVE_LABELS } from '../../types/ads';
import type { AdCampaign, AdObjective, LaunchCampaignPayload } from '../../types/ads';
import { AD_COPY_TONES, TARGET_GOAL_LABELS } from '../../types/ai';
import type { AdCopyTone, AdCopyVariant, TargetGoal } from '../../types/ai';
import type { MediaType } from '../../types/media';

const STATUS_BADGE: Record<AdCampaign['status'], string> = {
  ACTIVE: 'bg-emerald-50 text-emerald-700 ring-emerald-600/20',
  PAUSED: 'bg-slate-100 text-slate-600 ring-slate-500/20',
};

/** Global Table Filters refactor — Meta Ads had no filter controls at all before this. */
const CAMPAIGN_STATUS_OPTIONS = [
  { value: 'ACTIVE', label: 'Active' },
  { value: 'PAUSED', label: 'Paused' },
];

function formatMoney(value: number): string {
  return `$${value.toFixed(2)}`;
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
  countries: string;
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
}

const WIZARD_DEFAULTS: WizardState = {
  objective: 'LEAD_GENERATION',
  daily_budget: '',
  cpl_threshold: '',
  countries: 'IN',
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
};

/** Mirrors MetaAdsService::createAdCreative()'s exact CTA-type mapping so the live preview never shows a button the launched ad wouldn't actually have. */
const OBJECTIVE_CTA_LABEL: Record<AdObjective, string> = {
  LEAD_GENERATION: 'Sign Up',
  MESSAGES: 'Learn More',
  TRAFFIC: 'Learn More',
  // Step 4 (Click-to-WhatsApp Ads) — mirrors MetaAdsService::createAdCreative()'s ctaTypeMap.
  CLICK_TO_WHATSAPP: 'Send WhatsApp Message',
};

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
}: {
  onClose: () => void;
  onLaunched: () => void;
}) {
  const [step, setStep] = useState(0);
  const [form, setForm] = useState<WizardState>(WIZARD_DEFAULTS);
  const [stepError, setStepError] = useState<string | null>(null);
  const [isSubmitting, setIsSubmitting] = useState(false);
  const [submitError, setSubmitError] = useState<string | null>(null);

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

  const update = (patch: Partial<WizardState>) => setForm((prev) => ({ ...prev, ...patch }));

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
    }
    if (step === 1) {
      if (parseList(form.countries).length === 0) return 'Enter at least one country code (e.g. IN, US).';
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
      return;
    }

    const payload: LaunchCampaignPayload = {
      campaign_name: form.campaign_name.trim(),
      objective: form.objective,
      daily_budget: Number(form.daily_budget),
      cpl_threshold: form.cpl_threshold ? Number(form.cpl_threshold) : null,
      targeting_specs: {
        countries: parseList(form.countries),
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
      },
    };

    setSubmitError(null);
    setIsSubmitting(true);

    adsService
      .launch(payload)
      .then(() => {
        onLaunched();
      })
      .catch((err: unknown) => {
        setSubmitError(extractErrorMessage(err, 'Failed to launch the campaign.'));
      })
      .finally(() => setIsSubmitting(false));
  };

  return (
    <div className="fixed inset-0 z-50 flex items-center justify-center bg-slate-900/50 p-4">
      <div className={`w-full rounded-2xl bg-white p-6 shadow-xl transition-all ${step === 2 ? 'max-w-3xl' : 'max-w-lg'}`}>
        <div className="flex items-center justify-between">
          <h2 className="font-display text-base font-bold" style={{ color: indigo.ink }}>
            Launch Meta Ads Campaign
          </h2>
          <button onClick={onClose} className="rounded-md p-1 text-slate-400 hover:bg-slate-100 hover:text-slate-600" aria-label="Close">
            <X className="h-5 w-5" />
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
            <div className="flex items-start gap-2 rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700">
              <AlertCircle className="mt-0.5 h-4 w-4 flex-shrink-0" />
              {stepError}
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
              <label className="block text-sm font-medium text-slate-700">
                Daily Budget (USD) <span className="text-red-500">*</span>
                <input
                  type="number"
                  min="1"
                  step="0.01"
                  className={inputClass}
                  value={form.daily_budget}
                  onChange={(e) => update({ daily_budget: e.target.value })}
                  placeholder="25.00"
                />
              </label>
              <label className="block text-sm font-medium text-slate-700">
                CPL Auto-Pause Threshold (optional)
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
              <label className="block text-sm font-medium text-slate-700">
                Countries (comma-separated ISO codes) <span className="text-red-500">*</span>
                <input
                  type="text"
                  className={inputClass}
                  value={form.countries}
                  onChange={(e) => update({ countries: e.target.value })}
                  placeholder="IN, US"
                />
              </label>
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
                  ctaLabel={OBJECTIVE_CTA_LABEL[form.objective]}
                  mediaUrl={form.media_url}
                  mediaType={form.media_type}
                  isSponsored={form.target_goal === 'PAID_LEAD_AD'}
                />
              </div>
            </div>
          )}

          {submitError && (
            <div className="flex items-start gap-2 rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700">
              <AlertCircle className="mt-0.5 h-4 w-4 flex-shrink-0" />
              {submitError}
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
    </div>
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
        $
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

export default function MetaAdsPage() {
  const { isSuperAdmin } = useAuth();
  const { selectedAccountId } = useTenant();
  const superAdmin = isSuperAdmin();
  const noTenantSelected = superAdmin && selectedAccountId === null;

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
  const [busyId, setBusyId] = useState<number | null>(null);
  const [toast, setToast] = useState<string | null>(null);

  const [search, setSearch] = useState('');
  const [statusFilter, setStatusFilter] = useState('');

  const showToast = useCallback((message: string) => {
    setToast(message);
    setTimeout(() => setToast(null), 4000);
  }, []);

  const loadCampaigns = useCallback(() => {
    if (noTenantSelected) {
      setCampaigns([]);
      setIsLoading(false);
      return;
    }

    setIsLoading(true);
    setPageError(null);
    adsService
      .list()
      .then(setCampaigns)
      .catch((err: unknown) => setPageError(extractErrorMessage(err, 'Failed to load campaigns.')))
      .finally(() => setIsLoading(false));
  }, [noTenantSelected]);

  useEffect(() => {
    loadCampaigns();
  }, [loadCampaigns, selectedAccountId]);

  const handleLaunched = () => {
    setIsWizardOpen(false);
    showToast('Campaign launched.');
    loadCampaigns();
  };

  const handlePublished = () => {
    showToast('Post published.');
  };

  const toggleStatus = (campaign: AdCampaign) => {
    setBusyId(campaign.id);
    const action = campaign.status === 'ACTIVE' ? adsService.pause(campaign.id) : adsService.resume(campaign.id);

    action
      .then((res) => {
        setCampaigns((prev) => prev.map((c) => (c.id === campaign.id ? res.data : c)));
        showToast(campaign.status === 'ACTIVE' ? 'Campaign paused.' : 'Campaign resumed.');
      })
      .catch((err: unknown) => showToast(extractErrorMessage(err, 'Action failed.')))
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
            <button
              onClick={() => setIsOrganicModalOpen(true)}
              disabled={noTenantSelected}
              title={noTenantSelected ? 'Select a client above first' : undefined}
              className="flex items-center gap-2 rounded-lg border px-4 py-2 text-sm font-semibold disabled:opacity-60"
              style={{ borderColor: indigo.border, color: indigo.ink }}
            >
              <Share2 className="h-4 w-4" />
              Organic Post
            </button>
            <button
              onClick={() => setIsWizardOpen(true)}
              disabled={noTenantSelected}
              title={noTenantSelected ? 'Select a client above first' : undefined}
              className="flex items-center gap-2 rounded-lg px-4 py-2 text-sm font-semibold text-white disabled:opacity-60"
              style={{ background: activeGradient }}
            >
              <Rocket className="h-4 w-4" />
              Paid Meta Ad Campaign
            </button>
          </div>
        </div>

        {noTenantSelected ? (
          <div className="flex items-start gap-3 rounded-xl border border-slate-200 bg-slate-50 px-4 py-3 text-sm" style={{ color: indigo.muted }}>
            <Building2 className="mt-0.5 h-4 w-4 flex-shrink-0" />
            Select a client from the switcher at the top of the page to view or launch their ad campaigns.
          </div>
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
                      <td colSpan={7} className="px-4 py-10 text-center text-sm text-slate-500">
                        No campaigns launched yet.
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
                        </td>
                        <td className="px-4 py-3 text-slate-700">{formatMoney(campaign.spend)}</td>
                        <td className="px-4 py-3 text-slate-700">{campaign.leads}</td>
                        <td className="px-4 py-3 text-slate-700">{campaign.cpl !== null ? formatMoney(campaign.cpl) : '—'}</td>
                        <td className="px-4 py-3">
                          <CplThresholdEditor campaign={campaign} onSaved={updateCampaignInPlace} />
                        </td>
                        <td className="px-4 py-3 text-right">
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
                        </td>
                      </tr>
                    ))
                  )}
                </tbody>
              </table>
            </TableCard>
          </>
        )}
      </div>

      {isWizardOpen && <LaunchWizardModal onClose={() => setIsWizardOpen(false)} onLaunched={handleLaunched} />}
      {isOrganicModalOpen && <OrganicPostModal onClose={() => setIsOrganicModalOpen(false)} onPublished={handlePublished} />}

      {toast && (
        <div className="fixed bottom-6 right-6 z-50 rounded-lg bg-slate-900 px-4 py-3 text-sm font-medium text-white shadow-lg">
          {toast}
        </div>
      )}
    </div>
  );
}
