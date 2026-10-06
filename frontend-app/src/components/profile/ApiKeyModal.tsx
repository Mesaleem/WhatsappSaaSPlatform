import { useEffect, useState } from 'react';
import { Link } from 'react-router-dom';
import { BookOpen, CheckCircle2, Copy, Eye, EyeOff, Globe, HelpCircle, KeyRound, Loader2, RefreshCw, ShieldAlert, X, XCircle } from 'lucide-react';
import templateService from '../../services/templateService';
import type { ClientApiKey, ClientServerIp } from '../../types/templates';
import ConfirmModal from '../common/ConfirmModal';
import DismissibleAlert from '../common/DismissibleAlert';
import { extractErrorMessage } from '../../utils/apiError';

interface ApiKeyModalProps {
  onClose: () => void;
  /** The plan does not include API access: explain it, and do not load or create a key. */
  locked?: boolean;
  /** False when the plan has expired: the key stays, but sending is paused and a new key needs a renewal. */
  subscriptionActive?: boolean;
}

const STEP_TITLE = 'text-sm font-semibold text-slate-900';

function formatDate(value: string): string {
  return new Date(value).toLocaleString('en-IN', { day: '2-digit', month: 'short', year: 'numeric', hour: '2-digit', minute: '2-digit' });
}

/**
 * The Developer API setup, in three steps: 1) the one server IP that may call the API, 2) the API key,
 * 3) the developer docs. The key works only from the registered IP.
 */
export default function ApiKeyModal({ onClose, locked = false, subscriptionActive = true }: ApiKeyModalProps) {
  const [apiKey, setApiKey] = useState<ClientApiKey | null>(null);
  const [serverIp, setServerIp] = useState<ClientServerIp | null>(null);
  const [loading, setLoading] = useState(!locked);
  const [error, setError] = useState<string | null>(null);
  const [notice, setNotice] = useState<string | null>(null);

  // Step 1: the server IP.
  const [ipInput, setIpInput] = useState('');
  const [ipBusy, setIpBusy] = useState(false);
  const [ipError, setIpError] = useState<string | null>(null);
  const [hostHelpOpen, setHostHelpOpen] = useState(false);

  // Step 2: the key. The full key exists only in this session, straight after it is generated.
  const [fullKey, setFullKey] = useState<string | null>(null);
  const [showKey, setShowKey] = useState(false);
  const [copied, setCopied] = useState(false);
  const [confirmingNew, setConfirmingNew] = useState(false);
  const [busy, setBusy] = useState(false);
  // The user must agree to the IP restriction before a key is generated.
  const [agreed, setAgreed] = useState(false);

  useEffect(() => {
    if (locked) return;
    templateService
      .getApiKey()
      .then((res) => {
        setApiKey(res.data);
        setServerIp(res.server_ip);
        setIpInput(res.server_ip.authorized_server_ip ?? '');
      })
      .catch((err: unknown) => setError(extractErrorMessage(err, 'We could not load your API key. Please try again.')))
      .finally(() => setLoading(false));
  }, []);

  const ipRegistered = serverIp?.authorized_server_ip ?? null;
  const ipChanged = ipInput.trim() !== '' && ipInput.trim() !== (ipRegistered ?? '');
  const keySetupDone = Boolean(apiKey) && Boolean(ipRegistered);

  const saveIp = async () => {
    setIpError(null);
    setNotice(null);
    setIpBusy(true);
    try {
      const result = await templateService.saveServerIp(ipInput.trim());
      setServerIp(result.server_ip);
      setIpInput(result.server_ip.authorized_server_ip ?? '');
      setNotice(result.message);
    } catch (err) {
      setIpError(extractErrorMessage(err, 'We could not save the server IP. Please try again.'));
    } finally {
      setIpBusy(false);
    }
  };

  const copyKey = async () => {
    if (!fullKey) return;
    try {
      await navigator.clipboard.writeText(fullKey);
      setCopied(true);
      setTimeout(() => setCopied(false), 2000);
    } catch {
      setError('We could not copy the key. Select it and copy it manually.');
    }
  };

  const generateNew = async () => {
    setBusy(true);
    setError(null);
    const previousPrefix = apiKey?.key_prefix;
    try {
      const result = await templateService.regenerateApiKey();
      setApiKey(result.data);
      setServerIp(result.server_ip);
      setFullKey(result.plain_text_key);
      setShowKey(true);
      setConfirmingNew(false);
      setAgreed(false);
      setNotice(
        previousPrefix
          ? `New key created. The previous key (${previousPrefix}…) has stopped working. Update your server with the new key.`
          : 'Your API key is ready. Copy it now: it will not be shown again.',
      );
    } catch (err) {
      setConfirmingNew(false);
      setError(extractErrorMessage(err, 'We could not create a new key. Your current key still works.'));
    } finally {
      setBusy(false);
    }
  };

  const masked = apiKey ? `${apiKey.key_prefix}${'•'.repeat(24)}` : '';

  return (
    <div className="fixed inset-0 z-50 flex items-center justify-center bg-slate-900/50 p-4" role="dialog" aria-modal="true" aria-labelledby="api-key-title">
      <div className="max-h-[92vh] w-full max-w-lg overflow-y-auto rounded-xl bg-white p-6 shadow-xl">
        <div className="flex items-start justify-between gap-4">
          <div>
            <h2 id="api-key-title" className="text-base font-semibold text-slate-900">
              API key setup
            </h2>
            <p className="mt-1 text-xs text-slate-500">Three steps connect your server to our API.</p>
          </div>
          <button type="button" onClick={onClose} className="text-slate-400 hover:text-slate-600" aria-label="Close">
            <X className="h-5 w-5" />
          </button>
        </div>

        <div className="mt-5 space-y-5">
          {locked && (
            <div className="rounded-lg border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-900">
              <p className="font-medium">API access is not included in your plan.</p>
              <p className="mt-1 text-xs">
                An API key lets your own server connect to our API. Choose a plan that includes API access to use it.
              </p>
            </div>
          )}

          {!locked && !subscriptionActive && (
            <div className="rounded-lg border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-900">
              <p className="font-medium">Your plan has expired.</p>
              <p className="mt-1 text-xs">
                Your API key is kept, but messages sent with it are paused. Renew your plan to resume sending. A new key can be
                generated after renewal.
              </p>
            </div>
          )}

          {loading && (
            <p className="flex items-center gap-2 text-sm text-slate-500">
              <Loader2 className="h-4 w-4 animate-spin" /> Loading your setup…
            </p>
          )}

          {!locked && !loading && keySetupDone && (
            <p className="flex items-center gap-2 rounded-lg border border-emerald-200 bg-emerald-50 px-3 py-2 text-xs text-emerald-800">
              <CheckCircle2 className="h-4 w-4 flex-shrink-0" /> API key and server IP are set up.
            </p>
          )}

          {/* Step 1: the server IP */}
          {!locked && !loading && serverIp && (
            <section className="space-y-3">
              <h3 className={`${STEP_TITLE} flex items-center gap-2`}>
                <Globe className="h-4 w-4 text-slate-500" /> Step 1: Your server IP <span className="text-red-600">*</span>
              </h3>
              <p className="text-xs text-slate-500">
                Enter the public IP address of the server that calls our API. Requests from any other address are refused.
              </p>

              <div>
                <label htmlFor="api-key-server-ip" className="mb-1 block text-xs font-medium text-slate-700">
                  Server IP Address (Required) <span className="text-red-600">*</span>
                </label>
              </div>
              <div className="flex flex-wrap items-center gap-2">
                <input
                  id="api-key-server-ip"
                  type="text"
                  value={ipInput}
                  onChange={(e) => setIpInput(e.target.value)}
                  disabled={ipBusy || !serverIp.can_edit}
                  placeholder="203.0.113.10"
                  aria-label="Server IP address"
                  className="flex-1 rounded-lg border border-slate-300 px-3 py-2 text-sm text-slate-900 shadow-sm outline-none focus:border-indigo-500 focus:ring-2 focus:ring-indigo-100 disabled:bg-slate-50"
                />
                <button
                  type="button"
                  onClick={() => void saveIp()}
                  disabled={ipBusy || !serverIp.can_edit || ipInput.trim() === '' || (ipRegistered !== null && !ipChanged)}
                  className="flex items-center gap-1.5 rounded-lg bg-indigo-600 px-3 py-2 text-sm font-semibold text-white hover:bg-indigo-700 disabled:cursor-not-allowed disabled:opacity-50"
                >
                  {ipBusy && <Loader2 className="h-4 w-4 animate-spin" />}
                  {ipRegistered ? 'Save new IP' : 'Save IP'}
                </button>
              </div>

              <p className="rounded-lg bg-slate-50 px-3 py-2 text-xs text-slate-600">
                💡 Note for Shared Hosting: Enter your server's Outbound/cURL IP (not the domain's A-record IP). You can find this by fetching https://api.ipify.org from your server.
              </p>

              <button type="button" onClick={() => setHostHelpOpen((v) => !v)} className="flex items-center gap-1 text-xs font-medium text-indigo-700 hover:underline">
                <HelpCircle className="h-3.5 w-3.5" /> Host IP Changed?
              </button>
              {hostHelpOpen && (
                <div className="space-y-1.5 rounded-lg border border-indigo-200 bg-indigo-50 px-3 py-2 text-xs text-indigo-900">
                  <p className="font-semibold">Did your shared hosting provider move your site to a new IP?</p>
                  <ol className="list-decimal space-y-1 pl-4">
                    <li>On your server, run <code>curl https://api.ipify.org</code> to get its current outbound IP.</li>
                    <li>If your free change is still available, enter that IP above and save it.</li>
                    <li>If it asks for a recharge, contact WapHub support. They can reset your IP change once, then you save the new IP.</li>
                  </ol>
                </div>
              )}

              {ipRegistered && <p className="text-xs text-slate-600">Registered: <span className="font-mono">{ipRegistered}</span></p>}

              {!serverIp.can_edit && serverIp.require_payment && (
                <div className="rounded-lg border border-amber-200 bg-amber-50 px-3 py-2 text-xs text-amber-900">
                  <p>
                    Your 1-time lifetime free IP update has been used. To change your registered server IP, please recharge your
                    current plan (₹{Math.round(serverIp.recharge_price)}).
                  </p>
                  <Link to="/billing" className="mt-2 inline-block font-semibold text-indigo-700 hover:underline">
                    Recharge plan
                  </Link>
                </div>
              )}

              {!serverIp.can_edit && !serverIp.require_payment && serverIp.edit_available_at && (
                <p className="text-xs text-amber-800">You can change the IP once more on {formatDate(serverIp.edit_available_at)}.</p>
              )}

              {ipError && <p className="rounded-lg border border-red-200 bg-red-50 px-3 py-2 text-xs text-red-700">{ipError}</p>}
            </section>
          )}

          {/* Step 2: the API key */}
          {!locked && !loading && (
            <section className="space-y-3">
              <h3 className={STEP_TITLE}>Step 2: Generate your API key <span className="text-red-600">*</span></h3>
              <p className="text-xs text-slate-500">The key works only from the server IP registered in step 1.</p>
              {!ipRegistered && (
                <p className="text-xs text-amber-700">Save your server IP in step 1 first. The key cannot be generated without it.</p>
              )}

              {!apiKey && !fullKey && (
                <button
                  type="button"
                  onClick={() => setConfirmingNew(true)}
                  disabled={!subscriptionActive || !ipRegistered}
                  title={!ipRegistered ? 'Save your server IP first' : subscriptionActive ? 'Generate a key' : 'Renew your plan to generate a key'}
                  className="flex items-center gap-1.5 rounded-lg bg-indigo-600 px-3 py-2 text-sm font-semibold text-white hover:bg-indigo-700 disabled:cursor-not-allowed disabled:opacity-50"
                >
                  <KeyRound className="h-4 w-4" /> Generate key
                </button>
              )}

              {(apiKey || fullKey) && (
                <div className="space-y-3">
                  <div className="flex items-center gap-2">
                    <code className="flex-1 overflow-x-auto rounded-lg border border-slate-300 bg-slate-50 px-3 py-2 text-xs text-slate-900">
                      {showKey && fullKey ? fullKey : masked}
                    </code>
                    <button
                      type="button"
                      onClick={() => setShowKey((v) => !v)}
                      disabled={!fullKey}
                      aria-label={showKey ? 'Hide key' : 'Show key'}
                      title={fullKey ? (showKey ? 'Hide key' : 'Show key') : 'The full key is shown only once, right after it is generated.'}
                      className="rounded-lg border border-slate-300 p-2 text-slate-500 hover:bg-slate-50 disabled:cursor-not-allowed disabled:opacity-50"
                    >
                      {showKey ? <EyeOff className="h-4 w-4" /> : <Eye className="h-4 w-4" />}
                    </button>
                    <button
                      type="button"
                      onClick={() => void copyKey()}
                      disabled={!fullKey}
                      title={fullKey ? 'Copy key' : 'The full key is shown only once, right after it is generated.'}
                      className="flex items-center gap-1 rounded-lg border border-slate-300 px-3 py-2 text-xs font-medium text-slate-700 hover:bg-slate-50 disabled:cursor-not-allowed disabled:opacity-50"
                    >
                      <Copy className="h-3.5 w-3.5" />
                      {copied ? 'Copied' : 'Copy'}
                    </button>
                  </div>

                  <p className="text-xs text-slate-600">
                    Registered server IP: <span className="font-mono font-semibold text-slate-900">{ipRegistered ?? 'not set'}</span>
                  </p>

                  {fullKey ? (
                    <p className="flex items-start gap-2 rounded-lg border border-amber-200 bg-amber-50 px-3 py-2 text-xs text-amber-800">
                      <ShieldAlert className="mt-0.5 h-3.5 w-3.5 flex-shrink-0" />
                      Copy this key now. For your security it will not be shown again after you close this window.
                    </p>
                  ) : (
                    <p className="text-xs text-slate-500">
                      For your security the full key is not shown again. If you lost it, generate a new key.
                    </p>
                  )}

                  <p className="text-[11px] text-slate-500">
                    {apiKey?.last_used_at ? `Last used ${new Date(apiKey.last_used_at).toLocaleDateString('en-IN')}` : 'Not used yet'}
                  </p>

                  <button
                    type="button"
                    onClick={() => setConfirmingNew(true)}
                    disabled={busy || !subscriptionActive || !ipRegistered}
                    title={subscriptionActive ? 'Generate a new key' : 'Renew your plan to generate a new key'}
                    className="flex items-center gap-1.5 text-xs font-medium text-indigo-700 hover:underline disabled:cursor-not-allowed disabled:opacity-50"
                  >
                    {busy ? <Loader2 className="h-3.5 w-3.5 animate-spin" /> : <RefreshCw className="h-3.5 w-3.5" />}
                    Generate a new key
                  </button>
                </div>
              )}
            </section>
          )}

          {/* Step 3: the docs */}
          {!locked && !loading && (
            <section className="space-y-2">
              <h3 className={`${STEP_TITLE} flex items-center gap-2`}>
                <BookOpen className="h-4 w-4 text-slate-500" /> Step 3: Set up your integration
              </h3>
              <p className="text-xs text-slate-500">
                The endpoint, headers and a sample payload are in the Developer API Documentation on the Send Notification page.
              </p>
              <Link to="/alerts/send" className="inline-block text-xs font-semibold text-indigo-700 hover:underline">
                Open Developer API documentation
              </Link>
            </section>
          )}

          {notice && <p className="rounded-lg border border-emerald-200 bg-emerald-50 px-3 py-2 text-xs text-emerald-800">{notice}</p>}

          {error && (
            <DismissibleAlert className="flex items-center gap-2 rounded-lg border border-red-200 bg-red-50 px-3 py-2 text-xs text-red-700">
              <XCircle className="h-3.5 w-3.5 flex-shrink-0" />
              {error}
            </DismissibleAlert>
          )}
        </div>

        <div className="mt-6 flex justify-end">
          <button type="button" onClick={onClose} className="rounded-lg border border-slate-300 px-4 py-2 text-sm font-medium text-slate-700 hover:bg-slate-50">
            Close
          </button>
        </div>
      </div>

      {confirmingNew && (
        <ConfirmModal
          title={apiKey ? 'Generate a new key?' : 'Generate your key?'}
          variant={apiKey ? 'danger' : 'default'}
          message={
            <div className="space-y-3 text-left">
              <p>
                {apiKey
                  ? 'Your current key will stop working immediately. Any server still using it will be refused until you update it with the new key.'
                  : 'This creates the key your server uses to connect to our API.'}
              </p>
              <p className="rounded-lg bg-slate-50 px-3 py-2 text-xs text-slate-700">
                Registered server IP: <span className="font-mono font-semibold">{ipRegistered}</span>
              </p>
              <label className="flex items-start gap-2 text-sm text-slate-700">
                <input
                  type="checkbox"
                  checked={agreed}
                  onChange={(e) => setAgreed(e.target.checked)}
                  className="mt-0.5 h-4 w-4 rounded border-slate-300"
                />
                <span>
                  I understand and agree that this key works only from my registered server IP. Calls from any other address are refused.{' '}
                  <span className="text-red-600">*</span>
                </span>
              </label>
            </div>
          }
          confirmLabel={apiKey ? 'Generate new key' : 'Generate key'}
          confirmDisabled={!agreed}
          isLoading={busy}
          onConfirm={() => void generateNew()}
          onCancel={() => {
            setConfirmingNew(false);
            setAgreed(false);
          }}
        />
      )}
    </div>
  );
}
