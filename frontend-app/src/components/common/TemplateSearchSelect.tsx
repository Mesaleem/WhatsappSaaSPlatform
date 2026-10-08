import { useEffect, useMemo, useRef, useState } from 'react';
import { ChevronDown, FileText, Search } from 'lucide-react';
import type { AvailableTemplate } from '../../types/templates';

/**
 * Searchable Template Dropdown (owner request 2026-10-08) — Send
 * Notification's template picker was a plain native <select>, which has
 * no type-to-filter and, worse, truncates/hides whatever account_name
 * (see AvailableTemplate's own docblock) is appended to a long option
 * label behind the browser's own fixed-width option rendering. A
 * searchable combobox fixes both at once: the filter box matches title,
 * account_name (Super Admin only -- empty for every other caller, so
 * this silently degrades to a plain title/industry search for them),
 * and industry_type, and the open panel is ours to style, so the owner
 * label is never clipped.
 *
 * No new dependency: this codebase has no combobox/select library
 * installed, and this ships for a locked-down environment that cannot
 * be assumed to have npm registry access — built on plain React state +
 * an outside-click listener, matching every other hand-rolled dropdown
 * already in this app (see Header.tsx's own client switcher).
 *
 * Used for EVERY caller (Client, Agent, Super Admin alike) -- search
 * itself is not Super-Admin-only, only the account_name text it can
 * match against is.
 */
export default function TemplateSearchSelect({
  templates,
  value,
  noTemplate,
  onSelect,
  onSelectNoTemplate,
  disabled = false,
}: {
  templates: AvailableTemplate[];
  value: number | '';
  noTemplate: boolean;
  onSelect: (id: number) => void;
  onSelectNoTemplate: () => void;
  disabled?: boolean;
}) {
  const [open, setOpen] = useState(false);
  const [query, setQuery] = useState('');
  const containerRef = useRef<HTMLDivElement>(null);
  const inputRef = useRef<HTMLInputElement>(null);

  const selected = useMemo(() => templates.find((t) => t.id === value) ?? null, [templates, value]);

  const label = (t: AvailableTemplate) =>
    `${t.title}${t.account_name ? ` — ${t.account_name}` : ''}${t.industry_type ? ` (${t.industry_type})` : ''}`;

  const displayValue = open ? query : noTemplate ? 'No template (write your own message)' : selected ? label(selected) : '';

  const filtered = useMemo(() => {
    const q = query.trim().toLowerCase();
    if (!q) return templates;
    return templates.filter((t) =>
      [t.title, t.account_name, t.industry_type].some((field) => field?.toLowerCase().includes(q)),
    );
  }, [templates, query]);

  // Outside click closes the panel without changing the selection -- matches
  // a native <select>'s own "click away = keep whatever was chosen" behavior.
  useEffect(() => {
    if (!open) return;
    const handleClick = (e: MouseEvent) => {
      if (containerRef.current && !containerRef.current.contains(e.target as Node)) {
        setOpen(false);
        setQuery('');
      }
    };
    document.addEventListener('mousedown', handleClick);
    return () => document.removeEventListener('mousedown', handleClick);
  }, [open]);

  const openPanel = () => {
    if (disabled) return;
    setQuery('');
    setOpen(true);
    // Let the input mount/become visible before focusing it.
    requestAnimationFrame(() => inputRef.current?.focus());
  };

  const pick = (fn: () => void) => {
    fn();
    setOpen(false);
    setQuery('');
  };

  return (
    <div ref={containerRef} className="relative">
      <div className="relative">
        {open && <Search className="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-slate-400" />}
        <input
          ref={inputRef}
          type="text"
          readOnly={!open}
          disabled={disabled}
          value={displayValue}
          placeholder="Select a template…"
          onClick={openPanel}
          onChange={(e) => setQuery(e.target.value)}
          className={`mt-1.5 w-full rounded-lg border border-slate-300 py-2 text-sm text-slate-900 shadow-sm outline-none transition focus:border-indigo-500 focus:ring-2 focus:ring-indigo-100 disabled:bg-slate-50 disabled:text-slate-400 ${
            open ? 'pl-9 pr-3' : 'cursor-pointer px-3 pr-9'
          }`}
        />
        {!open && (
          <ChevronDown className="pointer-events-none absolute right-3 top-1/2 mt-0.5 h-4 w-4 -translate-y-1/2 text-slate-400" />
        )}
      </div>

      {open && (
        <div className="absolute z-20 mt-1 max-h-72 w-full overflow-y-auto rounded-lg border border-slate-200 bg-white py-1 shadow-lg">
          <button
            type="button"
            onMouseDown={(e) => e.preventDefault()}
            onClick={() => pick(onSelectNoTemplate)}
            className={`block w-full px-3 py-2 text-left text-sm hover:bg-slate-50 ${noTemplate ? 'bg-indigo-50 text-indigo-700' : 'text-slate-700'}`}
          >
            No template (write your own message)
          </button>

          {filtered.length === 0 && (
            <p className="px-3 py-2 text-sm text-slate-400">No templates match "{query}".</p>
          )}

          {filtered.map((t) => (
            <button
              key={t.id}
              type="button"
              onMouseDown={(e) => e.preventDefault()}
              onClick={() => pick(() => onSelect(t.id))}
              className={`flex w-full items-start gap-2 px-3 py-2 text-left text-sm hover:bg-slate-50 ${
                t.id === value && !noTemplate ? 'bg-indigo-50 text-indigo-700' : 'text-slate-700'
              }`}
            >
              <FileText className="mt-0.5 h-3.5 w-3.5 flex-shrink-0 text-slate-400" />
              <span>
                <span className="font-medium">{t.title}</span>
                {t.account_name && <span className="ml-1.5 text-xs font-medium text-indigo-600">{t.account_name}</span>}
                {t.industry_type && <span className="ml-1.5 text-xs text-slate-400">({t.industry_type})</span>}
              </span>
            </button>
          ))}
        </div>
      )}
    </div>
  );
}
