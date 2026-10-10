import { useCallback, useEffect, useState, type FormEvent } from 'react';
import { CheckCircle2, LogIn, Loader2, XCircle } from 'lucide-react';
import { useAuth } from '../../core/context/AuthContext';
import whatsappService from '../../services/whatsappService';
import type { MetaAppCredentialsResponse } from '../../types/whatsapp';
import { describeApiError } from '../../utils/apiError';
import { loadFacebookSdk, waitForEmbeddedSignupMessage } from '../../utils/facebookSdk';
import DismissibleAlert from '../common/DismissibleAlert';

const inputClass =
  'mt-1.5 w-full rounded-lg border border-slate-300 px-3 py-2 text-sm text-slate-900 shadow-sm outline-none transition focus:border-indigo-500 focus:ring-2 focus:ring-indigo-100';

/**
 * Phase 1 — Meta Channel Creation / Embedded Signup (per-tenant Meta App
 * model, confirmed with the user over the shared-platform-App alternative
 * — see the roadmap doc's Phase 1 / Open Questions). Two steps:
 *
 *   1. The tenant pastes their own Facebook App's ID/Secret/Embedded-
 *      Signup Config ID (created by them in Meta's Developer Console —
 *      that creation step itself happens outside this app, on Meta's
 *      site, and can take days if App Review is still pending).
 *   2. "Connect with Facebook" — FB.login() opens Meta's hosted Embedded
 *      Signup flow; on completion this exchanges the returned code for a
 *      channel connection via MetaConfigController::oauthExchange().
 *
 * Renders above MetaConfigCard, which stays as the manual/advanced
 * fallback (e.g. for a tenant who already has a long-lived token from
 * some other setup). Both write to the same WhatsAppSession row, so
 * either path leaves the tenant in the same "configured" state.
 */
export default function MetaEmbeddedSignupCard() {
  const { hasRole } = useAuth();
  const isAdmin = hasRole('admin');

  const [appConfig, setAppConfig] = useState<MetaAppCredentialsResponse | null>(null);
  const [isLoading, setIsLoading] = useState(true);
  const [loadError, setLoadError] = useState<string | null>(null);

  const [metaAppId, setMetaAppId] = useState('');
  const [metaAppSecret, setMetaAppSecret] = useState('');
  const [metaConfigId, setMetaConfigId] = useState('');
  const [fieldErrors, setFieldErrors] = useState<Record<string, string>>({});

  const [isSavingApp, setIsSavingApp] = useState(false);
  const [saveAppError, setSaveAppError] = useState<string | null>(null);
  const [saveAppSuccess, setSaveAppSuccess] = useState<string | null>(null);

  const [isConnecting, setIsConnecting] = useState(false);
  const [connectError, setConnectError] = useState<string | null>(null);
  const [connectSuccess, setConnectSuccess] = useState<string | null>(null);

  const loadAppConfig = useCallback(async () => {
    if (!isAdmin) {
      setIsLoading(false);
      return;
    }
    setIsLoading(true);
    setLoadError(null);
    try {
      const data = await whatsappService.getMetaAppCredentials();
      setAppConfig(data);
      setMetaAppId(data.meta_app_id ?? '');
      setMetaConfigId(data.meta_config_id ?? '');
    } catch (err) {
      setLoadError(describeApiError(err, 'Could not load your Meta App credentials.').message);
    } finally {
      setIsLoading(false);
    }
  }, [isAdmin]);

  useEffect(() => {
    void loadAppConfig();
  }, [loadAppConfig]);

  const clearFieldError = (field: string) => {
    setFieldErrors((prev) => {
      if (!(field in prev)) return prev;
      const next = { ...prev };
      delete next[field];
      return next;
    });
  };

  const handleSaveAppCredentials = async (event: FormEvent) => {
    event.preventDefault();
    if (isSavingApp) return;

    setSaveAppError(null);
    setSaveAppSuccess(null);

    const nextFieldErrors: Record<string, string> = {};
    if (!metaAppId.trim()) nextFieldErrors.meta_app_id = 'App ID is required.';
    if (!metaAppSecret.trim()) nextFieldErrors.meta_app_secret = 'App Secret is required.';
    if (!metaConfigId.trim()) nextFieldErrors.meta_config_id = 'Embedded Signup Config ID is required.';

    if (Object.keys(nextFieldErrors).length > 0) {
      setFieldErrors(nextFieldErrors);
      return;
    }

    setFieldErrors({});
    setIsSavingApp(true);
    try {
      const result = await whatsappService.saveMetaAppCredentials({
        meta_app_id: metaAppId.trim(),
        meta_app_secret: metaAppSecret.trim(),
        meta_config_id: metaConfigId.trim(),
      });
      setAppConfig({ configured: true, meta_app_id: result.meta_app_id, meta_config_id: result.meta_config_id });
      setMetaAppSecret('');
      setSaveAppSuccess('App credentials saved.');
    } catch (err) {
      const described = describeApiError(err, 'Could not verify this App ID / App Secret pair with Meta.');
      setFieldErrors(described.fieldErrors);
      setSaveAppError(described.message);
    } finally {
      setIsSavingApp(false);
    }
  };

  const handleConnectWithFacebook = async () => {
    if (!appConfig?.configured) return;

    setConnectError(null);
    setConnectSuccess(null);
    setIsConnecting(true);

    try {
      const { meta_app_id, meta_config_id } = await whatsappService.startMetaOAuth();

      await loadFacebookSdk(meta_app_id);

      const code = await new Promise<string>((resolve, reject) => {
        if (!window.FB) {
          reject(new Error('Facebook SDK did not load.'));
          return;
        }
        window.FB.login(
          (response) => {
            if (response.authResponse?.code) {
              resolve(response.authResponse.code);
            } else {
              reject(new Error('Sign-in with Meta did not complete.'));
            }
          },
          {
            config_id: meta_config_id,
            response_type: 'code',
            override_default_response_type: true,
            extras: { sessionInfoVersion: '3' },
          },
        );
      });

      const { wabaId, phoneNumberId } = await waitForEmbeddedSignupMessage();

      const result = await whatsappService.exchangeMetaOAuthCode({
        code,
        waba_id: wabaId,
        phone_number_id: phoneNumberId,
      });

      setConnectSuccess(result.message || 'WhatsApp channel connected.');
    } catch (err) {
      const described = describeApiError(err, 'Could not complete sign-in with Meta.');
      setConnectError(err instanceof Error && !('response' in err) ? err.message : described.message);
    } finally {
      setIsConnecting(false);
    }
  };

  if (!isAdmin) return null;

  if (isLoading) {
    return (
      <div className="mt-6 flex items-center gap-2 text-sm text-slate-500">
        <Loader2 className="h-4 w-4 animate-spin" />
        Loading…
      </div>
    );
  }

  return (
    <div className="mt-6 border-t border-slate-200 pt-6">
      <div className="flex items-center gap-2">
        {appConfig?.configured ? (
          <CheckCircle2 className="h-4 w-4 text-emerald-500" />
        ) : (
          <XCircle className="h-4 w-4 text-amber-500" />
        )}
        <span className="text-sm font-medium text-slate-900">Connect with Facebook (recommended)</span>
      </div>
      <p className="mt-1 text-xs text-slate-500">
        Create your own Facebook App for WhatsApp Embedded Signup in Meta's Developer Console first (App
        Review approval for WhatsApp permissions may take a few days), then paste its credentials below.
      </p>

      {loadError && (
        <DismissibleAlert className="mt-4 flex items-center gap-2 rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700">
          <XCircle className="h-4 w-4 flex-shrink-0" />
          {loadError}
        </DismissibleAlert>
      )}

      <form onSubmit={(e) => void handleSaveAppCredentials(e)} noValidate className="mt-4 space-y-4">
        <div className="grid grid-cols-1 gap-4 md:grid-cols-3">
          <div>
            <label htmlFor="meta-app-id" className="text-sm font-medium text-slate-700">
              App ID <span className="text-red-500">*</span>
            </label>
            <input
              id="meta-app-id"
              type="text"
              value={metaAppId}
              onChange={(e) => {
                setMetaAppId(e.target.value);
                clearFieldError('meta_app_id');
              }}
              placeholder="e.g. 987654321098765"
              aria-invalid={Boolean(fieldErrors.meta_app_id)}
              className={inputClass}
            />
            {fieldErrors.meta_app_id && <p className="mt-1.5 text-xs text-red-600">{fieldErrors.meta_app_id}</p>}
          </div>

          <div>
            <label htmlFor="meta-app-secret" className="text-sm font-medium text-slate-700">
              App Secret <span className="text-red-500">*</span>
            </label>
            <input
              id="meta-app-secret"
              type="password"
              value={metaAppSecret}
              onChange={(e) => {
                setMetaAppSecret(e.target.value);
                clearFieldError('meta_app_secret');
              }}
              placeholder={appConfig?.configured ? 'Leave filled in only to replace' : '••••••••'}
              autoComplete="off"
              aria-invalid={Boolean(fieldErrors.meta_app_secret)}
              className={inputClass}
            />
            {fieldErrors.meta_app_secret && (
              <p className="mt-1.5 text-xs text-red-600">{fieldErrors.meta_app_secret}</p>
            )}
          </div>

          <div>
            <label htmlFor="meta-config-id" className="text-sm font-medium text-slate-700">
              Embedded Signup Config ID <span className="text-red-500">*</span>
            </label>
            <input
              id="meta-config-id"
              type="text"
              value={metaConfigId}
              onChange={(e) => {
                setMetaConfigId(e.target.value);
                clearFieldError('meta_config_id');
              }}
              placeholder="e.g. 123123123123123"
              aria-invalid={Boolean(fieldErrors.meta_config_id)}
              className={inputClass}
            />
            {fieldErrors.meta_config_id && (
              <p className="mt-1.5 text-xs text-red-600">{fieldErrors.meta_config_id}</p>
            )}
          </div>
        </div>

        {saveAppError && (
          <DismissibleAlert className="flex items-center gap-2 rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700">
            <XCircle className="h-4 w-4 flex-shrink-0" />
            {saveAppError}
          </DismissibleAlert>
        )}
        {saveAppSuccess && (
          <div className="flex items-center gap-2 rounded-lg border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-700">
            <CheckCircle2 className="h-4 w-4 flex-shrink-0" />
            {saveAppSuccess}
          </div>
        )}

        <div className="flex items-center gap-3 pt-1">
          <button
            type="submit"
            disabled={isSavingApp}
            className="flex items-center gap-2 rounded-lg border border-slate-300 px-4 py-2 text-sm font-medium text-slate-700 hover:bg-slate-50 disabled:opacity-60"
          >
            {isSavingApp ? <Loader2 className="h-4 w-4 animate-spin" /> : null}
            {appConfig?.configured ? 'Update App Credentials' : 'Save App Credentials'}
          </button>

          <button
            type="button"
            onClick={() => void handleConnectWithFacebook()}
            disabled={!appConfig?.configured || isConnecting}
            title={!appConfig?.configured ? 'Save your App credentials first' : undefined}
            className="flex items-center gap-2 rounded-lg bg-[#1877F2] px-4 py-2 text-sm font-semibold text-white shadow-sm hover:bg-[#1465D8] disabled:opacity-60"
          >
            {isConnecting ? <Loader2 className="h-4 w-4 animate-spin" /> : <LogIn className="h-4 w-4" />}
            Connect with Facebook
          </button>
        </div>

        {connectError && (
          <DismissibleAlert className="flex items-center gap-2 rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700">
            <XCircle className="h-4 w-4 flex-shrink-0" />
            {connectError}
          </DismissibleAlert>
        )}
        {connectSuccess && (
          <div className="flex items-center gap-2 rounded-lg border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-700">
            <CheckCircle2 className="h-4 w-4 flex-shrink-0" />
            {connectSuccess}
          </div>
        )}
      </form>
    </div>
  );
}
