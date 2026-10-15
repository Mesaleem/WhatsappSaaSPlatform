import { useCallback, useEffect, useMemo, useState } from 'react';
import { AxiosError } from 'axios';
import { Eye, Loader2, Megaphone, Pencil, Plus, RotateCcw, Send, Trash2, X, XCircle } from 'lucide-react';
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
  NotificationTemplateKind,
  NotificationTemplateType,
} from '../../types/notifications';
import { PageHeader, PageShell } from '../../components/common/PageShell';
import { Card, TableCard, inputClass } from '../../components/common/Card';
import { ClearFiltersButton, Pagination, SearchInput, StatusFilterSelect } from '../../components/common/DataTableControls';
import { TableSkeletonRows } from '../../components/common/Skeleton';
import RichTextEditor from '../../components/common/RichTextEditor';
import ConfirmModal from '../../components/common/ConfirmModal';
import DismissibleAlert from '../../components/common/DismissibleAlert';
import EmailPreviewModal, { InlineCssReport } from './EmailPreviewModal';
import { findInlineCssIssues, renderPreview, usedVariables } from './emailPreview';

type Tab = 'compose' | 'templates' | 'history' | 'mail-logs';

const MAIL_LOG_STATUS_OPTIONS: { value: MailLogStatus; label: string }[] = [
  { value: 'sent', label: 'Sent' },
  { value: 'failed', label: 'Failed' },
];

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
                ? 'border-emerald-600 text-emerald-700'
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
      {tab === 'templates' && <TemplatesTab superAdmin={superAdmin} />}
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
  const [roles, setRoles] = useState<string[]>([]);
  const [roleName, setRoleName] = useState('');
  const [showPreview, setShowPreview] = useState(false);
  const [isSending, setIsSending] = useState(false);
  const [sendError, setSendError] = useState<string | null>(null);
  const [sendResult, setSendResult] = useState<NotificationBroadcast | null>(null);

  useEffect(() => {
    // Broadcast templates only: the automatic (system) emails need variables a broadcast cannot fill.
    void notificationService.listTemplates(1, 100, '', 'custom').then((res) => setTemplates(res.data)).catch(() => undefined);
    if (superAdmin) {
      void accountService.list({ per_page: 100 }).then((res) => setAccounts(res.data)).catch(() => undefined);
      void notificationService.listRoles().then((list) => setRoles(list.map((r) => r.name))).catch(() => undefined);
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
    if (effectiveTargetType === 'role' && !roleName) return false;
    if (effectiveTargetType === 'custom_emails' && parseEmailList(emailsText).length === 0) return false;
    return true;
  }, [subject, body, channels, effectiveTargetType, superAdmin, selectedAccountId, accountIds, roleName, emailsText]);

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
        ...(effectiveTargetType === 'role' ? { role_name: roleName } : {}),
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
                <option value="all_clients">All Clients (every client user)</option>
                <option value="all_agents">All Agents (every agent user)</option>
                <option value="role">Particular Role</option>
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

        {superAdmin && effectiveTargetType === 'role' && (
          <div>
            <label className="text-xs font-semibold uppercase tracking-wide text-slate-500">Role</label>
            <select value={roleName} onChange={(e) => setRoleName(e.target.value)} className={inputClass} aria-label="Role">
              <option value="">Choose a role…</option>
              {roles.map((r) => (
                <option key={r} value={r}>
                  {r.replace(/_/g, ' ')}
                </option>
              ))}
            </select>
            <p className="mt-1 text-xs text-slate-500">Every active user with this role, on every account.</p>
          </div>
        )}

        {superAdmin && (effectiveTargetType === 'all_clients' || effectiveTargetType === 'all_agents') && (
          <p className="text-xs text-slate-500">
            Every active user (owner and team) of every {effectiveTargetType === 'all_clients' ? 'client' : 'agent'} account.
          </p>
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
          <DismissibleAlert className="rounded-lg border border-red-200 bg-red-50 px-3 py-2 text-sm text-red-700">{sendError}</DismissibleAlert>
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
          className="flex w-full items-center justify-center gap-2 rounded-lg bg-emerald-600 px-4 py-2.5 text-sm font-semibold text-white shadow-sm hover:bg-emerald-700 disabled:opacity-50"
        >
          {isSending && <Loader2 className="h-4 w-4 animate-spin" />}
          {isSending ? 'Sending…' : 'Send Broadcast'}
        </button>
      </Card>

      <Card padded={false} className="flex flex-col">
        <PreviewHeader onOpen={() => setShowPreview(true)} disabled={!body.trim()} />
        <div className="flex-1 p-3">
          <div className="mb-2 text-sm font-semibold text-slate-900">
            {renderPreview(subject) || <span className="text-slate-400">(no subject yet)</span>}
          </div>
          <iframe
            title="Broadcast preview"
            sandbox=""
            srcDoc={renderPreview(body) || '<p style="color:#94a3b8;font-family:sans-serif">Start typing to see a preview…</p>'}
            className="h-[420px] w-full rounded-lg border border-slate-200 bg-white"
          />
          {body.trim() && channels.includes('email') && (
            <div className="mt-3">
              <InlineCssReport issues={findInlineCssIssues(body)} />
            </div>
          )}
        </div>
      </Card>

      {showPreview && <EmailPreviewModal subject={subject} body={body} onClose={() => setShowPreview(false)} />}
    </div>
  );
}

// ---------------------------------------------------------------------------
// Templates tab
// ---------------------------------------------------------------------------

function PreviewHeader({ onOpen, disabled }: { onOpen: () => void; disabled: boolean }) {
  return (
    <div className="flex items-center justify-between border-b border-slate-200 px-4 py-2">
      <span className="text-xs font-semibold uppercase tracking-wide text-slate-500">Live Preview</span>
      <button
        type="button"
        onClick={onOpen}
        disabled={disabled}
        title={disabled ? 'Write some HTML first' : 'Open a full-size preview (desktop / mobile)'}
        className="flex items-center gap-1.5 rounded-md border border-slate-300 px-2.5 py-1 text-xs font-medium text-slate-700 hover:bg-slate-50 disabled:opacity-50"
      >
        <Eye className="h-3.5 w-3.5" />
        Preview
      </button>
    </div>
  );
}

const TEMPLATE_KIND_OPTIONS: { value: NotificationTemplateKind; label: string }[] = [
  { value: '', label: 'All templates' },
  { value: 'system', label: 'System emails' },
  { value: 'custom', label: 'Broadcast templates' },
];

/**
 * Mail Templates. Two kinds live here:
 *  - broadcast templates — reusable content for Compose Broadcast;
 *  - system emails (Super Admin only) — the automatic emails the platform
 *    sends (signup code, welcome, forgot password, plan / quota / add-on
 *    requests, expiry reminders…). They are edited, switched off (the
 *    built-in default is then sent) or reset, never deleted.
 */
function TemplatesTab({ superAdmin }: { superAdmin: boolean }) {
  const [templates, setTemplates] = useState<NotificationTemplate[]>([]);
  const [isLoading, setIsLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);
  const [editing, setEditing] = useState<NotificationTemplate | 'new' | null>(null);
  const [previewing, setPreviewing] = useState<NotificationTemplate | null>(null);
  const [search, setSearch] = useState('');
  const [kind, setKind] = useState<NotificationTemplateKind>('');

  const load = useCallback(async () => {
    setIsLoading(true);
    setError(null);
    try {
      const res = await notificationService.listTemplates(1, 100, search, kind);
      setTemplates(res.data);
    } catch (err) {
      setError(errorMessage(err, 'Failed to load templates.'));
    } finally {
      setIsLoading(false);
    }
  }, [search, kind]);

  useEffect(() => {
    void load();
  }, [load]);

  const [pendingDelete, setPendingDelete] = useState<NotificationTemplate | null>(null);
  const [isDeleting, setIsDeleting] = useState(false);

  const confirmDelete = async () => {
    if (!pendingDelete) return;
    const template = pendingDelete;
    setIsDeleting(true);
    try {
      await notificationService.deleteTemplate(template.id);
      setPendingDelete(null);
      void load();
    } catch (err) {
      setError(errorMessage(err, 'Could not delete this template.'));
    } finally {
      setIsDeleting(false);
    }
  };

  return (
    <div className="space-y-4">
      <div className="flex flex-wrap items-center justify-between gap-3">
        <div className="flex flex-wrap items-center gap-3">
          <SearchInput value={search} onChange={setSearch} placeholder="Search name, subject or key…" />
          {superAdmin && (
            <select
              value={kind}
              onChange={(e) => setKind(e.target.value as NotificationTemplateKind)}
              aria-label="Template kind"
              className="rounded-lg border border-slate-300 px-3 py-2 text-sm text-slate-900 shadow-sm outline-none focus:border-emerald-500 focus:ring-2 focus:ring-emerald-100"
            >
              {TEMPLATE_KIND_OPTIONS.map((o) => (
                <option key={o.value} value={o.value}>
                  {o.label}
                </option>
              ))}
            </select>
          )}
          <ClearFiltersButton
            active={search !== '' || kind !== ''}
            onClear={() => {
              setSearch('');
              setKind('');
            }}
          />
        </div>
        <button
          onClick={() => setEditing('new')}
          className="flex items-center gap-2 rounded-lg bg-emerald-600 px-4 py-2 text-sm font-semibold text-white shadow-sm hover:bg-emerald-700"
        >
          <Plus className="h-4 w-4" />
          New Template
        </button>
      </div>

      {superAdmin && kind !== 'custom' && (
        <p className="text-xs text-slate-500">
          <span className="font-semibold text-slate-700">System emails</span> are sent automatically (signup code, welcome,
          forgot password, plan / quota / add-on requests, plan reminders…). Edit their HTML here — use inline styles only.
          A switched-off email sends the built-in default instead.
        </p>
      )}

      {error && (
        <DismissibleAlert className="rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700">{error}</DismissibleAlert>
      )}

      <TableCard>
        <table className="w-full min-w-[820px] divide-y divide-slate-200 text-sm">
          <thead className="bg-slate-50">
            <tr className="text-left text-xs font-medium uppercase tracking-wide text-slate-500">
              <th className="px-4 py-3">Name</th>
              <th className="px-4 py-3">Kind</th>
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
                  {search || kind ? 'No templates match these filters.' : 'No templates yet. Click "New Template" to create one.'}
                </td>
              </tr>
            ) : (
              templates.map((t) => (
                <tr key={t.id} className="hover:bg-slate-50">
                  <td className="px-4 py-3">
                    <div className="font-medium text-slate-900">{t.name}</div>
                    {t.slug && <div className="font-mono text-xs text-slate-400">{t.slug}</div>}
                  </td>
                  <td className="px-4 py-3">
                    {t.is_system ? (
                      <div className="flex flex-wrap items-center gap-1.5">
                        <span className="inline-flex items-center rounded-full bg-emerald-50 px-2 py-0.5 text-xs font-medium text-emerald-700 ring-1 ring-inset ring-emerald-600/20">
                          System
                        </span>
                        {!t.is_active && (
                          <span
                            className="inline-flex items-center rounded-full bg-slate-100 px-2 py-0.5 text-xs font-medium text-slate-600 ring-1 ring-inset ring-slate-500/20"
                            title="Switched off: the built-in default is sent"
                          >
                            Off
                          </span>
                        )}
                      </div>
                    ) : (
                      <span className="capitalize text-slate-600">{t.type.replace('_', ' ')}</span>
                    )}
                  </td>
                  <td className="px-4 py-3 text-slate-600">{t.category ?? '—'}</td>
                  <td className="max-w-[320px] truncate px-4 py-3 text-slate-600" title={t.subject}>
                    {t.subject}
                  </td>
                  <td className="px-4 py-3">
                    <div className="flex items-center justify-end gap-2">
                      <button
                        onClick={() => setPreviewing(t)}
                        className="rounded-md p-1.5 text-slate-500 hover:bg-slate-100 hover:text-slate-700"
                        aria-label={`Preview ${t.name}`}
                        title="Preview"
                      >
                        <Eye className="h-4 w-4" />
                      </button>
                      <button
                        onClick={() => setEditing(t)}
                        className="rounded-md p-1.5 text-slate-500 hover:bg-slate-100 hover:text-slate-700"
                        aria-label="Edit template"
                        title="Edit"
                      >
                        <Pencil className="h-4 w-4" />
                      </button>
                      {!t.is_system && (
                        <button
                          onClick={() => setPendingDelete(t)}
                          className="rounded-md p-1.5 text-slate-500 hover:bg-red-50 hover:text-red-600"
                          aria-label="Delete template"
                          title="Delete"
                        >
                          <Trash2 className="h-4 w-4" />
                        </button>
                      )}
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

      {previewing && (
        <EmailPreviewModal subject={previewing.subject} body={previewing.body} onClose={() => setPreviewing(null)} />
      )}

      {pendingDelete && (
        <ConfirmModal
          title="Delete template"
          message={`Delete the "${pendingDelete.name}" template? This cannot be undone.`}
          confirmLabel="Delete"
          variant="danger"
          isLoading={isDeleting}
          onConfirm={() => void confirmDelete()}
          onCancel={() => setPendingDelete(null)}
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
  const isSystem = template?.is_system ?? false;
  const [name, setName] = useState(template?.name ?? '');
  const [type, setType] = useState<NotificationTemplateType>(isSystem ? 'raw_html' : template?.type ?? 'rich_text');
  const [category, setCategory] = useState(template?.category ?? '');
  const [subject, setSubject] = useState(template?.subject ?? '');
  const [body, setBody] = useState(template?.body ?? '');
  const [isActive, setIsActive] = useState(template?.is_active ?? true);
  const [isSaving, setIsSaving] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [notice, setNotice] = useState<string | null>(null);
  const [showPreview, setShowPreview] = useState(false);
  const [confirmReset, setConfirmReset] = useState(false);
  const [isResetting, setIsResetting] = useState(false);
  const [isTesting, setIsTesting] = useState(false);

  const variables = useMemo(
    () => template?.variables ?? { name: 'Recipient name', email: 'Recipient email' },
    [template],
  );
  const unknownVariables = useMemo(
    () => (isSystem ? usedVariables(subject + body).filter((v) => !(v in variables)) : []),
    [isSystem, subject, body, variables],
  );

  const handleSubmit = async () => {
    setIsSaving(true);
    setError(null);
    try {
      const payload = { name, type, category: category || null, subject, body, ...(isSystem ? { is_active: isActive } : {}) };
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

  const handleReset = async () => {
    if (!template) return;
    setIsResetting(true);
    setError(null);
    try {
      const fresh = await notificationService.resetTemplate(template.id);
      setName(fresh.name);
      setCategory(fresh.category ?? '');
      setSubject(fresh.subject);
      setBody(fresh.body);
      setIsActive(fresh.is_active);
      setConfirmReset(false);
      setNotice('Reset to the built-in default and saved.');
    } catch (err) {
      setError(errorMessage(err, 'Could not reset this template.'));
    } finally {
      setIsResetting(false);
    }
  };

  const handleTest = async () => {
    setIsTesting(true);
    setError(null);
    setNotice(null);
    try {
      const res = await notificationService.sendTestEmail({ subject, body });
      setNotice(res.message);
    } catch (err) {
      setError(errorMessage(err, 'Could not send the test email.'));
    } finally {
      setIsTesting(false);
    }
  };

  return (
    <div className="fixed inset-0 z-50 flex items-center justify-center bg-slate-900/50 p-4">
      <div className="max-h-[92vh] w-full max-w-6xl overflow-y-auto rounded-xl bg-white shadow-xl">
        <div className="flex items-center justify-between border-b border-slate-200 px-6 py-4">
          <div className="min-w-0">
            <h2 className="text-lg font-semibold text-slate-900">
              {template ? (isSystem ? 'Edit System Email' : 'Edit Template') : 'New Template'}
            </h2>
            {isSystem && template?.description && <p className="mt-0.5 text-sm text-slate-500">{template.description}</p>}
          </div>
          <button onClick={onClose} aria-label="Close" className="rounded-md p-1.5 text-slate-400 hover:bg-slate-100 hover:text-slate-600">
            <X className="h-5 w-5" />
          </button>
        </div>

        <div className="grid grid-cols-1 gap-6 px-6 py-6 lg:grid-cols-2">
          <div className="space-y-4">
            {isSystem && (
              <div className="flex flex-wrap items-center justify-between gap-3 rounded-lg border border-slate-200 bg-slate-50 px-3 py-2">
                <span className="font-mono text-xs text-slate-500">{template?.slug}</span>
                <label className="flex items-center gap-2 text-sm text-slate-700">
                  <input type="checkbox" checked={isActive} onChange={(e) => setIsActive(e.target.checked)} />
                  Use this template
                  <span className="text-xs text-slate-400">(off = built-in default is sent)</span>
                </label>
              </div>
            )}
            <div className="grid grid-cols-1 gap-4 md:grid-cols-2">
              <div>
                <label className="text-xs font-semibold uppercase tracking-wide text-slate-500">Name <span className="text-red-500">*</span></label>
                <input type="text" value={name} onChange={(e) => setName(e.target.value)} className={inputClass} />
              </div>
              <div>
                <label className="text-xs font-semibold uppercase tracking-wide text-slate-500">Category (optional)</label>
                <input type="text" value={category} onChange={(e) => setCategory(e.target.value)} className={inputClass} />
              </div>
            </div>
            <div>
              <label className="text-xs font-semibold uppercase tracking-wide text-slate-500">Subject <span className="text-red-500">*</span></label>
              <input type="text" value={subject} onChange={(e) => setSubject(e.target.value)} className={inputClass} />
            </div>

            <div>
              <p className="text-xs font-semibold uppercase tracking-wide text-slate-500">Variables</p>
              <div className="mt-1.5 flex flex-wrap gap-1.5">
                {Object.entries(variables).map(([key, label]) => (
                  <code
                    key={key}
                    title={label}
                    className="rounded-md bg-slate-100 px-1.5 py-0.5 font-mono text-xs text-slate-700"
                  >
                    {`{{${key}}}`}
                  </code>
                ))}
              </div>
              {unknownVariables.length > 0 && (
                <p className="mt-1.5 text-xs text-amber-700">
                  Not available in this email (will show as typed): {unknownVariables.map((v) => `{{${v}}}`).join(', ')}
                </p>
              )}
            </div>

            <div>
              <label className="text-xs font-semibold uppercase tracking-wide text-slate-500">
                {isSystem ? 'HTML body (full email, inline CSS only)' : 'Body'}
              </label>
              <div className="mt-1.5">
                <RichTextEditor mode={type} value={body} onChange={setBody} onModeChange={setType} lockMode={isSystem} rows={isSystem ? 16 : 8} />
              </div>
              {type === 'raw_html' && body.trim() && (
                <div className="mt-2">
                  <InlineCssReport issues={findInlineCssIssues(body)} />
                </div>
              )}
            </div>

            {error && (
              <DismissibleAlert className="rounded-lg border border-red-200 bg-red-50 px-3 py-2 text-sm text-red-700">{error}</DismissibleAlert>
            )}
            {notice && (
              <div className="rounded-lg border border-emerald-200 bg-emerald-50 px-3 py-2 text-sm text-emerald-700">{notice}</div>
            )}

            <div className="flex flex-wrap items-center justify-between gap-2 pt-2">
              <div className="flex flex-wrap gap-2">
                <button
                  type="button"
                  onClick={() => void handleTest()}
                  disabled={isTesting || !subject.trim() || !body.trim()}
                  title="Emails this content to you, with sample values"
                  className="flex items-center gap-1.5 rounded-lg border border-slate-300 px-3 py-2 text-sm font-medium text-slate-700 hover:bg-slate-50 disabled:opacity-50"
                >
                  {isTesting ? <Loader2 className="h-4 w-4 animate-spin" /> : <Send className="h-4 w-4" />}
                  Send test email
                </button>
                {isSystem && (
                  <button
                    type="button"
                    onClick={() => setConfirmReset(true)}
                    className="flex items-center gap-1.5 rounded-lg border border-slate-300 px-3 py-2 text-sm font-medium text-slate-700 hover:bg-slate-50"
                  >
                    <RotateCcw className="h-4 w-4" />
                    Reset to default
                  </button>
                )}
              </div>
              <div className="flex gap-2">
                <button
                  onClick={onClose}
                  className="rounded-lg border border-slate-300 px-4 py-2 text-sm font-medium text-slate-700 hover:bg-slate-50"
                >
                  Cancel
                </button>
                <button
                  onClick={() => void handleSubmit()}
                  disabled={isSaving || !name.trim() || !subject.trim() || !body.trim()}
                  className="flex items-center gap-2 rounded-lg bg-emerald-600 px-4 py-2 text-sm font-semibold text-white shadow-sm hover:bg-emerald-700 disabled:opacity-50"
                >
                  {isSaving && <Loader2 className="h-4 w-4 animate-spin" />}
                  {isSaving ? 'Saving…' : 'Save Template'}
                </button>
              </div>
            </div>
          </div>

          {/*
            Mail Template Manager — Preview Before Saving: the HTML drawn in a
            sandboxed iframe with sample values, no round trip to the server.
            "Preview" opens it full size at desktop or phone width.
          */}
          <div className="flex flex-col rounded-xl border border-slate-200">
            <PreviewHeader onOpen={() => setShowPreview(true)} disabled={!body.trim()} />
            <div className="flex-1 p-3">
              <div className="mb-2 text-sm font-semibold text-slate-900">
                {renderPreview(subject) || <span className="text-slate-400">(no subject yet)</span>}
              </div>
              <iframe
                title="Template preview"
                sandbox=""
                srcDoc={renderPreview(body) || '<p style="color:#94a3b8;font-family:sans-serif">Start typing to see a preview…</p>'}
                className="h-[560px] w-full rounded-lg border border-slate-200 bg-white"
              />
            </div>
          </div>
        </div>
      </div>

      {showPreview && <EmailPreviewModal subject={subject} body={body} onClose={() => setShowPreview(false)} />}

      {confirmReset && (
        <ConfirmModal
          title="Reset to default"
          message="Replace this email's name, subject and HTML with the built-in default? Your changes to it will be lost."
          confirmLabel="Reset"
          variant="danger"
          isLoading={isResetting}
          onConfirm={() => void handleReset()}
          onCancel={() => setConfirmReset(false)}
        />
      )}
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
            className="rounded-lg border border-slate-300 px-3 py-2 text-sm text-slate-900 shadow-sm outline-none transition focus:border-emerald-500 focus:ring-2 focus:ring-emerald-100 w-40"
            aria-label="From date"
          />
          <span className="text-sm text-slate-400">to</span>
          <input
            type="date"
            value={to}
            onChange={(e) => setTo(e.target.value)}
            className="rounded-lg border border-slate-300 px-3 py-2 text-sm text-slate-900 shadow-sm outline-none transition focus:border-emerald-500 focus:ring-2 focus:ring-emerald-100 w-40"
            aria-label="To date"
          />
        </div>
        <ClearFiltersButton active={hasActiveHistoryFilters} onClear={clearHistoryFilters} />
      </div>

      {error && (
        <DismissibleAlert className="rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700">{error}</DismissibleAlert>
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
            className="rounded-lg border border-slate-300 px-3 py-2 text-sm text-slate-900 shadow-sm outline-none transition focus:border-emerald-500 focus:ring-2 focus:ring-emerald-100 w-40"
            aria-label="From date"
          />
          <span className="text-sm text-slate-400">to</span>
          <input
            type="date"
            value={to}
            onChange={(e) => setTo(e.target.value)}
            className="rounded-lg border border-slate-300 px-3 py-2 text-sm text-slate-900 shadow-sm outline-none transition focus:border-emerald-500 focus:ring-2 focus:ring-emerald-100 w-40"
            aria-label="To date"
          />
        </div>
        <ClearFiltersButton active={hasActiveMailLogFilters} onClear={clearMailLogFilters} />
      </div>

      {error && (
        <DismissibleAlert className="flex items-center gap-2 rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700">
          <XCircle className="h-4 w-4 flex-shrink-0" />
          {error}
        </DismissibleAlert>
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
