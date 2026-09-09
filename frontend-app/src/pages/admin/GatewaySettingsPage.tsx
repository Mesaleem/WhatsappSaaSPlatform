import { useCallback, useEffect, useState } from 'react';
import { Link } from 'react-router-dom';
import {
  ArrowLeft,
  CheckCircle2,
  Copy,
  Eye,
  EyeOff,
  KeyRound,
  Loader2,
  Mail,
  Send,
  ShieldCheck,
  XCircle,
} from 'lucide-react';
import axiosInstance from '../../core/api/axiosInstance';
import gatewaySettingsService from '../../services/gatewaySettingsService';
import mailSettingsService from '../../services/mailSettingsService';
import type { GatewayMode, GatewaySettings, PaymentGateway } from '../../types/billing';
import type { MailEncryption, MailSettings } from '../../types/mail';
import { extractErrorMessage as extractMessage } from '../../utils/apiError';

const inputClass =
  'mt-1.5 w-full rounded-lg border border-slate-300 px-3 py-2 text-sm text-slate-900 shadow-sm outline-none transition focus:border-indigo-500 focus:ring-2 focus:ring-indigo-100';


interface GatewayCardProps {
  settings: GatewaySettings;
  onSaved: (updated: GatewaySettings) => void;
}

/**
 * Module 8, requirement 1 — "Zero-Code Admin UI". One card per gateway.
 * Secret fields are NEVER prefilled (the API never returns them — see
 * GatewaySettingsController::present()); they start blank with a
 * "saved" indicator, and are only included in the save payload when the
 * Super Admin actually types a new value, matching the backend's
 * sometimes/nullable partial-update contract.
 */
function GatewayCard({ settings, onSaved }: GatewayCardProps) {
  const [mode, setMode] = useState<GatewayMode>(settings.mode);
  const [isEnabled, setIsEnabled] = useState(settings.is_enabled);
  const [testKeyId, setTestKeyId] = useState(settings.test_key_id ?? '');
  const [testKeySecret, setTestKeySecret] = useState('');
  const [testWebhookSecret, setTestWebhookSecret] = useState('');
  const [liveKeyId, setLiveKeyId] = useState(settings.live_key_id ?? '');
  const [liveKeySecret, setLiveKeySecret] = useState('');
  const [liveWebhookSecret, setLiveWebhookSecret] = useState('');
  const [showSecrets, setShowSecrets] = useState(false);

  const [isSaving, setIsSaving] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [success, setSuccess] = useState<string | null>(null);
  const [copied, setCopied] = useState(false);

  const webhookUrl = `${(axiosInstance.defaults.baseURL ?? '').replace(/\/$/, '')}/webhooks/${settings.gateway}`;

  const handleCopyWebhookUrl = async () => {
    try {
      await navigator.clipboard.writeText(webhookUrl);
      setCopied(true);
      setTimeout(() => setCopied(false), 2000);
    } catch {
      // Non-fatal — the URL is still visible/selectable in the page.
    }
  };

  const handleSave = async () => {
    setError(null);
    setSuccess(null);
    setIsSaving(true);
    try {
      const result = await gatewaySettingsService.update(settings.gateway, {
        mode,
        is_enabled: isEnabled,
        test_key_id: testKeyId,
        live_key_id: liveKeyId,
        ...(testKeySecret.trim() ? { test_key_secret: testKeySecret.trim() } : {}),
        ...(testWebhookSecret.trim() ? { test_webhook_secret: testWebhookSecret.trim() } : {}),
        ...(liveKeySecret.trim() ? { live_key_secret: liveKeySecret.trim() } : {}),
        ...(liveWebhookSecret.trim() ? { live_webhook_secret: liveWebhookSecret.trim() } : {}),
      });
      onSaved(result.data);
      setTestKeySecret('');
      setTestWebhookSecret('');
      setLiveKeySecret('');
      setLiveWebhookSecret('');
      setSuccess(`${settings.gateway === 'razorpay' ? 'Razorpay' : 'Stripe'} settings saved.`);
    } catch (err) {
      setError(extractMessage(err, 'Could not save these settings.'));
    } finally {
      setIsSaving(false);
    }
  };

  return (
    <div className="rounded-xl border border-slate-200 bg-white p-6 shadow-sm">
      <div className="flex flex-wrap items-center justify-between gap-3">
        <div className="flex items-center gap-2">
          <h3 className="text-base font-semibold capitalize text-slate-900">{settings.gateway}</h3>
          {settings.is_fully_configured ? (
            <span className="inline-flex items-center gap-1 rounded-full border border-emerald-200 bg-emerald-50 px-2.5 py-0.5 text-xs font-medium text-emerald-700">
              <CheckCircle2 className="h-3 w-3" /> Configured
            </span>
          ) : (
            <span className="inline-flex items-center gap-1 rounded-full border border-amber-200 bg-amber-50 px-2.5 py-0.5 text-xs font-medium text-amber-700">
              <XCircle className="h-3 w-3" /> Not configured
            </span>
          )}
        </div>

        <div className="flex items-center gap-3">
          <div className="flex items-center gap-1 rounded-full border border-slate-300 p-0.5 text-xs">
            {(['test', 'live'] as GatewayMode[]).map((m) => (
              <button
                key={m}
                onClick={() => setMode(m)}
                className={`rounded-full px-3 py-1 font-medium capitalize ${
                  mode === m ? 'bg-indigo-600 text-white' : 'text-slate-600 hover:bg-slate-50'
                }`}
              >
                {m}
              </button>
            ))}
          </div>
          <label className="flex items-center gap-2 text-xs font-medium text-slate-700">
            <input
              type="checkbox"
              checked={isEnabled}
              onChange={(e) => setIsEnabled(e.target.checked)}
              className="h-4 w-4 rounded border-slate-300 text-indigo-600 focus:ring-indigo-500"
            />
            Enabled
          </label>
        </div>
      </div>

      <div className="mt-3 flex items-center gap-2 rounded-lg border border-slate-200 bg-slate-50 px-3 py-2 text-xs text-slate-600">
        <span>
          Webhook URL: <span className="font-mono">{webhookUrl}</span>
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

      <div className="mt-4 flex items-center justify-end">
        <button
          type="button"
          onClick={() => setShowSecrets((v) => !v)}
          className="inline-flex items-center gap-1 text-xs font-medium text-slate-500 hover:text-slate-700"
        >
          {showSecrets ? <EyeOff className="h-3.5 w-3.5" /> : <Eye className="h-3.5 w-3.5" />}
          {showSecrets ? 'Hide secret fields' : 'Show secret fields'}
        </button>
      </div>

      <div className="mt-2 grid grid-cols-1 gap-4 sm:grid-cols-2">
        <div className="space-y-3 rounded-lg border border-slate-200 p-4">
          <p className="text-xs font-semibold uppercase text-slate-400">Test mode</p>
          <div>
            <label className="text-xs font-medium text-slate-700">Key ID</label>
            <input value={testKeyId} onChange={(e) => setTestKeyId(e.target.value)} className={inputClass} placeholder="rzp_test_... / pk_test_..." />
          </div>
          {showSecrets && (
            <>
              <div>
                <label className="text-xs font-medium text-slate-700">Key Secret</label>
                <input
                  type="password"
                  value={testKeySecret}
                  onChange={(e) => setTestKeySecret(e.target.value)}
                  className={inputClass}
                  placeholder={settings.test_key_secret_set ? 'Leave blank to keep saved secret' : 'sk_test_...'}
                  autoComplete="off"
                />
              </div>
              <div>
                <label className="text-xs font-medium text-slate-700">Webhook Secret</label>
                <input
                  type="password"
                  value={testWebhookSecret}
                  onChange={(e) => setTestWebhookSecret(e.target.value)}
                  className={inputClass}
                  placeholder={settings.test_webhook_secret_set ? 'Leave blank to keep saved secret' : 'whsec_...'}
                  autoComplete="off"
                />
              </div>
            </>
          )}
        </div>

        <div className="space-y-3 rounded-lg border border-slate-200 p-4">
          <p className="text-xs font-semibold uppercase text-slate-400">Live mode</p>
          <div>
            <label className="text-xs font-medium text-slate-700">Key ID</label>
            <input value={liveKeyId} onChange={(e) => setLiveKeyId(e.target.value)} className={inputClass} placeholder="rzp_live_... / pk_live_..." />
          </div>
          {showSecrets && (
            <>
              <div>
                <label className="text-xs font-medium text-slate-700">Key Secret</label>
                <input
                  type="password"
                  value={liveKeySecret}
                  onChange={(e) => setLiveKeySecret(e.target.value)}
                  className={inputClass}
                  placeholder={settings.live_key_secret_set ? 'Leave blank to keep saved secret' : 'sk_live_...'}
                  autoComplete="off"
                />
              </div>
              <div>
                <label className="text-xs font-medium text-slate-700">Webhook Secret</label>
                <input
                  type="password"
                  value={liveWebhookSecret}
                  onChange={(e) => setLiveWebhookSecret(e.target.value)}
                  className={inputClass}
                  placeholder={settings.live_webhook_secret_set ? 'Leave blank to keep saved secret' : 'whsec_...'}
                  autoComplete="off"
                />
              </div>
            </>
          )}
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
          Save {settings.gateway === 'razorpay' ? 'Razorpay' : 'Stripe'} Settings
        </button>
      </div>
    </div>
  );
}


/**
 * Dynamic System & Mail Configuration — Super Admin SMTP setup, on the
 * same "Admin Gateway Settings" screen as the payment gateway cards
 * above. The password field is never prefilled (same "leave blank to
 * keep saved secret" contract as GatewayCard). "Send Test Mail" posts
 * whatever is currently typed in the form (merged server-side onto the
 * saved config for any blank field) so a Super Admin can verify SMTP
 * before saving — see MailSettingsController::sendTest().
 */
function MailSettingsCard({ settings, onSaved }: { settings: MailSettings; onSaved: (updated: MailSettings) => void }) {
  const [host, setHost] = useState(settings.host ?? '');
  const [port, setPort] = useState(settings.port != null ? String(settings.port) : '');
  const [username, setUsername] = useState(settings.username ?? '');
  const [password, setPassword] = useState('');
  const [encryption, setEncryption] = useState<MailEncryption | ''>(settings.encryption ?? '');
  const [fromAddress, setFromAddress] = useState(settings.from_address ?? '');
  const [fromName, setFromName] = useState(settings.from_name ?? '');

  const [isSaving, setIsSaving] = useState(false);
  const [isTesting, setIsTesting] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [success, setSuccess] = useState<string | null>(null);
  const [testResult, setTestResult] = useState<{ success: boolean; message: string } | null>(null);

  const currentFormValues = () => ({
    mailer: 'smtp' as const,
    host: host.trim() || null,
    port: port.trim() ? Number(port) : null,
    username: username.trim() || null,
    encryption: (encryption || null) as MailEncryption | null,
    from_address: fromAddress.trim() || null,
    from_name: fromName.trim() || null,
    ...(password.trim() ? { password: password.trim() } : {}),
  });

  const handleSave = async () => {
    setError(null);
    setSuccess(null);
    setIsSaving(true);
    try {
      const result = await mailSettingsService.update(currentFormValues());
      onSaved(result.data);
      setPassword('');
      setSuccess('Mail settings saved.');
    } catch (err) {
      setError(extractMessage(err, 'Could not save these settings.'));
    } finally {
      setIsSaving(false);
    }
  };

  const handleSendTest = async () => {
    setError(null);
    setTestResult(null);
    setIsTesting(true);
    try {
      const result = await mailSettingsService.sendTest(currentFormValues());
      setTestResult({ success: true, message: result.message });
    } catch (err) {
      setTestResult({ success: false, message: extractMessage(err, 'Could not send the test email.') });
    } finally {
      setIsTesting(false);
    }
  };

  return (
    <div className="rounded-xl border border-slate-200 bg-white p-6 shadow-sm">
      <div className="flex flex-wrap items-center justify-between gap-3">
        <div className="flex items-center gap-2">
          <h3 className="flex items-center gap-2 text-base font-semibold text-slate-900">
            <Mail className="h-4 w-4 text-indigo-600" />
            SMTP Email Configuration
          </h3>
          {settings.is_configured ? (
            <span className="inline-flex items-center gap-1 rounded-full border border-emerald-200 bg-emerald-50 px-2.5 py-0.5 text-xs font-medium text-emerald-700">
              <CheckCircle2 className="h-3 w-3" /> Configured
            </span>
          ) : (
            <span className="inline-flex items-center gap-1 rounded-full border border-amber-200 bg-amber-50 px-2.5 py-0.5 text-xs font-medium text-amber-700">
              <XCircle className="h-3 w-3" /> Not configured
            </span>
          )}
        </div>
      </div>
      <p className="mt-1 text-sm text-slate-500">
        Used platform-wide for outgoing email (e.g. the Send Test Mail button below). Changes apply immediately —
        no .env edit or deploy required.
      </p>

      <div className="mt-4 grid grid-cols-1 gap-4 sm:grid-cols-2">
        <div>
          <label className="text-xs font-medium text-slate-700">Mail Driver</label>
          <input value="SMTP" disabled className={`${inputClass} bg-slate-50 text-slate-500`} />
        </div>
        <div>
          <label className="text-xs font-medium text-slate-700">Encryption</label>
          <select
            value={encryption}
            onChange={(e) => setEncryption(e.target.value as MailEncryption | '')}
            className={inputClass}
          >
            <option value="">None</option>
            <option value="tls">TLS</option>
            <option value="ssl">SSL</option>
          </select>
        </div>
        <div>
          <label className="text-xs font-medium text-slate-700">SMTP Host</label>
          <input value={host} onChange={(e) => setHost(e.target.value)} className={inputClass} placeholder="smtp.gmail.com" />
        </div>
        <div>
          <label className="text-xs font-medium text-slate-700">Port</label>
          <input
            type="number"
            min="1"
            max="65535"
            value={port}
            onChange={(e) => setPort(e.target.value)}
            className={inputClass}
            placeholder="587"
          />
        </div>
        <div>
          <label className="text-xs font-medium text-slate-700">Username</label>
          <input value={username} onChange={(e) => setUsername(e.target.value)} className={inputClass} placeholder="you@example.com" />
        </div>
        <div>
          <label className="text-xs font-medium text-slate-700">App Password</label>
          <input
            type="password"
            value={password}
            onChange={(e) => setPassword(e.target.value)}
            className={inputClass}
            placeholder={settings.password_set ? 'Leave blank to keep saved password' : 'App password'}
            autoComplete="off"
          />
        </div>
        <div>
          <label className="text-xs font-medium text-slate-700">From Address</label>
          <input
            type="email"
            value={fromAddress}
            onChange={(e) => setFromAddress(e.target.value)}
            className={inputClass}
            placeholder="noreply@example.com"
          />
        </div>
        <div>
          <label className="text-xs font-medium text-slate-700">From Name</label>
          <input value={fromName} onChange={(e) => setFromName(e.target.value)} className={inputClass} placeholder="WhatsApp SaaS Platform" />
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
      {testResult && (
        <div
          className={`mt-4 flex items-center gap-2 rounded-lg border px-4 py-3 text-sm ${
            testResult.success
              ? 'border-emerald-200 bg-emerald-50 text-emerald-700'
              : 'border-red-200 bg-red-50 text-red-700'
          }`}
        >
          {testResult.success ? (
            <CheckCircle2 className="h-4 w-4 flex-shrink-0" />
          ) : (
            <XCircle className="h-4 w-4 flex-shrink-0" />
          )}
          {testResult.message}
        </div>
      )}

      <div className="mt-4 flex flex-wrap justify-end gap-3">
        <button
          type="button"
          onClick={() => void handleSendTest()}
          disabled={isTesting || !host.trim() || !port.trim()}
          className="flex items-center gap-2 rounded-lg border border-slate-300 px-4 py-2 text-sm font-semibold text-slate-700 shadow-sm hover:bg-slate-50 disabled:cursor-not-allowed disabled:opacity-60"
        >
          {isTesting ? <Loader2 className="h-4 w-4 animate-spin" /> : <Send className="h-4 w-4" />}
          Send Test Mail
        </button>
        <button
          type="button"
          onClick={() => void handleSave()}
          disabled={isSaving}
          className="flex items-center gap-2 rounded-lg bg-indigo-600 px-4 py-2 text-sm font-semibold text-white shadow-sm hover:bg-indigo-700 disabled:opacity-60"
        >
          {isSaving ? <Loader2 className="h-4 w-4 animate-spin" /> : <Mail className="h-4 w-4" />}
          Save Mail Settings
        </button>
      </div>
    </div>
  );
}

/** Module 8 — Super Admin platform gateway configuration. Route-gated permission="manage-billing-settings". */
export default function GatewaySettingsPage() {
  const [settingsList, setSettingsList] = useState<GatewaySettings[]>([]);
  const [mailSettings, setMailSettings] = useState<MailSettings | null>(null);
  const [isLoading, setIsLoading] = useState(true);
  const [loadError, setLoadError] = useState<string | null>(null);

  const load = useCallback(async () => {
    setIsLoading(true);
    setLoadError(null);
    try {
      const [gatewayData, mailData] = await Promise.all([
        gatewaySettingsService.list(),
        mailSettingsService.get(),
      ]);
      setSettingsList(gatewayData);
      setMailSettings(mailData);
    } catch (err) {
      setLoadError(extractMessage(err, 'Failed to load gateway settings.'));
    } finally {
      setIsLoading(false);
    }
  }, []);

  useEffect(() => {
    void load();
  }, [load]);

  const handleSaved = (gateway: PaymentGateway, updated: GatewaySettings) => {
    setSettingsList((prev) => prev.map((s) => (s.gateway === gateway ? updated : s)));
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
            Payment Gateway Settings
          </h1>
          <p className="mt-1 text-sm text-slate-500">
            Platform-level Razorpay/Stripe credentials used to collect payments from tenants. Secrets are encrypted
            at rest and never shown again after saving.
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
            Loading gateway settings…
          </div>
        ) : (
          <div className="space-y-6">
            {settingsList.map((settings) => (
              <GatewayCard key={settings.gateway} settings={settings} onSaved={(u) => handleSaved(settings.gateway, u)} />
            ))}
            {mailSettings && <MailSettingsCard settings={mailSettings} onSaved={setMailSettings} />}
          </div>
        )}
      </div>
    </div>
  );
}
