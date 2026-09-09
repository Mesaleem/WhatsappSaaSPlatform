import { useCallback, useEffect, useMemo, useState, type FormEvent } from 'react';
import { Link } from 'react-router-dom';
import {
  AlertTriangle,
  CheckCircle2,
  ListChecks,
  Loader2,
  Pencil,
  Plus,
  Send,
  ShieldCheck,
  Sparkles,
  Trash2,
  XCircle,
} from 'lucide-react';
import accountService from '../../services/accountService';
import templateService from '../../services/templateService';
import type { Account } from '../../types/account';
import type {
  MessageTemplate,
  MessageTemplateStatus,
  SaveMessageTemplatePayload,
  TemplateVariableSchemaField,
  TemplateVariableType,
} from '../../types/templates';
import { inputClass, TableCard } from '../../components/common/Card';
import { PageHeader, PageShell } from '../../components/common/PageShell';
import { Pagination, SearchInput, StatusFilterSelect } from '../../components/common/DataTableControls';
import { TableSkeletonRows } from '../../components/common/Skeleton';
import { extractErrorCode, extractErrorMessage as extractMessage } from '../../utils/apiError';


/** Same {{token}} extraction as the backend's MessageTemplate::variableNames() — kept in sync deliberately. */
function extractVariables(body: string): string[] {
  const matches = body.matchAll(/\{\{\s*([a-zA-Z0-9_]+)\s*\}\}/g);
  const seen = new Set<string>();
  for (const m of matches) seen.add(m[1]);
  return Array.from(seen);
}

function defaultLabel(key: string): string {
  return key
    .split('_')
    .filter(Boolean)
    .map((w) => w[0].toUpperCase() + w.slice(1))
    .join(' ');
}

function defaultVariableField(key: string): TemplateVariableSchemaField {
  return { key, label: defaultLabel(key), type: 'string', required: true };
}

/**
 * Client-side mirror of the backend's MessageTemplate::effectiveVariablesSchema()
 * — the configured variables_schema if the Super Admin has used the
 * Variable Configurator Panel, else one auto-derived string/required
 * field per {{token}} in template_body. Reused by both the Variables
 * table column (display only) and TestTemplateModal (drives its dynamic
 * form fields), so the two can never disagree about a template's schema.
 */
function effectiveSchema(t: MessageTemplate): TemplateVariableSchemaField[] {
  if (t.variables_schema && t.variables_schema.length > 0) return t.variables_schema;
  return extractVariables(t.template_body).map(defaultVariableField);
}

const VARIABLE_TYPE_OPTIONS: { value: TemplateVariableType; label: string }[] = [
  { value: 'string', label: 'String' },
  { value: 'number', label: 'Number' },
  { value: 'date', label: 'Date' },
  { value: 'select', label: 'Dropdown / Select' },
];

const STATUS_OPTIONS: { value: MessageTemplateStatus; label: string }[] = [
  { value: 'pending', label: 'Pending' },
  { value: 'approved', label: 'Approved' },
  { value: 'rejected', label: 'Rejected' },
];

const STATUS_BADGE: Record<MessageTemplateStatus, string> = {
  pending: 'bg-amber-50 text-amber-700 ring-amber-600/20',
  approved: 'bg-emerald-50 text-emerald-700 ring-emerald-600/20',
  rejected: 'bg-red-50 text-red-700 ring-red-600/20',
};

function TemplateModal({
  template,
  accounts,
  onClose,
  onSaved,
}: {
  /** null = create new; a MessageTemplate = editing an existing one. */
  template: MessageTemplate | null;
  accounts: Account[];
  onClose: () => void;
  onSaved: () => void;
}) {
  const [title, setTitle] = useState(template?.title ?? '');
  const [industryType, setIndustryType] = useState(template?.industry_type ?? '');
  const [templateBody, setTemplateBody] = useState(template?.template_body ?? '');
  const [accountId, setAccountId] = useState<number | ''>(template?.account_id ?? '');
  const [variablesSchema, setVariablesSchema] = useState<TemplateVariableSchemaField[]>(
    template?.variables_schema ?? [],
  );
  const [isSaving, setIsSaving] = useState(false);
  const [error, setError] = useState<string | null>(null);

  const variables = useMemo(() => extractVariables(templateBody), [templateBody]);

  // Variable Configurator Panel — keeps variablesSchema in lockstep with the
  // {{tokens}} actually present in template_body: a newly-typed {{token}}
  // gets a default row (label auto-derived, type "string", required true —
  // the same default MessageTemplate::effectiveVariablesSchema() would
  // auto-derive server-side for an unconfigured template) appended in
  // first-appearance order; a token that was deleted from the body has its
  // row dropped. Existing rows for tokens still present are left untouched,
  // so editing template_body never discards configuration the Super Admin
  // already entered for the variables that survive the edit.
  useEffect(() => {
    setVariablesSchema((prev) => {
      const byKey = new Map(prev.map((f) => [f.key, f]));
      return variables.map((key) => byKey.get(key) ?? defaultVariableField(key));
    });
  }, [variables]);

  const updateVariableField = (key: string, patch: Partial<TemplateVariableSchemaField>) => {
    setVariablesSchema((prev) => prev.map((f) => (f.key === key ? { ...f, ...patch } : f)));
  };

  const handleSubmit = async (e: FormEvent) => {
    e.preventDefault();
    if (!title.trim() || !templateBody.trim()) {
      setError('Title and template body are required.');
      return;
    }
    const incompleteSelect = variablesSchema.find(
      (f) => f.type === 'select' && (!f.options || f.options.filter((o) => o.trim()).length === 0),
    );
    if (incompleteSelect) {
      setError(`"${incompleteSelect.label || incompleteSelect.key}" is a Dropdown field — add at least one option.`);
      return;
    }
    setError(null);
    setIsSaving(true);
    try {
      const payload: SaveMessageTemplatePayload = {
        title: title.trim(),
        industry_type: industryType.trim() || null,
        template_body: templateBody,
        account_id: accountId === '' ? null : accountId,
        variables_schema: variablesSchema,
      };
      if (template) {
        await templateService.update(template.id, payload);
      } else {
        await templateService.create(payload);
      }
      onSaved();
    } catch (err) {
      setError(extractMessage(err, 'Could not save this template.'));
    } finally {
      setIsSaving(false);
    }
  };

  return (
    <div className="fixed inset-0 z-50 flex items-center justify-center bg-slate-900/50 p-4">
      <div className="max-h-[92vh] w-full max-w-2xl overflow-y-auto rounded-xl bg-white p-6 shadow-xl">
        <div className="flex items-center justify-between">
          <h3 className="text-base font-semibold text-slate-900">{template ? 'Edit template' : 'New template'}</h3>
          <button onClick={onClose} className="text-slate-400 hover:text-slate-600">
            <XCircle className="h-5 w-5" />
          </button>
        </div>

        <form onSubmit={(e) => void handleSubmit(e)} className="mt-4 space-y-4">
          <div className="grid grid-cols-1 gap-4 md:grid-cols-2">
            <div>
              <label className="text-sm font-medium text-slate-700">Title</label>
              <input
                value={title}
                onChange={(e) => setTitle(e.target.value)}
                placeholder="e.g. Dose Reminder"
                className={inputClass}
                autoFocus
              />
            </div>
            <div>
              <label className="text-sm font-medium text-slate-700">Industry type (optional)</label>
              <input
                value={industryType}
                onChange={(e) => setIndustryType(e.target.value)}
                placeholder="e.g. Healthcare, Banking"
                className={inputClass}
              />
            </div>
          </div>

          <div>
            <label className="text-sm font-medium text-slate-700">Assign to client account (optional)</label>
            <select
              value={accountId}
              onChange={(e) => setAccountId(e.target.value === '' ? '' : Number(e.target.value))}
              className={inputClass}
            >
              <option value="">Global — available to every client</option>
              {accounts.map((a) => (
                <option key={a.id} value={a.id}>
                  {a.company_name}
                </option>
              ))}
            </select>
          </div>

          <div>
            <label className="text-sm font-medium text-slate-700">
              Template body — use {'{{variable_name}}'} for dynamic tags
            </label>
            <textarea
              value={templateBody}
              onChange={(e) => setTemplateBody(e.target.value)}
              rows={5}
              placeholder="Hello {{name}}, your dose {{dose_name}} is scheduled at {{dose_time}}."
              className={`${inputClass} font-mono text-sm`}
            />
          </div>

          {variablesSchema.length > 0 && (
            <div className="space-y-2 rounded-lg border border-slate-200 bg-slate-50/60 p-3">
              <div className="flex items-center gap-1.5 text-sm font-medium text-slate-700">
                <ListChecks className="h-4 w-4 text-indigo-600" />
                Variable Configurator
              </div>
              <div className="space-y-3">
                {variablesSchema.map((field) => (
                  <div key={field.key} className="rounded-lg border border-slate-200 bg-white p-3">
                    <div className="flex flex-wrap items-center gap-2">
                      <span className="inline-flex items-center gap-1 rounded-full bg-indigo-50 px-2 py-0.5 text-xs font-medium text-indigo-700">
                        <Sparkles className="h-3 w-3" />
                        {'{{' + field.key + '}}'}
                      </span>
                    </div>
                    <div className="mt-2 grid grid-cols-1 gap-3 sm:grid-cols-[1fr_1fr_auto]">
                      <div>
                        <label className="text-xs font-medium text-slate-500">Field Label</label>
                        <input
                          value={field.label}
                          onChange={(e) => updateVariableField(field.key, { label: e.target.value })}
                          placeholder="Human-readable name"
                          className={`${inputClass} mt-1 py-1.5 text-sm`}
                        />
                      </div>
                      <div>
                        <label className="text-xs font-medium text-slate-500">Input Type</label>
                        <select
                          value={field.type}
                          onChange={(e) =>
                            updateVariableField(field.key, { type: e.target.value as TemplateVariableType })
                          }
                          className={`${inputClass} mt-1 py-1.5 text-sm`}
                        >
                          {VARIABLE_TYPE_OPTIONS.map((opt) => (
                            <option key={opt.value} value={opt.value}>
                              {opt.label}
                            </option>
                          ))}
                        </select>
                      </div>
                      <div className="flex items-end pb-1.5">
                        <label className="flex items-center gap-1.5 text-xs font-medium text-slate-600">
                          <input
                            type="checkbox"
                            checked={field.required}
                            onChange={(e) => updateVariableField(field.key, { required: e.target.checked })}
                            className="h-3.5 w-3.5 rounded border-slate-300 text-indigo-600 focus:ring-indigo-500"
                          />
                          Required
                        </label>
                      </div>
                    </div>
                    {field.type === 'select' && (
                      <div className="mt-2">
                        <label className="text-xs font-medium text-slate-500">
                          Dropdown options (comma-separated)
                        </label>
                        <input
                          value={(field.options ?? []).join(', ')}
                          onChange={(e) =>
                            updateVariableField(field.key, {
                              options: e.target.value
                                .split(',')
                                .map((o) => o.trim())
                                .filter(Boolean),
                            })
                          }
                          placeholder="e.g. Consultation, Follow-up, Emergency"
                          className={`${inputClass} mt-1 py-1.5 text-sm`}
                        />
                      </div>
                    )}
                  </div>
                ))}
              </div>
            </div>
          )}

          {error && (
            <div className="flex items-center gap-2 rounded-lg border border-red-200 bg-red-50 px-3 py-2 text-sm text-red-700">
              <XCircle className="h-4 w-4 flex-shrink-0" />
              {error}
            </div>
          )}

          <div className="flex justify-end gap-3 border-t border-slate-100 pt-4">
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
              className="rounded-lg bg-indigo-600 px-4 py-2 text-sm font-semibold text-white shadow-sm hover:bg-indigo-700 disabled:opacity-60"
            >
              {isSaving ? 'Saving…' : template ? 'Save Changes' : 'Create Template'}
            </button>
          </div>
        </form>
      </div>
    </div>
  );
}

/**
 * Strict 1-Template-Per-Client & Testing Gate — "Send Template" test
 * action. Always fires through the Super Admin's OWN scanned WhatsApp
 * device (Account::platformDevice() server-side, never a client's — see
 * MessageTemplateController::test()'s docblock), works on a 'pending'
 * template (testing is what's REQUIRED before it can be approved at
 * all), and on a confirmed successful send flips is_super_admin_tested —
 * onTested() refreshes the table row so its badge and the Approve
 * button's gate update immediately.
 */
function TestTemplateModal({
  template,
  onClose,
  onTested,
}: {
  template: MessageTemplate;
  onClose: () => void;
  onTested: () => void;
}) {
  const schema = useMemo(() => effectiveSchema(template), [template]);

  const [recipientPhone, setRecipientPhone] = useState('');
  const [variables, setVariables] = useState<Record<string, string>>(
    Object.fromEntries(schema.map((f) => [f.key, ''])),
  );
  const [isSending, setIsSending] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [errorCode, setErrorCode] = useState<string | undefined>(undefined);
  const [success, setSuccess] = useState<string | null>(null);

  const handleSubmit = async (e: FormEvent) => {
    e.preventDefault();
    setError(null);
    setErrorCode(undefined);
    setSuccess(null);

    if (!recipientPhone.trim()) {
      setError('Recipient phone is required.');
      return;
    }
    const missingRequired = schema.filter((f) => f.required && !variables[f.key]?.trim());
    if (missingRequired.length > 0) {
      setError(`Fill in: ${missingRequired.map((f) => f.label || f.key).join(', ')}`);
      return;
    }

    setIsSending(true);
    try {
      const res = await templateService.test(template.id, {
        recipient_phone: recipientPhone.trim(),
        variables,
      });
      setSuccess(res.message);
      onTested();
    } catch (err) {
      setErrorCode(extractErrorCode(err));
      setError(extractMessage(err, 'Could not send the test message.'));
    } finally {
      setIsSending(false);
    }
  };

  return (
    <div className="fixed inset-0 z-50 flex items-center justify-center bg-slate-900/50 p-4">
      <div className="max-h-[92vh] w-full max-w-lg overflow-y-auto rounded-xl bg-white p-6 shadow-xl">
        <div className="flex items-center justify-between">
          <h3 className="text-base font-semibold text-slate-900">Send Template — Test "{template.title}"</h3>
          <button onClick={onClose} className="text-slate-400 hover:text-slate-600">
            <XCircle className="h-5 w-5" />
          </button>
        </div>
        <p className="mt-1 text-sm text-slate-500">
          Sends a real "[TEST]"-prefixed WhatsApp message through your own test device — not billed to any client.
        </p>

        <form onSubmit={(e) => void handleSubmit(e)} className="mt-4 space-y-4">
          <div>
            <label className="text-sm font-medium text-slate-700">Recipient Phone</label>
            <input
              value={recipientPhone}
              onChange={(e) => setRecipientPhone(e.target.value)}
              placeholder="919876543210"
              className={inputClass}
              autoFocus
            />
          </div>

          {schema.length > 0 && (
            <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
              {schema.map((field) => (
                <div key={field.key}>
                  <label className="text-sm font-medium text-slate-700">
                    {field.label || field.key}
                    {field.required && (
                      <span className="ml-0.5 text-red-500" aria-hidden="true">
                        *
                      </span>
                    )}
                  </label>
                  {field.type === 'select' ? (
                    <select
                      value={variables[field.key] ?? ''}
                      onChange={(e) => setVariables((prev) => ({ ...prev, [field.key]: e.target.value }))}
                      className={inputClass}
                    >
                      <option value="">Select…</option>
                      {(field.options ?? []).map((opt) => (
                        <option key={opt} value={opt}>
                          {opt}
                        </option>
                      ))}
                    </select>
                  ) : (
                    <input
                      type={field.type === 'number' ? 'number' : field.type === 'date' ? 'date' : 'text'}
                      value={variables[field.key] ?? ''}
                      onChange={(e) => setVariables((prev) => ({ ...prev, [field.key]: e.target.value }))}
                      placeholder={`{{${field.key}}}`}
                      className={inputClass}
                    />
                  )}
                </div>
              ))}
            </div>
          )}

          {error && (
            <div className="rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700">
              <div className="flex items-center gap-2">
                <AlertTriangle className="h-4 w-4 flex-shrink-0" />
                {error}
              </div>
              {errorCode === 'WHATSAPP_DISCONNECTED' && (
                <Link
                  to="/admin/device-settings"
                  className="mt-2 inline-block text-xs font-semibold text-red-800 underline underline-offset-2"
                >
                  Go scan your WhatsApp test device →
                </Link>
              )}
            </div>
          )}
          {success && (
            <div className="flex items-center gap-2 rounded-lg border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-700">
              <CheckCircle2 className="h-4 w-4 flex-shrink-0" />
              {success}
            </div>
          )}

          <div className="flex justify-end gap-3 border-t border-slate-100 pt-4">
            <button
              type="button"
              onClick={onClose}
              className="rounded-lg border border-slate-300 px-4 py-2 text-sm font-medium text-slate-700 hover:bg-slate-50"
            >
              Close
            </button>
            <button
              type="submit"
              disabled={isSending}
              className="flex items-center gap-2 rounded-lg bg-indigo-600 px-4 py-2 text-sm font-semibold text-white shadow-sm hover:bg-indigo-700 disabled:opacity-60"
            >
              {isSending ? <Loader2 className="h-4 w-4 animate-spin" /> : <Send className="h-4 w-4" />}
              {isSending ? 'Sending…' : 'Send Test'}
            </button>
          </div>
        </form>
      </div>
    </div>
  );
}

export default function TemplateManagerPage() {
  const [templates, setTemplates] = useState<MessageTemplate[]>([]);
  const [accounts, setAccounts] = useState<Account[]>([]);
  const [search, setSearch] = useState('');
  const [statusFilter, setStatusFilter] = useState('');
  const [page, setPage] = useState(1);
  const [perPage, setPerPage] = useState(10);
  const [isLoading, setIsLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);
  const [modalState, setModalState] = useState<{ open: boolean; template: MessageTemplate | null }>({
    open: false,
    template: null,
  });
  const [busyId, setBusyId] = useState<number | null>(null);
  // Strict 1-Template-Per-Client & Testing Gate — "Send Template" test action.
  const [testModalTemplate, setTestModalTemplate] = useState<MessageTemplate | null>(null);

  const load = useCallback(async () => {
    setIsLoading(true);
    setError(null);
    try {
      const [list, accountsRes] = await Promise.all([
        templateService.list(),
        accountService.list({ per_page: 100 }),
      ]);
      setTemplates(list);
      setAccounts(accountsRes.data);
    } catch (err) {
      setError(extractMessage(err, 'Failed to load templates.'));
    } finally {
      setIsLoading(false);
    }
  }, []);

  useEffect(() => {
    void load();
  }, [load]);

  const handleApprove = async (t: MessageTemplate) => {
    setBusyId(t.id);
    try {
      await templateService.approve(t.id);
      await load();
    } catch (err) {
      setError(extractMessage(err, 'Could not approve this template.'));
    } finally {
      setBusyId(null);
    }
  };

  const handleReject = async (t: MessageTemplate) => {
    setBusyId(t.id);
    try {
      await templateService.reject(t.id);
      await load();
    } catch (err) {
      setError(extractMessage(err, 'Could not reject this template.'));
    } finally {
      setBusyId(null);
    }
  };

  const handleDelete = async (t: MessageTemplate) => {
    if (!confirm(`Delete "${t.title}"? This cannot be undone.`)) return;
    setBusyId(t.id);
    try {
      await templateService.remove(t.id);
      await load();
    } catch (err) {
      setError(extractMessage(err, 'Could not delete this template.'));
    } finally {
      setBusyId(null);
    }
  };

  const filtered = useMemo(() => {
    const term = search.trim().toLowerCase();
    return templates.filter((t) => {
      if (term && !t.title.toLowerCase().includes(term) && !(t.industry_type ?? '').toLowerCase().includes(term)) {
        return false;
      }
      if (statusFilter && t.status !== statusFilter) return false;
      return true;
    });
  }, [templates, search, statusFilter]);

  useEffect(() => {
    setPage(1);
  }, [search, statusFilter]);

  const total = filtered.length;
  const lastPage = Math.max(1, Math.ceil(total / perPage));
  const currentPage = Math.min(page, lastPage);
  const paged = filtered.slice((currentPage - 1) * perPage, currentPage * perPage);

  return (
    <PageShell maxWidthClassName="max-w-full">
      <PageHeader
        icon={Sparkles}
        title="Template Manager"
        subtitle="Build dynamic message templates with {{variables}}, then approve and assign them to client accounts."
        actions={
          <button
            onClick={() => setModalState({ open: true, template: null })}
            className="flex items-center gap-2 rounded-lg bg-indigo-600 px-4 py-2 text-sm font-semibold text-white shadow-sm hover:bg-indigo-700"
          >
            <Plus className="h-4 w-4" />
            New Template
          </button>
        }
      />

      <div className="flex flex-wrap items-center gap-3">
        <SearchInput value={search} onChange={setSearch} placeholder="Search by title or industry…" />
        <StatusFilterSelect value={statusFilter} onChange={setStatusFilter} options={STATUS_OPTIONS} allLabel="All statuses" />
      </div>

      {error && (
        <div className="rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700">{error}</div>
      )}

      <TableCard>
        <table className="w-full min-w-[900px] divide-y divide-slate-200 text-sm">
          <thead className="bg-slate-50">
            <tr className="text-left text-xs font-medium uppercase tracking-wide text-slate-500">
              <th className="px-4 py-3">Title</th>
              <th className="px-4 py-3">Industry</th>
              <th className="px-4 py-3">Assigned To</th>
              <th className="px-4 py-3">Variables</th>
              <th className="px-4 py-3">Status</th>
              <th className="px-4 py-3 text-right">Actions</th>
            </tr>
          </thead>
          <tbody className="divide-y divide-slate-100">
            {isLoading ? (
              <TableSkeletonRows columns={6} />
            ) : paged.length > 0 ? (
              paged.map((t) => (
                <tr key={t.id}>
                  <td className="px-4 py-3 font-medium text-slate-900">{t.title}</td>
                  <td className="px-4 py-3 text-slate-600">{t.industry_type ?? '—'}</td>
                  <td className="px-4 py-3 text-slate-600">{t.account?.company_name ?? 'Global (every client)'}</td>
                  <td className="px-4 py-3">
                    {t.variables_schema && t.variables_schema.length > 0 ? (
                      <div className="flex flex-wrap gap-1">
                        {t.variables_schema.map((f) => (
                          <span
                            key={f.key}
                            title={`${f.label} (${f.type}${f.required ? ', required' : ', optional'})`}
                            className={`inline-flex items-center rounded-full px-2 py-0.5 text-[11px] font-medium ${
                              f.required
                                ? 'bg-indigo-50 text-indigo-700'
                                : 'bg-slate-100 text-slate-500'
                            }`}
                          >
                            {f.label || f.key}
                            {!f.required && <span className="ml-1 text-slate-400">(optional)</span>}
                          </span>
                        ))}
                      </div>
                    ) : (
                      <span className="text-slate-500">
                        {extractVariables(t.template_body).map((v) => `{{${v}}}`).join(', ') || '—'}
                      </span>
                    )}
                  </td>
                  <td className="px-4 py-3">
                    <span
                      className={`inline-flex items-center rounded-full px-2.5 py-0.5 text-xs font-medium ring-1 ring-inset ${STATUS_BADGE[t.status]}`}
                    >
                      {t.status}
                    </span>
                    <div
                      className={`mt-1 flex items-center gap-1 text-[11px] ${
                        t.is_super_admin_tested ? 'text-emerald-600' : 'text-slate-400'
                      }`}
                    >
                      <ShieldCheck className="h-3 w-3" />
                      {t.is_super_admin_tested ? 'Tested' : 'Not tested'}
                    </div>
                  </td>
                  <td className="px-4 py-3">
                    <div className="flex items-center justify-end gap-3">
                      <button
                        onClick={() => setTestModalTemplate(t)}
                        className="flex items-center gap-1 text-xs font-medium text-indigo-600 hover:text-indigo-700"
                      >
                        <Send className="h-3.5 w-3.5" />
                        Send Template
                      </button>
                      {t.status !== 'approved' && (
                        <button
                          onClick={() => void handleApprove(t)}
                          disabled={busyId === t.id || !t.is_super_admin_tested}
                          title={
                            t.is_super_admin_tested
                              ? undefined
                              : 'Send a test message first — approving is blocked until this template has been successfully test-fired.'
                          }
                          className="flex items-center gap-1 text-xs font-medium text-emerald-600 hover:text-emerald-700 disabled:cursor-not-allowed disabled:opacity-40"
                        >
                          <CheckCircle2 className="h-3.5 w-3.5" />
                          Approve
                        </button>
                      )}
                      {t.status !== 'rejected' && (
                        <button
                          onClick={() => void handleReject(t)}
                          disabled={busyId === t.id}
                          className="flex items-center gap-1 text-xs font-medium text-amber-600 hover:text-amber-700 disabled:opacity-60"
                        >
                          <XCircle className="h-3.5 w-3.5" />
                          Reject
                        </button>
                      )}
                      <button
                        onClick={() => setModalState({ open: true, template: t })}
                        className="flex items-center gap-1 text-xs font-medium text-indigo-600 hover:text-indigo-700"
                      >
                        <Pencil className="h-3.5 w-3.5" />
                        Edit
                      </button>
                      <button
                        onClick={() => void handleDelete(t)}
                        disabled={busyId === t.id}
                        className="flex items-center gap-1 text-xs font-medium text-red-600 hover:text-red-700 disabled:opacity-60"
                      >
                        <Trash2 className="h-3.5 w-3.5" />
                        Delete
                      </button>
                    </div>
                  </td>
                </tr>
              ))
            ) : (
              <tr>
                <td colSpan={6} className="px-4 py-10 text-center text-slate-400">
                  {templates.length === 0 ? 'No templates yet.' : 'No templates match the current filters.'}
                </td>
              </tr>
            )}
          </tbody>
        </table>
        <Pagination
          page={currentPage}
          lastPage={lastPage}
          total={total}
          perPage={perPage}
          onPageChange={setPage}
          onPerPageChange={setPerPage}
          perPageOptions={[10, 15, 25, 50]}
        />
      </TableCard>

      {modalState.open && (
        <TemplateModal
          template={modalState.template}
          accounts={accounts}
          onClose={() => setModalState({ open: false, template: null })}
          onSaved={() => {
            setModalState({ open: false, template: null });
            void load();
          }}
        />
      )}

      {testModalTemplate && (
        <TestTemplateModal
          template={testModalTemplate}
          onClose={() => setTestModalTemplate(null)}
          onTested={() => void load()}
        />
      )}
    </PageShell>
  );
}
