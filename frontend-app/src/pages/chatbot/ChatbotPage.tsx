import { useCallback, useEffect, useState, type FormEvent } from 'react';
import { Link } from 'react-router-dom';
import {
  AlertTriangle,
  ArrowLeft,
  Bot,
  History,
  Loader2,
  Plus,
  Search,
  Trash2,
  Users2,
  XCircle,
} from 'lucide-react';
import chatbotService from '../../services/chatbotService';
import { SearchInput } from '../../components/common/DataTableControls';
import { useAuth } from '../../core/context/AuthContext';
import { useTenant } from '../../core/context/TenantContext';
import { extractErrorMessage as extractMessage } from '../../utils/apiError';
import {
  MATCH_TYPES,
  MEDIA_TYPES,
  RESPONSE_TYPES,
  type ChatbotLog,
  type ChatbotLogStatus,
  type ChatbotMatchType,
  type ChatbotMediaType,
  type ChatbotResponseType,
  type ChatbotRule,
  type InteractiveButton,
  type InteractiveListSection,
  type SaveChatbotRulePayload,
} from '../../types/chatbot';

const inputClass =
  'mt-1.5 w-full rounded-lg border border-slate-300 px-3 py-2 text-sm text-slate-900 shadow-sm outline-none transition focus:border-indigo-500 focus:ring-2 focus:ring-indigo-100';

type Tab = 'rules' | 'logs';


function formatDateTime(value: string): string {
  return new Date(value).toLocaleString(undefined, {
    year: 'numeric',
    month: 'short',
    day: 'numeric',
    hour: '2-digit',
    minute: '2-digit',
  });
}

const MATCH_TYPE_LABELS: Record<ChatbotMatchType, string> = {
  exact: 'Exact match',
  contains: 'Contains',
  starts_with: 'Starts with',
  regex: 'Regex',
  fallback: 'Fallback (no match)',
};

const MATCH_TYPE_BADGE: Record<ChatbotMatchType, string> = {
  exact: 'bg-blue-50 text-blue-700 border-blue-200',
  contains: 'bg-violet-50 text-violet-700 border-violet-200',
  starts_with: 'bg-cyan-50 text-cyan-700 border-cyan-200',
  regex: 'bg-amber-50 text-amber-700 border-amber-200',
  fallback: 'bg-slate-100 text-slate-600 border-slate-200',
};

const RESPONSE_TYPE_LABELS: Record<ChatbotResponseType, string> = {
  text: 'Text',
  media: 'Media',
  interactive: 'Interactive',
};

// ---------------------------------------------------------------------------
// Tab 1: Auto-Responder Rules
// ---------------------------------------------------------------------------

/** Local editor shape — normalized so the form always has controlled inputs, whatever partial payload came back from the server. */
interface RuleFormState {
  name: string;
  match_type: ChatbotMatchType;
  keywordsText: string;
  response_type: ChatbotResponseType;
  priority: number;
  is_active: boolean;
  // text
  text: string;
  // media
  media_type: ChatbotMediaType;
  url: string;
  caption: string;
  filename: string;
  // interactive
  body: string;
  interactive_type: 'button' | 'list';
  buttons: InteractiveButton[];
  button_text: string;
  sections: InteractiveListSection[];
}

function emptyForm(): RuleFormState {
  return {
    name: '',
    match_type: 'contains',
    keywordsText: '',
    response_type: 'text',
    priority: 100,
    is_active: true,
    text: '',
    media_type: 'image',
    url: '',
    caption: '',
    filename: '',
    body: '',
    interactive_type: 'button',
    buttons: [{ id: 'opt1', title: '' }],
    button_text: 'Menu',
    sections: [{ title: '', rows: [{ id: 'row1', title: '', description: '' }] }],
  };
}

function formFromRule(rule: ChatbotRule): RuleFormState {
  const base = emptyForm();
  const payload = rule.response_payload as Record<string, unknown>;
  return {
    ...base,
    name: rule.name,
    match_type: rule.match_type,
    keywordsText: rule.keywords.join('\n'),
    response_type: rule.response_type,
    priority: rule.priority,
    is_active: rule.is_active,
    text: rule.response_type === 'text' ? String(payload.text ?? '') : base.text,
    media_type: rule.response_type === 'media' ? ((payload.media_type as ChatbotMediaType) ?? 'image') : base.media_type,
    url: rule.response_type === 'media' ? String(payload.url ?? '') : base.url,
    caption: rule.response_type === 'media' || rule.response_type === 'interactive' ? String(payload.caption ?? '') : base.caption,
    filename: rule.response_type === 'media' ? String(payload.filename ?? '') : base.filename,
    body: rule.response_type === 'interactive' ? String(payload.body ?? '') : base.body,
    interactive_type:
      rule.response_type === 'interactive' ? ((payload.interactive_type as 'button' | 'list') ?? 'button') : base.interactive_type,
    buttons:
      rule.response_type === 'interactive' && Array.isArray(payload.buttons) && payload.buttons.length > 0
        ? (payload.buttons as InteractiveButton[])
        : base.buttons,
    button_text: rule.response_type === 'interactive' ? String(payload.button_text ?? 'Menu') : base.button_text,
    sections:
      rule.response_type === 'interactive' && Array.isArray(payload.sections) && payload.sections.length > 0
        ? (payload.sections as InteractiveListSection[])
        : base.sections,
  };
}

/** Builds the exact response_payload shape ChatbotEngineService::buildReply() expects for the chosen response_type. */
function buildPayload(form: RuleFormState): Record<string, unknown> {
  if (form.response_type === 'text') {
    return { text: form.text.trim() };
  }
  if (form.response_type === 'media') {
    return {
      media_type: form.media_type,
      url: form.url.trim(),
      ...(form.caption.trim() ? { caption: form.caption.trim() } : {}),
      ...(form.filename.trim() ? { filename: form.filename.trim() } : {}),
    };
  }
  // interactive
  if (form.interactive_type === 'list') {
    return {
      body: form.body.trim(),
      interactive_type: 'list',
      button_text: form.button_text.trim() || 'Menu',
      sections: form.sections
        .filter((s) => s.title.trim() || s.rows.some((r) => r.title.trim()))
        .map((s) => ({
          title: s.title.trim(),
          rows: s.rows
            .filter((r) => r.title.trim())
            .map((r) => ({ id: r.id.trim() || r.title.trim(), title: r.title.trim(), description: r.description?.trim() || undefined })),
        })),
    };
  }
  return {
    body: form.body.trim(),
    interactive_type: 'button',
    buttons: form.buttons
      .filter((b) => b.title.trim())
      .slice(0, 3)
      .map((b) => ({ id: b.id.trim() || b.title.trim(), title: b.title.trim().slice(0, 20) })),
  };
}

function validateForm(form: RuleFormState): string | null {
  if (!form.name.trim()) return 'Name is required.';
  if (form.match_type !== 'fallback' && !form.keywordsText.trim()) {
    return 'At least one keyword is required (one per line) unless the match type is "Fallback".';
  }
  if (form.match_type === 'regex') {
    for (const line of form.keywordsText.split('\n').map((k) => k.trim()).filter(Boolean)) {
      try {
        // eslint-disable-next-line no-new
        new RegExp(line.replace(/^\/(.*)\/[a-z]*$/i, '$1'));
      } catch {
        return `"${line}" does not look like a valid regular expression.`;
      }
    }
  }
  if (form.response_type === 'text' && !form.text.trim()) return 'Text reply requires a message.';
  if (form.response_type === 'media' && !form.url.trim()) return 'Media reply requires a URL.';
  if (form.response_type === 'interactive') {
    if (!form.body.trim()) return 'Interactive reply requires body text.';
    if (form.interactive_type === 'button' && !form.buttons.some((b) => b.title.trim())) {
      return 'At least one button with a title is required.';
    }
    if (form.interactive_type === 'list' && !form.sections.some((s) => s.rows.some((r) => r.title.trim()))) {
      return 'At least one list row with a title is required.';
    }
  }
  return null;
}

function RuleFormModal({
  initial,
  onClose,
  onSaved,
}: {
  initial: ChatbotRule | null;
  onClose: () => void;
  onSaved: () => void;
}) {
  const [form, setForm] = useState<RuleFormState>(initial ? formFromRule(initial) : emptyForm());
  const [error, setError] = useState<string | null>(null);
  const [isSaving, setIsSaving] = useState(false);

  const update = <K extends keyof RuleFormState>(key: K, value: RuleFormState[K]) =>
    setForm((prev) => ({ ...prev, [key]: value }));

  const handleSubmit = async (e: FormEvent) => {
    e.preventDefault();
    const validationError = validateForm(form);
    if (validationError) {
      setError(validationError);
      return;
    }
    setError(null);
    setIsSaving(true);

    const payload: SaveChatbotRulePayload = {
      name: form.name.trim(),
      match_type: form.match_type,
      keywords: form.match_type === 'fallback' ? [] : form.keywordsText.split('\n').map((k) => k.trim()).filter(Boolean),
      response_type: form.response_type,
      response_payload: buildPayload(form),
      priority: form.priority,
      is_active: form.is_active,
    };

    try {
      if (initial) {
        await chatbotService.updateRule(initial.id, payload);
      } else {
        await chatbotService.createRule(payload);
      }
      onSaved();
    } catch (err) {
      setError(extractMessage(err, 'Could not save this rule.'));
    } finally {
      setIsSaving(false);
    }
  };

  return (
    <div className="fixed inset-0 z-50 flex items-center justify-center overflow-y-auto bg-slate-900/50 p-4">
      <div className="my-8 w-full max-w-xl rounded-xl bg-white p-6 shadow-xl">
        <div className="flex items-center justify-between">
          <h3 className="text-base font-semibold text-slate-900">{initial ? 'Edit auto-responder rule' : 'New auto-responder rule'}</h3>
          <button onClick={onClose} className="text-slate-400 hover:text-slate-600">
            <XCircle className="h-5 w-5" />
          </button>
        </div>

        <form onSubmit={(e) => void handleSubmit(e)} className="mt-4 space-y-4">
          <div>
            <label className="text-sm font-medium text-slate-700">Name</label>
            <input value={form.name} onChange={(e) => update('name', e.target.value)} placeholder="e.g. Business hours" className={inputClass} autoFocus />
          </div>

          <div className="grid grid-cols-2 gap-3">
            <div>
              <label className="text-sm font-medium text-slate-700">Match type</label>
              <select value={form.match_type} onChange={(e) => update('match_type', e.target.value as ChatbotMatchType)} className={inputClass}>
                {MATCH_TYPES.map((mt) => (
                  <option key={mt} value={mt}>
                    {MATCH_TYPE_LABELS[mt]}
                  </option>
                ))}
              </select>
            </div>
            <div>
              <label className="text-sm font-medium text-slate-700">Priority</label>
              <input
                type="number"
                value={form.priority}
                onChange={(e) => update('priority', Number(e.target.value))}
                min={0}
                max={65535}
                className={inputClass}
              />
              <p className="mt-1 text-xs text-slate-500">Lower number runs first.</p>
            </div>
          </div>

          {form.match_type !== 'fallback' && (
            <div>
              <label className="text-sm font-medium text-slate-700">
                Keywords <span className="text-xs font-normal text-slate-400">(one per line)</span>
              </label>
              <textarea
                value={form.keywordsText}
                onChange={(e) => update('keywordsText', e.target.value)}
                rows={3}
                placeholder={form.match_type === 'regex' ? '/^hi\\b/i' : 'hello\nhi there'}
                className={`${inputClass} font-mono`}
              />
              {form.match_type === 'regex' && (
                <p className="mt-1 text-xs text-slate-500">
                  Standard PCRE patterns (e.g. <code className="font-mono">/^hi\b/i</code>). Matched against the raw incoming
                  message, so use your own case-insensitive flag if needed.
                </p>
              )}
            </div>
          )}

          <div>
            <label className="text-sm font-medium text-slate-700">Response type</label>
            <select value={form.response_type} onChange={(e) => update('response_type', e.target.value as ChatbotResponseType)} className={inputClass}>
              {RESPONSE_TYPES.map((rt) => (
                <option key={rt} value={rt}>
                  {RESPONSE_TYPE_LABELS[rt]}
                </option>
              ))}
            </select>
          </div>

          {form.response_type === 'text' && (
            <div>
              <label className="text-sm font-medium text-slate-700">Reply text</label>
              <textarea value={form.text} onChange={(e) => update('text', e.target.value)} rows={3} className={inputClass} />
            </div>
          )}

          {form.response_type === 'media' && (
            <div className="space-y-3 rounded-lg border border-slate-200 p-3">
              <div>
                <label className="text-sm font-medium text-slate-700">Media type</label>
                <select value={form.media_type} onChange={(e) => update('media_type', e.target.value as ChatbotMediaType)} className={inputClass}>
                  {MEDIA_TYPES.map((mt) => (
                    <option key={mt} value={mt}>
                      {mt}
                    </option>
                  ))}
                </select>
              </div>
              <div>
                <label className="text-sm font-medium text-slate-700">Media URL</label>
                <input value={form.url} onChange={(e) => update('url', e.target.value)} placeholder="https://..." className={inputClass} />
              </div>
              <div>
                <label className="text-sm font-medium text-slate-700">Caption (optional)</label>
                <input value={form.caption} onChange={(e) => update('caption', e.target.value)} className={inputClass} />
              </div>
              {form.media_type === 'document' && (
                <div>
                  <label className="text-sm font-medium text-slate-700">Filename (optional)</label>
                  <input value={form.filename} onChange={(e) => update('filename', e.target.value)} placeholder="brochure.pdf" className={inputClass} />
                </div>
              )}
            </div>
          )}

          {form.response_type === 'interactive' && (
            <div className="space-y-3 rounded-lg border border-slate-200 p-3">
              <div>
                <label className="text-sm font-medium text-slate-700">Body text</label>
                <textarea value={form.body} onChange={(e) => update('body', e.target.value)} rows={2} className={inputClass} />
              </div>
              <div>
                <label className="text-sm font-medium text-slate-700">Layout</label>
                <select
                  value={form.interactive_type}
                  onChange={(e) => update('interactive_type', e.target.value as 'button' | 'list')}
                  className={inputClass}
                >
                  <option value="button">Quick reply buttons (max 3)</option>
                  <option value="list">List message</option>
                </select>
              </div>

              {form.interactive_type === 'button' ? (
                <div className="space-y-2">
                  <label className="text-sm font-medium text-slate-700">Buttons</label>
                  {form.buttons.map((btn, i) => (
                    <div key={i} className="flex items-center gap-2">
                      <input
                        value={btn.title}
                        onChange={(e) => {
                          const next = [...form.buttons];
                          next[i] = { ...next[i], id: next[i].id || `opt${i + 1}`, title: e.target.value };
                          update('buttons', next);
                        }}
                        placeholder={`Button ${i + 1} title (max 20 chars)`}
                        maxLength={20}
                        className={`${inputClass} mt-0`}
                      />
                      <button
                        type="button"
                        onClick={() => update('buttons', form.buttons.filter((_, idx) => idx !== i))}
                        className="text-slate-400 hover:text-red-600"
                      >
                        <Trash2 className="h-4 w-4" />
                      </button>
                    </div>
                  ))}
                  {form.buttons.length < 3 && (
                    <button
                      type="button"
                      onClick={() => update('buttons', [...form.buttons, { id: `opt${form.buttons.length + 1}`, title: '' }])}
                      className="text-xs font-medium text-indigo-600 hover:text-indigo-700"
                    >
                      + Add button
                    </button>
                  )}
                </div>
              ) : (
                <div className="space-y-3">
                  <div>
                    <label className="text-sm font-medium text-slate-700">List button label</label>
                    <input value={form.button_text} onChange={(e) => update('button_text', e.target.value)} className={inputClass} />
                  </div>
                  {form.sections.map((section, si) => (
                    <div key={si} className="space-y-2 rounded-lg border border-slate-200 p-2.5">
                      <div className="flex items-center gap-2">
                        <input
                          value={section.title}
                          onChange={(e) => {
                            const next = [...form.sections];
                            next[si] = { ...next[si], title: e.target.value };
                            update('sections', next);
                          }}
                          placeholder={`Section ${si + 1} title`}
                          className={`${inputClass} mt-0`}
                        />
                        <button
                          type="button"
                          onClick={() => update('sections', form.sections.filter((_, idx) => idx !== si))}
                          className="text-slate-400 hover:text-red-600"
                        >
                          <Trash2 className="h-4 w-4" />
                        </button>
                      </div>
                      {section.rows.map((row, ri) => (
                        <div key={ri} className="ml-3 flex items-center gap-2">
                          <input
                            value={row.title}
                            onChange={(e) => {
                              const next = [...form.sections];
                              next[si] = {
                                ...next[si],
                                rows: next[si].rows.map((r, idx) => (idx === ri ? { ...r, id: r.id || `row${ri + 1}`, title: e.target.value } : r)),
                              };
                              update('sections', next);
                            }}
                            placeholder={`Row ${ri + 1} title`}
                            className={`${inputClass} mt-0 flex-1`}
                          />
                          <button
                            type="button"
                            onClick={() => {
                              const next = [...form.sections];
                              next[si] = { ...next[si], rows: next[si].rows.filter((_, idx) => idx !== ri) };
                              update('sections', next);
                            }}
                            className="text-slate-400 hover:text-red-600"
                          >
                            <Trash2 className="h-3.5 w-3.5" />
                          </button>
                        </div>
                      ))}
                      <button
                        type="button"
                        onClick={() => {
                          const next = [...form.sections];
                          next[si] = { ...next[si], rows: [...next[si].rows, { id: `row${next[si].rows.length + 1}`, title: '', description: '' }] };
                          update('sections', next);
                        }}
                        className="ml-3 text-xs font-medium text-indigo-600 hover:text-indigo-700"
                      >
                        + Add row
                      </button>
                    </div>
                  ))}
                  <button
                    type="button"
                    onClick={() => update('sections', [...form.sections, { title: '', rows: [{ id: 'row1', title: '', description: '' }] }])}
                    className="text-xs font-medium text-indigo-600 hover:text-indigo-700"
                  >
                    + Add section
                  </button>
                </div>
              )}
            </div>
          )}

          <label className="flex items-center gap-2 text-sm text-slate-700">
            <input type="checkbox" checked={form.is_active} onChange={(e) => update('is_active', e.target.checked)} className="h-4 w-4 rounded border-slate-300 text-indigo-600 focus:ring-indigo-500" />
            Active
          </label>

          {error && (
            <div className="flex items-center gap-2 rounded-lg border border-red-200 bg-red-50 px-3 py-2 text-sm text-red-700">
              <XCircle className="h-4 w-4 flex-shrink-0" />
              {error}
            </div>
          )}

          <div className="flex justify-end gap-3 pt-1">
            <button type="button" onClick={onClose} className="rounded-lg border border-slate-300 px-4 py-2 text-sm font-medium text-slate-700 hover:bg-slate-50">
              Cancel
            </button>
            <button
              type="submit"
              disabled={isSaving}
              className="flex items-center gap-2 rounded-lg bg-indigo-600 px-4 py-2 text-sm font-semibold text-white shadow-sm hover:bg-indigo-700 disabled:opacity-60"
            >
              {isSaving ? <Loader2 className="h-4 w-4 animate-spin" /> : null}
              {initial ? 'Save changes' : 'Create rule'}
            </button>
          </div>
        </form>
      </div>
    </div>
  );
}

function RulesTab() {
  const { isReadOnly } = useAuth();
  const [rules, setRules] = useState<ChatbotRule[]>([]);
  const [isLoading, setIsLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);
  const [editing, setEditing] = useState<ChatbotRule | null>(null);
  const [showCreate, setShowCreate] = useState(false);
  const [busyId, setBusyId] = useState<number | null>(null);
  // Rules are the whole (small, per-tenant) list already loaded at once —
  // filtered client-side by name/keyword rather than round-tripping to
  // the server, unlike the paginated Execution Logs tab below.
  const [search, setSearch] = useState('');

  const load = useCallback(async () => {
    setIsLoading(true);
    setError(null);
    try {
      const data = await chatbotService.listRules();
      setRules(data);
    } catch (err) {
      setError(extractMessage(err, 'Failed to load chatbot rules.'));
    } finally {
      setIsLoading(false);
    }
  }, []);

  useEffect(() => {
    void load();
  }, [load]);

  const handleToggle = async (rule: ChatbotRule) => {
    setBusyId(rule.id);
    try {
      await chatbotService.toggleRule(rule.id);
      await load();
    } catch (err) {
      setError(extractMessage(err, 'Could not update this rule.'));
    } finally {
      setBusyId(null);
    }
  };

  const handleDelete = async (rule: ChatbotRule) => {
    if (!confirm(`Delete rule "${rule.name}"? This cannot be undone (past execution logs are kept).`)) return;
    setBusyId(rule.id);
    try {
      await chatbotService.deleteRule(rule.id);
      await load();
    } catch (err) {
      setError(extractMessage(err, 'Could not delete this rule.'));
      setBusyId(null);
    }
  };

  const q = search.trim().toLowerCase();
  const filteredRules = q
    ? rules.filter(
        (rule) => rule.name.toLowerCase().includes(q) || rule.keywords.some((k) => k.toLowerCase().includes(q)),
      )
    : rules;

  return (
    <div className="space-y-4">
      <div className="flex flex-wrap items-center justify-between gap-3">
        <p className="text-sm text-slate-500">
          Rules are evaluated in priority order (lowest first). A "Fallback" rule fires only when nothing else matches.
        </p>
        <div className="flex items-center gap-3">
          <SearchInput value={search} onChange={setSearch} placeholder="Search name or keyword…" />
          <button
            onClick={() => setShowCreate(true)}
            disabled={isReadOnly()}
            title={isReadOnly() ? 'Action disabled: Subscription expired.' : undefined}
            className="flex items-center gap-2 rounded-lg bg-indigo-600 px-4 py-2 text-sm font-semibold text-white shadow-sm hover:bg-indigo-700 disabled:opacity-60"
          >
            <Plus className="h-4 w-4" />
            New Rule
          </button>
        </div>
      </div>

      {error && (
        <div className="flex items-center gap-2 rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700">
          <XCircle className="h-4 w-4 flex-shrink-0" />
          {error}
        </div>
      )}

      <div className="rounded-xl border border-slate-200 bg-white shadow-sm">
        <div className="overflow-x-auto">
          <table className="min-w-full divide-y divide-slate-200 text-sm">
            <thead className="bg-slate-50 text-left text-xs font-medium uppercase text-slate-500">
              <tr>
                <th className="px-6 py-3">Priority</th>
                <th className="px-6 py-3">Name</th>
                <th className="px-6 py-3">Match</th>
                <th className="px-6 py-3">Response</th>
                <th className="px-6 py-3">Status</th>
                <th className="px-6 py-3 text-right">Actions</th>
              </tr>
            </thead>
            <tbody className="divide-y divide-slate-100">
              {isLoading ? (
                <tr>
                  <td colSpan={6} className="px-6 py-6 text-center text-slate-400">
                    <Loader2 className="mx-auto h-4 w-4 animate-spin" />
                  </td>
                </tr>
              ) : filteredRules.length > 0 ? (
                filteredRules.map((rule) => (
                  <tr key={rule.id}>
                    <td className="px-6 py-3 text-slate-500">{rule.priority}</td>
                    <td className="px-6 py-3 font-medium text-slate-900">{rule.name}</td>
                    <td className="px-6 py-3">
                      <span className={`inline-flex items-center rounded-full border px-2.5 py-0.5 text-xs font-medium ${MATCH_TYPE_BADGE[rule.match_type]}`}>
                        {MATCH_TYPE_LABELS[rule.match_type]}
                      </span>
                    </td>
                    <td className="px-6 py-3 text-slate-600">{RESPONSE_TYPE_LABELS[rule.response_type]}</td>
                    <td className="px-6 py-3">
                      <button
                        onClick={() => void handleToggle(rule)}
                        disabled={busyId === rule.id}
                        className={`relative inline-flex h-5 w-9 items-center rounded-full transition disabled:opacity-60 ${
                          rule.is_active ? 'bg-emerald-500' : 'bg-slate-300'
                        }`}
                      >
                        <span className={`inline-block h-3.5 w-3.5 transform rounded-full bg-white transition ${rule.is_active ? 'translate-x-[18px]' : 'translate-x-1'}`} />
                      </button>
                    </td>
                    <td className="px-6 py-3 text-right">
                      <div className="flex justify-end gap-3">
                        <button onClick={() => setEditing(rule)} className="text-xs font-medium text-indigo-600 hover:text-indigo-700">
                          Edit
                        </button>
                        <button
                          onClick={() => void handleDelete(rule)}
                          disabled={busyId === rule.id}
                          className="inline-flex items-center gap-1 text-xs font-medium text-red-600 hover:text-red-700 disabled:opacity-60"
                        >
                          {busyId === rule.id ? <Loader2 className="h-3 w-3 animate-spin" /> : <Trash2 className="h-3 w-3" />}
                          Delete
                        </button>
                      </div>
                    </td>
                  </tr>
                ))
              ) : (
                <tr>
                  <td colSpan={6} className="px-6 py-6 text-center text-slate-400">
                    {rules.length === 0 ? 'No auto-responder rules yet.' : 'No rules match this search.'}
                  </td>
                </tr>
              )}
            </tbody>
          </table>
        </div>
      </div>

      {showCreate && (
        <RuleFormModal
          initial={null}
          onClose={() => setShowCreate(false)}
          onSaved={() => {
            setShowCreate(false);
            void load();
          }}
        />
      )}
      {editing && (
        <RuleFormModal
          initial={editing}
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

// ---------------------------------------------------------------------------
// Tab 2: Execution Logs
// ---------------------------------------------------------------------------

const LOG_STATUS_BADGE: Record<ChatbotLogStatus, string> = {
  replied: 'bg-emerald-50 text-emerald-700 ring-emerald-600/20',
  ignored: 'bg-slate-100 text-slate-600 ring-slate-500/20',
  failed: 'bg-red-50 text-red-700 ring-red-600/20',
};

const LOG_STATUS_OPTIONS: Array<{ value: ChatbotLogStatus | ''; label: string }> = [
  { value: '', label: 'All statuses' },
  { value: 'replied', label: 'Replied' },
  { value: 'ignored', label: 'Ignored' },
  { value: 'failed', label: 'Failed' },
];

function LogsTab() {
  const [logs, setLogs] = useState<ChatbotLog[]>([]);
  const [page, setPage] = useState(1);
  const [lastPage, setLastPage] = useState(1);
  const [total, setTotal] = useState(0);
  const [isLoading, setIsLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);
  const [searchInput, setSearchInput] = useState('');
  const [search, setSearch] = useState('');
  const [status, setStatus] = useState<ChatbotLogStatus | ''>('');

  useEffect(() => {
    const handle = setTimeout(() => setSearch(searchInput.trim()), 400);
    return () => clearTimeout(handle);
  }, [searchInput]);

  const load = useCallback(
    async (pageToLoad: number) => {
      setIsLoading(true);
      setError(null);
      try {
        const res = await chatbotService.getLogs({
          page: pageToLoad,
          per_page: 15,
          search: search || undefined,
          status: status || undefined,
        });
        setLogs(res.data);
        setPage(res.current_page);
        setLastPage(res.last_page);
        setTotal(res.total);
      } catch (err) {
        setError(extractMessage(err, 'Failed to load chatbot execution logs.'));
      } finally {
        setIsLoading(false);
      }
    },
    [search, status],
  );

  useEffect(() => {
    void load(1);
  }, [load]);

  return (
    <div className="rounded-xl border border-slate-200 bg-white shadow-sm">
      <div className="flex flex-wrap items-center gap-2 border-b border-slate-200 p-4">
        <div className="relative">
          <Search className="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-slate-400" />
          <input
            type="text"
            value={searchInput}
            onChange={(e) => setSearchInput(e.target.value)}
            placeholder="Search phone or message…"
            className={`${inputClass.replace('mt-1.5', '')} w-64 pl-9`}
          />
        </div>
        <select value={status} onChange={(e) => setStatus(e.target.value as ChatbotLogStatus | '')} className={inputClass.replace('mt-1.5', '')}>
          {LOG_STATUS_OPTIONS.map((opt) => (
            <option key={opt.value} value={opt.value}>
              {opt.label}
            </option>
          ))}
        </select>
      </div>

      <div className="overflow-x-auto">
        <table className="min-w-full divide-y divide-slate-200 text-sm">
          <thead className="bg-slate-50">
            <tr>
              <th className="px-4 py-2.5 text-left font-medium text-slate-600">From</th>
              <th className="px-4 py-2.5 text-left font-medium text-slate-600">Incoming Message</th>
              <th className="px-4 py-2.5 text-left font-medium text-slate-600">Rule Matched</th>
              <th className="px-4 py-2.5 text-left font-medium text-slate-600">Reply Sent</th>
              <th className="px-4 py-2.5 text-left font-medium text-slate-600">Status</th>
              <th className="px-4 py-2.5 text-left font-medium text-slate-600">When</th>
            </tr>
          </thead>
          <tbody className="divide-y divide-slate-100">
            {isLoading ? (
              <tr>
                <td colSpan={6} className="px-4 py-8 text-center text-slate-400">
                  <Loader2 className="mx-auto h-5 w-5 animate-spin" />
                </td>
              </tr>
            ) : error ? (
              <tr>
                <td colSpan={6} className="px-4 py-8 text-center text-red-600">
                  {error}
                </td>
              </tr>
            ) : logs.length === 0 ? (
              <tr>
                <td colSpan={6} className="px-4 py-8 text-center text-slate-400">
                  No execution logs match these filters.
                </td>
              </tr>
            ) : (
              logs.map((log) => (
                <tr key={log.id}>
                  <td className="px-4 py-2.5 font-mono text-xs text-slate-700">{log.sender_phone}</td>
                  <td className="max-w-[220px] truncate px-4 py-2.5 text-slate-800" title={log.incoming_message}>
                    {log.incoming_message}
                  </td>
                  <td className="px-4 py-2.5 text-slate-600">{log.chatbot_rule?.name ?? '—'}</td>
                  <td className="max-w-[220px] truncate px-4 py-2.5 text-slate-600" title={log.reply_sent ?? undefined}>
                    {log.reply_sent ?? '—'}
                  </td>
                  <td className="px-4 py-2.5">
                    <span className={`inline-flex items-center rounded-full px-2 py-0.5 text-xs font-medium ring-1 ring-inset ${LOG_STATUS_BADGE[log.status]}`}>
                      {log.status}
                    </span>
                  </td>
                  <td className="px-4 py-2.5 text-slate-500">{formatDateTime(log.created_at)}</td>
                </tr>
              ))
            )}
          </tbody>
        </table>
      </div>

      <div className="flex items-center justify-between border-t border-slate-200 px-4 py-3 text-sm text-slate-500">
        <span>{total} total log{total === 1 ? '' : 's'}</span>
        <div className="flex items-center gap-2">
          <button
            onClick={() => void load(page - 1)}
            disabled={page <= 1 || isLoading}
            className="rounded-lg border border-slate-300 px-3 py-1.5 font-medium text-slate-700 hover:bg-slate-50 disabled:opacity-50"
          >
            Prev
          </button>
          <span>
            Page {page} of {lastPage}
          </span>
          <button
            onClick={() => void load(page + 1)}
            disabled={page >= lastPage || isLoading}
            className="rounded-lg border border-slate-300 px-3 py-1.5 font-medium text-slate-700 hover:bg-slate-50 disabled:opacity-50"
          >
            Next
          </button>
        </div>
      </div>
    </div>
  );
}

// ---------------------------------------------------------------------------
// Page shell
// ---------------------------------------------------------------------------

/**
 * Super Admin, Global View (no client selected via the header switcher) —
 * every backend action here (ChatbotRuleController/ChatbotLogController)
 * requires exactly one resolved tenant, so there is no meaningful
 * cross-client rules/logs list to show. Shown instead of attempting the
 * request and surfacing its 422 as a raw error banner.
 */
function SelectClientPrompt() {
  return (
    <div className="rounded-xl border border-slate-200 bg-white p-10 text-center shadow-sm">
      <Users2 className="mx-auto h-8 w-8 text-indigo-400" />
      <p className="mt-3 text-sm font-medium text-slate-900">Select a client to manage their chatbot</p>
      <p className="mx-auto mt-1 max-w-sm text-sm text-slate-500">
        Chatbot rules and execution logs are per-client. Choose a client from the header dropdown above to
        continue.
      </p>
    </div>
  );
}

/**
 * Module 10 — Chatbot Engine. Tenant Admin/editor only (route-gated
 * permission="manage-chatbot"); Super Admin can reach it too, scoped to
 * whichever client is selected in the header (see SelectClientPrompt
 * above for the no-selection case).
 */
export default function ChatbotPage() {
  const { isSuperAdmin } = useAuth();
  const { selectedAccountId } = useTenant();
  const [tab, setTab] = useState<Tab>('rules');

  const needsClientSelection = isSuperAdmin() && selectedAccountId === null;

  return (
    <div className="p-6">
      <div className="w-full space-y-6">
        <div>
          <Link to="/" className="inline-flex items-center gap-1 text-sm text-slate-500 hover:text-slate-700">
            <ArrowLeft className="h-4 w-4" />
            Back to dashboard
          </Link>
          <h1 className="mt-2 flex items-center gap-2 text-xl font-semibold text-slate-900">
            <Bot className="h-5 w-5 text-indigo-600" />
            Chatbot Engine
          </h1>
          <p className="mt-1 text-sm text-slate-500">Keyword auto-responder rules and their execution history.</p>
        </div>

        <div className="flex gap-1 border-b border-slate-200">
          <button
            onClick={() => setTab('rules')}
            className={`flex items-center gap-2 border-b-2 px-4 py-2 text-sm font-medium ${
              tab === 'rules' ? 'border-indigo-600 text-indigo-600' : 'border-transparent text-slate-500 hover:text-slate-700'
            }`}
          >
            <Bot className="h-4 w-4" />
            Auto-Responder Rules
          </button>
          <button
            onClick={() => setTab('logs')}
            className={`flex items-center gap-2 border-b-2 px-4 py-2 text-sm font-medium ${
              tab === 'logs' ? 'border-indigo-600 text-indigo-600' : 'border-transparent text-slate-500 hover:text-slate-700'
            }`}
          >
            <History className="h-4 w-4" />
            Execution Logs
          </button>
        </div>

        {needsClientSelection ? (
          <SelectClientPrompt />
        ) : tab === 'rules' ? (
          <RulesTab />
        ) : (
          <LogsTab />
        )}

        <p className="flex items-center gap-1.5 text-xs text-slate-400">
          <AlertTriangle className="h-3.5 w-3.5" />
          Auto-replies consume the same monthly message quota as payment alerts.
        </p>
      </div>
    </div>
  );
}
