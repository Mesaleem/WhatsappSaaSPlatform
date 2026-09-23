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
import ConfirmModal from '../../components/common/ConfirmModal';
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
import { ClearFiltersButton, Pagination, SearchInput, StatusFilterSelect } from '../../components/common/DataTableControls';
import { TableSkeletonRows } from '../../components/common/Skeleton';
import { describeApiError, extractErrorMessage as extractMessage } from '../../utils/apiError';

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
  // Developer API Key Management UI — the clipboard failure used to be
  // swallowed silently, which is the one moment in this whole flow where
  // a silent failure is unrecoverable: the value is shown exactly once,
  // so a user who believes they copied it and closes the dialog has lost
  // it for good. Now it says so and points at the selectable <code>.
  const [copyFailed, setCopyFailed] = useState(false);

  const handleCopy = async () => {
    try {
      await navigator.clipboard.writeText(value);
      setCopied(true);
      setCopyFailed(false);
      setTimeout(() => setCopied(false), 2000);
    } catch {
      setCopied(false);
      setCopyFailed(true);
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
            aria-label={`Copy ${label}`}
            className="flex items-center gap-1 rounded-lg border border-slate-300 px-3 py-2 text-xs font-medium text-slate-700 hover:bg-slate-50"
          >
            <Copy className="h-3.5 w-3.5" />
            {copied ? 'Copied' : 'Copy'}
          </button>
        </div>
        {copyFailed && (
          <p role="alert" className="mt-1.5 text-xs text-red-600">
            Could not copy automatically. Select the value above and copy it manually before closing.
          </p>
        )}
      </div>
    </div>
  );
}

/**
 * Developer API Key Management UI — inline, per-field validation message,
 * fed either by this form's own pre-submit checks or by mapping a 422's
 * `errors` object onto the matching input (see describeApiError()).
 */
function FieldError({ message }: { message?: string }) {
  if (!message) return null;

  return (
    <p role="alert" className="mt-1.5 text-xs text-red-600">
      {message}
    </p>
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
  const [fieldErrors, setFieldErrors] = useState<Record<string, string>>({});
  const [revealedKey, setRevealedKey] = useState<string | null>(null);
  const [revealedSecret, setRevealedSecret] = useState<string | null>(null);

  useEffect(() => {
    if (isSuperAdmin) {
      void accountService.list({ per_page: 100 }).then((res) => setAccounts(res.data)).catch(() => undefined);
    }
  }, [isSuperAdmin]);

  /**
   * The earliest date the backend will accept. ApiKeyController::store()
   * validates `expires_at` as `after:now`, and a date-only input is
   * parsed as that day's midnight — so "today" is always already in the
   * past by the time it is submitted and would come back as a confusing
   * 422. Tomorrow is the first value that can actually succeed.
   */
  const minExpiryDate = (() => {
    const tomorrow = new Date();
    tomorrow.setDate(tomorrow.getDate() + 1);
    return tomorrow.toISOString().slice(0, 10);
  })();

  const clearFieldError = (field: string) => {
    setFieldErrors((prev) => {
      if (!(field in prev)) return prev;
      const next = { ...prev };
      delete next[field];
      return next;
    });
  };

  const handleSubmit = async (e: FormEvent) => {
    e.preventDefault();
    // Double-submit guard: the button is already disabled while saving,
    // but Enter-in-a-text-input can still fire submit before React has
    // re-rendered the disabled state.
    if (isSaving) return;

    // Frontend validation only PRE-empts the backend's — it never
    // replaces it. ApiKeyController::store()'s own rules stay
    // authoritative, and anything it rejects is mapped back onto these
    // same fields below.
    const nextFieldErrors: Record<string, string> = {};

    if (!name.trim()) {
      nextFieldErrors.name = 'API key name is required.';
    } else if (name.trim().length > 255) {
      nextFieldErrors.name = 'Name cannot be longer than 255 characters.';
    }

    if (expiresAt && expiresAt < minExpiryDate) {
      nextFieldErrors.expires_at = 'Expiry must be a future date.';
    }

    if (isSuperAdmin && accountId === '') {
      nextFieldErrors.account_id = 'Select which client account this key is for.';
    }

    if (Object.keys(nextFieldErrors).length > 0) {
      setFieldErrors(nextFieldErrors);
      setError(null);
      return;
    }

    setFieldErrors({});
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
      setRevealedSecret(result.plain_text_secret);
      onCreated(result.api_key);
    } catch (err) {
      const described = describeApiError(err, 'The API key could not be created. Please try again.');
      setError(described.message);
      // A 422 puts its messages against `name` / `expires_at` inline;
      // every other status carries no field detail and shows the banner
      // only.
      setFieldErrors(described.fieldErrors);
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
            {/* Developer API Platform for WhatsApp Group Creation & Unified Messaging — the dual-factor secret, needed alongside the key for the new POST /api/v1/whatsapp/* endpoints. */}
            {revealedSecret && <OneTimeSecretReveal label="API secret" value={revealedSecret} />}
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
          <form onSubmit={(e) => void handleSubmit(e)} noValidate className="mt-4 space-y-4">
            {isSuperAdmin && (
              <div>
                <label className="text-sm font-medium text-slate-700">Client account <span className="text-red-500">*</span></label>
                <select
                  value={accountId}
                  onChange={(e) => {
                    setAccountId(e.target.value === '' ? '' : Number(e.target.value));
                    clearFieldError('account_id');
                  }}
                  aria-label="Client account"
                  aria-invalid={Boolean(fieldErrors.account_id)}
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
                <FieldError message={fieldErrors.account_id} />
                <p className="mt-1 text-xs text-slate-500">Every API key belongs to exactly one client account.</p>
              </div>
            )}
            {/*
              The expiry input carries min={minExpiryDate}, and native
              constraint validation blocks submission on a violation
              BEFORE handleSubmit runs — the user gets a browser tooltip
              whose wording and styling this app does not control, and
              our own "Expiry must be a future date." never appears. The
              form below is therefore marked noValidate so handleSubmit's
              checks are the single, consistently-styled source of
              client-side validation; min= stays as a picker affordance.
              The backend's rules remain authoritative either way.
            */}
            <div>
              <label htmlFor="api-key-name" className="text-sm font-medium text-slate-700">
                Name <span className="text-red-500">*</span>
              </label>
              <input
                id="api-key-name"
                value={name}
                onChange={(e) => {
                  setName(e.target.value);
                  clearFieldError('name');
                }}
                placeholder="e.g. Production integration"
                aria-invalid={Boolean(fieldErrors.name)}
                className={inputClass}
                autoFocus={!isSuperAdmin}
              />
              <FieldError message={fieldErrors.name} />
            </div>
            <div>
              <label htmlFor="api-key-expires-at" className="text-sm font-medium text-slate-700">
                Expires at (optional)
              </label>
              <input
                id="api-key-expires-at"
                type="date"
                value={expiresAt}
                onChange={(e) => {
                  setExpiresAt(e.target.value);
                  clearFieldError('expires_at');
                }}
                min={minExpiryDate}
                aria-invalid={Boolean(fieldErrors.expires_at)}
                className={inputClass}
              />
              <FieldError message={fieldErrors.expires_at} />
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
  // Developer API Platform for WhatsApp Group Creation & Unified
  // Messaging — one-time reveal for a (re)generated secret, kept
  // separate from CreateApiKeyModal's own reveal state since this fires
  // from an existing row's action, not the create form.
  const [regeneratingSecretId, setRegeneratingSecretId] = useState<number | null>(null);
  const [revealedSecretFor, setRevealedSecretFor] = useState<{ keyId: number; secret: string } | null>(null);

  // Developer API Key Management UI — success feedback for create/revoke/
  // regenerate, which previously reported only failures. Cleared on the
  // next action so it never lingers next to a newer error.
  const [success, setSuccess] = useState<string | null>(null);

  const load = useCallback(async () => {
    setIsLoading(true);
    setError(null);
    try {
      const res = await developerService.listApiKeys();
      setKeys(res.data);
      setScope(res.scope);
    } catch (err) {
      setError(describeApiError(err, 'The API keys could not be loaded. Please try again.').message);
    } finally {
      setIsLoading(false);
    }
  }, []);

  useEffect(() => {
    void load();
  }, [load]);

  const [pendingRevoke, setPendingRevoke] = useState<ApiKey | null>(null);

  const handleRevoke = (key: ApiKey) => {
    setPendingRevoke(key);
  };

  const confirmRevoke = async () => {
    if (!pendingRevoke || revokingId !== null) return;
    const key = pendingRevoke;
    setRevokingId(key.id);
    setError(null);
    setSuccess(null);
    try {
      await developerService.revokeApiKey(key.id);
      await load();
      setSuccess(`"${key.name}" has been revoked. Any integration using it will no longer authenticate.`);
      setPendingRevoke(null);
    } catch (err) {
      setError(describeApiError(err, 'The API key could not be revoked. Please try again.').message);
      setPendingRevoke(null);
    } finally {
      setRevokingId(null);
    }
  };

  /**
   * Developer API Platform for WhatsApp Group Creation & Unified
   * Messaging — issues (or rotates) this key's dual-factor secret,
   * needed to call the new POST /api/v1/whatsapp/* endpoints. Leaves
   * the key itself untouched (see ApiKeyController::regenerateSecret()'s
   * docblock) — every existing integration keeps working through this.
   */
  const [pendingRegenerateSecret, setPendingRegenerateSecret] = useState<ApiKey | null>(null);

  const handleRegenerateSecret = (key: ApiKey) => {
    if (!key.secret_prefix) {
      void doRegenerateSecret(key);
      return;
    }
    setPendingRegenerateSecret(key);
  };

  const doRegenerateSecret = async (key: ApiKey) => {
    if (regeneratingSecretId !== null) return;
    setRegeneratingSecretId(key.id);
    setError(null);
    setSuccess(null);
    try {
      const result = await developerService.regenerateApiKeySecret(key.id);
      setRevealedSecretFor({ keyId: key.id, secret: result.plain_text_secret });
      await load();
      setSuccess(`A new API secret was generated for "${key.name}". The previous secret no longer authenticates.`);
      setPendingRegenerateSecret(null);
    } catch (err) {
      // A revoked key returns 422 with ApiKeyController's own written
      // sentence ("This API key has been revoked and cannot be given a
      // new secret.") — describeApiError prefers that over any generic
      // text, since it tells the user exactly what to do instead.
      setError(describeApiError(err, 'The API secret could not be regenerated. Please try again.').message);
      setPendingRegenerateSecret(null);
    } finally {
      setRegeneratingSecretId(null);
    }
  };

  const confirmRegenerateSecret = () => {
    if (!pendingRegenerateSecret) return;
    void doRegenerateSecret(pendingRegenerateSecret);
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

  const hasActiveKeyFilters = search !== '' || statusFilter !== '' || from !== '' || to !== '';
  const clearKeyFilters = () => {
    setSearch('');
    setStatusFilter('');
    setFrom('');
    setTo('');
  };

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
                Send the key in the <code className="font-mono text-xs">X-API-KEY</code> header on requests to{' '}
                <code className="font-mono text-xs">/api/v1/*</code>. Endpoints under{' '}
                <code className="font-mono text-xs">/api/v1/whatsapp/*</code> also require{' '}
                <code className="font-mono text-xs">X-API-SECRET</code>.{' '}
                {/* Phase 6 CRM Hardening Round 2, Issue 14 — documented in the existing Developer Portal blurb (this app has no OpenAPI/doc framework to extend). */}
                <code className="font-mono text-xs">POST /api/v1/crm/leads</code> creates a CRM lead
                (<code className="font-mono text-xs">phone_number</code> required; optional{' '}
                <code className="font-mono text-xs">name</code>, <code className="font-mono text-xs">email</code>,{' '}
                <code className="font-mono text-xs">status</code>) and needs the CRM feature on your plan; its{' '}
                <code className="font-mono text-xs">source</code> is always recorded as{' '}
                <code className="font-mono text-xs">api</code>.{' '}
                {/* Phase 6 CRM Task 11 — the single-lead operations added to /api/v1/crm/leads. */}
                For one lead: <code className="font-mono text-xs">GET /api/v1/crm/leads/{'{id}'}</code>,{' '}
                <code className="font-mono text-xs">PATCH …/{'{id}'}/status</code>,{' '}
                <code className="font-mono text-xs">PATCH …/{'{id}'}/assignee</code> and{' '}
                <code className="font-mono text-xs">POST|DELETE …/{'{id}'}/tags/{'{tag}'}</code>. Changes need an active
                subscription.
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
        <ClearFiltersButton active={hasActiveKeyFilters} onClear={clearKeyFilters} />
      </div>

      {error && (
        <div role="alert" className="flex items-center gap-2 rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700">
          <XCircle className="h-4 w-4 flex-shrink-0" />
          {error}
        </div>
      )}

      {success && (
        <div role="status" className="flex items-center gap-2 rounded-lg border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-700">
          <CheckCircle2 className="h-4 w-4 flex-shrink-0" />
          {success}
        </div>
      )}

      <TableCard>
        <table className="min-w-full divide-y divide-slate-200 text-sm">
          <thead className="bg-slate-50 text-left text-xs font-medium uppercase text-slate-500">
            <tr>
              <th className="px-6 py-3">Name</th>
              {scope === 'global' && <th className="px-6 py-3">Client</th>}
              <th className="px-6 py-3">Key</th>
              <th className="px-6 py-3">Secret</th>
              <th className="px-6 py-3">Status</th>
              <th className="px-6 py-3">Created</th>
              <th className="px-6 py-3">Last Used</th>
              <th className="px-6 py-3">Expires</th>
              <th className="px-6 py-3 text-right">Action</th>
            </tr>
          </thead>
          <tbody className="divide-y divide-slate-100">
            {isLoading ? (
              <TableSkeletonRows columns={scope === 'global' ? 9 : 8} />
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
                    <td className="px-6 py-3 font-mono text-xs text-slate-500">
                      {key.secret_prefix ? `${key.secret_prefix}…` : <span className="italic text-slate-400">Not set</span>}
                    </td>
                    <td className="px-6 py-3">
                      <span className={`inline-flex items-center rounded-full border px-2.5 py-0.5 text-xs font-medium ${status.cls}`}>
                        {status.label}
                      </span>
                    </td>
                    <td className="px-6 py-3 text-slate-700">{formatDateTime(key.created_at)}</td>
                    <td className="px-6 py-3 text-slate-700">{formatDateTime(key.last_used_at)}</td>
                    <td className="px-6 py-3 text-slate-700">{key.expires_at ? formatDateTime(key.expires_at) : 'Never'}</td>
                    <td className="px-6 py-3 text-right">
                      {!key.revoked_at && (
                        <div className="flex items-center justify-end gap-3">
                          {/* Developer API Platform for WhatsApp Group Creation & Unified Messaging — a pre-existing key (secret_prefix null) needs this before it can call the new dual-factor endpoints. */}
                          <button
                            onClick={() => handleRegenerateSecret(key)}
                            disabled={regeneratingSecretId === key.id}
                            className="inline-flex items-center gap-1 text-xs font-medium text-indigo-600 hover:text-indigo-700 disabled:opacity-60"
                          >
                            {regeneratingSecretId === key.id ? (
                              <Loader2 className="h-3 w-3 animate-spin" />
                            ) : (
                              <KeyRound className="h-3 w-3" />
                            )}
                            {key.secret_prefix ? 'Regenerate Secret' : 'Generate Secret'}
                          </button>
                          <button
                            onClick={() => handleRevoke(key)}
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
                        </div>
                      )}
                    </td>
                  </tr>
                );
              })
            ) : (
              <tr>
                <td colSpan={scope === 'global' ? 9 : 8} className="px-6 py-10 text-center">
                  {keys.length === 0 ? (
                    <div className="flex flex-col items-center gap-2">
                      <KeyRound className="h-6 w-6 text-slate-300" />
                      <p className="text-sm font-medium text-slate-600">No API keys yet</p>
                      <p className="max-w-sm text-xs text-slate-400">
                        Create a key to let an external system call the Developer API on this account's behalf.
                      </p>
                    </div>
                  ) : (
                    <p className="text-sm text-slate-400">No API keys match the current filters.</p>
                  )}
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
          onCreated={(key) => {
            setError(null);
            setSuccess(`API key "${key.name}" was created.`);
            void load();
          }}
        />
      )}

      {/* Developer API Platform for WhatsApp Group Creation & Unified Messaging — one-time reveal after Generate/Regenerate Secret. */}
      {revealedSecretFor && (
        <div className="fixed inset-0 z-50 flex items-center justify-center bg-slate-900/50 p-4">
          <div className="w-full max-w-md rounded-xl bg-white p-6 shadow-xl">
            <div className="flex items-center justify-between">
              <h3 className="text-base font-semibold text-slate-900">API secret generated</h3>
              <button onClick={() => setRevealedSecretFor(null)} className="text-slate-400 hover:text-slate-600">
                <XCircle className="h-5 w-5" />
              </button>
            </div>
            <div className="mt-4 space-y-4">
              <OneTimeSecretReveal label="API secret" value={revealedSecretFor.secret} />
              <div className="flex justify-end">
                <button
                  onClick={() => setRevealedSecretFor(null)}
                  className="rounded-lg bg-indigo-600 px-4 py-2 text-sm font-semibold text-white shadow-sm hover:bg-indigo-700"
                >
                  Done
                </button>
              </div>
            </div>
          </div>
        </div>
      )}

      {pendingRevoke && (
        <ConfirmModal
          title="Revoke API key"
          message={`Revoke "${pendingRevoke.name}"? Any integration using it will stop working immediately.`}
          confirmLabel="Revoke"
          variant="danger"
          isLoading={revokingId === pendingRevoke.id}
          onConfirm={() => void confirmRevoke()}
          onCancel={() => setPendingRevoke(null)}
        />
      )}

      {pendingRegenerateSecret && (
        <ConfirmModal
          title="Regenerate secret"
          message={`Regenerate the secret for "${pendingRegenerateSecret.name}"? Any integration using the current secret will stop authenticating.`}
          confirmLabel="Regenerate"
          variant="danger"
          isLoading={regeneratingSecretId === pendingRegenerateSecret.id}
          onConfirm={confirmRegenerateSecret}
          onCancel={() => setPendingRegenerateSecret(null)}
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
                <label className="text-sm font-medium text-slate-700">Client account <span className="text-red-500">*</span></label>
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
              <label className="text-sm font-medium text-slate-700">Webhook URL <span className="text-red-500">*</span></label>
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
              <label className="text-sm font-medium text-slate-700">Events <span className="text-red-500">*</span></label>
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

  const [showDeleteConfirm, setShowDeleteConfirm] = useState(false);

  const handleDelete = () => {
    setShowDeleteConfirm(true);
  };

  const confirmDeleteWebhook = async () => {
    setIsDeleting(true);
    try {
      await developerService.deleteWebhook(webhook.id);
      onChanged();
      setShowDeleteConfirm(false);
    } catch {
      setIsDeleting(false);
      setShowDeleteConfirm(false);
    }
  };

  return (
    <>
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
            onClick={handleDelete}
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

    {showDeleteConfirm && (
      <ConfirmModal
        title="Delete webhook"
        message="Delete this webhook subscription? This cannot be undone."
        confirmLabel="Delete"
        variant="danger"
        isLoading={isDeleting}
        onConfirm={() => void confirmDeleteWebhook()}
        onCancel={() => setShowDeleteConfirm(false)}
      />
    )}
    </>
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

  const hasActiveWebhookFilters = search !== '' || statusFilter !== '';
  const clearWebhookFilters = () => {
    setSearch('');
    setStatusFilter('');
  };

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
        <ClearFiltersButton active={hasActiveWebhookFilters} onClear={clearWebhookFilters} />
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
