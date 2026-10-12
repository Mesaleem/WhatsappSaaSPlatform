import { useCallback, useEffect, useMemo, useState, type ReactNode } from 'react';
import { ArrowDown, ArrowUp, CheckCircle2, ExternalLink, Inbox, Loader2, Plus, RotateCcw, Save, Trash2 } from 'lucide-react';
import ConfirmModal from '../../components/common/ConfirmModal';
import DismissibleAlert from '../../components/common/DismissibleAlert';
import publicSiteService from '../../services/publicSiteService';
import type { ContactMessageRow, SiteContent, SiteContentKey } from '../../types/site';
import { extractErrorMessage } from '../../utils/apiError';

type Json = string | number | boolean | null | Json[] | { [key: string]: Json };

const SECTIONS: { key: SiteContentKey; label: string; preview: string }[] = [
  { key: 'brand', label: 'Brand & contact', preview: '/home' },
  { key: 'home', label: 'Home page', preview: '/home' },
  { key: 'features', label: 'Features page', preview: '/features' },
  { key: 'pricing', label: 'Pricing page', preview: '/pricing' },
  { key: 'about', label: 'About page', preview: '/about' },
  { key: 'contact', label: 'Contact page', preview: '/contact' },
  { key: 'faq', label: 'FAQ', preview: '/home' },
  { key: 'legal', label: 'Legal pages', preview: '/privacy' },
];

/** Field-level help shown under specific keys. */
const HINTS: Record<string, string> = {
  signup_enabled: 'When off, the website hides "Get started" and new sign-ups are refused.',
  trial_days: 'A website sign-up starts on a free trial of this many days (minimum 1).',
  trial_messages: 'Messages included in the free trial.',
  icon: 'One of: message, workflow, users, share, megaphone, sparkles, code, building, bot.',
  url: 'Full link starting with https:// — leave empty to hide.',
  updated: 'Date shown as "Last updated".',
};

const LONG_KEYS = /(subtitle|description|body|answer|mission|address|success|story)$/;

const humanize = (key: string) => key.replace(/_/g, ' ').replace(/^\w/, (c) => c.toUpperCase());
const clone = <T,>(v: T): T => JSON.parse(JSON.stringify(v)) as T;

/** An empty value with the same shape, used when adding a new list item. */
function blankLike(v: Json): Json {
  if (typeof v === 'string') return '';
  if (typeof v === 'number') return 0;
  if (typeof v === 'boolean') return false;
  if (Array.isArray(v)) return [];
  if (v && typeof v === 'object') return Object.fromEntries(Object.entries(v).map(([k, x]) => [k, blankLike(x)]));
  return '';
}

/**
 * Super Admin — Website Content. Edits each section of the public website
 * (GET/PUT /api/admin/site-content) with a form generated from the section's
 * shape, and lists messages from the website's contact form.
 */
export default function WebsiteContentPage() {
  const [tab, setTab] = useState<SiteContentKey | 'messages'>('brand');
  const [content, setContent] = useState<SiteContent | null>(null);
  const [defaults, setDefaults] = useState<SiteContent | null>(null);
  const [customized, setCustomized] = useState<SiteContentKey[]>([]);
  const [draft, setDraft] = useState<Json | null>(null);
  const [loadError, setLoadError] = useState<string | null>(null);
  const [saving, setSaving] = useState(false);
  const [confirmReset, setConfirmReset] = useState(false);
  const [notice, setNotice] = useState<{ type: 'ok' | 'error'; text: string } | null>(null);
  const [openCount, setOpenCount] = useState<number | null>(null);

  const load = useCallback(() => {
    setLoadError(null);
    publicSiteService
      .adminContent()
      .then((res) => {
        setContent(res.data);
        setDefaults(res.defaults);
        setCustomized(res.customized);
      })
      .catch((err) => setLoadError(extractErrorMessage(err, 'Could not load website content.')));
    publicSiteService
      .messages('open')
      .then((res) => setOpenCount(res.open_count))
      .catch(() => setOpenCount(null));
  }, []);

  useEffect(load, [load]);

  useEffect(() => {
    if (content && tab !== 'messages') setDraft(clone(content[tab] as Json));
  }, [content, tab]);

  useEffect(() => setNotice(null), [tab]);

  const dirty = useMemo(
    () => tab !== 'messages' && content !== null && draft !== null && JSON.stringify(draft) !== JSON.stringify(content[tab]),
    [content, draft, tab],
  );

  const section = SECTIONS.find((s) => s.key === tab);

  const save = async () => {
    if (tab === 'messages' || draft === null) return;
    setSaving(true);
    setNotice(null);
    try {
      const res = await publicSiteService.saveSection(tab, draft);
      setContent((c) => (c ? { ...c, [tab]: res.data } : c));
      setCustomized((list) => (list.includes(tab) ? list : [...list, tab]));
      setNotice({ type: 'ok', text: 'Saved. The website shows the new content now.' });
    } catch (err) {
      setNotice({ type: 'error', text: extractErrorMessage(err, 'Could not save this section.') });
    } finally {
      setSaving(false);
    }
  };

  const reset = async () => {
    if (tab === 'messages') return;
    setSaving(true);
    try {
      const res = await publicSiteService.resetSection(tab);
      setContent((c) => (c ? { ...c, [tab]: res.data } : c));
      setCustomized((list) => list.filter((k) => k !== tab));
      setNotice({ type: 'ok', text: 'Section reset to the default content.' });
    } catch (err) {
      setNotice({ type: 'error', text: extractErrorMessage(err, 'Could not reset this section.') });
    } finally {
      setSaving(false);
      setConfirmReset(false);
    }
  };

  return (
    <div className="p-6">
      <div className="w-full space-y-6">
        <div className="flex flex-wrap items-start justify-between gap-3">
          <div>
            <h1 className="text-xl font-semibold text-slate-900">Website Content</h1>
            <p className="mt-1 text-sm text-slate-500">
              Edit the text on the public website (home, features, pricing, about, contact, legal pages) and read contact-form messages.
            </p>
          </div>
          <a
            href="/home"
            target="_blank"
            rel="noopener noreferrer"
            className="inline-flex items-center gap-1.5 rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm font-medium text-slate-700 hover:bg-slate-50"
          >
            <ExternalLink className="h-4 w-4" /> View website
          </a>
        </div>

        {loadError && (
          <DismissibleAlert role="alert" className="rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700">
            {loadError}
          </DismissibleAlert>
        )}

        <div className="flex flex-wrap gap-2" role="tablist">
          {SECTIONS.map((s) => (
            <TabButton key={s.key} active={tab === s.key} onClick={() => setTab(s.key)}>
              {s.label}
              {customized.includes(s.key) && <span className="ml-1.5 h-1.5 w-1.5 rounded-full bg-emerald-500" title="Customised" />}
            </TabButton>
          ))}
          <TabButton active={tab === 'messages'} onClick={() => setTab('messages')}>
            <Inbox className="mr-1.5 h-4 w-4" /> Messages
            {openCount ? <span className="ml-1.5 rounded-full bg-emerald-600 px-1.5 text-[11px] font-semibold text-white">{openCount}</span> : null}
          </TabButton>
        </div>

        {tab === 'messages' ? (
          <MessagesPanel onCountChange={setOpenCount} />
        ) : !content || draft === null ? (
          !loadError && (
            <div className="flex justify-center py-16">
              <Loader2 className="h-6 w-6 animate-spin text-emerald-700" />
            </div>
          )
        ) : (
          <div className="rounded-xl border border-slate-200 bg-white">
            <div className="flex flex-wrap items-center justify-between gap-3 border-b border-slate-200 px-5 py-3">
              <div className="flex items-center gap-2 text-sm">
                <span className="font-semibold text-slate-900">{section?.label}</span>
                <span className={`rounded-full px-2 py-0.5 text-xs ${customized.includes(tab) ? 'bg-emerald-50 text-emerald-700' : 'bg-slate-100 text-slate-500'}`}>
                  {customized.includes(tab) ? 'Customised' : 'Default content'}
                </span>
                {section && (
                  <a href={section.preview} target="_blank" rel="noopener noreferrer" className="inline-flex items-center gap-1 text-xs text-emerald-700 hover:underline">
                    Preview <ExternalLink className="h-3 w-3" />
                  </a>
                )}
              </div>
              <div className="flex flex-wrap gap-2">
                {customized.includes(tab) && (
                  <button
                    type="button"
                    onClick={() => setConfirmReset(true)}
                    disabled={saving}
                    className="inline-flex items-center gap-1.5 rounded-lg border border-slate-300 px-3 py-1.5 text-sm text-slate-700 hover:bg-slate-50 disabled:opacity-50"
                  >
                    <RotateCcw className="h-4 w-4" /> Reset to default
                  </button>
                )}
                <button
                  type="button"
                  onClick={() => setDraft(clone(content[tab] as Json))}
                  disabled={!dirty || saving}
                  className="rounded-lg border border-slate-300 px-3 py-1.5 text-sm text-slate-700 hover:bg-slate-50 disabled:opacity-50"
                >
                  Discard changes
                </button>
                <button
                  type="button"
                  onClick={() => void save()}
                  disabled={!dirty || saving}
                  className="inline-flex items-center gap-1.5 rounded-lg bg-emerald-600 px-4 py-1.5 text-sm font-semibold text-white hover:bg-emerald-700 disabled:opacity-50"
                >
                  {saving ? <Loader2 className="h-4 w-4 animate-spin" /> : <Save className="h-4 w-4" />} Save section
                </button>
              </div>
            </div>

            {notice && (
              <div className={`mx-5 mt-4 flex items-center gap-2 rounded-lg px-4 py-2.5 text-sm ${notice.type === 'ok' ? 'bg-emerald-50 text-emerald-800' : 'bg-red-50 text-red-700'}`} role="status">
                {notice.type === 'ok' && <CheckCircle2 className="h-4 w-4" />} {notice.text}
              </div>
            )}

            <div className="p-5">
              <ValueEditor
                value={draft}
                template={defaults ? (defaults[tab] as Json) : null}
                onChange={setDraft}
                name={tab}
              />
            </div>
          </div>
        )}
      </div>

      {confirmReset && section && (
        <ConfirmModal
          title="Reset to default?"
          message={`"${section.label}" will go back to the built-in default text. Your edits to this section will be lost.`}
          confirmLabel="Reset"
          isLoading={saving}
          onConfirm={() => void reset()}
          onCancel={() => setConfirmReset(false)}
        />
      )}
    </div>
  );
}

function TabButton({ active, onClick, children }: { active: boolean; onClick: () => void; children: ReactNode }) {
  return (
    <button
      type="button"
      role="tab"
      aria-selected={active}
      onClick={onClick}
      className={`inline-flex items-center rounded-lg px-3 py-1.5 text-sm font-medium transition ${
        active ? 'bg-emerald-600 text-white shadow-sm' : 'border border-slate-200 bg-white text-slate-600 hover:bg-slate-50'
      }`}
    >
      {children}
    </button>
  );
}

const inputClass = 'w-full rounded-lg border border-slate-300 px-3 py-2 text-sm text-slate-900 outline-none focus:border-emerald-500 focus:ring-2 focus:ring-emerald-100';

/**
 * Renders a form for any JSON value: text inputs for strings, toggles for
 * booleans, editable lists for arrays and grouped fields for objects.
 * `template` (the default for the same spot) supplies the shape of a new
 * list item when the current list is empty.
 */
function ValueEditor({ value, template, onChange, name }: { value: Json; template: Json | null; onChange: (v: Json) => void; name: string }) {
  const hint = HINTS[name];

  if (typeof value === 'boolean') {
    return (
      <label className="flex items-start gap-2.5 text-sm text-slate-700">
        <input type="checkbox" checked={value} onChange={(e) => onChange(e.target.checked)} className="mt-0.5 h-4 w-4 rounded border-slate-300 text-emerald-700" aria-label={humanize(name)} />
        <span>
          <span className="font-medium">{humanize(name)}</span>
          {hint && <span className="block text-xs text-slate-500">{hint}</span>}
        </span>
      </label>
    );
  }

  if (typeof value === 'number') {
    return (
      <Labeled name={name} hint={hint}>
        <input type="number" min={0} className={`${inputClass} max-w-[12rem]`} value={value} onChange={(e) => onChange(Number(e.target.value))} aria-label={humanize(name)} />
      </Labeled>
    );
  }

  if (typeof value === 'string' || value === null) {
    const text = value ?? '';
    const long = LONG_KEYS.test(name) || text.length > 90;
    return (
      <Labeled name={name} hint={hint}>
        {long ? (
          <textarea className={`${inputClass} min-h-[88px]`} value={text} onChange={(e) => onChange(e.target.value)} aria-label={humanize(name)} />
        ) : (
          <input className={inputClass} value={text} onChange={(e) => onChange(e.target.value)} aria-label={humanize(name)} />
        )}
      </Labeled>
    );
  }

  if (Array.isArray(value)) {
    const sample = value[0] ?? (Array.isArray(template) ? template[0] : undefined) ?? '';
    const itemTemplate = Array.isArray(template) ? template[0] ?? null : null;
    const objectItems = sample !== null && typeof sample === 'object' && !Array.isArray(sample);

    const update = (i: number, v: Json) => onChange(value.map((x, j) => (j === i ? v : x)));
    const remove = (i: number) => onChange(value.filter((_, j) => j !== i));
    const move = (i: number, d: -1 | 1) => {
      const next = [...value];
      [next[i], next[i + d]] = [next[i + d], next[i]];
      onChange(next);
    };

    return (
      <fieldset className="space-y-2">
        <legend className="mb-1 text-sm font-semibold text-slate-800">{humanize(name)}</legend>
        {hint && <p className="text-xs text-slate-500">{hint}</p>}
        {value.map((item, i) => (
          <div key={i} className={objectItems ? 'rounded-lg border border-slate-200 bg-slate-50/60 p-4' : 'flex items-start gap-2'}>
            {objectItems && (
              <div className="mb-3 flex items-center justify-between">
                <span className="text-xs font-semibold uppercase tracking-wide text-slate-400">#{i + 1}</span>
                <ItemControls index={i} count={value.length} onMove={move} onRemove={remove} />
              </div>
            )}
            <div className="min-w-0 flex-1">
              <ValueEditor value={item} template={itemTemplate} onChange={(v) => update(i, v)} name={objectItems ? `${name}_item` : ''} />
            </div>
            {!objectItems && <ItemControls index={i} count={value.length} onMove={move} onRemove={remove} />}
          </div>
        ))}
        <button
          type="button"
          onClick={() => onChange([...value, blankLike(sample)])}
          className="inline-flex items-center gap-1 rounded-lg border border-dashed border-slate-300 px-3 py-1.5 text-sm text-slate-600 hover:border-emerald-400 hover:text-emerald-800"
        >
          <Plus className="h-4 w-4" /> Add {objectItems ? 'item' : 'line'}
        </button>
      </fieldset>
    );
  }

  // Object
  const tmpl = template && typeof template === 'object' && !Array.isArray(template) ? template : {};
  const entries = Object.entries(value);
  const nested = entries.some(([, v]) => v !== null && typeof v === 'object');
  return (
    <div className={nested ? 'space-y-6' : 'grid gap-4 md:grid-cols-2'}>
      {entries.map(([k, v]) => {
        const isGroup = v !== null && typeof v === 'object' && !Array.isArray(v);
        const field = <ValueEditor value={v} template={(tmpl as Record<string, Json>)[k] ?? null} onChange={(nv) => onChange({ ...value, [k]: nv })} name={k} />;
        return isGroup ? (
          <section key={k} className="rounded-xl border border-slate-200 p-4">
            <h3 className="mb-4 text-sm font-semibold text-slate-800">{humanize(k)}</h3>
            {field}
          </section>
        ) : (
          <div key={k} className={Array.isArray(v) || LONG_KEYS.test(k) ? 'md:col-span-2' : ''}>
            {field}
          </div>
        );
      })}
    </div>
  );
}

function Labeled({ name, hint, children }: { name: string; hint?: string; children: ReactNode }) {
  if (!name) return <>{children}</>;
  return (
    <label className="block text-sm">
      <span className="mb-1 block font-medium text-slate-700">{humanize(name)}</span>
      {children}
      {hint && <span className="mt-1 block text-xs text-slate-500">{hint}</span>}
    </label>
  );
}

function ItemControls({ index, count, onMove, onRemove }: { index: number; count: number; onMove: (i: number, d: -1 | 1) => void; onRemove: (i: number) => void }) {
  const btn = 'rounded-md p-1.5 text-slate-400 hover:bg-white hover:text-slate-700 disabled:opacity-30';
  return (
    <div className="flex shrink-0 items-center gap-0.5">
      <button type="button" className={btn} onClick={() => onMove(index, -1)} disabled={index === 0} aria-label="Move up">
        <ArrowUp className="h-4 w-4" />
      </button>
      <button type="button" className={btn} onClick={() => onMove(index, 1)} disabled={index === count - 1} aria-label="Move down">
        <ArrowDown className="h-4 w-4" />
      </button>
      <button type="button" className={`${btn} hover:text-red-600`} onClick={() => onRemove(index)} aria-label="Remove">
        <Trash2 className="h-4 w-4" />
      </button>
    </div>
  );
}

function MessagesPanel({ onCountChange }: { onCountChange: (n: number) => void }) {
  const [status, setStatus] = useState<'open' | 'handled' | 'all'>('open');
  const [page, setPage] = useState(1);
  const [rows, setRows] = useState<ContactMessageRow[] | null>(null);
  const [lastPage, setLastPage] = useState(1);
  const [error, setError] = useState<string | null>(null);

  const load = useCallback(() => {
    setRows(null);
    setError(null);
    publicSiteService
      .messages(status, page)
      .then((res) => {
        setRows(res.data);
        setLastPage(res.last_page);
        onCountChange(res.open_count);
      })
      .catch((err) => setError(extractErrorMessage(err, 'Could not load messages.')));
  }, [status, page, onCountChange]);

  useEffect(load, [load]);

  const toggle = async (row: ContactMessageRow) => {
    try {
      await publicSiteService.setHandled(row.id, !row.handled_at);
      load();
    } catch (err) {
      setError(extractErrorMessage(err, 'Could not update the message.'));
    }
  };

  return (
    <div className="space-y-4">
      <div className="flex gap-2">
        {(['open', 'handled', 'all'] as const).map((s) => (
          <button
            key={s}
            type="button"
            onClick={() => {
              setStatus(s);
              setPage(1);
            }}
            className={`rounded-full px-3 py-1 text-sm ${status === s ? 'bg-slate-900 text-white' : 'bg-slate-100 text-slate-600 hover:bg-slate-200'}`}
          >
            {humanize(s)}
          </button>
        ))}
      </div>

      {error && <p className="text-sm text-red-600" role="alert">{error}</p>}

      {rows === null ? (
        !error && (
          <div className="flex justify-center py-12">
            <Loader2 className="h-6 w-6 animate-spin text-emerald-700" />
          </div>
        )
      ) : rows.length === 0 ? (
        <p className="rounded-xl border border-dashed border-slate-300 py-12 text-center text-sm text-slate-500">No messages here.</p>
      ) : (
        <ul className="space-y-3">
          {rows.map((row) => (
            <li key={row.id} className="rounded-xl border border-slate-200 bg-white p-4">
              <div className="flex flex-wrap items-start justify-between gap-3">
                <div className="min-w-0 text-sm">
                  <p className="font-semibold text-slate-900">
                    {row.name}
                    {row.company && <span className="font-normal text-slate-500"> · {row.company}</span>}
                    {row.topic && <span className="ml-2 rounded-full bg-emerald-50 px-2 py-0.5 text-xs font-medium text-emerald-800">{row.topic}</span>}
                  </p>
                  <p className="mt-0.5 text-slate-500">
                    <a href={`mailto:${row.email}`} className="text-emerald-700 hover:underline">{row.email}</a>
                    {row.phone && <> · {row.phone}</>} · {new Date(row.created_at).toLocaleString()}
                  </p>
                </div>
                <button
                  type="button"
                  onClick={() => void toggle(row)}
                  className={`rounded-lg px-3 py-1.5 text-sm font-medium ${row.handled_at ? 'border border-slate-300 text-slate-700 hover:bg-slate-50' : 'bg-emerald-600 text-white hover:bg-emerald-700'}`}
                >
                  {row.handled_at ? 'Reopen' : 'Mark handled'}
                </button>
              </div>
              <p className="mt-3 whitespace-pre-line text-sm text-slate-700">{row.message}</p>
            </li>
          ))}
        </ul>
      )}

      {lastPage > 1 && (
        <div className="flex items-center justify-end gap-2 text-sm">
          <button type="button" disabled={page <= 1} onClick={() => setPage((p) => p - 1)} className="rounded-lg border border-slate-300 px-3 py-1 disabled:opacity-40">
            Previous
          </button>
          <span className="text-slate-500">Page {page} of {lastPage}</span>
          <button type="button" disabled={page >= lastPage} onClick={() => setPage((p) => p + 1)} className="rounded-lg border border-slate-300 px-3 py-1 disabled:opacity-40">
            Next
          </button>
        </div>
      )}
    </div>
  );
}
