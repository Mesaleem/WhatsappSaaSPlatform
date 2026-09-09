import { useCallback, useEffect, useState, type FormEvent } from 'react';
import {
  AlertTriangle,
  CheckCircle2,
  ChevronDown,
  ChevronUp,
  Code2,
  Copy,
  KeyRound,
  Loader2,
  Plus,
  Send,
  ShieldAlert,
  Trash2,
  Webhook as WebhookIcon,
  XCircle,
} from 'lucide-react';
import { useAuth } from '../../core/context/AuthContext';
import accountService from '../../services/accountService';
import developerService from '../../services/developerService';
import type { Account } from '../../types/account';
import {
  WEBHOOK_EVENTS,
  type ApiKey,
  type DeveloperScope,
  type WebhookDelivery,
  type WebhookEvent,
  type WebhookSubscription,
} from '../../types/developer';
import { inputClass, TableCard } from '../../components/common/Card';
import { PageHeader, PageShell } from '../../components/common/PageShell';
import { Pagination, SearchInput, StatusFilterSelect } from '../../components/common/DataTableControls';
import { TableSkeletonRows } from '../../components/common/Skeleton';
import { extractErrorMessage as extractMessage } from '../../utils/apiError';

type Tab = 'keys' | 'webhooks';


function formatDateTime(value: string | null): string {
  if (!value) return '—';
  return new Date(value).toLocaleString(undefined, {
    year: 'numeric',
    month: 'short',
    day: 'numeric',
    hour: '2-digit',
    minute: '2-digit',
  });
}

/** Reusable "copy this secret — shown only once" panel, used by both create modals. */
function OneTimeSecretReveal({ label, value }: { label: string; value: string }) {
  const [copied, setCopied] = useState(false);

  const handleCopy = async () => {
    try {
      await navigator.clipboard.writeText(value);
      setCopied(true);
      setTimeout(() => setCopied(false), 2000);
    } catch {
      // Clipboard API can be unavailable — the value is still selectable below.
    }
  };

  return (
    <div className="space-y-3">
      <div className="flex items-start gap-2 rounded-lg border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-800">
        <ShieldAlert className="mt-0.5 h-4 w-4 flex-shrink-0" />
        <span>
          <strong>Copy {label} now.</strong> For your security, it will not be shown again after you close this
          dialog.
        </span>
      </div>
      <div>
        <label className="text-xs font-medium text-slate-500">{label}</label>
        <div className="mt-1 flex items-center gap-2">
          <code className="flex-1 overflow-x-auto rounded-lg border border-slate-300 bg-slate-50 px-3 py-2 text-xs text-slate-900">
            {value}
          </code>
          <button
            type="button"
            onClick={() => void handleCopy()}
            className="flex items-center gap-1 rounded-lg border border-slate-300 px-3 py-2 text-xs font-medium text-slate-700 hover:bg-slate-50"
          >
            <Copy className="h-3.5 w-3.5" />
            {copied ? 'Copied' : 'Copy'}
          </button>
        </div>
      </div>
    </div>
  );
}

// ---------------------------------------------------------------------------
// Tab 1: API Keys
// ---------------------------------------------------------------------------

function apiKeyStatus(key: ApiKey): { label: string; cls: string } {
  if (key.revoked_at) return { label: 'Revoked', cls: 'bg-slate-100 text-slate-600 border-slate-200' };
  if (key.expires_at && new Date(key.expires_at).getTime() < Date.now()) {
    return { label: 'Expired', cls: 'bg-red-50 text-red-700 border-red-200' };
  }
  return { label: 'Active', cls: 'bg-emerald-50 text-emerald-700 border-emerald-200' };
}

function CreateApiKeyModal({
  isSuperAdmin,
  onClose,
  onCreated,
}: {
  /** Developer Portal & UI Action Restoration — Super Admin gets an Account Selector Dropdown (required: api_keys.account_id is a NOT NULL FK, so there is no schema-level "global" key — this picks which tenant, not whether one is needed). */
  isSuperAdmin: boolean;
  onClose: () => void;
  onCreated: (key: ApiKey) => void;
}) {
  const [name, setName] = useState('');
  const [expiresAt, setExpiresAt] = useState('');
  const [accounts, setAccounts] = useState<Account[]>([]);
  const [accountId, setAccountId] = useState<number | ''>('');
  const [isSaving, setIsSaving] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [revealedKey, setRevealedKey] = useState<string | null>(null);

  useEffect(() => {
    if (isSuperAdmin) {
      void accountService.list({ per_page: 100 }).then((res) => setAccounts(res.data)).catch(() => undefined);
    }
  }, [isSuperAdmin]);

  const handleSubmit = async (e: FormEvent) => {
    e.preventDefault();
    if (!name.trim()) {
      setError('Name is required.');
      return;
    }
    if (isSuperAdmin && accountId === '') {
      setError('Select which client account this key is for.');
      return;
    }
    setError(null);
    setIsSaving(true);
    try {
      const result = await developerService.createApiKey(
        {
          name: name.trim(),
          expires_at: expiresAt ? new Date(expiresAt).toISOString() : null,
        },
        isSuperAdmin ? (accountId as number) : undefined,
      );
      setRevealedKey(result.plain_text_key);
      onCreated(result.api_key);
    } catch (err) {
      setError(extractMessage(err, 'Could not create this API key.'));
    } finally {
      setIsSaving(false);
    }
  };

  return (
    <div className="fixed inset-0 z-50 flex items-center justify-center bg-slate-900/50 p-4">
      <div className="w-full max-w-md rounded-xl bg-white p-6 shadow-xl">
        <div className="flex items-center justify-between">
          <h3 className="text-base font-semibold text-slate-900">
            {revealedKey ? 'API key created' : 'Create API key'}
          </h3>
          <button onClick={onClose} className="text-slate-400 hover:text-slate-600">
            <XCircle className="h-5 w-5" />
          </button>
        </div>

        {revealedKey ? (
          <div className="mt-4 space-y-4">
            <OneTimeSecretReveal label="API key" value={revealedKey} />
            <div className="flex justify-end">
              <button
                onClick={onClose}
                className="rounded-lg bg-indigo-600 px-4 py-2 text-sm font-semibold text-white shadow-sm hover:bg-indigo-700"
              >
                Done
              </button>
            </div>
          </div>
        ) : (
          <form onSubmit={(e) => void handleSubmit(e)} className="mt-4 space-y-4">
            {isSuperAdmin && (
              <div>
                <label className="text-sm font-medium text-slate-700">Client account</label>
                <select
                  value={accountId}
                  onChange={(e) => setAccountId(e.target.value === '' ? '' : Number(e.target.value))}
                  className={inputClass}
                  autoFocus
                >
                  <option value="">Select a client account…</option>
                  {accounts.map((a) => (
                    <option key={a.id} value={a.id}>
                      {a.company_name}
                    </option>
                  ))}
                </select>
                <p className="mt-1 text-xs text-slate-500">Every API key belongs to exactly one client account.</p>
              </div>
            )}
            <div>
              <label className="text-sm font-medium text-slate-700">Name</label>
              <input
                value={name}
                onChange={(e) => setName(e.target.value)}
                placeholder="e.g. Production integration"
                className={inputClass}
                autoFocus={!isSuperAdmin}
              />
            </div>
            <div>
              <label className="text-sm font-medium text-slate-700">Expires at (optional)</label>
              <input
                type="date"
                value={expiresAt}
                onChange={(e) => setExpiresAt(e.target.value)}
                min={new Date().toISOString().slice(0, 10)}
                className={inputClass}
              />
              <p className="mt-1 text-xs text-slate-500">Leave blank for a key that never expires.</p>
            </div>

            {error && (
              <div className="flex items-center gap-2 rounded-lg border border-red-200 bg-red-50 px-3 py-2 text-sm text-red-700">
                <XCircle className="h-4 w-4 flex-shrink-0" />
                {error}
              </div>
            )}

            <div className="flex justify-end gap-3">
              <button
                type="button"
                onClick={onClose}
                className="rounded-lg border border-slate-300 px-4 py-2 text-sm font-medium text-slate-700 hover:bg-slate-50"
              >
                Cancel
              </button>
              <button
                type="submit"
                disabled={isSaving}
                className="flex items-center gap-2 rounded-lg bg-indigo-600 px-4 py-2 text-sm font-semibold text-white shadow-sm hover:bg-indigo-700 disabled:opacity-60"
              >
                {isSaving ? <Loader2 className="h-4 w-4 animate-spin" /> : <KeyRound className="h-4 w-4" />}
                Generate Key
              </button>
            </div>
          </form>
        )}
      </div>
    </div>
  );
}

const API_KEY_STATUS_OPTIONS = [
  { value: 'active', label: 'Active' },
  { value: 'expired', label: 'Expired' },
  { value: 'revoked', label: 'Revoked' },
];

function ApiKeysTab() {
  const { isSuperAdmin } = useAuth();
  const [keys, setKeys] = useState<ApiKey[]>([]);
  const [scope, setScope] = useState<DeveloperScope>('account');
  const [search, setSearch] = useState('');
  const [statusFilter, setStatusFilter] = useState('');
  const [from, setFrom] = useState('');
  const [to, setTo] = useState('');
  const [page, setPage] = useState(1);
  const [perPage, setPerPage] = useState(10);
  const [isLoading, setIsLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);
  const [showCreate, setShowCreate] = useState(false);
  const [revokingId, setRevokingId] = useState<number | null>(null);

  const load = useCallback(async () => {
    setIsLoading(true);
    setError(null);
    try {
      const res = await developerService.listApiKeys();
      setKeys(res.data);
      setScope(res.scope);
    } catch (err) {
      setError(extractMessage(err, 'Failed to load API keys.'));
    } finally {
      setIsLoading(false);
    }
  }, []);

  useEffect(() => {
    void load();
  }, [load]);

  const handleRevoke = async (key: ApiKey) => {
    if (!confirm(`Revoke "${key.name}"? Any integration using it will stop working immediately.`)) return;
    setRevokingId(key.id);
    try {
      await developerService.revokeApiKey(key.id);
      await load();
    } catch (err) {
      setError(extractMessage(err, 'Could not revoke this key.'));
    } finally {
      setRevokingId(null);
    }
  };

  const filteredKeys = keys.filter((key) => {
    const q = search.trim().toLowerCase();
    if (q) {
      const matches =
        key.name.toLowerCase().includes(q) ||
        key.key_prefix.toLowerCase().includes(q) ||
        (key.account?.company_name.toLowerCase().includes(q) ?? false);
      if (!matches) return false;
    }
    if (statusFilter) {
      const status = apiKeyStatus(key).label.toLowerCase();
      if (status !== statusFilter) return false;
    }
    // Date Range Pickers — filters by the key's own creation date, the
    // same calendar-day comparison used across every other table.
    const createdDay = key.created_at.slice(0, 10);
    if (from && createdDay < from) return false;
    if (to && createdDay > to) return false;
    return true;
  });

  useEffect(() => {
    setPage(1);
  }, [search, statusFilter, from, to]);

  const total = filteredKeys.length;
  const lastPage = Math.max(1, Math.ceil(total / perPage));
  const currentPage = Math.min(page, lastPage);
  const pagedKeys = filteredKeys.slice((currentPage - 1) * perPage, currentPage * perPage);

  return (
    <div className="space-y-4">
      <div className="flex flex-wrap items-center justify-between gap-3">
        <p className="text-sm text-slate-500">
          {scope === 'global'
            ? 'Every API key across every client account. Select a client in the header to create a new one.'
            : (
              <>
                Keys authenticate external systems calling{' '}
                <code className="font-mono text-xs">POST /api/v1/messages/send-payment-alert</code>.
              </>
            )}
        </p>
        {(scope === 'account' || isSuperAdmin()) && (
          <button
            onClick={() => setShowCreate(true)}
            className="flex items-center gap-2 rounded-lg bg-indigo-600 px-4 py-2 text-sm font-semibold text-white shadow-sm hover:bg-indigo-700"
          >
            <Plus className="h-4 w-4" />
            Create API Key
          </button>
        )}
      </div>

      <div className="flex flex-wrap items-center gap-3">
        <SearchInput value={search} onChange={setSearch} placeholder="Search by name, key or client…" />
        <StatusFilterSelect value={statusFilter} onChange={setStatusFilter} options={API_KEY_STATUS_OPTIONS} allLabel="All statuses" />
        <div className="flex items-center gap-2">
          <input
            type="date"
            value={from}
            onChange={(e) => setFrom(e.target.value)}
            className="rounded-lg border border-slate-300 px-3 py-2 text-sm text-slate-900 shadow-sm outline-none transition focus:border-indigo-500 focus:ring-2 focus:ring-indigo-100 w-40"
            aria-label="Created from"
          />
          <span className="text-sm text-slate-400">to</span>
          <input
            type="date"
            value={to}
            onChange={(e) => setTo(e.target.value)}
            className="rounded-lg border border-slate-300 px-3 py-2 text-sm text-slate-900 shadow-sm outline-none transition focus:border-indigo-500 focus:ring-2 focus:ring-indigo-100 w-40"
            aria-label="Created to"
          />
        </div>
      </div>

      {error && (
        <div className="flex items-center gap-2 rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700">
          <XCircle className="h-4 w-4 flex-shrink-0" />
          {error}
        </div>
      )}

      <TableCard>
        <table className="min-w-full divide-y divide-slate-200 text-sm">
          <thead className="bg-slate-50 text-left text-xs font-medium uppercase text-slate-500">
            <tr>
              <th className="px-6 py-3">Name</th>
              {scope === 'global' && <th className="px-6 py-3">Client</th>}
              <th className="px-6 py-3">Key</th>
              <th className="px-6 py-3">Status</th>
              <th className="px-6 py-3">Last Used</th>
              <th className="px-6 py-3">Expires</th>
              <th className="px-6 py-3 text-right">Action</th>
            </tr>
          </thead>
          <tbody className="divide-y divide-slate-100">
            {isLoading ? (
              <TableSkeletonRows columns={scope === 'global' ? 7 : 6} />
            ) : pagedKeys.length > 0 ? (
              pagedKeys.map((key) => {
                const status = apiKeyStatus(key);
                return (
                  <tr key={key.id}>
                    <td className="px-6 py-3 font-medium text-slate-900">{key.name}</td>
                    {scope === 'global' && (
                      <td className="px-6 py-3 text-slate-600">{key.account?.company_name ?? '—'}</td>
                    )}
                    <td className="px-6 py-3 font-mono text-xs text-slate-500">{key.key_prefix}…</td>
                    <td className="px-6 py-3">
                      <span className={`inline-flex items-center rounded-full border px-2.5 py-0.5 text-xs font-medium ${status.cls}`}>
                        {status.label}
                      </span>
                    </td>
                    <td className="px-6 py-3 text-slate-700">{formatDateTime(key.last_used_at)}</td>
                    <td className="px-6 py-3 text-slate-700">{key.expires_at ? formatDateTime(key.expires_at) : 'Never'}</td>
                    <td className="px-6 py-3 text-right">
                      {!key.revoked_at && (
                        <button
                          onClick={() => void handleRevoke(key)}
                          disabled={revokingId === key.id}
                          className="inline-flex items-center gap-1 text-xs font-medium text-red-600 hover:text-red-700 disabled:opacity-60"
                        >
                          {revokingId === key.id ? (
                            <Loader2 className="h-3 w-3 animate-spin" />
                          ) : (
                            <Trash2 className="h-3 w-3" />
                          )}
                          Revoke
                        </button>
                      )}
                    </td>
                  </tr>
                );
              })
            ) : (
              <tr>
                <td colSpan={scope === 'global' ? 7 : 6} className="px-6 py-6 text-center text-slate-400">
                  {keys.length === 0 ? 'No API keys yet.' : 'No API keys match the current filters.'}
                </td>
              </tr>
            )}
          </tbody>
        </table>
      </TableCard>

      <Pagination
        page={currentPage}
        lastPage={lastPage}
        total={total}
        perPage={perPage}
        onPageChange={setPage}
        onPerPageChange={setPerPage}
        perPageOptions={[10, 15, 25, 50]}
      />

      {showCreate && (
        <CreateApiKeyModal
          isSuperAdmin={isSuperAdmin()}
          onClose={() => {
            setShowCreate(false);
            void load();
          }}
          onCreated={() => void load()}
        />
      )}
    </div>
  );
}

// ---------------------------------------------------------------------------
// Tab 2: Webhook Subscriptions
// ---------------------------------------------------------------------------

const EVENT_LABELS: Record<WebhookEvent, string> = {
  'message.sent': 'Message Sent',
  'message.failed': 'Message Failed',
  'message.delivered': 'Message Delivered',
};

function CreateWebhookModal({
  isSuperAdmin,
  onClose,
  onCreated,
}: {
  /** Developer Portal & UI Action Restoration — same Account Selector requirement as CreateApiKeyModal: webhook_subscriptions.account_id is a NOT NULL FK, so Super Admin must pick which tenant. */
  isSuperAdmin: boolean;
  onClose: () => void;
  onCreated: () => void;
}) {
  const [url, setUrl] = useState('');
  const [secret, setSecret] = useState('');
  const [events, setEvents] = useState<WebhookEvent[]>(['message.sent', 'message.failed']);
  const [accounts, setAccounts] = useState<Account[]>([]);
  const [accountId, setAccountId] = useState<number | ''>('');
  const [isSaving, setIsSaving] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [revealedSecret, setRevealedSecret] = useState<string | null>(null);

  useEffect(() => {
    if (isSuperAdmin) {
      void accountService.list({ per_page: 100 }).then((res) => setAccounts(res.data)).catch(() => undefined);
    }
  }, [isSuperAdmin]);

  const toggleEvent = (event: WebhookEvent) => {
    setEvents((prev) => (prev.includes(event) ? prev.filter((e) => e !== event) : [...prev, event]));
  };

  const handleSubmit = async (e: FormEvent) => {
    e.preventDefault();
    if (!url.trim()) {
      setError('Webhook URL is required.');
      return;
    }
    if (events.length === 0) {
      setError('Select at least one event.');
      return;
    }
    if (isSuperAdmin && accountId === '') {
      setError('Select which client account this webhook is for.');
      return;
    }
    setError(null);
    setIsSaving(true);
    try {
      const result = await developerService.createWebhook(
        {
          url: url.trim(),
          secret: secret.trim() || undefined,
          events,
        },
        isSuperAdmin ? (accountId as number) : undefined,
      );
      setRevealedSecret(result.plain_text_secret);
      onCreated();
    } catch (err) {
      setError(extractMessage(err, 'Could not register this webhook.'));
    } finally {
      setIsSaving(false);
    }
  };

  return (
    <div className="fixed inset-0 z-50 flex items-center justify-center bg-slate-900/50 p-4">
      <div className="w-full max-w-md rounded-xl bg-white p-6 shadow-xl">
        <div className="flex items-center justify-between">
          <h3 className="text-base font-semibold text-slate-900">
            {revealedSecret ? 'Webhook registered' : 'Add webhook'}
          </h3>
          <button onClick={onClose} className="text-slate-400 hover:text-slate-600">
            <XCircle className="h-5 w-5" />
          </button>
        </div>

        {revealedSecret ? (
          <div className="mt-4 space-y-4">
            <OneTimeSecretReveal label="Signing secret" value={revealedSecret} />
            <p className="text-xs text-slate-500">
              Verify each delivery by recomputing HMAC-SHA256 of the raw request body with this secret and comparing
              it to the <code className="font-mono">X-WASAAS-Signature</code> header.
            </p>
            <div className="flex justify-end">
              <button
                onClick={onClose}
                className="rounded-lg bg-indigo-600 px-4 py-2 text-sm font-semibold text-white shadow-sm hover:bg-indigo-700"
              >
                Done
              </button>
            </div>
          </div>
        ) : (
          <form onSubmit={(e) => void handleSubmit(e)} className="mt-4 space-y-4">
            {isSuperAdmin && (
              <div>
                <label className="text-sm font-medium text-slate-700">Client account</label>
                <select
                  value={accountId}
                  onChange={(e) => setAccountId(e.target.value === '' ? '' : Number(e.target.value))}
                  className={inputClass}
                  autoFocus
                >
                  <option value="">Select a client account…</option>
                  {accounts.map((a) => (
                    <option key={a.id} value={a.id}>
                      {a.company_name}
                    </option>
                  ))}
                </select>
                <p className="mt-1 text-xs text-slate-500">Every webhook belongs to exactly one client account.</p>
              </div>
            )}
            <div>
              <label className="text-sm font-medium text-slate-700">Webhook URL</label>
              <input
                value={url}
                onChange={(e) => setUrl(e.target.value)}
                placeholder="https://example.com/webhooks/wasaas"
                className={inputClass}
                autoFocus={!isSuperAdmin}
              />
            </div>
            <div>
              <label className="text-sm font-medium text-slate-700">Secret (optional)</label>
              <input
                value={secret}
                onChange={(e) => setSecret(e.target.value)}
                placeholder="Leave blank to auto-generate a strong secret"
                className={inputClass}
                autoComplete="off"
              />
            </div>
            <div>
              <label className="text-sm font-medium text-slate-700">Events</label>
              <div className="mt-2 space-y-2">
                {WEBHOOK_EVENTS.map((event) => (
                  <label key={event} className="flex items-center gap-2 text-sm text-slate-700">
                    <input
                      type="checkbox"
                      checked={events.includes(event)}
                      onChange={() => toggleEvent(event)}
                      className="h-4 w-4 rounded border-slate-300 text-indigo-600 focus:ring-indigo-500"
                    />
                    {EVENT_LABELS[event]}
                    <code className="text-xs text-slate-400">{event}</code>
                  </label>
                ))}
              </div>
            </div>

            {error && (
              <div className="flex items-center gap-2 rounded-lg border border-red-200 bg-red-50 px-3 py-2 text-sm text-red-700">
                <XCircle className="h-4 w-4 flex-shrink-0" />
                {error}
              </div>
            )}

            <div className="flex justify-end gap-3">
              <button
                type="button"
                onClick={onClose}
                className="rounded-lg border border-slate-300 px-4 py-2 text-sm font-medium text-slate-700 hover:bg-slate-50"
              >
                Cancel
              </button>
              <button
                type="submit"
                disabled={isSaving}
                className="flex items-center gap-2 rounded-lg bg-indigo-600 px-4 py-2 text-sm font-semibold text-white shadow-sm hover:bg-indigo-700 disabled:opacity-60"
              >
                {isSaving ? <Loader2 className="h-4 w-4 animate-spin" /> : <WebhookIcon className="h-4 w-4" />}
                Register Webhook
              </button>
            </div>
          </form>
        )}
      </div>
    </div>
  );
}

function DeliveryLogRow({ delivery }: { delivery: WebhookDelivery }) {
  const cls =
    delivery.status === 'success'
      ? 'bg-emerald-50 text-emerald-700 border-emerald-200'
      : 'bg-red-50 text-red-700 border-red-200';
  return (
    <tr>
      <td className="px-4 py-2 font-mono text-xs text-slate-700">{delivery.event}</td>
      <td className="px-4 py-2">
        <span className={`inline-flex items-center rounded-full border px-2 py-0.5 text-xs font-medium ${cls}`}>
          {delivery.status}
        </span>
      </td>
      <td className="px-4 py-2 text-slate-700">{delivery.response_code ?? '—'}</td>
      <td className="px-4 py-2 text-slate-700">#{delivery.attempt}</td>
      <td className="px-4 py-2 text-slate-500">{formatDateTime(delivery.created_at)}</td>
    </tr>
  );
}

function WebhookRow({
  webhook,
  showClient = false,
  onChanged,
}: {
  webhook: WebhookSubscription;
  showClient?: boolean;
  onChanged: () => void;
}) {
  const [isExpanded, setIsExpanded] = useState(false);
  const [deliveries, setDeliveries] = useState<WebhookDelivery[] | null>(null);
  const [isLoadingDeliveries, setIsLoadingDeliveries] = useState(false);
  const [isTesting, setIsTesting] = useState(false);
  const [testResult, setTestResult] = useState<{ success: boolean; message: string } | null>(null);
  const [isDeleting, setIsDeleting] = useState(false);

  const toggleExpanded = async () => {
    const next = !isExpanded;
    setIsExpanded(next);
    if (next && deliveries === null) {
      setIsLoadingDeliveries(true);
      try {
        const data = await developerService.getDeliveries(webhook.id);
        setDeliveries(data);
      } catch {
        setDeliveries([]);
      } finally {
        setIsLoadingDeliveries(false);
      }
    }
  };

  const handleTest = async () => {
    setIsTesting(true);
    setTestResult(null);
    try {
      const result = await developerService.testWebhook(webhook.id);
      setTestResult({ success: result.success, message: result.message });
      if (isExpanded) {
        const data = await developerService.getDeliveries(webhook.id);
        setDeliveries(data);
      }
    } catch (err) {
      setTestResult({ success: false, message: extractMessage(err, 'Test failed.') });
    } finally {
      setIsTesting(false);
    }
  };

  const handleDelete = async () => {
    if (!confirm('Delete this webhook subscription? This cannot be undone.')) return;
    setIsDeleting(true);
    try {
      await developerService.deleteWebhook(webhook.id);
      onChanged();
    } catch {
      setIsDeleting(false);
    }
  };

  return (
    <div className="rounded-xl border border-slate-200 bg-white shadow-sm">
      <div className="flex flex-wrap items-center justify-between gap-3 p-4">
        <div className="min-w-0 flex-1">
          <p className="truncate font-mono text-sm text-slate-900">{webhook.url}</p>
          {showClient && (
            <p className="mt-0.5 text-xs text-slate-500">{webhook.account?.company_name ?? '—'}</p>
          )}
          <div className="mt-1 flex flex-wrap gap-1.5">
            {webhook.events.map((event) => (
              <span key={event} className="rounded-full border border-slate-200 bg-slate-50 px-2 py-0.5 text-xs text-slate-600">
                {event}
              </span>
            ))}
            <span
              className={`rounded-full border px-2 py-0.5 text-xs font-medium ${
                webhook.is_active
                  ? 'border-emerald-200 bg-emerald-50 text-emerald-700'
                  : 'border-slate-200 bg-slate-50 text-slate-500'
              }`}
            >
              {webhook.is_active ? 'Active' : 'Inactive'}
            </span>
          </div>
        </div>
        <div className="flex items-center gap-2">
          <button
            onClick={() => void handleTest()}
            disabled={isTesting}
            className="flex items-center gap-1 rounded-lg border border-slate-300 px-3 py-1.5 text-xs font-medium text-slate-700 hover:bg-slate-50 disabled:opacity-60"
          >
            {isTesting ? <Loader2 className="h-3.5 w-3.5 animate-spin" /> : <Send className="h-3.5 w-3.5" />}
            Test
          </button>
          <button
            onClick={() => void toggleExpanded()}
            className="flex items-center gap-1 rounded-lg border border-slate-300 px-3 py-1.5 text-xs font-medium text-slate-700 hover:bg-slate-50"
          >
            {isExpanded ? <ChevronUp className="h-3.5 w-3.5" /> : <ChevronDown className="h-3.5 w-3.5" />}
            Logs
          </button>
          <button
            onClick={() => void handleDelete()}
            disabled={isDeleting}
            className="flex items-center gap-1 rounded-lg border border-red-200 px-3 py-1.5 text-xs font-medium text-red-600 hover:bg-red-50 disabled:opacity-60"
          >
            {isDeleting ? <Loader2 className="h-3.5 w-3.5 animate-spin" /> : <Trash2 className="h-3.5 w-3.5" />}
            Delete
          </button>
        </div>
      </div>

      {testResult && (
        <div
          className={`mx-4 mb-3 flex items-center gap-2 rounded-lg border px-3 py-2 text-xs ${
            testResult.success
              ? 'border-emerald-200 bg-emerald-50 text-emerald-700'
              : 'border-red-200 bg-red-50 text-red-700'
          }`}
        >
          {testResult.success ? <CheckCircle2 className="h-3.5 w-3.5 flex-shrink-0" /> : <XCircle className="h-3.5 w-3.5 flex-shrink-0" />}
          {testResult.message}
        </div>
      )}

      {isExpanded && (
        <div className="border-t border-slate-100 px-4 py-3">
          {isLoadingDeliveries ? (
            <div className="flex items-center gap-2 text-xs text-slate-400">
              <Loader2 className="h-3.5 w-3.5 animate-spin" />
              Loading delivery log…
            </div>
          ) : deliveries && deliveries.length > 0 ? (
            <div className="overflow-x-auto">
              <table className="min-w-full text-xs">
                <thead className="text-left uppercase text-slate-400">
                  <tr>
                    <th className="px-4 py-1.5">Event</th>
                    <th className="px-4 py-1.5">Status</th>
                    <th className="px-4 py-1.5">HTTP</th>
                    <th className="px-4 py-1.5">Attempt</th>
                    <th className="px-4 py-1.5">When</th>
                  </tr>
                </thead>
                <tbody className="divide-y divide-slate-50">
                  {deliveries.map((d) => (
                    <DeliveryLogRow key={d.id} delivery={d} />
                  ))}
                </tbody>
              </table>
            </div>
          ) : (
            <p className="text-xs text-slate-400">No deliveries yet.</p>
          )}
        </div>
      )}
    </div>
  );
}

const WEBHOOK_STATUS_OPTIONS = [
  { value: 'active', label: 'Active' },
  { value: 'inactive', label: 'Inactive' },
];

function WebhooksTab() {
  const { isSuperAdmin } = useAuth();
  const [webhooks, setWebhooks] = useState<WebhookSubscription[]>([]);
  const [scope, setScope] = useState<DeveloperScope>('account');
  const [search, setSearch] = useState('');
  const [statusFilter, setStatusFilter] = useState('');
  const [page, setPage] = useState(1);
  const [perPage, setPerPage] = useState(10);
  const [isLoading, setIsLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);
  const [showCreate, setShowCreate] = useState(false);

  const load = useCallback(async () => {
    setIsLoading(true);
    setError(null);
    try {
      const res = await developerService.listWebhooks();
      setWebhooks(res.data);
      setScope(res.scope);
    } catch (err) {
      setError(extractMessage(err, 'Failed to load webhooks.'));
    } finally {
      setIsLoading(false);
    }
  }, []);

  useEffect(() => {
    void load();
  }, [load]);

  const filteredWebhooks = webhooks.filter((w) => {
    const q = search.trim().toLowerCase();
    if (q && !w.url.toLowerCase().includes(q) && !(w.account?.company_name.toLowerCase().includes(q) ?? false)) {
      return false;
    }
    if (statusFilter === 'active' && !w.is_active) return false;
    if (statusFilter === 'inactive' && w.is_active) return false;
    return true;
  });

  useEffect(() => {
    setPage(1);
  }, [search, statusFilter]);

  const total = filteredWebhooks.length;
  const lastPage = Math.max(1, Math.ceil(total / perPage));
  const currentPage = Math.min(page, lastPage);
  const pagedWebhooks = filteredWebhooks.slice((currentPage - 1) * perPage, currentPage * perPage);

  return (
    <div className="space-y-4">
      <div className="flex flex-wrap items-center justify-between gap-3">
        <p className="text-sm text-slate-500">
          {scope === 'global' ? (
            'Every webhook subscription across every client account. Select a client in the header to add a new one.'
          ) : (
            <>
              Get an HTTP POST whenever a payment alert changes status, signed with{' '}
              <code className="font-mono text-xs">X-WASAAS-Signature</code>.
            </>
          )}
        </p>
        {(scope === 'account' || isSuperAdmin()) && (
          <button
            onClick={() => setShowCreate(true)}
            className="flex items-center gap-2 rounded-lg bg-indigo-600 px-4 py-2 text-sm font-semibold text-white shadow-sm hover:bg-indigo-700"
          >
            <Plus className="h-4 w-4" />
            Add Webhook
          </button>
        )}
      </div>

      <div className="flex flex-wrap items-center gap-3">
        <SearchInput value={search} onChange={setSearch} placeholder="Search by URL or client…" />
        <StatusFilterSelect value={statusFilter} onChange={setStatusFilter} options={WEBHOOK_STATUS_OPTIONS} allLabel="All statuses" />
      </div>

      {error && (
        <div className="flex items-center gap-2 rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700">
          <XCircle className="h-4 w-4 flex-shrink-0" />
          {error}
        </div>
      )}

      {isLoading ? (
        <div className="flex items-center gap-2 text-sm text-slate-500">
          <Loader2 className="h-4 w-4 animate-spin" />
          Loading webhooks…
        </div>
      ) : pagedWebhooks.length > 0 ? (
        <div className="space-y-3">
          {pagedWebhooks.map((webhook) => (
            <WebhookRow
              key={webhook.id}
              webhook={webhook}
              showClient={scope === 'global'}
              onChanged={() => void load()}
            />
          ))}
        </div>
      ) : (
        <div className="rounded-xl border border-slate-200 bg-white p-6 text-center text-sm text-slate-400 shadow-sm">
          {webhooks.length === 0 ? 'No webhooks registered yet.' : 'No webhooks match the current filters.'}
        </div>
      )}

      {pagedWebhooks.length > 0 && (
        <Pagination
          page={currentPage}
          lastPage={lastPage}
          total={total}
          perPage={perPage}
          onPageChange={setPage}
          onPerPageChange={setPerPage}
          perPageOptions={[10, 15, 25, 50]}
        />
      )}

      {showCreate && (
        <CreateWebhookModal
          isSuperAdmin={isSuperAdmin()}
          onClose={() => setShowCreate(false)}
          onCreated={() => void load()}
        />
      )}
    </div>
  );
}

// ---------------------------------------------------------------------------
// Page shell
// ---------------------------------------------------------------------------

/**
 * Module 9 — Developer Portal. Tenant Admin only (route-gated
 * permission="manage-developer-settings").
 */
export default function DeveloperPage() {
  const [tab, setTab] = useState<Tab>('keys');

  return (
    <PageShell maxWidthClassName="max-w-full">
      <PageHeader
        icon={Code2}
        title="Developer Portal"
        subtitle="Manage API keys and outbound webhook subscriptions."
      />

        <div className="flex gap-1 border-b border-slate-200">
          <button
            onClick={() => setTab('keys')}
            className={`flex items-center gap-2 border-b-2 px-4 py-2 text-sm font-medium ${
              tab === 'keys' ? 'border-indigo-600 text-indigo-600' : 'border-transparent text-slate-500 hover:text-slate-700'
            }`}
          >
            <KeyRound className="h-4 w-4" />
            API Keys
          </button>
          <button
            onClick={() => setTab('webhooks')}
            className={`flex items-center gap-2 border-b-2 px-4 py-2 text-sm font-medium ${
              tab === 'webhooks' ? 'border-indigo-600 text-indigo-600' : 'border-transparent text-slate-500 hover:text-slate-700'
            }`}
          >
            <WebhookIcon className="h-4 w-4" />
            Webhook Subscriptions
          </button>
        </div>

      {tab === 'keys' ? <ApiKeysTab /> : <WebhooksTab />}

      <p className="flex items-center gap-1.5 text-xs text-slate-400">
        <AlertTriangle className="h-3.5 w-3.5" />
        API keys and webhook secrets grant access to your account's messaging — store them like passwords.
      </p>
    </PageShell>
  );
}
