import { useCallback, useEffect, useState, type FormEvent } from 'react';
import { AxiosError } from 'axios';
import {
  CheckCircle2,
  Copy,
  Eye,
  EyeOff,
  KeyRound,
  Loader2,
  ShieldCheck,
  XCircle,
} from 'lucide-react';
import { useAuth } from '../../core/context/AuthContext';
import whatsappService from '../../services/whatsappService';
import type { ApiErrorResponse } from '../../types/auth';
import type { MetaConfigResponse, TestMetaConnectionResult } from '../../types/whatsapp';

const inputClass =
  'mt-1.5 w-full rounded-lg border border-slate-300 px-3 py-2 text-sm text-slate-900 shadow-sm outline-none transition focus:border-indigo-500 focus:ring-2 focus:ring-indigo-100';

/** Narrows an AxiosError's response body when it matches the test-connection result shape. */
function isTestResultBody(data: unknown): data is TestMetaConnectionResult {
  return typeof data === 'object' && data !== null && 'success' in data;
}

/**
 * Module 5 — Meta Cloud API credential configuration card.
 *
 * Renders inside WhatsAppSetupPage when engineType === 'meta'. The backend
 * route (/whatsapp/meta-config*) is gated to role:Admin — Super Admin has
 * no tenant account to configure (account_id is null), so this component
 * only offers the form to Admins, matching the backend gate exactly.
 */
export default function MetaConfigCard() {
  const { hasRole, isReadOnly } = useAuth();
  const isAdmin = hasRole('admin');

  const [config, setConfig] = useState<MetaConfigResponse | null>(null);
  const [isLoading, setIsLoading] = useState(true);
  const [loadError, setLoadError] = useState<string | null>(null);

  const [phoneNumberId, setPhoneNumberId] = useState('');
  const [wabaId, setWabaId] = useState('');
  const [accessToken, setAccessToken] = useState('');
  const [showToken, setShowToken] = useState(false);

  const [isTesting, setIsTesting] = useState(false);
  const [testResult, setTestResult] = useState<TestMetaConnectionResult | null>(null);

  const [isSaving, setIsSaving] = useState(false);
  const [saveError, setSaveError] = useState<string | null>(null);
  const [saveSuccess, setSaveSuccess] = useState<string | null>(null);

  const [copied, setCopied] = useState(false);

  const loadConfig = useCallback(async () => {
    if (!isAdmin) {
      setIsLoading(false);
      return;
    }
    setIsLoading(true);
    setLoadError(null);
    try {
      const data = await whatsappService.getMetaConfig();
      setConfig(data);
      setPhoneNumberId(data.meta_phone_number_id ?? '');
      setWabaId(data.meta_waba_id ?? '');
    } catch (err) {
      const axiosErr = err as AxiosError<ApiErrorResponse>;
      setLoadError(axiosErr.response?.data?.message ?? 'Failed to load Meta configuration.');
    } finally {
      setIsLoading(false);
    }
  }, [isAdmin]);

  useEffect(() => {
    void loadConfig();
  }, [loadConfig]);

  const handleTestConnection = async () => {
    setTestResult(null);
    if (!phoneNumberId.trim() || !accessToken.trim()) {
      setTestResult({ success: false, error: 'Enter both Phone Number ID and Access Token first.' });
      return;
    }
    setIsTesting(true);
    try {
      const result = await whatsappService.testMetaConnection({
        meta_phone_number_id: phoneNumberId.trim(),
        meta_access_token: accessToken.trim(),
      });
      setTestResult(result);
    } catch (err) {
      const axiosErr = err as AxiosError<unknown>;
      const body = axiosErr.response?.data;
      if (isTestResultBody(body)) {
        setTestResult(body);
      } else {
        setTestResult({ success: false, error: 'Could not reach the server to test this connection.' });
      }
    } finally {
      setIsTesting(false);
    }
  };

  const handleSave = async (event: FormEvent) => {
    event.preventDefault();
    setSaveError(null);
    setSaveSuccess(null);

    if (!phoneNumberId.trim() || !wabaId.trim() || !accessToken.trim()) {
      setSaveError('Phone Number ID, WABA ID and Access Token are all required.');
      return;
    }

    setIsSaving(true);
    try {
      const result = await whatsappService.saveMetaConfig({
        meta_phone_number_id: phoneNumberId.trim(),
        meta_waba_id: wabaId.trim(),
        meta_access_token: accessToken.trim(),
      });
      setConfig(result);
      setAccessToken('');
      setTestResult(null);
      setSaveSuccess(
        result.verified_name ? `Saved and verified as "${result.verified_name}".` : 'Meta credentials saved.',
      );
    } catch (err) {
      const axiosErr = err as AxiosError<ApiErrorResponse>;
      setSaveError(
        axiosErr.response?.data?.message ?? 'Could not save these credentials. Please try again.',
      );
    } finally {
      setIsSaving(false);
    }
  };

  const handleCopyWebhookUrl = async () => {
    if (!config?.webhook_url) return;
    try {
      await navigator.clipboard.writeText(config.webhook_url);
      setCopied(true);
      setTimeout(() => setCopied(false), 2000);
    } catch {
      // Clipboard API can be unavailable (permissions, insecure context) —
      // the URL is still visible and selectable in the field, so this is
      // a non-fatal, silent fallback.
    }
  };

  if (!isAdmin) {
    return (
      <div className="mt-6 rounded-lg border border-slate-200 bg-slate-50 px-4 py-3 text-sm text-slate-600">
        Only an account Admin can view or manage Meta Cloud API credentials.
      </div>
    );
  }

  if (isLoading) {
    return (
      <div className="mt-6 flex items-center gap-2 text-sm text-slate-500">
        <Loader2 className="h-4 w-4 animate-spin" />
        Loading Meta configuration…
      </div>
    );
  }

  return (
    <div className="mt-6 border-t border-slate-200 pt-6">
      {loadError && (
        <div className="mb-4 flex items-center gap-2 rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700">
          <XCircle className="h-4 w-4 flex-shrink-0" />
          {loadError}
        </div>
      )}

      <div className="flex items-center gap-2">
        {config?.configured ? (
          <>
            <CheckCircle2 className="h-4 w-4 text-emerald-500" />
            <span className="text-sm font-medium text-slate-900">Meta Cloud API configured</span>
          </>
        ) : (
          <>
            <XCircle className="h-4 w-4 text-amber-500" />
            <span className="text-sm font-medium text-slate-900">Meta Cloud API not configured yet</span>
          </>
        )}
      </div>

      {config?.configured && (
        <div className="mt-3 space-y-1 rounded-lg border border-slate-200 bg-slate-50 px-4 py-3 text-sm text-slate-600">
          <p>
            Current token: <span className="font-mono">{config.meta_access_token_masked}</span>
          </p>
          <div className="flex items-center gap-2">
            <p>
              Webhook URL: <span className="font-mono">{config.webhook_url}</span>
            </p>
            <button
              type="button"
              onClick={() => void handleCopyWebhookUrl()}
              className="inline-flex items-center gap-1 text-xs font-medium text-indigo-600 hover:text-indigo-700"
            >
              <Copy className="h-3 w-3" />
              {copied ? 'Copied' : 'Copy'}
            </button>
          </div>
          {config.meta_webhook_verify_token && (
            <p>
              Verify token: <span className="font-mono">{config.meta_webhook_verify_token}</span>
            </p>
          )}
          <p className="pt-1 text-xs text-slate-500">
            Paste the webhook URL and verify token into your Meta App Dashboard's WhatsApp product
            webhook configuration.
          </p>
        </div>
      )}

      <form onSubmit={(e) => void handleSave(e)} className="mt-5 space-y-4">
        <div className="grid grid-cols-1 gap-4 md:grid-cols-2">
          <div>
            <label className="text-sm font-medium text-slate-700">Phone Number ID <span className="text-red-500">*</span></label>
            <input
              type="text"
              value={phoneNumberId}
              onChange={(e) => setPhoneNumberId(e.target.value)}
              placeholder="e.g. 109876543210987"
              className={inputClass}
            />
          </div>

          <div>
            <label className="text-sm font-medium text-slate-700">WhatsApp Business Account ID (WABA ID) <span className="text-red-500">*</span></label>
            <input
              type="text"
              value={wabaId}
              onChange={(e) => setWabaId(e.target.value)}
              placeholder="e.g. 123456789012345"
              className={inputClass}
            />
          </div>
        </div>

        <div>
          <label className="text-sm font-medium text-slate-700">Permanent Access Token <span className="text-red-500">*</span></label>
          <div className="relative">
            <input
              type={showToken ? 'text' : 'password'}
              value={accessToken}
              onChange={(e) => setAccessToken(e.target.value)}
              placeholder={
                config?.configured ? 'Leave filled in only to replace the saved token' : 'EAAG...'
              }
              className={`${inputClass} pr-10`}
              autoComplete="off"
            />
            <button
              type="button"
              onClick={() => setShowToken((v) => !v)}
              className="absolute right-2 top-1/2 -translate-y-1/2 text-slate-400 hover:text-slate-600"
              tabIndex={-1}
            >
              {showToken ? <EyeOff className="h-4 w-4" /> : <Eye className="h-4 w-4" />}
            </button>
          </div>
          <p className="mt-1 text-xs text-slate-500">
            Never displayed again after saving — only a masked preview is shown.
          </p>
        </div>

        {testResult && (
          <div
            className={`flex items-start gap-2 rounded-lg border px-4 py-3 text-sm ${
              testResult.success
                ? 'border-emerald-200 bg-emerald-50 text-emerald-700'
                : 'border-red-200 bg-red-50 text-red-700'
            }`}
          >
            {testResult.success ? (
              <ShieldCheck className="mt-0.5 h-4 w-4 flex-shrink-0" />
            ) : (
              <XCircle className="mt-0.5 h-4 w-4 flex-shrink-0" />
            )}
            <span>
              {testResult.success
                ? `Connection verified${testResult.verified_name ? ` — ${testResult.verified_name}` : ''}${
                    testResult.display_phone_number ? ` (${testResult.display_phone_number})` : ''
                  }.`
                : testResult.error ?? 'Connection test failed.'}
            </span>
          </div>
        )}

        {saveError && (
          <div className="flex items-center gap-2 rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700">
            <XCircle className="h-4 w-4 flex-shrink-0" />
            {saveError}
          </div>
        )}

        {saveSuccess && (
          <div className="flex items-center gap-2 rounded-lg border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-700">
            <CheckCircle2 className="h-4 w-4 flex-shrink-0" />
            {saveSuccess}
          </div>
        )}

        <div className="flex items-center gap-3 pt-1">
          <button
            type="button"
            onClick={() => void handleTestConnection()}
            disabled={isTesting || isSaving}
            className="flex items-center gap-2 rounded-lg border border-slate-300 px-4 py-2 text-sm font-medium text-slate-700 hover:bg-slate-50 disabled:opacity-60"
          >
            {isTesting ? <Loader2 className="h-4 w-4 animate-spin" /> : <ShieldCheck className="h-4 w-4" />}
            Test Connection
          </button>

          <button
            type="submit"
            disabled={isSaving || isTesting || isReadOnly()}
            title={isReadOnly() ? 'Action disabled: Subscription expired.' : undefined}
            className="flex items-center gap-2 rounded-lg bg-indigo-600 px-4 py-2 text-sm font-semibold text-white shadow-sm hover:bg-indigo-700 disabled:opacity-60"
          >
            {isSaving ? <Loader2 className="h-4 w-4 animate-spin" /> : <KeyRound className="h-4 w-4" />}
            {config?.configured ? 'Update Credentials' : 'Save Credentials'}
          </button>
        </div>
      </form>
    </div>
  );
}
