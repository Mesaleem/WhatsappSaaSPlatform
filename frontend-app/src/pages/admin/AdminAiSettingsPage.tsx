import { useCallback, useEffect, useState } from 'react';
import { Link } from 'react-router-dom';
import { ArrowLeft, Bot, CheckCircle2, Loader2, XCircle } from 'lucide-react';
import aiGatewayService from '../../services/aiGatewayService';
import type { AiProvider, AiProviderSettingsRow } from '../../types/ai';
import { extractErrorMessage } from '../../utils/apiError';
import DismissibleAlert from '../../components/common/DismissibleAlert';

const inputClass =
  'mt-1.5 w-full rounded-lg border border-slate-300 px-3 py-2 text-sm text-slate-900 shadow-sm outline-none transition focus:border-indigo-500 focus:ring-2 focus:ring-indigo-100';

const PROVIDER_LABEL: Record<AiProvider, string> = {
  openai: 'OpenAI',
  anthropic: 'Anthropic (Claude)',
  gemini: 'Google Gemini',
  groq: 'Groq',
};

/**
 * A masked secret field with an explicit "Set New Value" toggle — same
 * blank-until-typed / never-prefilled contract as AdminSocialSettingsPage's
 * MaskedSecretField (this page's sibling, same tier); duplicated locally
 * rather than imported since that component is social.ts-shaped and not
 * exported, and the two pages' secret semantics could diverge later.
 */
function MaskedSecretField({
  isSet,
  value,
  onChange,
  placeholder,
}: {
  isSet: boolean;
  value: string;
  onChange: (v: string) => void;
  placeholder: string;
}) {
  const [isEditing, setIsEditing] = useState(false);

  return (
    <div>
      <div className="flex items-center justify-between">
        <label className="text-xs font-medium text-slate-700">API Key</label>
        {!isEditing && (
          <button
            type="button"
            onClick={() => setIsEditing(true)}
            className="text-xs font-medium text-indigo-600 hover:text-indigo-700"
          >
            Set New Value
          </button>
        )}
        {isEditing && (
          <button
            type="button"
            onClick={() => {
              setIsEditing(false);
              onChange('');
            }}
            className="text-xs font-medium text-slate-500 hover:text-slate-700"
          >
            Cancel
          </button>
        )}
      </div>
      {isEditing ? (
        <input
          type="password"
          value={value}
          onChange={(e) => onChange(e.target.value)}
          className={inputClass}
          placeholder={placeholder}
          autoComplete="off"
          autoFocus
        />
      ) : (
        <input
          value={isSet ? '••••••••••••••••' : 'Not set — falls back to the .env credential'}
          disabled
          className={`${inputClass} bg-slate-50 text-slate-400`}
        />
      )}
    </div>
  );
}

interface ProviderCardProps {
  settings: AiProviderSettingsRow;
  onSaved: (updated: AiProviderSettingsRow) => void;
}

/**
 * One AI provider override card (openai / anthropic / gemini / groq).
 * Every field here is OPTIONAL: left blank/"Use .env default", the
 * provider behaves exactly as it does today (config/ai.php, unchanged).
 * Setting a field is the DB-level override Phase 8 Task 12 exists for —
 * fixing a retired/renamed model (the ConnexxaIQ model_not_found
 * incident) or rotating a leaked key with no redeploy.
 */
function ProviderCard({ settings, onSaved }: ProviderCardProps) {
  const [isEnabled, setIsEnabled] = useState<'' | 'true' | 'false'>(
    settings.is_enabled === null ? '' : settings.is_enabled ? 'true' : 'false',
  );
  const [model, setModel] = useState(settings.model ?? '');
  const [baseUrl, setBaseUrl] = useState(settings.base_url ?? '');
  const [apiKey, setApiKey] = useState('');
  const [allowedModels, setAllowedModels] = useState(settings.allowed_models.join(', '));
  const [tokensPerCredit, setTokensPerCredit] = useState(
    settings.tokens_per_credit_override !== null ? String(settings.tokens_per_credit_override) : '',
  );
  const [notes, setNotes] = useState(settings.notes ?? '');

  const [isSaving, setIsSaving] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [success, setSuccess] = useState<string | null>(null);

  const handleSave = async () => {
    setIsSaving(true);
    setError(null);
    setSuccess(null);

    try {
      const result = await aiGatewayService.update(settings.provider, {
        is_enabled: isEnabled === '' ? null : isEnabled === 'true',
        model: model.trim() || null,
        base_url: baseUrl.trim() || null,
        allowed_models: allowedModels.trim()
          ? allowedModels.split(',').map((m) => m.trim()).filter(Boolean)
          : null,
        tokens_per_credit_override: tokensPerCredit.trim() ? Number(tokensPerCredit) : null,
        notes: notes.trim() || null,
        ...(apiKey.trim() ? { api_key: apiKey.trim() } : {}),
      });
      onSaved(result.data);
      setApiKey('');
      setSuccess(`${PROVIDER_LABEL[settings.provider]} settings saved.`);
    } catch (err) {
      setError(extractErrorMessage(err, 'Could not save these settings.'));
    } finally {
      setIsSaving(false);
    }
  };

  const effectivelyEnabled = isEnabled === '' ? null : isEnabled === 'true';

  return (
    <div className="rounded-xl border border-slate-200 bg-white p-6 shadow-sm">
      <div className="flex flex-wrap items-center justify-between gap-3">
        <div className="flex items-center gap-2">
          <h3 className="text-base font-semibold text-slate-900">{PROVIDER_LABEL[settings.provider]}</h3>
          {effectivelyEnabled === null && (
            <span className="inline-flex items-center gap-1 rounded-full border border-slate-200 bg-slate-50 px-2.5 py-0.5 text-xs font-medium text-slate-600">
              Using .env (AI_ENABLED_PROVIDERS)
            </span>
          )}
          {effectivelyEnabled === true && (
            <span className="inline-flex items-center gap-1 rounded-full border border-emerald-200 bg-emerald-50 px-2.5 py-0.5 text-xs font-medium text-emerald-700">
              <CheckCircle2 className="h-3 w-3" /> Enabled (DB override)
            </span>
          )}
          {effectivelyEnabled === false && (
            <span className="inline-flex items-center gap-1 rounded-full border border-red-200 bg-red-50 px-2.5 py-0.5 text-xs font-medium text-red-700">
              <XCircle className="h-3 w-3" /> Disabled (DB override)
            </span>
          )}
        </div>

        <select
          value={isEnabled}
          onChange={(e) => setIsEnabled(e.target.value as '' | 'true' | 'false')}
          className="rounded-lg border border-slate-300 px-2.5 py-1.5 text-xs font-medium text-slate-700 shadow-sm outline-none focus:border-indigo-500 focus:ring-2 focus:ring-indigo-100"
        >
          <option value="">Use .env default</option>
          <option value="true">Force enabled</option>
          <option value="false">Force disabled</option>
        </select>
      </div>

      <div className="mt-4 grid grid-cols-1 gap-4 sm:grid-cols-2">
        <div>
          <label className="text-xs font-medium text-slate-700">Model</label>
          <input
            value={model}
            onChange={(e) => setModel(e.target.value)}
            className={inputClass}
            placeholder="Blank = use the .env model (AI_*_MODEL)"
          />
        </div>
        <MaskedSecretField
          isSet={settings.api_key_set}
          value={apiKey}
          onChange={setApiKey}
          placeholder="Rotate this provider's API key"
        />
        <div>
          <label className="text-xs font-medium text-slate-700">Base URL</label>
          <input
            value={baseUrl}
            onChange={(e) => setBaseUrl(e.target.value)}
            className={inputClass}
            placeholder="Blank = use the .env base URL"
          />
        </div>
        <div>
          <label className="text-xs font-medium text-slate-700">Tokens per credit (pricing override)</label>
          <input
            type="number"
            min={1}
            value={tokensPerCredit}
            onChange={(e) => setTokensPerCredit(e.target.value)}
            className={inputClass}
            placeholder="Blank = use the platform default"
          />
        </div>
        <div className="sm:col-span-2">
          <label className="text-xs font-medium text-slate-700">Allowed models (reference only, comma-separated)</label>
          <input
            value={allowedModels}
            onChange={(e) => setAllowedModels(e.target.value)}
            className={inputClass}
            placeholder="e.g. llama-3.3-70b-versatile, llama-3.1-8b-instant"
          />
          <p className="mt-1 text-xs text-slate-400">
            A catalog for your own reference — not yet enforced anywhere. Journey prompt/agent node model hints are
            still gated separately by AI_JOURNEY_ALLOWED_MODELS.
          </p>
        </div>
        <div className="sm:col-span-2">
          <label className="text-xs font-medium text-slate-700">Notes</label>
          <textarea
            value={notes}
            onChange={(e) => setNotes(e.target.value)}
            rows={2}
            className={inputClass}
            placeholder="e.g. &quot;Switched 2026-10-09: llama-3.1 retired by Groq&quot;"
          />
        </div>
      </div>

      {error && (
        <DismissibleAlert className="mt-4 flex items-center gap-2 rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700">
          <XCircle className="h-4 w-4 flex-shrink-0" />
          {error}
        </DismissibleAlert>
      )}
      {success && (
        <div className="mt-4 flex items-center gap-2 rounded-lg border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-700">
          <CheckCircle2 className="h-4 w-4 flex-shrink-0" />
          {success}
        </div>
      )}

      <div className="mt-4 flex items-center justify-between gap-3">
        {settings.updated_at && (
          <span className="text-xs text-slate-400">Last saved {new Date(settings.updated_at).toLocaleString()}</span>
        )}
        <button
          type="button"
          onClick={() => void handleSave()}
          disabled={isSaving}
          className="flex items-center gap-2 rounded-lg bg-indigo-600 px-4 py-2 text-sm font-semibold text-white shadow-sm hover:bg-indigo-700 disabled:opacity-60"
        >
          {isSaving ? <Loader2 className="h-4 w-4 animate-spin" /> : null}
          Save {PROVIDER_LABEL[settings.provider]} Settings
        </button>
      </div>
    </div>
  );
}

/**
 * Phase 8 Task 12 (continued) — Super Admin platform AI provider/model
 * settings. Route-gated permission="manage-ai-settings" (App.tsx), same
 * split from manage-billing-settings/manage-social-settings as those two
 * are from each other. The actual point of this screen: when a provider
 * retires or renames a hosted model (the ConnexxaIQ model_not_found
 * incident this task exists to avoid) or a key needs rotating, a Super
 * Admin fixes it HERE — no .env edit, no redeploy.
 */
export default function AdminAiSettingsPage() {
  const [settings, setSettings] = useState<AiProviderSettingsRow[]>([]);
  const [isLoading, setIsLoading] = useState(true);
  const [loadError, setLoadError] = useState<string | null>(null);

  const load = useCallback(async () => {
    setIsLoading(true);
    setLoadError(null);
    try {
      const data = await aiGatewayService.list();
      setSettings(data);
    } catch (err) {
      setLoadError(extractErrorMessage(err, 'Failed to load AI provider settings.'));
    } finally {
      setIsLoading(false);
    }
  }, []);

  useEffect(() => {
    void load();
  }, [load]);

  const handleSaved = (provider: AiProvider, updated: AiProviderSettingsRow) => {
    setSettings((prev) => prev.map((s) => (s.provider === provider ? updated : s)));
  };

  return (
    <div className="p-6">
      <div className="w-full space-y-6">
        <div>
          <Link to="/" className="inline-flex items-center gap-1 text-sm text-slate-500 hover:text-slate-700">
            <ArrowLeft className="h-4 w-4" />
            Back to dashboard
          </Link>
          <h1 className="mt-2 flex items-center gap-2 text-xl font-semibold text-slate-900">
            <Bot className="h-5 w-5 text-indigo-600" />
            AI Gateway Settings
          </h1>
          <p className="mt-1 text-sm text-slate-500">
            Platform-level AI provider, model, and key overrides for every feature that uses AI (Journey prompt/
            agent/RAG nodes, the AI Agent Registry, Ad Copywriter). Anything left blank keeps today's .env-configured
            behaviour; a filled-in field takes effect on the next request — no redeploy.
          </p>
        </div>

        {loadError && (
          <DismissibleAlert className="flex items-center gap-2 rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700">
            <XCircle className="h-4 w-4 flex-shrink-0" />
            {loadError}
          </DismissibleAlert>
        )}

        {isLoading ? (
          <div className="flex items-center gap-2 text-sm text-slate-500">
            <Loader2 className="h-4 w-4 animate-spin" />
            Loading AI provider settings…
          </div>
        ) : (
          <div className="space-y-6">
            {settings.map((s) => (
              <ProviderCard key={s.provider} settings={s} onSaved={(u) => handleSaved(s.provider, u)} />
            ))}
          </div>
        )}
      </div>
    </div>
  );
}
