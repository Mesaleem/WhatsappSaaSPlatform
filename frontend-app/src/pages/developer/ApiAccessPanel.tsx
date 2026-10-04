import { useState, type FormEvent } from 'react';
import { CheckCircle2, Clock, Copy, KeyRound, Loader2, ShieldAlert, XCircle } from 'lucide-react';
import developerService from '../../services/developerService';
import { inputClass } from '../../components/common/Card';
import ServerBindingFields, {
  SERVER_BINDING_WARNING,
  emptyServerBinding,
  parseIps,
  toServerBindingPayload,
  validateServerBinding,
  type ServerBindingValue,
} from '../../components/developer/ServerBindingFields';
import { describeApiError } from '../../utils/apiError';
import type { ApiKey, BindingStatus } from '../../types/developer';

const STATUS_LABEL: Record<BindingStatus, { label: string; cls: string }> = {
  active: { label: 'Active', cls: 'border-emerald-200 bg-emerald-50 text-emerald-700' },
  pending_activation: { label: 'Awaiting first request from your server', cls: 'border-amber-200 bg-amber-50 text-amber-700' },
  unbound: { label: 'Server authorization required', cls: 'border-amber-200 bg-amber-50 text-amber-700' },
  revoked: { label: 'Authorized server revoked', cls: 'border-red-200 bg-red-50 text-red-700' },
  disabled: { label: 'API access disabled', cls: 'border-red-200 bg-red-50 text-red-700' },
};

function fmt(value: string | null | undefined): string {
  return value ? new Date(value).toLocaleString() : 'Never';
}

/** One-time reveal of the installation credential. The server stores only its hash. */
function CredentialReveal({ credential, header, onDone }: { credential: string; header: string; onDone: () => void }) {
  const [copied, setCopied] = useState(false);
  return (
    <div role="dialog" aria-label="Installation credential" className="fixed inset-0 z-50 flex items-center justify-center bg-slate-900/50 p-4">
      <div className="w-full max-w-md space-y-4 rounded-xl bg-white p-6 shadow-xl">
        <h3 className="text-base font-semibold text-slate-900">Installation credential</h3>
        <p className="text-sm text-slate-600">
          Send this value in the <code className="font-mono text-xs">{header}</code> header from your authorized server on every API request. It is shown once and cannot be retrieved again.
        </p>
        <div className="flex items-center gap-2 rounded-lg border border-slate-200 bg-slate-50 px-3 py-2">
          <code data-testid="installation-credential" className="min-w-0 flex-1 break-all font-mono text-xs text-slate-800">{credential}</code>
          <button
            type="button"
            aria-label="Copy installation credential"
            onClick={() => {
              void navigator.clipboard?.writeText(credential).then(() => setCopied(true)).catch(() => undefined);
            }}
            className="text-slate-500 hover:text-slate-700"
          >
            {copied ? <CheckCircle2 className="h-4 w-4 text-emerald-600" /> : <Copy className="h-4 w-4" />}
          </button>
        </div>
        <div className="flex justify-end">
          <button onClick={onDone} className="rounded-lg bg-indigo-600 px-4 py-2 text-sm font-semibold text-white hover:bg-indigo-700">
            Done
          </button>
        </div>
      </div>
    </div>
  );
}

function RequestChangeModal({ apiKey, onClose, onDone }: { apiKey: ApiKey; onClose: () => void; onDone: () => void }) {
  const current = apiKey.server_binding?.binding;
  const [reason, setReason] = useState('');
  const [label, setLabel] = useState('');
  const [policy, setPolicy] = useState<'SINGLE_IP' | 'IP_ALLOWLIST'>('SINGLE_IP');
  const [ips, setIps] = useState('');
  const [saving, setSaving] = useState(false);
  const [error, setError] = useState<string | null>(null);

  const submit = async (e: FormEvent) => {
    e.preventDefault();
    if (saving) return;
    if (!reason.trim()) return setError('Tell us why the server is changing.');
    if (parseIps(ips).length === 0) return setError('Enter the IP address of the new server.');
    setError(null);
    setSaving(true);
    try {
      await developerService.requestServerChange(apiKey.id, {
        reason: reason.trim(),
        ...(label.trim() ? { requested_label: label.trim() } : {}),
        ip_policy: policy,
        requested_ips: parseIps(ips),
      });
      onDone();
    } catch (err) {
      setError(describeApiError(err, 'The request could not be submitted.').message);
    } finally {
      setSaving(false);
    }
  };

  return (
    <div role="dialog" aria-label="Request server change" className="fixed inset-0 z-50 flex items-center justify-center bg-slate-900/50 p-4">
      <form onSubmit={(e) => void submit(e)} noValidate className="w-full max-w-md space-y-4 rounded-xl bg-white p-6 shadow-xl">
        <div className="flex items-center justify-between">
          <h3 className="text-base font-semibold text-slate-900">Request Server Change</h3>
          <button type="button" onClick={onClose} aria-label="Close" className="text-slate-400 hover:text-slate-600">
            <XCircle className="h-5 w-5" />
          </button>
        </div>
        <dl className="grid grid-cols-2 gap-2 rounded-lg bg-slate-50 p-3 text-xs text-slate-600">
          <dt>Current server</dt>
          <dd className="font-medium text-slate-800">{current?.label ?? '—'}</dd>
          <dt>Current IP</dt>
          <dd className="font-mono text-slate-800">{current?.last_success_ip ?? current?.registered_ip ?? '—'}</dd>
        </dl>
        <p className="text-xs text-slate-500">Your current server stays authorized until a Super Admin approves this request.</p>
        <div>
          <label htmlFor="change-label" className="text-sm font-medium text-slate-700">New server label (optional)</label>
          <input id="change-label" value={label} maxLength={100} onChange={(e) => setLabel(e.target.value)} className={inputClass} />
        </div>
        <div className="grid grid-cols-1 gap-3 sm:grid-cols-2">
          <div>
            <label htmlFor="change-policy" className="text-sm font-medium text-slate-700">IP restriction</label>
            <select id="change-policy" value={policy} onChange={(e) => setPolicy(e.target.value as 'SINGLE_IP' | 'IP_ALLOWLIST')} className={inputClass}>
              <option value="SINGLE_IP">Single server IP</option>
              <option value="IP_ALLOWLIST">IP allowlist</option>
            </select>
          </div>
          <div>
            <label htmlFor="change-ips" className="text-sm font-medium text-slate-700">New server IP</label>
            <input id="change-ips" value={ips} onChange={(e) => setIps(e.target.value)} placeholder="203.0.113.55" className={inputClass} />
          </div>
        </div>
        <div>
          <label htmlFor="change-reason" className="text-sm font-medium text-slate-700">Reason <span className="text-red-500">*</span></label>
          <textarea id="change-reason" value={reason} maxLength={500} onChange={(e) => setReason(e.target.value)} rows={3} className={inputClass} />
        </div>
        {error && <p role="alert" className="rounded-lg border border-red-200 bg-red-50 px-3 py-2 text-sm text-red-700">{error}</p>}
        <div className="flex justify-end gap-3">
          <button type="button" onClick={onClose} className="rounded-lg border border-slate-300 px-4 py-2 text-sm font-medium text-slate-700 hover:bg-slate-50">Cancel</button>
          <button type="submit" disabled={saving} className="flex items-center gap-2 rounded-lg bg-indigo-600 px-4 py-2 text-sm font-semibold text-white hover:bg-indigo-700 disabled:opacity-60">
            {saving && <Loader2 className="h-4 w-4 animate-spin" />}Submit request
          </button>
        </div>
      </form>
    </div>
  );
}

function RegisterServerModal({ apiKey, onClose, onRegistered }: { apiKey: ApiKey; onClose: () => void; onRegistered: (credential: string, header: string) => void }) {
  const [value, setValue] = useState<ServerBindingValue>(emptyServerBinding);
  const [errors, setErrors] = useState<Record<string, string>>({});
  const [saving, setSaving] = useState(false);
  const [error, setError] = useState<string | null>(null);

  const submit = async (e: FormEvent) => {
    e.preventDefault();
    if (saving) return;
    const found = validateServerBinding(value);
    if (Object.keys(found).length) return setErrors(found);
    setErrors({});
    setError(null);
    setSaving(true);
    try {
      const res = await developerService.registerServer(apiKey.id, toServerBindingPayload(value));
      onRegistered(res.installation_credential, res.installation_header);
    } catch (err) {
      setError(describeApiError(err, 'The server could not be registered.').message);
    } finally {
      setSaving(false);
    }
  };

  return (
    <div role="dialog" aria-label="Register authorized server" className="fixed inset-0 z-50 flex items-center justify-center bg-slate-900/50 p-4">
      <form onSubmit={(e) => void submit(e)} noValidate className="w-full max-w-md space-y-4 rounded-xl bg-white p-6 shadow-xl">
        <div className="flex items-center justify-between">
          <h3 className="text-base font-semibold text-slate-900">Register authorized server</h3>
          <button type="button" onClick={onClose} aria-label="Close" className="text-slate-400 hover:text-slate-600"><XCircle className="h-5 w-5" /></button>
        </div>
        <ServerBindingFields value={value} onChange={setValue} errors={errors} idPrefix="register" />
        {error && <p role="alert" className="rounded-lg border border-red-200 bg-red-50 px-3 py-2 text-sm text-red-700">{error}</p>}
        <div className="flex justify-end gap-3">
          <button type="button" onClick={onClose} className="rounded-lg border border-slate-300 px-4 py-2 text-sm font-medium text-slate-700 hover:bg-slate-50">Cancel</button>
          <button type="submit" disabled={saving} className="flex items-center gap-2 rounded-lg bg-indigo-600 px-4 py-2 text-sm font-semibold text-white hover:bg-indigo-700 disabled:opacity-60">
            {saving && <Loader2 className="h-4 w-4 animate-spin" />}Register server
          </button>
        </div>
      </form>
    </div>
  );
}

/** The buyer's "API Access" card for one key: status, authorized server, IP, last use, and the only way to change the server (a request). */
export default function ApiAccessPanel({ apiKey, onChanged }: { apiKey: ApiKey; onChanged: () => void }) {
  const sb = apiKey.server_binding;
  const [modal, setModal] = useState<'change' | 'register' | null>(null);
  const [reveal, setReveal] = useState<{ credential: string; header: string } | null>(null);
  const [issuing, setIssuing] = useState(false);
  const [error, setError] = useState<string | null>(null);

  if (!sb || apiKey.revoked_at) return null;

  const status = STATUS_LABEL[sb.status];
  const binding = sb.binding;
  const ip = binding && binding.ip_policy !== 'NONE' ? (binding.authorized_ips.length ? binding.authorized_ips.join(', ') : binding.registered_ip) : binding ? 'Any (set by Super Admin)' : null;
  const pending = sb.pending_change_request;

  const issue = async () => {
    setIssuing(true);
    setError(null);
    try {
      const res = await developerService.issueInstallationCredential(apiKey.id);
      setReveal({ credential: res.installation_credential, header: res.installation_header });
      onChanged();
    } catch (err) {
      setError(describeApiError(err, 'The credential could not be issued.').message);
    } finally {
      setIssuing(false);
    }
  };

  return (
    <section aria-label={`API Access for ${apiKey.name}`} className="rounded-xl border border-slate-200 bg-white p-4 shadow-sm">
      <div className="flex flex-wrap items-center justify-between gap-2">
        <h4 className="flex items-center gap-2 text-sm font-semibold text-slate-900">
          <KeyRound className="h-4 w-4 text-slate-400" />
          API Access <span className="font-normal text-slate-500">— {apiKey.name}</span>
        </h4>
        <span className={`inline-flex rounded-full border px-2.5 py-0.5 text-xs font-medium ${status.cls}`}>Status: {status.label}</span>
      </div>

      <dl className="mt-3 grid grid-cols-1 gap-x-6 gap-y-2 text-sm sm:grid-cols-3">
        <div>
          <dt className="text-xs uppercase text-slate-400">Authorized Server</dt>
          <dd className="text-slate-800">{binding ? binding.label ?? 'Unnamed server' : '—'}</dd>
        </div>
        <div>
          <dt className="text-xs uppercase text-slate-400">Authorized IP</dt>
          <dd className="font-mono text-xs text-slate-800">{ip ?? '—'}</dd>
        </div>
        <div>
          <dt className="text-xs uppercase text-slate-400">Last Used</dt>
          <dd className="text-slate-800">{fmt(binding?.last_success_at ?? apiKey.last_used_at)}</dd>
        </div>
      </dl>

      <div className="mt-3 flex flex-wrap items-center gap-3 text-sm">
        {sb.status === 'unbound' && (
          <button onClick={() => setModal('register')} className="rounded-lg bg-indigo-600 px-3 py-1.5 text-xs font-semibold text-white hover:bg-indigo-700">
            Register authorized server
          </button>
        )}
        {sb.credential_pending && (
          <button onClick={() => void issue()} disabled={issuing} className="inline-flex items-center gap-1 rounded-lg bg-amber-600 px-3 py-1.5 text-xs font-semibold text-white hover:bg-amber-700 disabled:opacity-60">
            {issuing && <Loader2 className="h-3 w-3 animate-spin" />}Issue installation credential
          </button>
        )}
        {(sb.status === 'active' || sb.status === 'disabled') && !pending && (
          <button onClick={() => setModal('change')} className="rounded-lg border border-slate-300 px-3 py-1.5 text-xs font-medium text-slate-700 hover:bg-slate-50">
            Request Server Change
          </button>
        )}
        {pending && (
          <span className="inline-flex items-center gap-1 text-xs font-medium text-amber-700">
            <Clock className="h-3.5 w-3.5" />
            Server Change Request: Pending Super Admin Approval
          </span>
        )}
        {!pending && sb.last_change_request?.status === 'rejected' && (
          <span className="text-xs text-red-600">Last server change request was rejected{sb.last_change_request.decision_note ? `: ${sb.last_change_request.decision_note}` : '.'}</span>
        )}
      </div>
      {sb.status === 'unbound' && (
        <p role="note" className="mt-2 text-xs text-amber-700">
          This API key requires server authorization before it can be used. Register your authorized server to receive an installation credential; the same key then keeps working from that server only.
        </p>
      )}
      {sb.credential_pending && (
        <p className="mt-2 text-xs text-amber-700">Your new server is approved but has no installation credential yet. Issue it once and add it to the server.</p>
      )}
      {error && <p role="alert" className="mt-2 text-xs text-red-600">{error}</p>}

      <p className="mt-3 flex gap-2 text-xs text-slate-500">
        <ShieldAlert className="mt-0.5 h-3.5 w-3.5 flex-shrink-0" />
        {SERVER_BINDING_WARNING}
      </p>

      {modal === 'change' && (
        <RequestChangeModal
          apiKey={apiKey}
          onClose={() => setModal(null)}
          onDone={() => {
            setModal(null);
            onChanged();
          }}
        />
      )}
      {modal === 'register' && (
        <RegisterServerModal
          apiKey={apiKey}
          onClose={() => setModal(null)}
          onRegistered={(credential, header) => {
            setModal(null);
            setReveal({ credential, header });
            onChanged();
          }}
        />
      )}
      {reveal && <CredentialReveal credential={reveal.credential} header={reveal.header} onDone={() => setReveal(null)} />}
    </section>
  );
}
