import { useCallback, useEffect, useState } from 'react';
import { Link } from 'react-router-dom';
import {
  ArrowLeft,
  CheckCircle2,
  Copy,
  KeyRound,
  Loader2,
  RefreshCw,
  ShieldCheck,
  XCircle,
} from 'lucide-react';
import socialGatewayService from '../../services/socialGatewayService';
import type { SocialProvider, SocialProviderConfigRow } from '../../types/social';
import { extractErrorMessage } from '../../utils/apiError';

const inputClass =
  'mt-1.5 w-full rounded-lg border border-slate-300 px-3 py-2 text-sm text-slate-900 shadow-sm outline-none transition focus:border-indigo-500 focus:ring-2 focus:ring-indigo-100';

const PROVIDER_LABEL: Record<SocialProvider, string> = {
  meta: 'Meta (Facebook & Instagram)',
  linkedin: 'LinkedIn',
  google: 'Google (YouTube)',
};

/**
 * Social Media Marketing & Meta Ads Automation Expansion — Phase 2.
 * A masked secret field with an explicit "Set New Value" toggle — same
 * blank-until-typed / never-prefilled contract as GatewaySettingsPage's
 * secret inputs, but rendered as a locked display by default (per this
 * phase's spec) rather than an always-editable blank password field.
 */
function MaskedSecretField({
  label,
  isSet,
  value,
  onChange,
  placeholder,
}: {
  label: string;
  isSet: boolean;
  value: string;
  onChange: (v: string) => void;
  placeholder: string;
}) {
  const [isEditing, setIsEditing] = useState(false);

  return (
    <div>
      <div className="flex items-center justify-between">
        <label className="text-xs font-medium text-slate-700">{label}</label>
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
          value={isSet ? '••••••••••••••••' : 'Not set'}
          disabled
          className={`${inputClass} bg-slate-50 text-slate-400`}
        />
      )}
    </div>
  );
}

interface ProviderCardProps {
  config: SocialProviderConfigRow;
  onSaved: (updated: SocialProviderConfigRow) => void;
}

/**
 * One OAuth App credential card per provider (meta / linkedin / google).
 * `client_id` is masked here too — unlike GatewaySettingsPage's Key ID
 * fields, this backend never returns it in plaintext (see
 * SocialGatewayController::present()), so it follows the same
 * MaskedSecretField pattern as client_secret rather than a plain input.
 */
function ProviderCard({ config, onSaved }: ProviderCardProps) {
  const [isActive, setIsActive] = useState(config.is_active);
  const [redirectUri, setRedirectUri] = useState(config.redirect_uri ?? '');
  const [clientId, setClientId] = useState('');
  const [clientSecret, setClientSecret] = useState('');
  const [webhookToken, setWebhookToken] = useState('');

  const [isSaving, setIsSaving] = useState(false);
  const [isRegenerating, setIsRegenerating] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [success, setSuccess] = useState<string | null>(null);
  const [copied, setCopied] = useState(false);

  const handleCopyWebhookUrl = async () => {
    try {
      await navigator.clipboard.writeText(config.webhook_url);
      setCopied(true);
      setTimeout(() => setCopied(false), 2000);
    } catch {
      // Non-fatal — the URL is still visible/selectable in the page.
    }
  };

  const save = async (extra: Partial<{ regenerate_webhook_verify_token: boolean }> = {}) => {
    setError(null);
    setSuccess(null);

    try {
      const result = await socialGatewayService.update(config.provider, {
        is_active: isActive,
        redirect_uri: redirectUri.trim() || null,
        ...(clientId.trim() ? { client_id: clientId.trim() } : {}),
        ...(clientSecret.trim() ? { client_secret: clientSecret.trim() } : {}),
        ...(webhookToken.trim() ? { webhook_verify_token: webhookToken.trim() } : {}),
        ...extra,
      });
      onSaved(result.data);
      setClientId('');
      setClientSecret('');
      setWebhookToken('');
      return result;
    } catch (err) {
      setError(extractErrorMessage(err, 'Could not save these settings.'));
      throw err;
    }
  };

  const handleSave = async () => {
    setIsSaving(true);
    try {
      await save();
      setSuccess(`${PROVIDER_LABEL[config.provider]} settings saved.`);
    } catch {
      // error state already set by save()
    } finally {
      setIsSaving(false);
    }
  };

  const handleRegenerateWebhookToken = async () => {
    setIsRegenerating(true);
    try {
      await save({ regenerate_webhook_verify_token: true });
      setSuccess('Webhook verify token regenerated. Update it in the provider dashboard.');
    } catch {
      // error state already set by save()
    } finally {
      setIsRegenerating(false);
    }
  };

  return (
    <div className="rounded-xl border border-slate-200 bg-white p-6 shadow-sm">
      <div className="flex flex-wrap items-center justify-between gap-3">
        <div className="flex items-center gap-2">
          <h3 className="text-base font-semibold text-slate-900">{PROVIDER_LABEL[config.provider]}</h3>
          {config.is_fully_configured ? (
            <span className="inline-flex items-center gap-1 rounded-full border border-emerald-200 bg-emerald-50 px-2.5 py-0.5 text-xs font-medium text-emerald-700">
              <CheckCircle2 className="h-3 w-3" /> Configured
            </span>
          ) : (
            <span className="inline-flex items-center gap-1 rounded-full border border-amber-200 bg-amber-50 px-2.5 py-0.5 text-xs font-medium text-amber-700">
              <XCircle className="h-3 w-3" /> Not configured
            </span>
          )}
        </div>

        <label className="flex items-center gap-2 text-xs font-medium text-slate-700">
          <input
            type="checkbox"
            checked={isActive}
            onChange={(e) => setIsActive(e.target.checked)}
            className="h-4 w-4 rounded border-slate-300 text-indigo-600 focus:ring-indigo-500"
          />
          Enabled
        </label>
      </div>

      <div className="mt-3 flex flex-wrap items-center gap-2 rounded-lg border border-slate-200 bg-slate-50 px-3 py-2 text-xs text-slate-600">
        <span className="break-all">
          Webhook URL: <span className="font-mono">{config.webhook_url}</span>
        </span>
        <button
          type="button"
          onClick={() => void handleCopyWebhookUrl()}
          className="inline-flex items-center gap-1 font-medium text-indigo-600 hover:text-indigo-700"
        >
          <Copy className="h-3 w-3" />
          {copied ? 'Copied' : 'Copy'}
        </button>
      </div>

      <div className="mt-4 grid grid-cols-1 gap-4 sm:grid-cols-2">
        <MaskedSecretField
          label="Client ID"
          isSet={config.client_id_set}
          value={clientId}
          onChange={setClientId}
          placeholder="App / Client ID"
        />
        <MaskedSecretField
          label="Client Secret"
          isSet={config.client_secret_set}
          value={clientSecret}
          onChange={setClientSecret}
          placeholder="App / Client Secret"
        />
        <div>
          <label className="text-xs font-medium text-slate-700">Redirect URI</label>
          <input
            value={redirectUri}
            onChange={(e) => setRedirectUri(e.target.value)}
            className={inputClass}
            placeholder="https://your-backend/api/social/callback/meta"
          />
        </div>
        <div>
          <div className="flex items-center justify-between">
            <label className="text-xs font-medium text-slate-700">Webhook Verify Token</label>
            <button
              type="button"
              onClick={() => void handleRegenerateWebhookToken()}
              disabled={isRegenerating}
              className="inline-flex items-center gap-1 text-xs font-medium text-indigo-600 hover:text-indigo-700 disabled:opacity-60"
            >
              {isRegenerating ? <Loader2 className="h-3 w-3 animate-spin" /> : <RefreshCw className="h-3 w-3" />}
              Regenerate
            </button>
          </div>
          <MaskedSecretField
            label=""
            isSet={config.webhook_verify_token_set}
            value={webhookToken}
            onChange={setWebhookToken}
            placeholder="Paste a token you've already registered, or Regenerate"
          />
        </div>
      </div>

      {error && (
        <div className="mt-4 flex items-center gap-2 rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700">
          <XCircle className="h-4 w-4 flex-shrink-0" />
          {error}
        </div>
      )}
      {success && (
        <div className="mt-4 flex items-center gap-2 rounded-lg border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-700">
          <CheckCircle2 className="h-4 w-4 flex-shrink-0" />
          {success}
        </div>
      )}

      <div className="mt-4 flex justify-end">
        <button
          type="button"
          onClick={() => void handleSave()}
          disabled={isSaving}
          className="flex items-center gap-2 rounded-lg bg-indigo-600 px-4 py-2 text-sm font-semibold text-white shadow-sm hover:bg-indigo-700 disabled:opacity-60"
        >
          {isSaving ? <Loader2 className="h-4 w-4 animate-spin" /> : <KeyRound className="h-4 w-4" />}
          Save {PROVIDER_LABEL[config.provider]} Settings
        </button>
      </div>
    </div>
  );
}

/**
 * Social Media Marketing & Meta Ads Automation Expansion — Phase 2.
 * Super Admin platform Meta/LinkedIn/Google OAuth App credential vault.
 * Route-gated permission="manage-social-settings" (App.tsx) — separate
 * from manage-billing-settings, same split as this page's sibling
 * GatewaySettingsPage vs manage-subscriptions.
 */
export default function AdminSocialSettingsPage() {
  const [configs, setConfigs] = useState<SocialProviderConfigRow[]>([]);
  const [isLoading, setIsLoading] = useState(true);
  const [loadError, setLoadError] = useState<string | null>(null);

  const load = useCallback(async () => {
    setIsLoading(true);
    setLoadError(null);
    try {
      const data = await socialGatewayService.list();
      setConfigs(data);
    } catch (err) {
      setLoadError(extractErrorMessage(err, 'Failed to load social provider settings.'));
    } finally {
      setIsLoading(false);
    }
  }, []);

  useEffect(() => {
    void load();
  }, [load]);

  const handleSaved = (provider: SocialProvider, updated: SocialProviderConfigRow) => {
    setConfigs((prev) => prev.map((c) => (c.provider === provider ? updated : c)));
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
            <ShieldCheck className="h-5 w-5 text-indigo-600" />
            Social Gateway Settings
          </h1>
          <p className="mt-1 text-sm text-slate-500">
            Platform-level Meta, LinkedIn, and Google OAuth App credentials used by every tenant's Social Accounts
            connection flow. Secrets are encrypted at rest and never shown again after saving.
          </p>
        </div>

        {loadError && (
          <div className="flex items-center gap-2 rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700">
            <XCircle className="h-4 w-4 flex-shrink-0" />
            {loadError}
          </div>
        )}

        {isLoading ? (
          <div className="flex items-center gap-2 text-sm text-slate-500">
            <Loader2 className="h-4 w-4 animate-spin" />
            Loading social provider settings…
          </div>
        ) : (
          <div className="space-y-6">
            {configs.map((config) => (
              <ProviderCard key={config.provider} config={config} onSaved={(u) => handleSaved(config.provider, u)} />
            ))}
          </div>
        )}
      </div>
    </div>
  );
}
