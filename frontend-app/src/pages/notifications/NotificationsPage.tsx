import { useCallback, useEffect, useMemo, useState } from 'react';
import { AxiosError } from 'axios';
import { Loader2, Megaphone, Pencil, Plus, Trash2, X, XCircle } from 'lucide-react';
import { useAuth } from '../../core/context/AuthContext';
import { useTenant } from '../../core/context/TenantContext';
import accountService from '../../services/accountService';
import notificationService from '../../services/notificationService';
import type { ApiErrorResponse } from '../../types/auth';
import type { Account } from '../../types/account';
import type {
  BroadcastChannel,
  BroadcastTargetType,
  MailLog,
  MailLogStatus,
  NotificationBroadcast,
  NotificationTemplate,
  NotificationTemplateType,
} from '../../types/notifications';
import { PageHeader, PageShell } from '../../components/common/PageShell';
import { Card, TableCard, inputClass } from '../../components/common/Card';
import { ClearFiltersButton, Pagination, SearchInput, StatusFilterSelect } from '../../components/common/DataTableControls';
import { TableSkeletonRows } from '../../components/common/Skeleton';
import RichTextEditor from '../../components/common/RichTextEditor';

type Tab = 'compose' | 'templates' | 'history' | 'mail-logs';

const MAIL_LOG_STATUS_OPTIONS: { value: MailLogStatus; label: string }[] = [
  { value: 'sent', label: 'Sent' },
  { value: 'failed', label: 'Failed' },
];

const SAMPLE_PREVIEW_VARS = { name: 'Jane Doe', email: 'jane.doe@example.com' };

/**
 * Live preview mirrors App\Support\TemplateRenderer::render() exactly
 * (simple {{token}} strtr-style substitution) — done here purely
 * client-side, with no round-trip to the backend, so the preview updates
 * with zero latency as the composer types. See TemplateRenderer's
 * docblock: this duplication is deliberate, not a gap, and the two are
 * kept in sync by hand since both are this same simple algorithm.
 */
function renderPreview(html: string, vars: Record<string, string>): string {
  let out = html;
  for (const [key, value] of Object.entries(vars)) {
    out = out.split(`{{${key}}}`).join(value);
  }
  return out;
}

function parseEmailList(text: string): string[] {
  const parts = text.split(/[\s,;]+/).map((s) => s.trim()).filter(Boolean);
  return Array.from(new Set(parts));
}

function errorMessage(err: unknown, fallback: string): string {
  const axiosErr = err as AxiosError<ApiErrorResponse>;
  return axiosErr.response?.data?.message ?? fallback;
}

export default function NotificationsPage() {
  const { isSuperAdmin } = useAuth();
  const superAdmin = isSuperAdmin();
  const { selectedAccountId, selectedAccount } = useTenant();

  const [tab, setTab] = useState<Tab>('compose');

  return (
    <PageShell maxWidthClassName="max-w-full">
      <PageHeader
        icon={Megaphone}
        title="Broadcast & Notifications"
        subtitle="Compose platform notifications, manage reusable templates, and review past broadcasts."
      />

      <div className="flex gap-1 border-b border-slate-200">
        {(
          [
            { key: 'compose', label: 'Compose Broadcast' },
            { key: 'templates', label: 'Mail Templates' },
            { key: 'history', label: 'Broadcast History' },
            { key: 'mail-logs', label: 'Mail Logs' },
          ] as const
        ).map((t) => (
          <button
            key={t.key}
            onClick={() => setTab(t.key)}
            className={`-mb-px border-b-2 px-4 py-2.5 text-sm font-medium transition ${
              tab === t.key
                ? 'border-indigo-600 text-indigo-600'
                : 'border-transparent text-slate-500 hover:text-slate-700'
            }`}
          >
            {t.label}
          </button>
        ))}
      </div>

      {tab === 'compose' && (
        <ComposeTab superAdmin={superAdmin} selectedAccountId={selectedAccountId} selectedAccountName={selectedAccount?.company_name ?? null} />
      )}
      {tab === 'templates' && <TemplatesTab />}
      {tab === 'history' && <HistoryTab />}
      {tab === 'mail-logs' && <MailLogsTab />}
    </PageShell>
  );
}

// ---------------------------------------------------------------------------
// Compose tab
// ---------------------------------------------------------------------------

function ComposeTab({
  superAdmin,
  selectedAccountId,
  selectedAccountName,
}: {
  superAdmin: boolean;
  selectedAccountId: number | null;
  selectedAccountName: string | null;
}) {
  const [templates, setTemplates] = useState<NotificationTemplate[]>([]);
  const [accounts, setAccounts] = useState<Account[]>([]);
  const [templateId, setTemplateId] = useState<number | ''>('');
  const [subject, setSubject] = useState('');
  const [body, setBody] = useState('');
  const [mode, setMode] = useState<NotificationTemplateType>('rich_text');
  const [category, setCategory] = useState('');
  const [channels, setChannels] = useState<BroadcastChannel[]>(['email']);
  const [targetType, setTargetType] = useState<BroadcastTargetType>('account_users');
  const [accountIds, setAccountIds] = useState<number[]>([]);
  const [emailsText, setEmailsText] = useState('');
  const [isSending, setIsSending] = useState(false);
  const [sendError, setSendError] = useState<string | null>(null);
  const [sendResult, setSendResult] = useState<NotificationBroadcast | null>(null);

  useEffect(() => {
    void notificationService.listTemplates(1, 100).then((res) => setTemplates(res.data)).catch(() => undefined);
    if (superAdmin) {
      void accountService.list({ per_page: 100 }).then((res) => setAccounts(res.data)).catch(() => undefined);
    }
  }, [superAdmin]);

  const applyTemplate = (id: number | '') => {
    setTemplateId(id);
    if (id === '') return;
    const tpl = templates.find((t) => t.id === id);
    if (tpl) {
      setSubject(tpl.subject);
      setBody(tpl.body);
      setMode(tpl.type);
      setCategory(tpl.category ?? '');
    }
  };

  const toggleChannel = (channel: BroadcastChannel) => {
    setChannels((prev) => (prev.includes(channel) ? prev.filter((c) => c !== channel) : [...prev, channel]));
  };

  // custom_emails has no User row to attach an in-app notification to —
  // the backend rejects this combination with a 422 (see
  // NotificationBroadcastController::store()); the composer prevents it
  // client-side too rather than letting the user hit that error.
  useEffect(() => {
    if (targetType === 'custom_emails' && channels.includes('in_app')) {
      setChannels((prev) => prev.filter((c) => c !== 'in_app'));
    }
  }, [targetType, channels]);

  const effectiveTargetType: BroadcastTargetType = superAdmin ? targetType : 'account_users';

  const canSend = useMemo(() => {
    if (!subject.trim() || !body.trim() || channels.length === 0) return false;
    if (effectiveTargetType === 'account_users' && superAdmin && !selectedAccountId) return false;
    if (effectiveTargetType === 'specific_clients' && accountIds.length === 0) return false;
    if (effectiveTargetType === 'custom_emails' && parseEmailList(emailsText).length === 0) return false;
    return true;
  }, [subject, body, channels, effectiveTargetType, superAdmin, selectedAccountId, accountIds, emailsText]);

  const handleSend = async () => {
    setIsSending(true);
    setSendError(null);
    setSendResult(null);
    try {
      const result = await notificationService.sendBroadcast({
        template_id: templateId === '' ? null : templateId,
        subject,
        body,
        category: category || null,
        channels,
        target_type: effectiveTargetType,
        ...(effectiveTargetType === 'specific_clients' ? { account_ids: accountIds } : {}),
        ...(effectiveTargetType === 'custom_emails' ? { emails: parseEmailList(emailsText) } : {}),
      });
      setSendResult(result);
    } catch (err) {
      setSendError(errorMessage(err, 'Could not send this broadcast. Please try again.'));
    } finally {
      setIsSending(false);
    }
  };

  return (
    <div className="grid grid-cols-1 gap-6 lg:grid-cols-2">
      <Card className="space-y-4">
        <div className="grid grid-cols-1 gap-4 md:grid-cols-2">
          <div>
            <label className="text-xs font-semibold uppercase tracking-wide text-slate-500">Load from template (optional)</label>
            <select
              value={templateId}
              onChange={(e) => applyTemplate(e.target.value === '' ? '' : Number(e.target.value))}
              className={inputClass}
            >
              <option value="">Start from scratch</option>
              {templates.map((t) => (
                <option key={t.id} value={t.id}>
                  {t.name}
                </option>
              ))}
            </select>
          </div>

          <div>
            <label className="text-xs font-semibold uppercase tracking-wide text-slate-500">Target audience</label>
            {!superAdmin ? (
              <p className="mt-1.5 rounded-lg border border-slate-200 bg-slate-50 px-3 py-2 text-sm text-slate-600">
                Broadcasting to every user of your own account.
              </p>
            ) : (
              <select
                value={targetType}
                onChange={(e) => setTargetType(e.target.value as BroadcastTargetType)}
                className={inputClass}
              >
                <option value="account_users">Account Users (client selected in header)</option>
                <option value="specific_clients">Specific Clients</option>
                <option value="all_users">All Platform Users</option>
                <option value="custom_emails">Custom Email Addresses</option>
              </select>
            )}
          </div>
        </div>

        {superAdmin && effectiveTargetType === 'account_users' && (
          <p className="text-xs text-slate-500">
            {selectedAccountId
              ? `Sending to all users of ${selectedAccountName ?? 'the selected client'}.`
              : 'Select a client in the header switcher above before sending to "Account Users".'}
          </p>
        )}

        {superAdmin && effectiveTargetType === 'specific_clients' && (
          <div className="max-h-40 space-y-1 overflow-y-auto rounded-lg border border-slate-200 p-2">
            {accounts.map((a) => (
              <label key={a.id} className="flex items-center gap-2 rounded px-1.5 py-1 text-sm hover:bg-slate-50">
                <input
                  type="checkbox"
                  checked={accountIds.includes(a.id)}
                  onChange={() =>
                    setAccountIds((prev) => (prev.includes(a.id) ? prev.filter((id) => id !== a.id) : [...prev, a.id]))
                  }
                />
                {a.company_name}
              </label>
            ))}
          </div>
        )}

        {superAdmin && effectiveTargetType === 'custom_emails' && (
          <textarea
            value={emailsText}
            onChange={(e) => setEmailsText(e.target.value)}
            placeholder="one@example.com, two@example.com"
            rows={3}
            className={inputClass}
          />
        )}

        <div>
          <label className="text-xs font-semibold uppercase tracking-wide text-slate-500">Channels</label>
          <div className="mt-1.5 flex gap-4">
            <label className="flex items-center gap-2 text-sm">
              <input type="checkbox" checked={channels.includes('email')} onChange={() => toggleChannel('email')} />
              Email
            </label>
            <label
              className={`flex items-center gap-2 text-sm ${effectiveTargetType === 'custom_emails' ? 'opacity-40' : ''}`}
              title={effectiveTargetType === 'custom_emails' ? 'Not available for custom email addresses' : undefined}
            >
              <input
                type="checkbox"
                checked={channels.includes('in_app')}
                disabled={effectiveTargetType === 'custom_emails'}
                onChange={() => toggleChannel('in_app')}
              />
              In-App Notification
            </label>
          </div>
        </div>

        <div className="grid grid-cols-1 gap-4 md:grid-cols-2">
          <div>
            <label className="text-xs font-semibold uppercase tracking-wide text-slate-500">Category (optional)</label>
            <input
              type="text"
              value={category}
              onChange={(e) => setCategory(e.target.value)}
              placeholder="e.g. Announcement, Billing, Maintenance"
              className={inputClass}
            />
          </div>

          <div>
            <label className="text-xs font-semibold uppercase tracking-wide text-slate-500">Subject <span className="text-red-500">*</span></label>
            <input type="text" value={subject} onChange={(e) => setSubject(e.target.value)} className={inputClass} />
          </div>
        </div>

        <div>
          <label className="text-xs font-semibold uppercase tracking-wide text-slate-500">
            Message body <span className="text-red-500">*</span> — use {'{{name}}'} / {'{{email}}'} for per-recipient variables
          </label>
          <div className="mt-1.5">
            <RichTextEditor mode={mode} value={body} onChange={setBody} onModeChange={setMode} />
          </div>
        </div>

        {sendError && (
          <div className="rounded-lg border border-red-200 bg-red-50 px-3 py-2 text-sm text-red-700">{sendError}</div>
        )}
        {sendResult && (
          <div className="rounded-lg border border-emerald-200 bg-emerald-50 px-3 py-2 text-sm text-emerald-700">
            Broadcast sent to {sendResult.recipient_count} recipient(s). Email: {sendResult.email_sent_count} sent,{' '}
            {sendResult.email_failed_count} failed.
          </div>
        )}

        <button
          onClick={() => void handleSend()}
          disabled={!canSend || isSending}
          className="flex w-full items-center justify-center gap-2 rounded-lg bg-indigo-600 px-4 py-2.5 text-sm font-semibold text-white shadow-sm hover:bg-indigo-700 disabled:opacity-50"
        >
          {isSending && <Loader2 className="h-4 w-4 animate-spin" />}
          {isSending ? 'Sending…' : 'Send Broadcast'}
        </button>
      </Card>

      <Card padded={false} className="flex flex-col">
        <div className="border-b border-slate-200 px-4 py-2.5 text-xs font-semibold uppercase tracking-wide text-slate-500">
          Live Preview
        </div>
        <div className="flex-1 p-3">
          <div className="mb-2 text-sm font-semibold text-slate-900">
            {renderPreview(subject, SAMPLE_PREVIEW_VARS) || <span className="text-slate-400">(no subject yet)</span>}
          </div>
          <iframe
            title="Broadcast preview"
            sandbox=""
            srcDoc={renderPreview(body, SAMPLE_PREVIEW_VARS) || '<p style="color:#94a3b8;font-family:sans-serif">Start typing to see a preview…</p>'}
            className="h-[420px] w-full rounded-lg border border-slate-200 bg-white"
          />
        </div>
      </Card>
    </div>
  );
}

// ---------------------------------------------------------------------------
// Templates tab
// ---------------------------------------------------------------------------

function TemplatesTab() {
  const [templates, setTemplates] = useState<NotificationTemplate[]>([]);
  const [isLoading, setIsLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);
  const [editing, setEditing] = useState<NotificationTemplate | 'new' | null>(null);
  const [search, setSearch] = useState('');

  const load = useCallback(async () => {
    setIsLoading(true);
    setError(null);
    try {
      const res = await notificationService.listTemplates(1, 100, search);
      setTemplates(res.data);
    } catch (err) {
      setError(errorMessage(err, 'Failed to load templates.'));
    } finally {
      setIsLoading(false);
    }
  }, [search]);

  useEffect(() => {
    void load();
  }, [load]);

  const handleDelete = async (template: NotificationTemplate) => {
    if (!window.confirm(`Delete the "${template.name}" template? This cannot be undone.`)) return;
    try {
      await notificationService.deleteTemplate(template.id);
      void load();
    } catch (err) {
      setError(errorMessage(err, 'Could not delete this template.'));
    }
  };

  return (
    <div className="space-y-4">
      <div className="flex flex-wrap items-center justify-between gap-3">
        <div className="flex flex-wrap items-center gap-3">
          <SearchInput value={search} onChange={setSearch} placeholder="Search name or subject…" />
          <ClearFiltersButton active={search !== ''} onClear={() => setSearch('')} />
        </div>
        <button
          onClick={() => setEditing('new')}
          className="flex items-center gap-2 rounded-lg bg-indigo-600 px-4 py-2 text-sm font-semibold text-white shadow-sm hover:bg-indigo-700"
        >
          <Plus className="h-4 w-4" />
          New Template
        </button>
      </div>

      {error && (
        <div className="rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700">{error}</div>
      )}

      <TableCard>
        <table className="w-full min-w-[720px] divide-y divide-slate-200 text-sm">
          <thead className="bg-slate-50">
            <tr className="text-left text-xs font-medium uppercase tracking-wide text-slate-500">
              <th className="px-4 py-3">Name</th>
              <th className="px-4 py-3">Type</th>
              <th className="px-4 py-3">Category</th>
              <th className="px-4 py-3">Subject</th>
              <th className="px-4 py-3 text-right">Actions</th>
            </tr>
          </thead>
          <tbody className="divide-y divide-slate-100">
            {isLoading ? (
              <TableSkeletonRows columns={5} />
            ) : templates.length === 0 ? (
              <tr>
                <td colSpan={5} className="px-4 py-10 text-center text-slate-400">
                  {search ? 'No templates match this search.' : 'No templates yet. Click "New Template" to create one.'}
                </td>
              </tr>
            ) : (
              templates.map((t) => (
                <tr key={t.id} className="hover:bg-slate-50">
                  <td className="px-4 py-3 font-medium text-slate-900">{t.name}</td>
                  <td className="px-4 py-3 capitalize text-slate-600">{t.type.replace('_', ' ')}</td>
                  <td className="px-4 py-3 text-slate-600">{t.category ?? '—'}</td>
                  <td className="px-4 py-3 text-slate-600">{t.subject}</td>
                  <td className="px-4 py-3">
                    <div className="flex items-center justify-end gap-2">
                      <button
                        onClick={() => setEditing(t)}
                        className="rounded-md p-1.5 text-slate-500 hover:bg-slate-100 hover:text-slate-700"
                        aria-label="Edit template"
                        title="Edit"
                      >
                        <Pencil className="h-4 w-4" />
                      </button>
                      <button
                        onClick={() => void handleDelete(t)}
                        className="rounded-md p-1.5 text-slate-500 hover:bg-red-50 hover:text-red-600"
                        aria-label="Delete template"
                        title="Delete"
                      >
                        <Trash2 className="h-4 w-4" />
                      </button>
                    </div>
                  </td>
                </tr>
              ))
            )}
          </tbody>
        </table>
      </TableCard>

      {editing && (
        <TemplateModal
          template={editing === 'new' ? null : editing}
          onClose={() => setEditing(null)}
          onSaved={() => {
            setEditing(null);
            void load();
          }}
        />
      )}
    </div>
  );
}

function TemplateModal({
  template,
  onClose,
  onSaved,
}: {
  template: NotificationTemplate | null;
  onClose: () => void;
  onSaved: () => void;
}) {
  const [name, setName] = useState(template?.name ?? '');
  const [type, setType] = useState<NotificationTemplateType>(template?.type ?? 'rich_text');
  const [category, setCategory] = useState(template?.category ?? '');
  const [subject, setSubject] = useState(template?.subject ?? '');
  const [body, setBody] = useState(template?.body ?? '');
  const [isSaving, setIsSaving] = useState(false);
  const [error, setError] = useState<string | null>(null);

  const handleSubmit = async () => {
    setIsSaving(true);
    setError(null);
    try {
      const payload = { name, type, category: category || null, subject, body };
      if (template) {
        await notificationService.updateTemplate(template.id, payload);
      } else {
        await notificationService.createTemplate(payload);
      }
      onSaved();
    } catch (err) {
      setError(errorMessage(err, 'Could not save this template.'));
    } finally {
      setIsSaving(false);
    }
  };

  return (
    <div className="fixed inset-0 z-50 flex items-center justify-center bg-slate-900/50 p-4">
      <div className="max-h-[92vh] w-full max-w-5xl overflow-y-auto rounded-xl bg-white shadow-xl">
        <div className="flex items-center justify-between border-b border-slate-200 px-6 py-4">
          <h2 className="text-lg font-semibold text-slate-900">{template ? 'Edit Template' : 'New Template'}</h2>
          <button onClick={onClose} className="rounded-md p-1.5 text-slate-400 hover:bg-slate-100 hover:text-slate-600">
            <X className="h-5 w-5" />
          </button>
        </div>

        <div className="grid grid-cols-1 gap-6 px-6 py-6 lg:grid-cols-2">
          <div className="space-y-4">
            <div>
              <label className="text-xs font-semibold uppercase tracking-wide text-slate-500">Name <span className="text-red-500">*</span></label>
              <input type="text" value={name} onChange={(e) => setName(e.target.value)} className={inputClass} />
            </div>
            <div>
              <label className="text-xs font-semibold uppercase tracking-wide text-slate-500">Category (optional)</label>
              <input type="text" value={category} onChange={(e) => setCategory(e.target.value)} className={inputClass} />
            </div>
            <div>
              <label className="text-xs font-semibold uppercase tracking-wide text-slate-500">Subject <span className="text-red-500">*</span></label>
              <input type="text" value={subject} onChange={(e) => setSubject(e.target.value)} className={inputClass} />
            </div>
            <div>
              <label className="text-xs font-semibold uppercase tracking-wide text-slate-500">
                Body — use {'{{name}}'} / {'{{email}}'} for per-recipient variables
              </label>
              <div className="mt-1.5">
                <RichTextEditor mode={type} value={body} onChange={setBody} onModeChange={setType} />
              </div>
            </div>

            {error && (
              <div className="rounded-lg border border-red-200 bg-red-50 px-3 py-2 text-sm text-red-700">{error}</div>
            )}

            <div className="flex justify-end gap-2 pt-2">
              <button
                onClick={onClose}
                className="rounded-lg border border-slate-300 px-4 py-2 text-sm font-medium text-slate-700 hover:bg-slate-50"
              >
                Cancel
              </button>
              <button
                onClick={() => void handleSubmit()}
                disabled={isSaving || !name.trim() || !subject.trim()}
                className="flex items-center gap-2 rounded-lg bg-indigo-600 px-4 py-2 text-sm font-semibold text-white shadow-sm hover:bg-indigo-700 disabled:opacity-50"
              >
                {isSaving && <Loader2 className="h-4 w-4 animate-spin" />}
                {isSaving ? 'Saving…' : 'Save Template'}
              </button>
            </div>
          </div>

          {/*
            Mail Template Manager — Preview Before Saving: same
            renderPreview()/SAMPLE_PREVIEW_VARS + sandboxed iframe pattern
            as ComposeTab's Live Preview panel above, so a Super
            Admin/Admin can see exactly how {{name}}/{{email}} will
            render for a real recipient before committing the template —
            no round trip to the server, no draft saved just to look at it.
          */}
          <div className="flex flex-col rounded-xl border border-slate-200">
            <div className="border-b border-slate-200 px-4 py-2.5 text-xs font-semibold uppercase tracking-wide text-slate-500">
              Live Preview
            </div>
            <div className="flex-1 p-3">
              <div className="mb-2 text-sm font-semibold text-slate-900">
                {renderPreview(subject, SAMPLE_PREVIEW_VARS) || <span className="text-slate-400">(no subject yet)</span>}
              </div>
              <iframe
                title="Template preview"
                sandbox=""
                srcDoc={
                  renderPreview(body, SAMPLE_PREVIEW_VARS) ||
                  '<p style="color:#94a3b8;font-family:sans-serif">Start typing to see a preview…</p>'
                }
                className="h-[420px] w-full rounded-lg border border-slate-200 bg-white"
              />
            </div>
          </div>
        </div>
      </div>
    </div>
  );
}

// ---------------------------------------------------------------------------
// History tab
// ---------------------------------------------------------------------------

const CHANNEL_LABEL: Record<BroadcastChannel, string> = { email: 'Email', in_app: 'In-App' };

function HistoryTab() {
  const [broadcasts, setBroadcasts] = useState<NotificationBroadcast[]>([]);
  const [page, setPage] = useState(1);
  const [lastPage, setLastPage] = useState(1);
  const [total, setTotal] = useState(0);
  const [perPage, setPerPage] = useState(15);
  const [search, setSearch] = useState('');
  const [from, setFrom] = useState('');
  const [to, setTo] = useState('');
  const [isLoading, setIsLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);

  const filters = useMemo(() => ({ search, from, to }), [search, from, to]);

  const hasActiveHistoryFilters = search !== '' || from !== '' || to !== '';
  const clearHistoryFilters = () => {
    setSearch('');
    setFrom('');
    setTo('');
  };

  const load = useCallback(
    async (pageToLoad: number) => {
      setIsLoading(true);
      setError(null);
      try {
        const res = await notificationService.listBroadcasts(pageToLoad, perPage, filters);
        setBroadcasts(res.data);
        setPage(res.current_page);
        setLastPage(res.last_page);
        setTotal(res.total);
      } catch (err) {
        setError(errorMessage(err, 'Failed to load broadcast history.'));
      } finally {
        setIsLoading(false);
      }
    },
    [perPage, filters],
  );

  useEffect(() => {
    void load(1);
  }, [load]);

  return (
    <div className="space-y-4">
      <div className="flex flex-wrap items-center gap-3">
        <SearchInput value={search} onChange={setSearch} placeholder="Search by subject…" />
        <div className="flex items-center gap-2">
          <input
            type="date"
            value={from}
            onChange={(e) => setFrom(e.target.value)}
            className="rounded-lg border border-slate-300 px-3 py-2 text-sm text-slate-900 shadow-sm outline-none transition focus:border-indigo-500 focus:ring-2 focus:ring-indigo-100 w-40"
            aria-label="From date"
          />
          <span className="text-sm text-slate-400">to</span>
          <input
            type="date"
            value={to}
            onChange={(e) => setTo(e.target.value)}
            className="rounded-lg border border-slate-300 px-3 py-2 text-sm text-slate-900 shadow-sm outline-none transition focus:border-indigo-500 focus:ring-2 focus:ring-indigo-100 w-40"
            aria-label="To date"
          />
        </div>
        <ClearFiltersButton active={hasActiveHistoryFilters} onClear={clearHistoryFilters} />
      </div>

      {error && (
        <div className="rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700">{error}</div>
      )}
      <TableCard>
        <table className="w-full min-w-[880px] divide-y divide-slate-200 text-sm">
          <thead className="bg-slate-50">
            <tr className="text-left text-xs font-medium uppercase tracking-wide text-slate-500">
              <th className="px-4 py-3">Subject</th>
              <th className="px-4 py-3">Target</th>
              <th className="px-4 py-3">Channels</th>
              <th className="px-4 py-3">Recipients</th>
              <th className="px-4 py-3">Email Sent / Failed</th>
              <th className="px-4 py-3">Sent By</th>
              <th className="px-4 py-3">Sent At</th>
            </tr>
          </thead>
          <tbody className="divide-y divide-slate-100">
            {isLoading ? (
              <TableSkeletonRows columns={7} />
            ) : broadcasts.length === 0 ? (
              <tr>
                <td colSpan={7} className="px-4 py-10 text-center text-slate-400">
                  No broadcasts sent yet.
                </td>
              </tr>
            ) : (
              broadcasts.map((b) => (
                <tr key={b.id} className="hover:bg-slate-50">
                  <td className="px-4 py-3">
                    <div className="font-medium text-slate-900">{b.subject}</div>
                    {b.category && <div className="text-xs text-slate-500">{b.category}</div>}
                  </td>
                  <td className="px-4 py-3 text-slate-600">{b.target_summary ?? b.target_type}</td>
                  <td className="px-4 py-3 text-slate-600">
                    {b.channels.map((c) => CHANNEL_LABEL[c]).join(', ')}
                  </td>
                  <td className="px-4 py-3 text-slate-600">{b.recipient_count}</td>
                  <td className="px-4 py-3 text-slate-600">
                    {b.email_sent_count} / {b.email_failed_count}
                  </td>
                  <td className="px-4 py-3 text-slate-600">{b.sender?.name ?? '—'}</td>
                  <td className="px-4 py-3 text-slate-600">{b.sent_at ? new Date(b.sent_at).toLocaleString() : '—'}</td>
                </tr>
              ))
            )}
          </tbody>
        </table>
        <Pagination
          page={page}
          lastPage={lastPage}
          total={total}
          perPage={perPage}
          onPageChange={(p) => void load(p)}
          onPerPageChange={(pp) => setPerPage(pp)}
        />
      </TableCard>
    </div>
  );
}

// ---------------------------------------------------------------------------
// Mail Logs tab
// ---------------------------------------------------------------------------

const MAIL_LOG_STATUS_BADGE: Record<MailLogStatus, string> = {
  sent: 'bg-emerald-50 text-emerald-700 ring-emerald-600/20',
  failed: 'bg-red-50 text-red-700 ring-red-600/20',
};

/**
 * Mail Log — Track Record of Mail Sends. Per-recipient send attempts
 * behind a broadcast's aggregate email_sent_count/email_failed_count on
 * the History tab above; a failed row carries the real SMTP/mailer
 * error instead of just a count, so a bounced address is diagnosable
 * here directly.
 */
function MailLogsTab() {
  const [logs, setLogs] = useState<MailLog[]>([]);
  const [page, setPage] = useState(1);
  const [lastPage, setLastPage] = useState(1);
  const [total, setTotal] = useState(0);
  const [perPage, setPerPage] = useState(15);
  const [search, setSearch] = useState('');
  const [status, setStatus] = useState<MailLogStatus | ''>('');
  const [from, setFrom] = useState('');
  const [to, setTo] = useState('');
  const [isLoading, setIsLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);

  const filters = useMemo(() => ({ search, status, from, to }), [search, status, from, to]);

  const hasActiveMailLogFilters = search !== '' || status !== '' || from !== '' || to !== '';
  const clearMailLogFilters = () => {
    setSearch('');
    setStatus('');
    setFrom('');
    setTo('');
  };

  const load = useCallback(
    async (pageToLoad: number) => {
      setIsLoading(true);
      setError(null);
      try {
        const res = await notificationService.listMailLogs(pageToLoad, perPage, filters);
        setLogs(res.data);
        setPage(res.current_page);
        setLastPage(res.last_page);
        setTotal(res.total);
      } catch (err) {
        setError(errorMessage(err, 'Failed to load mail logs.'));
      } finally {
        setIsLoading(false);
      }
    },
    [perPage, filters],
  );

  useEffect(() => {
    void load(1);
  }, [load]);

  return (
    <div className="space-y-4">
      <div className="flex flex-wrap items-center gap-3">
        <SearchInput value={search} onChange={setSearch} placeholder="Search recipient email or name…" />
        <StatusFilterSelect
          value={status}
          onChange={(v) => setStatus(v as MailLogStatus | '')}
          options={MAIL_LOG_STATUS_OPTIONS}
          allLabel="All statuses"
        />
        <div className="flex items-center gap-2">
          <input
            type="date"
            value={from}
            onChange={(e) => setFrom(e.target.value)}
            className="rounded-lg border border-slate-300 px-3 py-2 text-sm text-slate-900 shadow-sm outline-none transition focus:border-indigo-500 focus:ring-2 focus:ring-indigo-100 w-40"
            aria-label="From date"
          />
          <span className="text-sm text-slate-400">to</span>
          <input
            type="date"
            value={to}
            onChange={(e) => setTo(e.target.value)}
            className="rounded-lg border border-slate-300 px-3 py-2 text-sm text-slate-900 shadow-sm outline-none transition focus:border-indigo-500 focus:ring-2 focus:ring-indigo-100 w-40"
            aria-label="To date"
          />
        </div>
        <ClearFiltersButton active={hasActiveMailLogFilters} onClear={clearMailLogFilters} />
      </div>

      {error && (
        <div className="flex items-center gap-2 rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700">
          <XCircle className="h-4 w-4 flex-shrink-0" />
          {error}
        </div>
      )}

      <TableCard>
        <table className="w-full min-w-[900px] divide-y divide-slate-200 text-sm">
          <thead className="bg-slate-50">
            <tr className="text-left text-xs font-medium uppercase tracking-wide text-slate-500">
              <th className="px-4 py-3">Recipient</th>
              <th className="px-4 py-3">Subject</th>
              <th className="px-4 py-3">Broadcast</th>
              <th className="px-4 py-3">Status</th>
              <th className="px-4 py-3">Error</th>
              <th className="px-4 py-3">Sent At</th>
            </tr>
          </thead>
          <tbody className="divide-y divide-slate-100">
            {isLoading ? (
              <TableSkeletonRows columns={6} />
            ) : logs.length === 0 ? (
              <tr>
                <td colSpan={6} className="px-4 py-10 text-center text-slate-400">
                  No mail send attempts match these filters.
                </td>
              </tr>
            ) : (
              logs.map((log) => (
                <tr key={log.id}>
                  <td className="px-4 py-3">
                    <div className="font-medium text-slate-900">{log.recipient_name ?? log.recipient_email}</div>
                    {log.recipient_name && <div className="text-xs text-slate-500">{log.recipient_email}</div>}
                  </td>
                  <td className="max-w-[220px] truncate px-4 py-3 text-slate-600" title={log.subject}>
                    {log.subject}
                  </td>
                  <td className="px-4 py-3 text-slate-500">{log.broadcast?.subject ?? '—'}</td>
                  <td className="px-4 py-3">
                    <span className={`inline-flex items-center rounded-full px-2 py-0.5 text-xs font-medium ring-1 ring-inset ${MAIL_LOG_STATUS_BADGE[log.status]}`}>
                      {log.status}
                    </span>
                  </td>
                  <td className="max-w-[260px] truncate px-4 py-3 text-red-600" title={log.error_message ?? undefined}>
                    {log.error_message ?? '—'}
                  </td>
                  <td className="px-4 py-3 text-slate-500">{new Date(log.sent_at).toLocaleString()}</td>
                </tr>
              ))
            )}
          </tbody>
        </table>
        <Pagination
          page={page}
          lastPage={lastPage}
          total={total}
          perPage={perPage}
          onPageChange={(p) => void load(p)}
          onPerPageChange={(pp) => setPerPage(pp)}
        />
      </TableCard>
    </div>
  );
}
